<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\QueueOAuthDurableRecoveryStore;
use PDO;
use RuntimeException;
use Throwable;

final class QueueCoreRepository
{
    private bool $lastEnqueueCreated=false;

    public function __construct(private readonly PDO $pdo) {}

    public function enqueue(QueueJob $job): int
    {
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
        $sql = 'INSERT INTO queue_core_jobs
            (company_id,meli_account_id,work_type,resource_type,resource_id,lane,queue_domain,priority,
             idempotency_key,input_version,state,max_attempts,available_at,source,source_ref,payload_json,provenance_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,\'pending\',?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $job->companyId, $job->meliAccountId, $job->workType, $job->resourceType,
            $job->resourceId, $job->lane, $job->domain(), $job->priority, $job->idempotencyKey,
            $job->inputVersion, $job->maxAttempts,
            $job->availableAt ?? gmdate('Y-m-d H:i:s'), $job->source, $job->sourceRef,
            self::json($job->payload), self::json($job->provenance),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            $lookup = $this->pdo->prepare('SELECT id FROM queue_core_jobs WHERE company_id=? AND meli_account_id=? AND work_type=? AND idempotency_key=? AND input_version=?');
            $lookup->execute([$job->companyId,$job->meliAccountId,$job->workType,$job->idempotencyKey,$job->inputVersion]);
            $id = (int) $lookup->fetchColumn();
        }
        if ($id < 1) {
            throw new RuntimeException('Queue Core could not persist idempotent work.');
        }
        $this->lastEnqueueCreated=$stmt->rowCount()===1;
        if ($this->lastEnqueueCreated) {
            $this->event($id,$job->companyId,$job->meliAccountId,$job->lane,'created');
        }
        if($ownsTransaction)$this->pdo->commit();
        return $id;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function lastEnqueueCreated(): bool
    {
        return $this->lastEnqueueCreated;
    }

    /**
     * Jobs whose account token is not eligible do not belong to the executable
     * FIFO. Parking them before the OAuth work is appended prevents a large
     * historical head from starving token recovery without priority jumping.
     */
    public function parkRemoteWorkForOAuth(int $companyId, int $accountId, int $refreshVersion): int
    {
        if ($companyId < 1 || $accountId < 1 || $refreshVersion < 0) {
            return 0;
        }
        $statement = $this->pdo->prepare(
            "UPDATE queue_core_jobs
             SET state='waiting_oauth',wait_refresh_version=?,next_attempt_at=NULL,
                 last_error_class='oauth_refresh_required',updated_at=UTC_TIMESTAMP(3)
             WHERE company_id=? AND meli_account_id=? AND queue_domain='operational'
               AND state IN ('pending','retry_wait')
               AND work_type NOT IN ('oauth_refresh','financial_projection')"
        );
        $statement->execute([$refreshVersion, $companyId, $accountId]);
        return $statement->rowCount();
    }

    /**
     * Append an exact observation without creating a second concurrently
     * executable fetch for the same resource.  The scheduler row is the
     * serialization point shared by Fresh and webhook producers; the oldest
     * active logical job keeps its original FIFO id.
     *
     * @param list<string> $equivalentWorkTypes
     */
    public function enqueueCoalescedExact(QueueJob $job, array $equivalentWorkTypes): int
    {
        $types = array_values(array_unique(array_filter(array_map('strval', $equivalentWorkTypes))));
        if ($job->resourceId === null || $job->resourceId === '' || $types === []) {
            return $this->enqueue($job);
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->query(
                "SELECT cycle_position FROM queue_core_scheduler_state
                 WHERE scheduler_key='default' FOR UPDATE"
            )->fetchColumn();
            $in = implode(',', array_fill(0, count($types), '?'));
            $lookup = $this->pdo->prepare(
                "SELECT id FROM queue_core_jobs
                 WHERE company_id=? AND meli_account_id=?
                   AND resource_type=? AND resource_id=?
                   AND queue_domain='operational'
                   AND work_type IN ($in)
                   AND state IN ('pending','claimed','running','retry_wait','waiting_oauth')
                 ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $lookup->execute([
                $job->companyId,
                $job->meliAccountId,
                $job->resourceType,
                $job->resourceId,
                ...$types,
            ]);
            $existing = (int) ($lookup->fetchColumn() ?: 0);
            if ($existing > 0) {
                $this->lastEnqueueCreated = false;
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $existing;
            }
            $id = $this->enqueue($job);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function claimNext(QueueRunRequest $request, array $registeredTypes): ?QueueClaim
    {
        if ($registeredTypes === [] || $request->maxJobs < 1 || microtime(true) >= $request->deadline) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            // One stable lock serializes the FIFO head decision. Lanes and
            // priority are descriptive only; neither may reorder ready work.
            $this->pdo->query("SELECT cycle_position FROM queue_core_scheduler_state WHERE scheduler_key='default' FOR UPDATE")->fetchColumn();
            $row = $this->candidate($request,$registeredTypes);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return null;
            }
            $id=(int)$row['id']; $generation=(int)$row['lease_generation']+1;
            $update=$this->pdo->prepare("UPDATE queue_core_jobs SET state='claimed',claimed_at=UTC_TIMESTAMP(3),lease_owner=?,lease_generation=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),lease_heartbeat_at=UTC_TIMESTAMP(3),attempt_count=attempt_count+1,wait_refresh_version=NULL,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_generation=? AND state IN ('pending','retry_wait','waiting_oauth')");
            $update->execute([$request->workerId,$generation,max(5,$request->leaseSeconds),$id,(int)$row['company_id'],(int)$row['meli_account_id'],(int)$row['lease_generation']]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $scheduler=$this->pdo->prepare("UPDATE queue_core_scheduler_state SET generation=generation+1 WHERE scheduler_key='default'");
            $scheduler->execute();
            if($scheduler->rowCount()!==1){
                $this->pdo->rollBack();
                throw new RuntimeException('Queue Core scheduler generation changed during claim.');
            }
            $this->event($id,(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lane'],'claimed');
            $this->pdo->commit();
            $row['lease_generation']=$generation;
            $row['lease_owner']=$request->workerId;
            $row['attempt_count']=(int)$row['attempt_count']+1;
            $row['state']='claimed';
            return $this->claimFromRow($row);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function beginAttempt(QueueClaim $claim, string $launcher, ?int $runId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $locked=$this->lockedJob($claim);
            if(!is_array($locked) || (string)$locked['state']!=='claimed'){
                throw new RuntimeException('Queue Core lease changed before handler start.');
            }
            $resetKnown=$claim->dispatchState==='DISPATCHED_RESULT_KNOWN'
                && $this->knownResponseRetryAllowed($claim,(int)($locked['last_http_status']??0));
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state='running',started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),dispatch_state=CASE WHEN ?=1 THEN 'NOT_DISPATCHED' ELSE dispatch_state END,last_http_status=CASE WHEN ?=1 THEN NULL ELSE last_http_status END,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='claimed'");
            $u->execute([$resetKnown?1:0,$resetKnown?1:0,$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
            if($u->rowCount()!==1) throw new RuntimeException('Queue Core lease changed before handler start.');
            $a=$this->pdo->prepare("INSERT INTO queue_core_attempts (run_id,job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher) VALUES (?,?,?,?,?,?,?)");
            $a->execute([$runId,$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration,$launcher]);
            $attempt=(int)$this->pdo->lastInsertId();
            $this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,'started');
            $this->pdo->commit();
            return $attempt;
        } catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function validateTransport(QueueClaim $claim,int $leaseSeconds=60): bool
    {
        return $this->heartbeat($claim,$leaseSeconds);
    }

    public function heartbeat(QueueClaim $claim,int $leaseSeconds=60): bool
    {
        $stmt=$this->pdo->prepare("UPDATE queue_core_jobs SET lease_heartbeat_at=UTC_TIMESTAMP(3),lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='running' AND lease_expires_at>UTC_TIMESTAMP(3)");
        $stmt->execute([max(5,min(300,$leaseSeconds)),$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
        return $stmt->rowCount()===1;
    }

    public function reserveTransport(QueueClaim $claim,int $attemptId): bool
    {
        $this->pdo->beginTransaction();
        try {
            if(!$this->lockFence($claim,'running')){$this->pdo->rollBack();return false;}
            $stmt=$this->pdo->prepare("UPDATE queue_core_attempts SET dispatch_reserved_at=UTC_TIMESTAMP(3) WHERE id=? AND job_id=? AND lease_owner=? AND lease_generation=? AND dispatch_reserved_at IS NULL AND physical_http_calls=0");
            $stmt->execute([$attemptId,$claim->id,$claim->leaseOwner,$claim->leaseGeneration]);
            if($stmt->rowCount()!==1){$this->pdo->rollBack();return false;}
            $this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,'dispatch_reserved');
            $this->pdo->commit();return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function physicalTransportStarted(QueueClaim $claim,int $attemptId,string $method,string $endpoint): bool
    {
        $this->pdo->beginTransaction();
        try {
            if(!$this->lockFence($claim,'running')){$this->pdo->rollBack();return false;}
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET dispatch_state='DISPATCHED_RESULT_UNCERTAIN',updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='running' AND dispatch_state='NOT_DISPATCHED'");
            $u->execute([$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
            if($u->rowCount()!==1){$this->pdo->rollBack();return false;}
            $j=$this->pdo->prepare("INSERT INTO queue_core_dispatch_journal (job_id,attempt_id,company_id,meli_account_id,lease_owner,lease_generation,method,endpoint_key,state) VALUES (?,?,?,?,?,?,?,?, 'in_flight')");
            $j->execute([$claim->id,$attemptId,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration,strtoupper($method),substr($endpoint,0,120)]);
            $attempt=$this->pdo->prepare("UPDATE queue_core_attempts SET dispatch_state='DISPATCHED_RESULT_UNCERTAIN',physical_http_calls=1,physical_http_started_at=UTC_TIMESTAMP(3) WHERE id=? AND job_id=? AND lease_owner=? AND lease_generation=? AND dispatch_state='NOT_DISPATCHED' AND physical_http_calls=0");
            $attempt->execute([$attemptId,$claim->id,$claim->leaseOwner,$claim->leaseGeneration]);
            if($j->rowCount()!==1 || $attempt->rowCount()!==1){$this->pdo->rollBack();return false;}
            $this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,'physical_http_started');
            $this->pdo->commit(); return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Persistent authority for whether this exact fenced attempt crossed the physical HTTP boundary. */
    public function physicalTransportRecorded(QueueClaim $claim,int $attemptId): bool
    {
        $s=$this->pdo->prepare("SELECT 1 FROM queue_core_attempts WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND physical_http_calls=1 AND physical_http_started_at IS NOT NULL LIMIT 1");
        $s->execute([$attemptId,$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
        return (bool)$s->fetchColumn();
    }

    /** Roll back only the marker written before curl_exec; no HTTP has run yet. */
    public function cancelPhysicalTransportBeforeCurl(QueueClaim $claim,int $attemptId): bool
    {
        $this->pdo->beginTransaction();
        try{
            if(!$this->lockFence($claim,'running')){$this->pdo->rollBack();return false;}
            $job=$this->pdo->prepare("UPDATE queue_core_jobs SET dispatch_state='NOT_DISPATCHED',updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='running' AND dispatch_state='DISPATCHED_RESULT_UNCERTAIN'");
            $job->execute([$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
            $attempt=$this->pdo->prepare("UPDATE queue_core_attempts SET dispatch_state='NOT_DISPATCHED',physical_http_calls=0,physical_http_started_at=NULL WHERE id=? AND job_id=? AND lease_owner=? AND lease_generation=? AND dispatch_state='DISPATCHED_RESULT_UNCERTAIN' AND physical_http_calls=1 AND response_known_at IS NULL");
            $attempt->execute([$attemptId,$claim->id,$claim->leaseOwner,$claim->leaseGeneration]);
            $journal=$this->pdo->prepare("UPDATE queue_core_dispatch_journal SET state='cancelled_before_remote' WHERE attempt_id=? AND lease_owner=? AND lease_generation=? AND state='in_flight'");
            $journal->execute([$attemptId,$claim->leaseOwner,$claim->leaseGeneration]);
            if($job->rowCount()!==1||$attempt->rowCount()!==1||$journal->rowCount()!==1){$this->pdo->rollBack();return false;}
            $this->pdo->commit();return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Backward-compatible name; this now means the physical boundary. */
    public function dispatchStarted(QueueClaim $claim,int $attemptId,string $method,string $endpoint): bool
    {
        return $this->physicalTransportStarted($claim,$attemptId,$method,$endpoint);
    }

    public function responseKnown(QueueClaim $claim,int $attemptId,int $status): bool
    {
        $this->pdo->beginTransaction();
        try {
            if(!$this->lockFence($claim,'running')){$this->pdo->rollBack();return false;}
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET dispatch_state='DISPATCHED_RESULT_KNOWN',last_http_status=?,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='running' AND dispatch_state='DISPATCHED_RESULT_UNCERTAIN'");
            $u->execute([$status?:null,$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
            if($u->rowCount()!==1){$this->pdo->rollBack();return false;}
            $journal=$this->pdo->prepare("UPDATE queue_core_dispatch_journal SET state='response_known',http_status=? WHERE attempt_id=? AND lease_owner=? AND lease_generation=? AND state='in_flight'");
            $journal->execute([$status?:null,$attemptId,$claim->leaseOwner,$claim->leaseGeneration]);
            $attempt=$this->pdo->prepare("UPDATE queue_core_attempts SET dispatch_state='DISPATCHED_RESULT_KNOWN',http_status=?,response_known_at=UTC_TIMESTAMP(3) WHERE id=? AND job_id=? AND lease_owner=? AND lease_generation=? AND dispatch_state='DISPATCHED_RESULT_UNCERTAIN' AND physical_http_calls=1");
            $attempt->execute([$status?:null,$attemptId,$claim->id,$claim->leaseOwner,$claim->leaseGeneration]);
            if($journal->rowCount()!==1 || $attempt->rowCount()!==1){$this->pdo->rollBack();return false;}
            $this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,'response_known');
            $this->pdo->commit(); return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function finish(QueueClaim $claim,int $attemptId,QueueResult $result): bool
    {
        $this->pdo->beginTransaction();
        try {
            $row=$this->lockedJob($claim);
            if(!is_array($row) || (string)$row['state']!=='running'){$this->pdo->rollBack();return false;}
            $outcome=$result->outcome;
            if((string)$row['dispatch_state']==='DISPATCHED_RESULT_UNCERTAIN')$outcome='review';
            if($outcome==='retry_wait' && (string)$row['dispatch_state']==='DISPATCHED_RESULT_KNOWN'
                && !$this->knownResponseRetryAllowed($claim,(int)($row['last_http_status']??0)))$outcome='review';
            $failureAttempts=max(0,(int)$row['attempt_count']-($result->consumesFailureAttempt?0:1));
            if($outcome==='retry_wait' && $result->consumesFailureAttempt
                && $failureAttempts >= (int)$row['max_attempts'])$outcome='dead';
            $state=in_array($outcome,['completed','retry_wait','waiting_oauth','review','dead'],true)?$outcome:'review';
            $next=$state==='retry_wait'?($result->retryAt??QueueRetryPolicy::nextAttemptAt((int)$row['attempt_count'])):null;
            $waitVersion=null;
            if($state==='waiting_oauth'){$s=$this->pdo->prepare('SELECT COALESCE(refresh_version,0) FROM meli_tokens WHERE meli_account_id=? LIMIT 1');$s->execute([$claim->meliAccountId]);$waitVersion=max(0,(int)($s->fetchColumn()?:0));}
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state=?,attempt_count=?,next_attempt_at=?,available_at=COALESCE(?,available_at),wait_refresh_version=?,completed_at=CASE WHEN ? IN ('completed','review','dead') THEN UTC_TIMESTAMP(3) ELSE NULL END,last_error_class=?,last_http_status=COALESCE(?,last_http_status),lease_owner=NULL,lease_expires_at=NULL,lease_heartbeat_at=NULL,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state='running'");
            $u->execute([$state,$failureAttempts,$next,$next,$waitVersion,$state,$result->errorClass,$result->httpStatus,$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);
            if($u->rowCount()!==1){$this->pdo->rollBack();return false;}
            $a=$this->pdo->prepare("UPDATE queue_core_attempts SET outcome=?,resources_discovered=?,resources_persisted=?,error_class=?,http_status=COALESCE(?,http_status),source_closed_at=CASE WHEN ?='completed' THEN UTC_TIMESTAMP(3) ELSE source_closed_at END,finished_at=UTC_TIMESTAMP(3) WHERE id=? AND job_id=? AND lease_owner=? AND lease_generation=?");
            $a->execute([$state,$result->resourcesDiscovered,$result->resourcesPersisted,$result->errorClass,$result->httpStatus,$state,$attemptId,$claim->id,$claim->leaseOwner,$claim->leaseGeneration]);
            if($a->rowCount()!==1){$this->pdo->rollBack();return false;}
            $this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,$state,1,$result->resourcesPersisted);
            if($state==='completed')$this->event($claim->id,$claim->companyId,$claim->meliAccountId,$claim->lane,'source_closed');
            $this->pdo->commit();return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function reviveExhaustedTransient(int $jobId,string $expectedWorkType,string $expectedInputVersion,
        ?int $expectedCompanyId=null,?int $expectedAccountId=null): bool
    {
        if($jobId<1 || !in_array($expectedWorkType,['fresh_orders_discovery','order_exact','oauth_refresh'],true)
            || $expectedInputVersion==='')return false;
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('SELECT * FROM queue_core_jobs WHERE id=? AND work_type=? AND input_version=? FOR UPDATE');
            $s->execute([$jobId,$expectedWorkType,$expectedInputVersion]);
            $row=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || (string)$row['state']!=='dead'
                || ($expectedCompanyId!==null && (int)$row['company_id']!==$expectedCompanyId)
                || ($expectedAccountId!==null && (int)$row['meli_account_id']!==$expectedAccountId)
                || !$this->revivableTransient($row)
                || (strtotime((string)$row['updated_at'].' UTC')?:time())>time()-60){if($ownsTransaction)$this->pdo->rollBack();return false;}
            $generation=(int)$row['lease_generation'];
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state='pending',attempt_count=0,revival_count=revival_count+1,available_at=UTC_TIMESTAMP(3),next_attempt_at=NULL,dispatch_state='NOT_DISPATCHED',completed_at=NULL,last_error_class=NULL,last_http_status=NULL,lease_owner=NULL,lease_expires_at=NULL,lease_heartbeat_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND work_type=? AND input_version=? AND state='dead' AND lease_generation=? AND updated_at<=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)");
            $u->execute([$jobId,(int)$row['company_id'],(int)$row['meli_account_id'],$expectedWorkType,$expectedInputVersion,$generation]);
            if($u->rowCount()!==1){if($ownsTransaction)$this->pdo->rollBack();return false;}
            $this->event($jobId,(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lane'],'revived');
            if($ownsTransaction)$this->pdo->commit();return true;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * Close a web-manual job that could not reach QueueRunner's normal finish
     * path. The row lock is the authority: no background continuation is
     * created, and an uncertain physical dispatch is deliberately left for
     * human review instead of being rewritten as safely abandoned.
     */
    public function abandonManualJob(int $jobId,string $reason): bool
    {
        if($jobId<1)return false;
        $safeReason=substr(trim((string)preg_replace('/[^a-z0-9_]+/i','_',strtolower($reason)),'_'),0,100);
        if($safeReason==='')$safeReason='manual_launcher_abandoned';
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare("SELECT * FROM queue_core_jobs WHERE id=? AND queue_domain='manual' AND work_type='manual_exact' FOR UPDATE");
            $s->execute([$jobId]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)
                || !in_array((string)$row['state'],['pending','claimed','running','retry_wait','waiting_oauth'],true)){
                $this->pdo->rollBack();return false;
            }
            $terminalReason=(string)$row['dispatch_state']==='DISPATCHED_RESULT_UNCERTAIN'
                ? 'remote_result_uncertain':$safeReason;
            $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state='review',completed_at=UTC_TIMESTAMP(3),next_attempt_at=NULL,wait_refresh_version=NULL,last_error_class=?,lease_owner=NULL,lease_expires_at=NULL,lease_heartbeat_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND queue_domain='manual' AND work_type='manual_exact' AND state IN ('pending','claimed','running','retry_wait','waiting_oauth')");
            $u->execute([$terminalReason,$jobId]);
            if($u->rowCount()!==1){$this->pdo->rollBack();return false;}
            $a=$this->pdo->prepare("UPDATE queue_core_attempts SET outcome='review',error_class=?,finished_at=UTC_TIMESTAMP(3) WHERE job_id=? AND finished_at IS NULL");
            $a->execute([$terminalReason,$jobId]);
            $expectedAttempts=(string)$row['state']==='running'?1:0;
            if($a->rowCount()!==$expectedAttempts){
                $this->pdo->rollBack();
                throw new RuntimeException('Queue Core manual abandon lost its exact attempt fence.');
            }
            $this->event($jobId,(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lane'],'review');
            $this->pdo->commit();return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * Reconcile durable manual leftovers after the caller has acquired the
     * global manual execution lease. A still-live claimed/running row remains
     * busy. An expired uncertain dispatch is closed only as action-required;
     * it is never made retryable or presented as safely not dispatched.
     */
    public function recoverAbandonedManualJobs(): int
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->query("SELECT * FROM queue_core_jobs WHERE queue_domain='manual' AND work_type='manual_exact' AND state IN ('claimed','running') AND lease_expires_at<=UTC_TIMESTAMP(3) ORDER BY id FOR UPDATE");
            $recovered=0;
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $uncertain=(string)$row['dispatch_state']==='DISPATCHED_RESULT_UNCERTAIN';
                $reason=$uncertain?'remote_result_uncertain':'manual_launcher_abandoned';
                $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state='review',completed_at=UTC_TIMESTAMP(3),next_attempt_at=NULL,wait_refresh_version=NULL,last_error_class=?,lease_owner=NULL,lease_expires_at=NULL,lease_heartbeat_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND queue_domain='manual' AND work_type='manual_exact' AND state IN ('claimed','running') AND lease_expires_at<=UTC_TIMESTAMP(3)");
                $u->execute([$reason,(int)$row['id']]);
                if($u->rowCount()!==1)continue;
                $a=$this->pdo->prepare("UPDATE queue_core_attempts SET outcome='review',error_class=?,finished_at=UTC_TIMESTAMP(3) WHERE job_id=? AND finished_at IS NULL");
                $a->execute([$reason,(int)$row['id']]);
                if((string)$row['state']==='running' && $a->rowCount()!==1)throw new RuntimeException('Queue Core manual recovery lost its exact attempt fence.');
                $this->event((int)$row['id'],(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lane'],'review');
                $recovered++;
            }
            $this->pdo->commit();return $recovered;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @return array{retry_wait:int,review:int,dead:int} */
    public function recoverStale(int $limit=100): array
    {
        $counts=['retry_wait'=>0,'review'=>0,'dead'=>0];
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->query("SELECT * FROM queue_core_jobs WHERE state IN ('claimed','running') AND lease_expires_at<UTC_TIMESTAMP(3) ORDER BY lease_expires_at,id LIMIT ".max(1,min(500,$limit)).' FOR UPDATE');
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $dispatch=(string)$row['dispatch_state'];
                $safeKnownRead = $dispatch === 'DISPATCHED_RESULT_KNOWN'
                    && $this->knownResponseRetryAllowed($this->claimFromRow($row),(int)($row['last_http_status']??0));
                $state=($dispatch==='NOT_DISPATCHED'||$safeKnownRead)?(((int)$row['attempt_count']>=(int)$row['max_attempts'])?'dead':'retry_wait'):'review';
                $next=$state==='retry_wait'?QueueRetryPolicy::nextAttemptAt((int)$row['attempt_count']):null;
                $u=$this->pdo->prepare("UPDATE queue_core_jobs SET state=?,next_attempt_at=?,available_at=COALESCE(?,available_at),last_error_class=?,completed_at=CASE WHEN ? IN ('review','dead') THEN UTC_TIMESTAMP(3) ELSE NULL END,lease_owner=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP(3) WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND state=? AND lease_expires_at<UTC_TIMESTAMP(3)");
                $errorClass=$dispatch==='NOT_DISPATCHED'?'lease_expired_before_dispatch':($safeKnownRead?'lease_expired_after_known_read':'lease_expired_after_dispatch');
                $u->execute([$state,$next,$next,$errorClass,$state,(int)$row['id'],(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lease_owner'],(int)$row['lease_generation'],(string)$row['state']]);
                if($u->rowCount()===1){$a=$this->pdo->prepare("UPDATE queue_core_attempts SET outcome=?,error_class=?,finished_at=UTC_TIMESTAMP(3) WHERE job_id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? AND outcome='started'");$a->execute([$state,$errorClass,(int)$row['id'],(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lease_owner'],(int)$row['lease_generation']]);if((string)$row['state']==='running'&&$a->rowCount()!==1)throw new RuntimeException('Queue Core stale recovery lost its exact attempt fence.');$counts[$state]++;$this->event((int)$row['id'],(int)$row['company_id'],(int)$row['meli_account_id'],(string)$row['lane'],'recovered');}
            }
            $this->pdo->commit();return $counts;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @return array<string,mixed>|null */
    public function job(int $id): ?array
    { $s=$this->pdo->prepare('SELECT * FROM queue_core_jobs WHERE id=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null; }

    /** @param list<string> $types @return array<string,mixed>|null */
    public function peekOldestEligible(array $types, string $domain = 'operational'): ?array
    {
        $types=array_values(array_unique(array_filter(array_map('strval',$types))));
        if($types===[])return null;
        $in=implode(',',array_fill(0,count($types),'?'));
        $sql="SELECT id,company_id,meli_account_id,work_type,state FROM queue_core_jobs
              WHERE work_type IN ($in) AND queue_domain=? AND
              ((state='pending' AND available_at<=UTC_TIMESTAMP(3))
               OR (state='retry_wait' AND next_attempt_at<=UTC_TIMESTAMP(3))
               OR (state='waiting_oauth' AND EXISTS (
                    SELECT 1 FROM meli_tokens t
                    WHERE t.meli_account_id=queue_core_jobs.meli_account_id
                      AND t.refresh_version>COALESCE(queue_core_jobs.wait_refresh_version,0)
                      AND t.expires_at>DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 120 SECOND))))
              ORDER BY id ASC LIMIT 1";
        $statement=$this->pdo->prepare($sql);
        $statement->execute([...$types,$domain]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @param list<string> $types @return array<string,mixed>|null */
    private function candidate(QueueRunRequest $request,array $types): ?array
    {
        $params=[];
        $in=implode(',',array_fill(0,count($types),'?'));$params=array_merge($params,$types);
        $sql="SELECT j.* FROM queue_core_jobs j WHERE j.work_type IN ($in) AND ((j.state='pending' AND j.available_at<=UTC_TIMESTAMP(3)) OR (j.state='retry_wait' AND j.next_attempt_at<=UTC_TIMESTAMP(3)) OR (j.state='waiting_oauth' AND EXISTS (SELECT 1 FROM meli_tokens t WHERE t.meli_account_id=j.meli_account_id AND t.refresh_version>COALESCE(j.wait_refresh_version,0) AND t.expires_at>DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 120 SECOND))))";
        if($request->allowedWorkTypes!==[]){$sql.=' AND j.work_type IN ('.implode(',',array_fill(0,count($request->allowedWorkTypes),'?')).')';$params=array_merge($params,$request->allowedWorkTypes);}
        if($request->accountId!==null){$sql.=' AND j.meli_account_id=?';$params[]=$request->accountId;}
        if($request->domain()!==null){$sql.=' AND j.queue_domain=?';$params[]=$request->domain();}
        // Retry/wait rows keep their original id. While blocked they are not
        // executable; once eligible again they re-enter at that stable FIFO
        // position instead of receiving a new priority or arrival sequence.
        $sql.=" ORDER BY j.id ASC LIMIT 1 FOR UPDATE";
        $s=$this->pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(is_array($r) && $request->targetJobId!==null && (int)$r['id']!==$request->targetJobId)return null;
        return is_array($r)?$r:null;
    }

    private function lockFence(QueueClaim $claim,string $state): bool
    { $r=$this->lockedJob($claim);return is_array($r)&&(string)$r['state']===$state; }
    /** @return array<string,mixed>|null */
    private function lockedJob(QueueClaim $claim): ?array
    {$s=$this->pdo->prepare('SELECT * FROM queue_core_jobs WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=? FOR UPDATE');$s->execute([$claim->id,$claim->companyId,$claim->meliAccountId,$claim->leaseOwner,$claim->leaseGeneration]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function knownResponseRetryAllowed(QueueClaim $claim,int $httpStatus=0): bool
    {
        if(in_array($claim->workType,[
            'fresh_orders_discovery','order_exact','webhook_order_exact',
            'webhook_pack_exact','webhook_shipment_exact',
        ],true))return ($httpStatus>=200&&$httpStatus<300)||$httpStatus===429||$httpStatus>=500;
        if($claim->workType==='oauth_refresh'){
            if($httpStatus===429 || $httpStatus>=500)return true;
            if($httpStatus<200 || $httpStatus>=300)return false;
            $identity=trim((string)($claim->payload['expected_meli_user_id']??''));
            if($identity==='')return false;
            try{
                return (new QueueOAuthDurableRecoveryStore())->load(
                    $claim->companyId,$claim->meliAccountId,$identity
                )!==null;
            }catch(Throwable){return false;}
        }
        if($claim->workType!=='manual_exact')return false;
        $contract=$claim->payload['remote_contract']??null;
        return is_array($contract) && strtoupper((string)($contract['method']??''))==='GET'
            && (int)($contract['max_remote_calls']??0)===1;
    }
    /** @param array<string,mixed> $row */
    private function revivableTransient(array $row): bool
    {
        if(!in_array((string)($row['dispatch_state']??''),['NOT_DISPATCHED','DISPATCHED_RESULT_KNOWN'],true))return false;
        $error=(string)($row['last_error_class']??'');
        if((string)($row['work_type']??'')==='oauth_refresh'){
            if((string)$row['dispatch_state']==='NOT_DISPATCHED'){
                return in_array($error,['oauth_refresh_busy','deadline','deadline_deferred','policy_deferred','rhythm_deferred','pre_remote_blocked','oauth_local_failure'],true);
            }
            $claim=$this->claimFromRow($row);
            $status=(int)($row['last_http_status']??0);
            if($error==='oauth_remote_failure')return $status===429 || $status>=500;
            return $error==='oauth_local_failure' && $this->knownResponseRetryAllowed($claim,$status);
        }
        $allowed=['partial_remote_page','incoherent_remote_paging','lease_expired_before_dispatch',
            'lease_expired_after_known_read','policy_deferred','pre_remote_blocked','deadline'];
        if(in_array($error,$allowed,true))return true;
        if($error==='remote_rate_limit'){
            $status=(int)($row['last_http_status']??0);
            return $status===429 || $status>=500;
        }
        if($error!=='meli_http_error')return false;
        $status=(int)($row['last_http_status']??0);
        return $status===0 || $status===429 || $status>=500;
    }
    /** @param array<string,mixed> $r */
    private function claimFromRow(array $r): QueueClaim
    {return new QueueClaim((int)$r['id'],(int)$r['company_id'],(int)$r['meli_account_id'],(string)$r['work_type'],(string)$r['resource_type'],$r['resource_id']!==null?(string)$r['resource_id']:null,(string)$r['lane'],(int)$r['priority'],(string)$r['state'],(int)$r['attempt_count'],(int)$r['max_attempts'],(string)$r['lease_owner'],(int)$r['lease_generation'],(string)$r['dispatch_state'],self::decode((string)$r['payload_json']),(string)$r['source'],$r['source_ref']!==null?(string)$r['source_ref']:null);}
    private function event(?int $job,int $company,int $account,string $lane,string $type,int $count=1,int $resources=0): void
    {$s=$this->pdo->prepare('INSERT INTO queue_core_events (job_id,company_id,meli_account_id,lane,event_type,event_count,resources_count) VALUES (?,?,?,?,?,?,?)');$s->execute([$job,$company,$account,$lane,$type,$count,$resources]);}
    /** @param array<string,mixed> $v */ private static function json(array $v): string {$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return $j;}
    /** @return array<string,mixed> */ private static function decode(string $v): array {$r=json_decode($v,true,64,JSON_THROW_ON_ERROR);return is_array($r)?$r:[];}
}
