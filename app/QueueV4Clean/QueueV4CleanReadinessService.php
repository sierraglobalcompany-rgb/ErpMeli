<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Crypto;
use App\Core\Env;
use App\Core\AppPaths;
use App\Core\Session;
use App\Services\BusinessScopeContext;
use App\Services\MeliApiException;
use App\Services\RemoteResultUncertainException;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AutomationCallBudgetService;
use App\Services\AppVersionService;
use App\Services\EmergencyControlService;
use App\Services\InstalledVersionMarkerService;
use App\Services\MeliApiClient;
use App\Services\ManualPhysicalCallBudget;
use App\Services\MeliReadClientInterface;
use App\Services\Migrator;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanReadinessService
{
    private const REQUIRED_MIGRATION = '298_queue_v4_sales_repair_transport_authority_2_38_9.sql';
    private const SESSION_KEY = '_queue_v4_clean_readiness';
    /** @var \Closure(int):MeliReadClientInterface */
    private \Closure $clientFactory;

    /** @param null|callable(int):MeliReadClientInterface $clientFactory */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $repository = new QueueV4CleanRepository($this->pdo);
        $control = $repository->control();
        $budget = (new AutomationCallBudgetService())->resolve(null, null);
        $observability = $repository->operationalObservability();
        $review = (new QueueV4CleanReviewService($this->pdo))->summary();
        $oauth = (new QueueV4CleanOAuthOperationRepository($this->pdo))->observability();
        $checks = $this->preconditions();
        $stored = (string) $control['readiness_state'];
        $state = $stored;
        if (!in_array($stored, ['TESTING', 'CERTIFIED'], true)) {
            $state = $checks['ok'] ? 'READY_TO_TEST' : ($stored === 'FAILED' ? 'FAILED' : 'NOT_READY');
        }
        return array_merge([
            'ok' => true,
            'state' => $state,
            'accounts_oauth' => count($checks['accounts']),
            'readiness_get_passed' => (int) $control['readiness_passed_accounts'],
            'queue' => $repository->counts(),
            'scheduler' => (int) $control['scheduler_enabled'] === 1 ? 'active' : 'inactive',
            'scheduler_config' => (int) $control['scheduler_enabled'] === 1 ? 'ENABLED' : 'DISABLED',
            'last_scheduler_heartbeat' => $observability['last_scheduler_heartbeat'],
            'physical_cron_observed' => $observability['physical_cron_observed'],
            'engine' => (string) $control['engine_state'],
            'control_unit' => $budget['control_unit'],
            'max_calls' => $budget['max_calls'],
            'max_calls_source' => $budget['max_calls_source'],
            'configured_max_calls' => $budget['configured_max_calls'],
            'review_forensics' => $review,
            'oauth_control_plane' => $oauth,
            'issues' => $checks['issues'],
            'legacy_state_consulted' => false,
            'read_only' => true,
        ], $this->ownProgress());
    }

    /** @return list<string> */
    public function activationIssues(): array
    {
        return $this->preconditions()['issues'];
    }


    /** The retired single-request certification may not split itself into budgets. */
    public function certify(int $actorId): array
    {
        throw new RuntimeException('queue_v4_clean_readiness_explicit_steps_required');
    }

    public function prepare(int $actorId): array
    {
        $this->assertNoOuterBudget();
        $this->freshSession();
        $this->assertActor($actorId);
        $checks = $this->preconditions();
        if (!$checks['ok']) { throw new RuntimeException('queue_v4_clean_not_ready:' . implode(',', $checks['issues'])); }
        // This session lock covers local work only, never physical HTTP.
        if (!session_start()) { throw new RuntimeException('queue_v4_clean_session_unavailable'); }
        try {
            $this->assertActor($actorId);
            $this->pdo->beginTransaction();
            $control = (new QueueV4CleanRepository($this->pdo))->control(true);
            if ($control['engine_state'] === 'ACTIVE' || (int)$control['scheduler_enabled'] !== 0) {
                throw new RuntimeException('queue_v4_clean_readiness_runtime_active');
            }
            if ($control['readiness_state'] === 'TESTING') { throw new RuntimeException('queue_v4_clean_readiness_cancel_pending_first'); }
            $this->pdo->prepare("INSERT INTO queue_v4_clean_readiness_runs(state,started_by) VALUES('TESTING',?)")->execute([$actorId]);
            $manifest = ['run_id'=>(int)$this->pdo->lastInsertId(), 'run_token'=>bin2hex(random_bytes(32)),
                'actor_id'=>$actorId, 'session_id'=>hash('sha256',session_id()), 'expires_at'=>time()+600,
                'session_generation'=>(string)(Session::get('user')['session_generation'] ?? ''),
                'accounts'=>$checks['accounts'], 'content_fingerprint'=>$this->contentFingerprint()];
            $this->pdo->prepare("UPDATE queue_v4_clean_control SET engine_state='STOPPED',readiness_state='TESTING',readiness_passed_accounts=0,readiness_error_class=NULL,certified_at=NULL,updated_by=? WHERE control_key='primary'")->execute([$actorId]);
            $this->pdo->commit();
            Session::put(self::SESSION_KEY, $manifest);
            if (!session_write_close()) { throw new RuntimeException('queue_v4_clean_session_persist_failed'); }
            return $this->receipt($manifest, ['state'=>'TESTING','passed_accounts'=>0], true, null, $this->zeroReceipt());
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            throw $error;
        }
    }

    public function check(int $actorId, int $runId, string $token, int $stepNo): array
    {
        $this->assertNoOuterBudget();
        if ($stepNo < 1 || $stepNo > 3) { throw new RuntimeException('queue_v4_clean_readiness_step_invalid'); }
        return ManualPhysicalCallBudget::withinTechnical(1, function() use($actorId,$runId,$token,$stepNo): array {
            // Include initial session-lock wait and preflight in the same deadline.
            $this->freshSession();
            $manifest = $this->validateContext($actorId, $runId, $token);
            if ((int)$this->pdo->query("SELECT GET_LOCK('erp_meli_queue_v4_clean_readiness',0)")->fetchColumn() !== 1) {
                throw new RuntimeException('queue_v4_clean_readiness_busy');
            }
            try {
                $this->freshSession();
                $this->validateContext($actorId,$runId,$token);
                $this->pdo->beginTransaction();
                $run = $this->currentRun($manifest, ['TESTING','CERTIFIED'], true);
                $account = $manifest['accounts'][$stepNo-1];
                $claim = $this->accountResult($runId,$account);
                if ($claim !== null) {
                    $this->pdo->commit();
                    return $this->receipt($manifest,$run,$claim['outcome']==='PASS',
                        $claim['outcome']==='PASS' ? 'replay' : 'claim_already_consumed', $this->zeroReceipt());
                }
                if ($run['state'] !== 'TESTING' || (int)$run['passed_accounts'] !== $stepNo-1) {
                    throw new RuntimeException('queue_v4_clean_readiness_step_out_of_order');
                }
                for ($i=0; $i<$stepNo-1; $i++) {
                    if (($this->accountResult($runId,$manifest['accounts'][$i])['outcome'] ?? '') !== 'PASS') {
                        throw new RuntimeException('queue_v4_clean_readiness_previous_pass_required');
                    }
                }
                $this->pdo->prepare('INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome,failure_class) VALUES(?,?,?,"FAIL",NULL)')
                    ->execute([$runId,$account['company_id'],$account['meli_account_id']]);
                $this->pdo->commit(); // Single-use claim is durable before client construction or wire.
                $failure = null;
                try {
                    $client = ($this->clientFactory)($account['meli_account_id']);
                    $response = QueueV4CleanTransportContext::runReadiness($account['company_id'],$account['meli_account_id'],
                        static fn(): array => ApiExecutionMetadataContext::run([
                            'source'=>'queue_v4_clean_readiness','job_type'=>'queue_v4_clean_readiness',
                            'company_id'=>$account['company_id'],'account_id'=>$account['meli_account_id'],'bulk'=>false,
                        ], static fn(): array => ApiExecutionMetadataContext::withTechnicalOperation('readiness',static fn(): array => $client->get('/users/me'))),
                        function() use($actorId,$runId,$token,$manifest): void {
                            $this->freshSession();
                            $this->validateContext($actorId,$runId,$token);
                            $this->currentRun($manifest,['TESTING']);
                        });
                    if ((string)($response['id'] ?? '') !== $account['meli_user_id']) { throw new RuntimeException('identity_mismatch'); }
                } catch (Throwable $error) { $failure = $this->failureReason($error); }
                // Capture before withinTechnical clears this request's one owned counter.
                $physical = QueueV4CleanCycleBudget::snapshot();
                $contextValid = true;
                if ($physical['physical_http_calls_certainty'] !== 'CERTIFIED') { $failure = 'remote_result_uncertain'; }
                elseif ($failure === null && $physical['physical_http_calls'] !== 1) { $failure = 'physical_call_not_certified'; }
                try {
                    $this->freshSession();
                    $this->validateContext($actorId,$runId,$token);
                    $this->pdo->beginTransaction();
                    $this->currentRun($manifest,['TESTING'],true);
                    $this->assertActor($actorId);
                    $passed = $failure === null ? $stepNo : $stepNo-1;
                    $state = $failure !== null ? 'FAILED' : ($passed === 3 ? 'CERTIFIED' : 'TESTING');
                    $claimResult = $this->pdo->prepare('UPDATE queue_v4_clean_readiness_accounts SET outcome=?,failure_class=?,checked_at=UTC_TIMESTAMP(3) WHERE readiness_run_id=? AND company_id=? AND meli_account_id=? AND outcome="FAIL" AND failure_class IS NULL');
                    $claimResult->execute([$failure===null?'PASS':'FAIL',$failure,$runId,$account['company_id'],$account['meli_account_id']]);
                    if ($claimResult->rowCount() !== 1) {
                        // The physical request cannot be approved or retried after
                        // losing its single-use claim. Only this exact locked run fails.
                        $failure = 'remote_result_uncertain';
                        $passed = $stepNo-1;
                        $state = 'FAILED';
                    }
                    $runResult = $this->pdo->prepare('UPDATE queue_v4_clean_readiness_runs SET state=?,passed_accounts=?,failure_class=?,finished_at=IF(?="TESTING",NULL,UTC_TIMESTAMP(3)) WHERE id=? AND started_by=? AND state="TESTING"');
                    $runResult->execute([$state,$passed,$failure,$state,$runId,$actorId]);
                    if ($runResult->rowCount() !== 1) { throw new RuntimeException('queue_v4_clean_readiness_run_lost'); }
                    $controlResult = $this->pdo->prepare('UPDATE queue_v4_clean_control SET engine_state=IF(?="CERTIFIED","CERTIFIED","STOPPED"),readiness_state=?,readiness_passed_accounts=?,readiness_error_class=?,certified_at=IF(?="CERTIFIED",UTC_TIMESTAMP(3),NULL),updated_by=? WHERE control_key="primary"');
                    $controlResult->execute([$state,$state,$passed,$failure,$state,$actorId]);
                    if ($controlResult->rowCount() !== 1) { throw new RuntimeException('queue_v4_clean_readiness_finalize_lost'); }
                    $this->pdo->commit();
                    $run = ['state'=>$state,'passed_accounts'=>$passed];
                } catch (Throwable) {
                    if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                    // Revoked or superseded proof cannot change current control.
                    $contextValid = false;
                    if (!in_array($failure, ['remote_429','remote_result_uncertain'], true)) {
                        $failure = 'readiness_context_invalidated';
                    }
                    try { $this->discardContext($manifest); }
                    catch (Throwable) { $failure = 'remote_result_uncertain'; }
                    $run = ['state'=>'FAILED','passed_accounts'=>$stepNo-1];
                }
                $receipt = $this->receipt($manifest,$run,$failure===null,$failure,$physical);
                if (!$contextValid) {
                    // The fresh persisted session may now belong to B or be logged out.
                    // Never re-issue A's proof or private account set from memory.
                    $receipt = array_replace($receipt, ['run_token'=>null,'accounts'=>[], 'next_step'=>null,'expires_at'=>null]);
                }
                return $receipt;
            } finally {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                $this->pdo->query("SELECT RELEASE_LOCK('erp_meli_queue_v4_clean_readiness')");
            }
        });
    }

    /** Empty token explicitly cancels an orphan; it never grants its proof to another session. */
    public function cancel(int $actorId, int $runId, string $token): array
    {
        $this->freshSession();
        $this->assertActor($actorId);
        if ($runId < 1) { throw new RuntimeException('queue_v4_clean_readiness_run_invalid'); }
        if ($token !== '') { $this->validateContext($actorId,$runId,$token); }
        else { $this->assertOrphanScope($actorId,$runId); }
        $this->pdo->beginTransaction();
        try {
            $control = (new QueueV4CleanRepository($this->pdo))->control(true);
            $this->assertActor($actorId);
            if ($token === '') { $this->assertOrphanScope($actorId,$runId); }
            $max = (int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM queue_v4_clean_readiness_runs')->fetchColumn();
            if ($max !== $runId || $control['engine_state']==='ACTIVE' || $control['readiness_state']!=='TESTING') {
                throw new RuntimeException('queue_v4_clean_readiness_cancel_stale');
            }
            $this->pdo->prepare('UPDATE queue_v4_clean_readiness_runs SET state="FAILED",failure_class="cancelled",finished_at=UTC_TIMESTAMP(3) WHERE id=? AND state="TESTING"')->execute([$runId]);
            $this->pdo->prepare('UPDATE queue_v4_clean_control SET engine_state="STOPPED",scheduler_enabled=0,readiness_state="FAILED",readiness_passed_accounts=0,readiness_error_class="cancelled",certified_at=NULL,updated_by=? WHERE control_key="primary"')->execute([$actorId]);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
        return ['ok'=>true,'state'=>'FAILED','run_id'=>$runId,'failure_class'=>'cancelled','physical_http_calls'=>0,'physical_http_calls_certainty'=>'CERTIFIED'];
    }

    /** Read the session before DB locks; revalidate DB/context again under control FOR UPDATE. */
    public function assertActivationContext(int $actorId, int $runId, string $token): array
    {
        if (!$this->pdo->inTransaction()) { $this->freshSession(); }
        $manifest = $this->validateContext($actorId,$runId,$token);
        $run = $this->currentRun($manifest,['CERTIFIED']);
        if ((int)$run['passed_accounts'] !== 3) { throw new RuntimeException('queue_v4_clean_not_certified'); }
        foreach ($manifest['accounts'] as $account) {
            if (($this->accountResult($runId,$account)['outcome'] ?? '') !== 'PASS') { throw new RuntimeException('queue_v4_clean_not_certified'); }
        }
        return ['accounts'=>$this->publicAccounts($manifest['accounts']), 'run_id'=>$runId];
    }

    private function assertNoOuterBudget(): void
    {
        if (QueueV4CleanCycleBudget::snapshot()['limit'] !== 0 || $this->pdo->inTransaction()) {
            throw new RuntimeException('queue_v4_clean_readiness_outer_context_forbidden');
        }
    }

    private function freshSession(bool $writable = false): void
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('queue_v4_clean_session_lock_order'); }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (session_id() === '') { throw new RuntimeException('queue_v4_clean_session_required'); }
        $_SESSION = [];
        if (!session_start(['read_and_close'=>!$writable])) { throw new RuntimeException('queue_v4_clean_session_unavailable'); }
    }

    private function discardContext(array $manifest): void
    {
        // Called only after rollback, never while holding database row locks.
        $this->freshSession(true);
        $current = Session::get(self::SESSION_KEY);
        if (is_array($current) && (int)($current['run_id'] ?? 0)===$manifest['run_id']
            && hash_equals((string)($current['run_token'] ?? ''),$manifest['run_token'])) {
            Session::forget(self::SESSION_KEY);
        }
        if (!session_write_close()) { throw new RuntimeException('queue_v4_clean_session_persist_failed'); }
    }

    private function assertActor(int $actorId): void
    {
        $user = Session::get('user');
        $generation = trim((string)@file_get_contents(AppPaths::storage('session-generation')));
        if ($actorId < 1 || !is_array($user) || (int)($user['id'] ?? 0) !== $actorId
            || ($user['role'] ?? '') !== 'admin' || (int)($user['is_temporary'] ?? 0) !== 0
            || !hash_equals($generation,(string)($user['session_generation'] ?? ''))) {
            throw new RuntimeException('queue_v4_clean_actor_invalid');
        }
        $statement = $this->pdo->prepare('SELECT role,status,is_temporary FROM users WHERE id=?');
        $statement->execute([$actorId]); $current = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current) || $current['role'] !== 'admin' || (int)$current['status'] !== 1 || (int)$current['is_temporary'] !== 0) {
            throw new RuntimeException('queue_v4_clean_actor_invalid');
        }
        $scope = new BusinessScopeContext();
        $companies = $scope->companyIds($actorId); $accounts = $scope->accountIds($actorId);
        $allCompanies = array_map('intval',$this->pdo->query('SELECT id FROM companies WHERE status=1')->fetchAll(PDO::FETCH_COLUMN));
        $allAccounts = array_map('intval',$this->pdo->query('SELECT id FROM meli_accounts WHERE status IN ("conectado","connected")')->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($allCompanies,$companies)!==[] || array_diff($allAccounts,$accounts)!==[]) {
            throw new RuntimeException('queue_v4_clean_global_scope_required');
        }
    }

    /** Orphans grant only local cancellation, never ownership of their missing manifest. */
    private function assertOrphanScope(int $actorId, int $runId): void
    {
        $scope = new BusinessScopeContext();
        $companies = $scope->companyIds($actorId);
        $accounts = $scope->accountIds($actorId);
        foreach ($this->pdo->query('SELECT company_id,id FROM meli_accounts')->fetchAll(PDO::FETCH_ASSOC) as $account) {
            if (!in_array((int)$account['company_id'],$companies,true) || !in_array((int)$account['id'],$accounts,true)) {
                throw new RuntimeException('queue_v4_clean_global_scope_required');
            }
        }
        // A deleted/moved claimed tuple cannot be reconstructed from a newer account.
        $claims = $this->pdo->prepare('SELECT ra.company_id,ra.meli_account_id,a.id current_account_id
            FROM queue_v4_clean_readiness_accounts ra LEFT JOIN meli_accounts a
              ON a.id=ra.meli_account_id AND a.company_id=ra.company_id
            WHERE ra.readiness_run_id=?');
        $claims->execute([$runId]);
        foreach ($claims->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            if ($claim['current_account_id']===null || !in_array((int)$claim['company_id'],$companies,true)
                || !in_array((int)$claim['meli_account_id'],$accounts,true)) {
                throw new RuntimeException('queue_v4_clean_global_scope_required');
            }
        }
    }

    private function validateContext(int $actorId, int $runId, string $token): array
    {
        $this->assertActor($actorId);
        $manifest = Session::get(self::SESSION_KEY);
        if (!is_array($manifest) || $runId < 1 || preg_match('/^[a-f0-9]{64}$/D',$token)!==1
            || (int)($manifest['run_id'] ?? 0)!==$runId || (int)($manifest['actor_id'] ?? 0)!==$actorId
            || !hash_equals((string)($manifest['run_token'] ?? ''),$token)
            || !hash_equals((string)($manifest['session_id'] ?? ''),hash('sha256',session_id()))
            || !array_key_exists('session_generation',$manifest)
            || !hash_equals((string)$manifest['session_generation'],(string)(Session::get('user')['session_generation'] ?? ''))
            || (int)($manifest['expires_at'] ?? 0)<=time()
            || !hash_equals((string)($manifest['content_fingerprint'] ?? ''),$this->contentFingerprint())) {
            throw new RuntimeException('queue_v4_clean_readiness_context_invalid');
        }
        $checks = $this->preconditions();
        if (!$checks['ok'] || $checks['accounts'] !== ($manifest['accounts'] ?? null)) {
            throw new RuntimeException('queue_v4_clean_readiness_inputs_changed');
        }
        return $manifest;
    }

    private function currentRun(array $manifest, array $states, bool $lock = false): array
    {
        $control = (new QueueV4CleanRepository($this->pdo))->control($lock); // Always first lock.
        $max = (int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM queue_v4_clean_readiness_runs')->fetchColumn();
        $statement = $this->pdo->prepare('SELECT id,state,passed_accounts,started_by FROM queue_v4_clean_readiness_runs WHERE id=?'.($lock?' FOR UPDATE':''));
        $statement->execute([$manifest['run_id']]); $run = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run) || $max !== $manifest['run_id'] || (int)$run['started_by']!==$manifest['actor_id']
            || !in_array($run['state'],$states,true) || $control['readiness_state']!==$run['state']
            || $control['engine_state']==='ACTIVE' || (int)$control['scheduler_enabled']!==0) {
            throw new RuntimeException('queue_v4_clean_readiness_generation_lost');
        }
        return $run;
    }

    private function accountResult(int $runId,array $account): ?array
    {
        $s = $this->pdo->prepare('SELECT outcome,failure_class FROM queue_v4_clean_readiness_accounts WHERE readiness_run_id=? AND company_id=? AND meli_account_id=?');
        $s->execute([$runId,$account['company_id'],$account['meli_account_id']]); $row=$s->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function ownProgress(): array
    {
        $progress=['run_id'=>null,'run_token'=>null,'next_step'=>null,'expires_at'=>null,'accounts'=>[],'active_run_id'=>null];
        try {
            $this->freshSession();
            $actor=(int)(Session::get('user')['id'] ?? 0);
            $this->assertActor($actor);
            $activeRunId=(int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM queue_v4_clean_readiness_runs')->fetchColumn();
            try {
                $this->assertOrphanScope($actor,$activeRunId);
                $progress['active_run_id']=$activeRunId ?: null;
            } catch (Throwable) { /* Cancellation hint requires its own full current scope. */ }
            $manifest=Session::get(self::SESSION_KEY);
            if (is_array($manifest)) {
                $this->validateContext($actor,(int)$manifest['run_id'],(string)$manifest['run_token']);
                $run=$this->currentRun($manifest,['TESTING','CERTIFIED']);
                $progress=array_replace($progress,$this->progress($manifest,$run));
            }
        } catch (Throwable) { /* GET never extends TTL or repairs an orphan. */ }
        return $progress;
    }

    private function progress(array $manifest,array $run): array
    {
        return ['run_id'=>$manifest['run_id'],'run_token'=>$manifest['run_token'],
            'next_step'=>$run['state']==='TESTING'?(int)$run['passed_accounts']+1:null,
            'expires_at'=>$manifest['expires_at'],'accounts'=>$this->publicAccounts($manifest['accounts'])];
    }

    private function publicAccounts(array $accounts): array
    {
        return array_map(static fn(array $a):array=>['company_id'=>$a['company_id'],'meli_account_id'=>$a['meli_account_id'],'meli_user_id'=>$a['meli_user_id']],$accounts);
    }

    private function zeroReceipt(): array { return ['physical_http_calls'=>0,'physical_http_calls_certainty'=>'CERTIFIED']; }

    private function receipt(array $manifest,array $run,bool $ok,?string $reason,array $physical): array
    {
        return array_merge($this->progress($manifest,$run),[
            'ok'=>$ok,'state'=>$run['state'],'readiness_get_passed'=>(int)$run['passed_accounts'],
            'oauth_accounts'=>3,'scheduler_created'=>false,'engine_activated'=>false,'queue_jobs_created'=>0,
            'failure_class'=>$ok?null:$reason,'stopped_reason'=>$reason,
            'physical_http_calls'=>$physical['physical_http_calls'],'physical_http_calls_certainty'=>$physical['physical_http_calls_certainty'],
        ]);
    }

    private function failureReason(Throwable $error): string
    {
        $reason = QueueV4CleanCycleBudget::snapshot()['stopped_reason'];
        if (in_array($reason, ['remote_429','remote_429_global_pause'], true)
            || ($error instanceof ApiRhythmDeferredException && $error->reachedRemote && $error->blockingScope==='remote_429_global_pause')
            || ($error instanceof MeliApiException && $error->httpStatus===429)
            || ($error instanceof RemoteResultUncertainException && $error->httpStatus===429)) { return 'remote_429'; }
        if ($error instanceof RemoteResultUncertainException) { return 'remote_result_uncertain'; }
        if ($error->getMessage()==='identity_mismatch') { return 'identity_mismatch'; }
        return 'readiness_failed'; // No remote text or credentials in durable failure_class.
    }

    private function contentFingerprint(): string
    {
        $files = ['VERSION','app/QueueV4Clean/QueueV4CleanReadinessService.php','app/QueueV4Clean/QueueV4CleanControlService.php',
            'app/QueueV4Clean/QueueV4CleanTransportContext.php','app/QueueV4Clean/QueueV4CleanCycleBudget.php',
            'app/Services/MeliApiClient.php','app/Services/CurlMeliHttpTransport.php','app/Services/ManualPhysicalCallBudget.php'];
        $hashes=[];
        foreach($files as $file) {
            $bytes=@file_get_contents(dirname(__DIR__,2).'/'.$file,false,null,0,2097153);
            if (!is_string($bytes) || strlen($bytes)>2097152) { throw new RuntimeException('queue_v4_clean_content_unavailable'); }
            $hashes[$file]=hash('sha256',$bytes);
        }
        return hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    /** @return array{ok:bool,issues:list<string>,accounts:list<array<string,mixed>>} */
    private function preconditions(): array
    {
        $issues = [];
        $version = AppVersionService::fileVersion();
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            $issues[] = 'version_invalid';
        }
        $appVersion = $this->pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
        );
        $appVersion->execute();
        if ((string) $appVersion->fetchColumn() !== $version) {
            $issues[] = 'app_version_invalid';
        }
        $migration = $this->pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE version=?'
        );
        $migration->execute([self::REQUIRED_MIGRATION]);
        if ((int) $migration->fetchColumn() !== 1) {
            $issues[] = 'migration_298_missing';
        }
        $pending = (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->pendingCount();
        if ($pending !== 0) {
            $issues[] = 'pending_migrations';
        }
        array_push($issues, ...(new QueueV4CleanDatabaseContract($this->pdo))->issues());
        $marker = (new InstalledVersionMarkerService())->read();
        if (!$marker['valid'] || !hash_equals($version, $marker['version'])) {
            $issues[] = 'marker_version_invalid';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $issues[] = 'ml_write_enabled';
        }
        if (Env::bool('CRON_V3_ENABLED', false) || Env::bool('CRON_V3_SHADOW_ENABLED', false)) {
            $issues[] = 'legacy_cron_enabled';
        }
        $safety = new EmergencyControlService();
        if (!$safety->automationStopped()) {
            $issues[] = 'automation_not_stopped';
        }
        $control = (new QueueV4CleanRepository($this->pdo))->control();
        if ((int) $control['scheduler_enabled'] !== 0) {
            $issues[] = 'scheduler_not_stopped';
        }
        if ((string) $control['engine_state'] === 'ACTIVE') {
            $issues[] = 'engine_active';
        }
        $accounts = [];
        $rows = $this->pdo->query(
            'SELECT a.company_id,a.id meli_account_id,a.meli_user_id,
                    t.access_token_encrypted,t.refresh_token_encrypted,t.expires_at,t.refresh_version
             FROM meli_accounts a
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected")
             ORDER BY a.company_id,a.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 3) { $issues[] = 'oauth_accounts_not_exactly_three'; }
        foreach ($rows as $row) {
            try {
                $valid = (int) $row['company_id'] > 0
                    && (int) $row['meli_account_id'] > 0
                    && trim((string) $row['meli_user_id']) !== ''
                    && trim(Crypto::decrypt((string) $row['access_token_encrypted'])) !== ''
                    && trim(Crypto::decrypt((string) $row['refresh_token_encrypted'])) !== ''
                    && (strtotime((string) $row['expires_at'] . ' UTC') ?: 0) > time() + 30;
            } catch (Throwable) {
                $valid = false;
            }
            if (!$valid) {
                $issues[] = 'oauth_account_invalid';
            }
            $accounts[] = [
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'meli_user_id' => (string) $row['meli_user_id'],
                'oauth_fingerprint' => hash('sha256', json_encode([(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['meli_user_id'],(string)$row['access_token_encrypted'],(string)$row['refresh_token_encrypted'],(string)$row['expires_at'],(int)$row['refresh_version']],JSON_THROW_ON_ERROR)),
            ];
        }
        if (count($accounts) !== 3) {
            $issues[] = 'oauth_accounts_not_exactly_three';
        }
        $issues = array_values(array_unique($issues));
        return ['ok' => $issues === [], 'issues' => $issues, 'accounts' => $accounts];
    }

}
