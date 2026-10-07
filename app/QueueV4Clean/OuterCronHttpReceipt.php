<?php
declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;
use App\Services\AppSettingsService;
use App\Services\CronDeadlineContext;

/** Technical receipt, not execution authority or a second capacity budget. */
final class OuterCronHttpReceipt
{
    private const COMPONENT = 'queue_v4_outer_http';
    private static ?self $active = null;
    private static ?string $capacitySource = null;
    private int $runId;
    private string $cycleId;
    private array $receipt;
    private array $requests = [];
    private bool $persistenceFailed = false;

    private function __construct(private readonly PDO $pdo, array $capacity)
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('outer_http_receipt_external_transaction'); }
        $this->cycleId = bin2hex(random_bytes(20));
        $this->receipt = [
            'cycle_id'=>$this->cycleId, 'control_unit'=>'PHYSICAL_HTTP_CALL',
            'started_at'=>gmdate('Y-m-d\TH:i:s\Z'), 'ended_at'=>null,
            'configured_http_limit'=>(int)$capacity['max_calls'],
            'configured_http_ceiling'=>(int)$capacity['ceiling'],
            'max_calls_source'=>self::$capacitySource ?? (string)($capacity['max_calls_source'] ?? 'UNSPECIFIED'),
            'physical_http_total'=>null, 'physical_http_known'=>0, 'physical_http_unknown'=>null,
            'http_oauth'=>0, 'http_orders_exact'=>0, 'http_orders_search'=>0, 'http_billing'=>0, 'http_other'=>0,
            'http_2xx'=>0, 'http_206'=>0, 'http_429'=>0, 'http_4xx_other'=>0, 'http_5xx'=>0,
            'blocked_before_transport'=>0, 'released_before_transport'=>0, 'budget_exhausted_before_transport'=>0,
            'physical_request_ids'=>[], 'pending_physical_request'=>null,
            'terminal_status'=>'incomplete', 'protected_stop_reason'=>null,
            'version'=>trim((string)file_get_contents(dirname(__DIR__, 2).'/VERSION')),
            'schema_version'=>(int)$pdo->query('SELECT MAX(CAST(version AS UNSIGNED)) FROM schema_migrations')->fetchColumn(),
        ];
        $insert = $pdo->prepare('INSERT INTO system_execution_runs (run_token,component_key,http_receipt_json) VALUES (?,?,?)');
        $insert->execute([$this->cycleId,self::COMPONENT,json_encode($this->receipt,JSON_THROW_ON_ERROR)]);
        $this->runId = (int)$pdo->lastInsertId();
    }

    /** Identity exists before any callback, including OAuth and early returns. */
    public static function within(PDO $pdo, array $capacity, callable $operation): array
    {
        if (self::$active !== null) { throw new RuntimeException('outer_http_receipt_already_owned'); }
        $cycle = new self($pdo,$capacity);
        self::$active = $cycle;
        try {
            $result = $operation();
            $cycle->finish((string)($result['status'] ?? 'unknown'));
            $result['cron_cycle_id'] = $cycle->cycleId;
            return $result;
        } catch (Throwable $failure) {
            // Keep the original exception. Persistence failure must not fabricate a closed receipt.
            if ($failure instanceof \App\Services\RemoteResultUncertainException) {
                $cycle->receipt['protected_stop_reason']='remote_result_uncertain';
            }
            try { $cycle->finish('failed'); } catch (Throwable) {}
            throw $failure;
        } finally { self::$active = null; }
    }

    /** Preserve CLI resolution provenance without changing the drainer's capacity argument. */
    public static function withCapacitySource(string $source, callable $operation): array
    {
        if (!in_array($source,['ERP_SETTINGS','SAFE_DEFAULT','CLI_MAX_CALLS_OVERRIDE'],true) || self::$capacitySource!==null) {
            throw new RuntimeException('outer_http_capacity_source_invalid');
        }
        self::$capacitySource=$source;
        try { return $operation(); } finally { self::$capacitySource=null; }
    }

    /** Process-local observation before the existing shared budget clears its state. */
    public static function budgetClosed(array $snapshot): void
    {
        if (self::$active===null || ($snapshot['owner']??null)===null) { return; }
        $reason=$snapshot['stopped_reason']??null;
        if (is_string($reason) && $reason!=='') { self::$active->receipt['protected_stop_reason']=$reason; }
        elseif ((int)$snapshot['limit']>0 && (int)$snapshot['remaining']===0) {
            self::$active->receipt['protected_stop_reason']??='budget_exhausted';
        }
    }

    private function finish(string $terminal): void
    {
        $next=$this->receipt;
        $next['ended_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $terminal=(string)($this->receipt['protected_stop_reason']??$terminal);
        $next['terminal_status'] = $this->persistenceFailed || $next['pending_physical_request']!==null ? 'incomplete' : $terminal;
        $next['physical_http_unknown']=$next['pending_physical_request']!==null ? 1 : ($this->persistenceFailed ? null : 0);
        $next['physical_http_total']=$next['terminal_status']==='incomplete' ? null : $next['physical_http_known'];
        if (in_array($terminal,['remote_429_global_pause','remote_result_uncertain','budget_exhausted'],true)) {
            $next['protected_stop_reason']=$terminal;
        }
        $status = $next['terminal_status']==='incomplete' ? 'interrupted'
            : ($terminal === 'completed' ? 'completed' : ($terminal === 'failed' ? 'failed' : 'skipped'));
        $update = $this->pdo->prepare('UPDATE system_execution_runs SET status=?,finished_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),end_reason=?,http_receipt_json=? WHERE id=? AND run_token=? AND component_key=?');
        $update->execute([$status,$terminal,json_encode($next,JSON_THROW_ON_ERROR),$this->runId,$this->cycleId,self::COMPONENT]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('outer_http_receipt_close_identity'); }
        $this->receipt=$next;
    }

    public static function read(PDO $pdo, string $cycleId): array
    {
        $read = $pdo->prepare('SELECT id,status,finished_at,http_receipt_json FROM system_execution_runs WHERE run_token=? AND component_key=?');
        $read->execute([$cycleId,self::COMPONENT]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['http_receipt_json'])) { throw new RuntimeException('outer_http_receipt_not_found'); }
        $receipt=json_decode($row['http_receipt_json'],true,512,JSON_THROW_ON_ERROR);
        // The parent is the only telemetry ledger, including after a crash.
        // Never infer a complete cycle from a known last response alone.
        if ($row['status']==='running' || $row['finished_at']===null) {
            $receipt['terminal_status']='incomplete';
            $receipt['physical_http_total']=null;
        }
        return $receipt;
    }

    public static function reserve(string $requestId,string $method,string $path,int $companyId,int $accountId,string $source): void
    {
        $cycle=self::$active;
        if ($cycle===null) { return; }
        if (!preg_match('/^[a-f0-9]{40}$/D',$requestId) || $companyId<=0 || $accountId<=0
            || !preg_match('/^[a-zA-Z0-9_:-]{1,96}$/D',$source)
            || !in_array($method,['GET','POST','PUT','DELETE','PATCH','HEAD'],true)) {
            throw new RuntimeException('outer_http_request_identity_invalid');
        }
        $endpoint=self::endpoint($path);
        $identity=['company'=>$companyId,'account'=>$accountId,'method'=>$method,'endpoint'=>$endpoint,'source'=>$source];
        if (isset($cycle->requests[$requestId])) {
            if ($cycle->requests[$requestId]['identity']!==$identity || $cycle->requests[$requestId]['wire'] || ($cycle->requests[$requestId]['cancelled']??false)) {
                throw new RuntimeException('outer_http_request_identity_reused');
            }
            return;
        }
        $cycle->requests[$requestId]=['identity'=>$identity,'wire'=>false,'cancelled'=>false,'released'=>false,'known'=>false];
    }

    public static function boundary(string $requestId): void
    {
        $cycle=self::$active;
        if ($cycle===null) { return; }
        if ($cycle->persistenceFailed) { throw new RuntimeException('outer_http_receipt_persistence_failed'); }
        $request=$cycle->requests[$requestId]??null;
        if ($request===null || $request['wire'] || $request['cancelled']) { throw new RuntimeException('outer_http_request_transition_invalid'); }
        $pending=$cycle->receipt['pending_physical_request'];
        if ($pending!==null) {
            if ($pending['request_id']===$requestId) { return; }
            throw new RuntimeException('outer_http_pending_request_exists');
        }
        $identity=$request['identity'];
        $next=$cycle->receipt;
        $next['pending_physical_request']=['request_id'=>$requestId,'company_id'=>$identity['company'],
            'meli_account_id'=>$identity['account'],'source'=>$identity['source'],'method'=>$identity['method'],
            'endpoint'=>$identity['endpoint'],'boundary_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        $next['physical_http_unknown']=1;
        $next['physical_http_total']=null;
        try { $cycle->persist($next); }
        catch (Throwable $failure) { $cycle->persistenceFailed=true; throw $failure; }
    }

    /** Pure process-local bookkeeping. Never reject after Billing's final fence. */
    public static function enteringWire(string $requestId): void
    {
        if (self::$active===null) { return; }
        if (isset(self::$active->requests[$requestId])) { self::$active->requests[$requestId]['wire']=true; }
        else { self::$active->persistenceFailed=true; }
    }

    /** A telemetry failure cannot discard a known OAuth/Financial response. */
    public static function result(string $requestId,int $status,string $curlError): void
    {
        $cycle=self::$active;
        if ($cycle===null) { return; }
        try {
            if (($cycle->requests[$requestId]['known']??false)===true) { return; }
            if (($cycle->requests[$requestId]['wire']??false)!==true
                || ($cycle->receipt['pending_physical_request']['request_id']??null)!==$requestId) {
                throw new RuntimeException('outer_http_result_without_wire');
            }
            $known=$status>0 && $curlError==='';
            // Unknown leaves the already-durable pending untouched.
            if (!$known) { return; }
            $next=$cycle->receipt;
            $next['physical_http_known']++;
            $next['physical_request_ids'][]=$requestId;
            $next['http_'.$cycle->requests[$requestId]['identity']['endpoint']]++;
            if ($status>=200 && $status<300) { $next['http_2xx']++; if ($status===206) { $next['http_206']++; } }
            elseif ($status===429) { $next['http_429']++; }
            elseif ($status>=400 && $status<500) { $next['http_4xx_other']++; }
            elseif ($status>=500 && $status<600) { $next['http_5xx']++; }
            $next['pending_physical_request']=null;
            $next['physical_http_unknown']=0;
            // An open cycle remains incomplete even when its current request is known.
            $cycle->persist($next);
            $cycle->requests[$requestId]['known']=true;
        } catch (Throwable) { $cycle->persistenceFailed=true; }
    }

    public static function cancelBeforeTransport(string $requestId,bool $budgetExhausted=false): void
    {
        $cycle=self::$active;
        if ($cycle===null || !isset($cycle->requests[$requestId]) || $cycle->requests[$requestId]['wire'] || ($cycle->requests[$requestId]['cancelled']??false)) { return; }
        try {
            $next=$cycle->receipt;
            $next['blocked_before_transport']++;
            $next['budget_exhausted_before_transport']+=$budgetExhausted?1:0;
            $pendingId=$next['pending_physical_request']['request_id']??null;
            if ($pendingId===$requestId) {
                $next['pending_physical_request']=null;
                $next['physical_http_unknown']=0;
                $cycle->persist($next);
            } else { $cycle->receipt=$next; }
            $cycle->requests[$requestId]['cancelled']=true;
        } catch (Throwable) { $cycle->persistenceFailed=true; }
    }

    public static function released(string $requestId): void
    {
        $cycle=self::$active;
        if ($cycle===null || !isset($cycle->requests[$requestId]) || $cycle->requests[$requestId]['released']) { return; }
        $cycle->requests[$requestId]['released']=true;
        $cycle->receipt['released_before_transport']++;
    }

    public static function capacity(array $capacity): void
    {
        $cycle=self::$active;
        if ($cycle===null) { return; }
        $cycle->receipt['configured_http_limit']=(int)$capacity['max_calls'];
        $cycle->receipt['configured_http_ceiling']=(int)$capacity['ceiling'];
        // A lease-time revalidation changes values, not the provenance of the original input.
        $update=$cycle->pdo->prepare('UPDATE system_execution_runs SET http_receipt_json=? WHERE id=? AND run_token=? AND component_key=?');
        $update->execute([json_encode($cycle->receipt,JSON_THROW_ON_ERROR),$cycle->runId,$cycle->cycleId,self::COMPONENT]);
    }

    private function persist(array $next): void
    {
        $stmt=$this->pdo->prepare('UPDATE system_execution_runs SET http_receipt_json=? WHERE id=? AND run_token=? AND component_key=? AND status="running" AND finished_at IS NULL');
        $stmt->execute([json_encode($next,JSON_THROW_ON_ERROR),$this->runId,$this->cycleId,self::COMPONENT]);
        if ($stmt->rowCount()!==1) { throw new RuntimeException('outer_http_parent_transition_invalid'); }
        $this->receipt=$next;
    }

    private static function endpoint(string $path): string
    {
        $path=(string)(parse_url($path,PHP_URL_PATH)??$path);
        if ($path==='/oauth/token') { return 'oauth'; }
        if ($path==='/orders/search') { return 'orders_search'; }
        if (preg_match('~^/orders/[0-9]+$~D',$path)) { return 'orders_exact'; }
        if (str_starts_with($path,'/billing/')) { return 'billing'; }
        return 'other';
    }

    /** One indexed, bounded local maintenance step; no remote capacity authority. */
    public static function retainStep(PDO $pdo): array
    {
        $result=['deleted'=>0,'scanned'=>0,'deferred'=>false,'limit'=>10,'scan_limit'=>100];
        $hasTime=static fn(): bool => CronDeadlineContext::canAcceptWork(2) && CronDeadlineContext::remainingSeconds()>2.0;
        if (!$hasTime() || $pdo->inTransaction()) { return [...$result,'deferred'=>true]; }
        $days=max(30,(int)(new AppSettingsService())->get('retention.incident_days','90'));
        $cutoff=gmdate('Y-m-d H:i:s',time()-$days*86400);
        $pdo->beginTransaction();
        try {
            // Constant progress row in the EXISTING technical-retention state
            // table. It is not a telemetry child, dataset rotation or drainer.
            $pdo->exec('INSERT IGNORE INTO system_retention_cli_state (lane_key,last_dataset) VALUES ("outer_http_receipts","0")');
            $state=$pdo->query('SELECT last_dataset FROM system_retention_cli_state WHERE lane_key="outer_http_receipts" FOR UPDATE')->fetchColumn();
            if (!is_string($state) || preg_match('/^[0-9]{1,20}$/D',$state)!==1) { throw new RuntimeException('outer_http_retention_cursor_invalid'); }
            if (!$hasTime()) { $pdo->rollBack(); return [...$result,'deferred'=>true]; }
            // LIMIT applies to indexed physical rows BEFORE JSON/TTL checks.
            // A held prefix is visited once, not selected forever.
            $select=$pdo->prepare('SELECT id,run_token,http_receipt_json,status,finished_at FROM system_execution_runs FORCE INDEX (idx_execution_http_retention) WHERE component_key="queue_v4_outer_http" AND id>? ORDER BY id LIMIT 100');
            $select->execute([$state]);
            $rows=$select->fetchAll(PDO::FETCH_ASSOC);
            $cursor=$rows===[] ? '0' : $state;
            $delete=$pdo->prepare('DELETE FROM system_execution_runs WHERE id=? AND run_token=? AND component_key="queue_v4_outer_http" AND status IN ("completed","failed","skipped") AND finished_at < ? AND http_receipt_json=? AND NOT EXISTS (SELECT 1 FROM system_execution_attempts WHERE system_execution_run_id=system_execution_runs.id)');
            foreach ($rows as $row) {
                if (!$hasTime()) { $pdo->rollBack(); return [...$result,'deleted'=>0,'deferred'=>true]; }
                $cursor=(string)$row['id'];
                $result['scanned']++;
                if (!in_array($row['status'],['completed','failed','skipped'],true)
                    || $row['finished_at']===null || strcmp((string)$row['finished_at'],$cutoff)>=0
                    || !self::purgeable(json_decode((string)$row['http_receipt_json'],true),(string)$row['run_token'])) { continue; }
                if (!$hasTime()) { $pdo->rollBack(); return [...$result,'deleted'=>0,'deferred'=>true]; }
                // Exact bytes and no FK children: no cascading evidence loss.
                $delete->execute([(int)$row['id'],$row['run_token'],$cutoff,$row['http_receipt_json']]);
                $result['deleted']+=$delete->rowCount();
                if ($result['deleted']===10) { break; }
            }
            if (!$hasTime()) { $pdo->rollBack(); return [...$result,'deleted'=>0,'deferred'=>true]; }
            $pdo->prepare('UPDATE system_retention_cli_state SET last_dataset=?,last_stage="parent_hot",last_processed=?,generation=generation+1,heartbeat_at=UTC_TIMESTAMP(3) WHERE lane_key="outer_http_receipts"')->execute([$cursor,$result['deleted']]);
            $pdo->commit();
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $failure;
        }
        return $result;
    }

    private static function purgeable(mixed $receipt,string $cycle): bool
    {
        if (!is_array($receipt) || ($receipt['cycle_id']??null)!==$cycle
            || ($receipt['control_unit']??null)!=='PHYSICAL_HTTP_CALL'
            || !is_string($receipt['ended_at']??null) || $receipt['ended_at']===''
            || !is_string($receipt['terminal_status']??null) || $receipt['terminal_status']==='incomplete'
            || !array_key_exists('pending_physical_request',$receipt) || $receipt['pending_physical_request']!==null
            || ($receipt['physical_http_unknown']??null)!==0
            || !is_int($receipt['physical_http_known']??null) || $receipt['physical_http_known']<0
            || ($receipt['physical_http_total']??null)!==$receipt['physical_http_known']
            || !is_int($receipt['configured_http_limit']??null) || $receipt['configured_http_limit']<1
            || !is_array($receipt['physical_request_ids']??null) || !array_is_list($receipt['physical_request_ids'])) { return false; }
        $ids=$receipt['physical_request_ids'];
        if (count($ids)!==$receipt['physical_http_known'] || count($ids)>$receipt['configured_http_limit']) { return false; }
        foreach ($ids as $id) { if (!is_string($id) || preg_match('/^[a-f0-9]{40}$/D',$id)!==1) { return false; } }
        return count(array_unique($ids))===count($ids);
    }
}
