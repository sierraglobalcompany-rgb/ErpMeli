<?php
declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

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
        $this->receipt['ended_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $terminal=(string)($this->receipt['protected_stop_reason']??$terminal);
        $this->receipt['terminal_status'] = $terminal;
        $this->receipt = self::aggregate($this->pdo,$this->runId,$this->receipt);
        if ($this->persistenceFailed) {
            $this->receipt['terminal_status'] = 'incomplete';
            $this->receipt['physical_http_total'] = null;
        }
        if (in_array($terminal,['remote_429_global_pause','remote_result_uncertain','budget_exhausted'],true)) {
            $this->receipt['protected_stop_reason']=$terminal;
        }
        $status = $terminal === 'completed' ? 'completed' : ($terminal === 'failed' ? 'failed' : 'skipped');
        $update = $this->pdo->prepare('UPDATE system_execution_runs SET status=?,finished_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),end_reason=?,http_receipt_json=? WHERE id=? AND run_token=? AND component_key=?');
        $update->execute([$status,$terminal,json_encode($this->receipt,JSON_THROW_ON_ERROR),$this->runId,$this->cycleId,self::COMPONENT]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('outer_http_receipt_close_identity'); }
    }

    public static function read(PDO $pdo, string $cycleId): array
    {
        $read = $pdo->prepare('SELECT id,status,finished_at,http_receipt_json FROM system_execution_runs WHERE run_token=? AND component_key=?');
        $read->execute([$cycleId,self::COMPONENT]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_string($row['http_receipt_json'])) { throw new RuntimeException('outer_http_receipt_not_found'); }
        $receipt=json_decode($row['http_receipt_json'],true,512,JSON_THROW_ON_ERROR);
        // Closed aggregates remain authoritative when verified retention archives
        // their detail. Never turn missing hot children into a fabricated zero.
        if ($row['status']!=='running' && $row['finished_at']!==null
            && ($receipt['ended_at']??null)!==null && ($receipt['terminal_status']??'incomplete')!=='incomplete') {
            return $receipt;
        }
        return self::aggregate($pdo,(int)$row['id'],$receipt);
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
        $insert=$cycle->pdo->prepare("INSERT INTO system_execution_attempts
            (system_execution_run_id,company_id,meli_account_id,queue_key,source_id,operation_key,idempotency_key,http_request_id,http_state,http_source,http_method,http_endpoint)
            VALUES (?,?,?,'physical_http',?,'outer_http',?,?,'reserved',?,?,?)");
        $insert->execute([$cycle->runId,$companyId,$accountId,$requestId,hash('sha256',$cycle->cycleId.':'.$requestId),$requestId,$source,$method,$endpoint]);
        $cycle->requests[$requestId]=['identity'=>$identity,'wire'=>false];
    }

    public static function boundary(string $requestId): void
    {
        if (self::$active===null) { return; }
        self::$active->updateRequest($requestId,"http_state='boundary_pending',state='uncertain',dispatched_at=UTC_TIMESTAMP(3)",[],['reserved']);
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
            if (($cycle->requests[$requestId]['wire']??false)!==true) { throw new RuntimeException('outer_http_result_without_wire'); }
            $known=$status>0 && $curlError==='';
            $cycle->updateRequest($requestId,'http_state=?,state=?,reached_remote=?,response_status=?,response_at=UTC_TIMESTAMP(3)',
                [$known?'known_result':'uncertain_result',$known?'response_received':'uncertain',$known?1:null,$status>0?$status:null],['boundary_pending']);
        } catch (Throwable) { $cycle->persistenceFailed=true; }
    }

    public static function cancelBeforeTransport(string $requestId,bool $budgetExhausted=false): void
    {
        $cycle=self::$active;
        if ($cycle===null || !isset($cycle->requests[$requestId]) || $cycle->requests[$requestId]['wire'] || ($cycle->requests[$requestId]['cancelled']??false)) { return; }
        try {
            $cycle->updateRequest($requestId,"http_state='cancelled_before_transport',state='failed',reached_remote=0,http_budget_exhausted=?",[$budgetExhausted?1:0],['reserved','boundary_pending']);
            $cycle->requests[$requestId]['cancelled']=true;
        } catch (Throwable) { $cycle->persistenceFailed=true; }
    }

    public static function released(string $requestId): void
    {
        if (self::$active===null) { return; }
        try { self::$active->updateRequest($requestId,'http_budget_released=1',[],['reserved','boundary_pending','cancelled_before_transport']); }
        catch (Throwable) { self::$active->persistenceFailed=true; }
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

    private function updateRequest(string $requestId,string $assignment,array $values,array $states): void
    {
        $identity=$this->requests[$requestId]['identity']??null;
        if ($identity===null) { throw new RuntimeException('outer_http_request_missing'); }
        $sql='UPDATE system_execution_attempts SET '.$assignment.' WHERE system_execution_run_id=? AND http_request_id=? AND company_id=? AND meli_account_id=? AND http_state IN ('.implode(',',array_fill(0,count($states),'?')).')';
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute([...$values,$this->runId,$requestId,$identity['company'],$identity['account'],...$states]);
        if ($stmt->rowCount()!==1) { throw new RuntimeException('outer_http_request_transition_invalid'); }
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

    private static function aggregate(PDO $pdo,int $runId,array $receipt): array
    {
        // This is a technical run census, not a cross-tenant business selection.
        $query=$pdo->prepare('SELECT http_state,http_endpoint,response_status,http_budget_released,http_budget_exhausted FROM system_execution_attempts WHERE system_execution_run_id=? AND http_request_id IS NOT NULL');
        $query->execute([$runId]);
        foreach (['physical_http_known','physical_http_unknown','http_oauth','http_orders_exact','http_orders_search','http_billing','http_other','http_2xx','http_206','http_429','http_4xx_other','http_5xx','blocked_before_transport','released_before_transport','budget_exhausted_before_transport'] as $key) { $receipt[$key]=0; }
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state=$row['http_state'];
            $receipt['released_before_transport']+=(int)$row['http_budget_released'];
            $receipt['budget_exhausted_before_transport']+=(int)$row['http_budget_exhausted'];
            if ($state==='cancelled_before_transport') { $receipt['blocked_before_transport']++; continue; }
            if ($state!=='known_result') { $receipt['physical_http_unknown']++; continue; }
            $receipt['physical_http_known']++;
            $receipt['http_'.$row['http_endpoint']]++;
            $status=(int)$row['response_status'];
            if ($status>=200 && $status<300) { $receipt['http_2xx']++; if ($status===206) { $receipt['http_206']++; } }
            elseif ($status===429) { $receipt['http_429']++; }
            elseif ($status>=400 && $status<500) { $receipt['http_4xx_other']++; }
            elseif ($status>=500 && $status<600) { $receipt['http_5xx']++; }
        }
        $receipt['physical_http_total']=$receipt['physical_http_unknown']>0 || $receipt['terminal_status']==='incomplete' ? null : $receipt['physical_http_known'];
        return $receipt;
    }
}
