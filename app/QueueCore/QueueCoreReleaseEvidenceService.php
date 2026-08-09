<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Env;
use App\Services\QueueCoreDeploymentGateService;
use PDO;

/** Evidencia no secreta y ligada a generación/contexto para el CAS de activación. */
final class QueueCoreReleaseEvidenceService
{
    private const MAX_CERTIFIED_CATCHUP_MINUTES = 11520.0;

    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,scalar|null> $metrics */
    public function record(
        int $engineGeneration,
        string $type,
        bool $passed,
        string $contextHash,
        array $metrics,
        int $ttlSeconds = 3600,
        ?int $companyId = null,
        ?int $accountId = null,
    ): int {
        if (!in_array($type, ['backup', 'capacity', 'manifest'], true)) {
            throw new \InvalidArgumentException('Queue Core release evidence type is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower($contextHash)) !== 1) {
            throw new \InvalidArgumentException('Queue Core readiness context hash is invalid.');
        }
        ksort($metrics);
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_release_evidence
             (engine_generation,evidence_type,company_id,meli_account_id,status,
              readiness_context_hash,evidence_hash,metrics_json,expires_at)
             VALUES (?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
        );
        $statement->execute([
            max(0, $engineGeneration),
            $type,
            $companyId,
            $accountId,
            $passed ? 'pass' : 'fail',
            strtolower($contextHash),
            hash('sha256', $json),
            $json,
            max(60, min(86400, $ttlSeconds)),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new \RuntimeException('Queue Core release evidence was not persisted.');
        }
        return $id;
    }

    /** @return array{ok:bool,reason:string,id?:int,metrics?:array<string,mixed>} */
    public function requireLatest(
        int $engineGeneration,
        string $type,
        string $contextHash,
        ?int $companyId = null,
        ?int $accountId = null,
        bool $enforceExpiry = true,
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT id,status,readiness_context_hash,metrics_json,expires_at
             FROM queue_core_release_evidence
             WHERE engine_generation=? AND evidence_type=?
               AND company_id <=> ? AND meli_account_id <=> ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            max(0, $engineGeneration), $type, $companyId, $accountId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'reason' => $type . '_evidence_missing'];
        }
        if ((string) $row['status'] !== 'pass') {
            return ['ok' => false, 'reason' => $type . '_latest_failed'];
        }
        if (!hash_equals(strtolower($contextHash), strtolower((string) $row['readiness_context_hash']))) {
            return ['ok' => false, 'reason' => $type . '_context_changed'];
        }
        $expires = strtotime((string) $row['expires_at'] . ' UTC');
        if ($enforceExpiry && ($expires === false || $expires <= time())) {
            return ['ok' => false, 'reason' => $type . '_evidence_expired'];
        }
        $metrics = json_decode((string) $row['metrics_json'], true);
        return [
            'ok' => true,
            'reason' => 'ready',
            'id' => (int) $row['id'],
            'metrics' => is_array($metrics) ? $metrics : [],
        ];
    }

    /** @return array{ok:bool,sha256:?string,bytes:int,path_verified:bool,format_valid:bool,table_count:int,data_statements:int,current_table_count:int,missing_table_count:int,critical_data_missing_count:int,critical_row_count_mismatch_count:int} */
    public function verifyBackup(string $path, string $expectedSha256, bool $compareLiveRows = true): array
    {
        $expected = strtolower(trim($expectedSha256));
        $approved = strtolower(trim((string) Env::get('QUEUE_CORE_APPROVED_BACKUP_SHA256', '')));
        $approvedPath=trim((string)Env::get('QUEUE_CORE_APPROVED_BACKUP_PATH',''));
        $resolvedPath=$path!==''?realpath($path):false;
        $resolvedApproved=$approvedPath!==''?realpath($approvedPath):false;
        $pathVerified=is_string($resolvedPath)&&is_string($resolvedApproved)
            &&hash_equals(str_replace('\\','/',$resolvedApproved),str_replace('\\','/',$resolvedPath));
        $exists = $path !== '' && is_file($path) && is_readable($path);
        $sha = $exists ? hash_file('sha256', $path) : false;
        $bytes = $exists ? max(0, (int) filesize($path)) : 0;
        $content = $exists ? $this->inspectSqlBackup($path) : [
            'format_valid' => false, 'table_count' => 0, 'data_statements' => 0,
            'tables' => [], 'data_tables' => [], 'row_counts'=>[],
        ];
        $inventory = $this->compareBackupWithCurrentDatabase(
            $content['tables'],
            $content['data_tables'],
            $content['row_counts'],
            $compareLiveRows,
        );
        $ok = is_string($sha)
            && $bytes > 0
            && preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && preg_match('/^[a-f0-9]{64}$/', $approved) === 1
            && $pathVerified
            && hash_equals($approved, $expected)
            && hash_equals($expected, strtolower($sha))
            && $content['format_valid']
            && $inventory['missing_table_count'] === 0
            && $inventory['critical_data_missing_count'] === 0
            && $inventory['critical_row_count_mismatch_count'] === 0;
        return [
            'ok' => $ok,
            'sha256' => is_string($sha) ? strtoupper($sha) : null,
            'bytes' => $bytes,
            'path_verified'=>$pathVerified,
            'format_valid' => $content['format_valid'],
            'table_count' => $content['table_count'],
            'data_statements' => $content['data_statements'],
        ] + $inventory;
    }

    /** @return array{ok:bool,id:int,sha256:?string,bytes:int} */
    public function certifyBackup(
        int $engineGeneration,
        string $contextHash,
        string $path,
        string $expectedSha256,
        int $ttlSeconds = 3600,
    ): array {
        $verification = $this->verifyBackup($path, $expectedSha256);
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $tables = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
        )->fetchColumn();
        $metrics = [
            'bytes' => $verification['bytes'],
            'database_scope_hash' => hash('sha256', $database),
            'sha256' => $verification['sha256'],
            'table_count' => max(0, $tables),
            'dump_table_count' => $verification['table_count'],
            'dump_data_statements' => $verification['data_statements'],
            'current_table_count' => $verification['current_table_count'],
            'missing_table_count' => $verification['missing_table_count'],
            'critical_data_missing_count' => $verification['critical_data_missing_count'],
            'critical_row_count_mismatch_count' => $verification['critical_row_count_mismatch_count'],
            'path_verified'=>$verification['path_verified']?1:0,
            'format_valid' => $verification['format_valid'] ? 1 : 0,
            'verified' => $verification['ok'] ? 1 : 0,
        ];
        $id = $this->record(
            $engineGeneration,
            'backup',
            $verification['ok'],
            $contextHash,
            $metrics,
            $ttlSeconds,
        );
        return $verification + ['id' => $id];
    }

    /**
     * @param array<string,float|int> $calculation
     * @param array{cadence_seconds:int,runtime_seconds:int,safe_close_seconds:int,max_remote_jobs:int} $profile
     * @return array{ok:bool,id:int,calculation:array<string,float|int>}
     */
    public function certifyCapacity(
        int $engineGeneration,
        string $contextHash,
        array $calculation,
        array $profile,
        int $ttlSeconds = 3600,
    ): array {
        $arrival = (float) ($calculation['arrival_rate_resources_per_minute'] ?? 0.0);
        $sustainable = (float) ($calculation['sustainable_resources_per_minute'] ?? 0.0);
        $margin = (float) ($calculation['safety_margin_ratio'] ?? 0.0);
        $safeJobs = (int) ($calculation['safe_jobs_per_run'] ?? 0);
        $knownSample = (int) ($calculation['measurement_known_responses'] ?? 0);
        $persistedSample = (int) ($calculation['measurement_resources_persisted'] ?? 0);
        $backlogResources = max(0, (int) ($calculation['measurement_backlog_resources'] ?? 0));
        $catchupMinutes = isset($calculation['catchup_minutes'])
            && is_numeric($calculation['catchup_minutes'])
            ? (float) $calculation['catchup_minutes']
            : null;
        $profileValid = $profile['cadence_seconds'] > 0
            && $profile['runtime_seconds'] > $profile['safe_close_seconds']
            && $profile['max_remote_jobs'] > 0;
        $passed = $arrival >= 0.0
            && $sustainable > $arrival
            && $margin > 0.0
            && $safeJobs > 0
            && $knownSample >= 3
            && $persistedSample > 0
            && $catchupMinutes !== null
            && is_finite($catchupMinutes)
            && $catchupMinutes >= 0.0
            && $catchupMinutes <= self::MAX_CERTIFIED_CATCHUP_MINUTES
            && $profileValid;
        $metrics = [
            'arrival_rate' => round($arrival, 4),
            'catchup_minutes' => $catchupMinutes,
            'margin_ratio' => round($margin, 4),
            'safe_jobs_per_run' => $safeJobs,
            'sustainable_rate' => round($sustainable, 4),
            'cadence_seconds' => $profile['cadence_seconds'],
            'runtime_seconds' => $profile['runtime_seconds'],
            'safe_close_seconds' => $profile['safe_close_seconds'],
            'max_remote_jobs' => $profile['max_remote_jobs'],
            'measurement_known_responses' => $knownSample,
            'measurement_resources_persisted' => $persistedSample,
            'measurement_backlog_resources' => $backlogResources,
            'measurement_window_minutes' => (int) ($calculation['measurement_window_minutes'] ?? 0),
        ];
        $id = $this->record(
            $engineGeneration,
            'capacity',
            $passed,
            $contextHash,
            $metrics,
            $ttlSeconds,
        );
        return ['ok' => $passed, 'id' => $id, 'calculation' => $calculation];
    }

    /**
     * Capacity authority derived exclusively from the bounded Queue Core run
     * ledger and attempts. No operator-provided rate or latency can become a
     * release receipt.
     *
     * @param array{cadence_seconds:int,runtime_seconds:int,safe_close_seconds:int,max_remote_jobs:int,safe_http_per_minute:float} $profile
     * @return array<string,float|int>
     */
    public function measuredCapacity(int $windowMinutes, array $profile): array
    {
        $window=max(15,min(1440,$windowMinutes));
        $jobs=$this->pdo->prepare(
            "SELECT COUNT(DISTINCT CONCAT(company_id,':',meli_account_id,':',COALESCE(resource_id,'')))
             FROM queue_core_jobs
             WHERE queue_domain='operational'
               AND work_type IN ('order_exact','webhook_order_exact')
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)"
        );
        $jobs->execute([$window]);$arrivals=max(0,(int)$jobs->fetchColumn());
        $attempts=$this->pdo->prepare(
            "SELECT COALESCE(SUM(a.physical_http_calls),0) physical_http,
                    SUM(a.response_known_at IS NOT NULL) known_responses,
                    COALESCE(SUM(CASE WHEN j.work_type IN ('order_exact','webhook_order_exact')
                                      THEN a.resources_persisted ELSE 0 END),0) resources_persisted
             FROM queue_core_attempts a
             JOIN queue_core_jobs j ON j.id=a.job_id AND j.queue_domain='operational'
             WHERE a.started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)
               AND a.launcher IN ('cron_v4','canary_v4')
               AND a.physical_http_calls=1"
        );
        $attempts->execute([$window]);$sample=$attempts->fetch(PDO::FETCH_ASSOC)?:[];
        $durations=$this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(MICROSECOND,a.started_at,a.response_known_at)/1000000 duration_seconds
             FROM queue_core_attempts a
             JOIN queue_core_jobs j ON j.id=a.job_id AND j.queue_domain='operational'
             WHERE a.started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)
               AND a.launcher IN ('cron_v4','canary_v4')
               AND a.physical_http_calls=1 AND a.response_known_at IS NOT NULL
             ORDER BY duration_seconds LIMIT 2000"
        );
        $durations->execute([$window]);
        $values=array_map('floatval',$durations->fetchAll(PDO::FETCH_COLUMN));sort($values,SORT_NUMERIC);
        $known=max(0,(int)($sample['known_responses']??0));
        $persisted=max(0,(int)($sample['resources_persisted']??0));
        $physical=max(0,(int)($sample['physical_http']??0));
        $p50=$this->percentile($values,0.50);$p95=$this->percentile($values,0.95);
        // Primer go-live: una venta puede requerir order + pack + shipment.
        // El numerador incluye todo HTTP operacional (incluido discovery/OAuth)
        // y el denominador solo ventas exactas persistidas; nunca mezclar
        // resultados locales o hijos logísticos como si fueran ventas nuevas.
        $httpPerResource=$persisted>0?max(3.0,$physical/$persisted):PHP_FLOAT_MAX;
        $calculation=(new QueueCoreCapacityService())->calculate(
            $arrivals/$window,$httpPerResource,$p50,$p95,
            max(0.0,(float)$profile['safe_http_per_minute']),
            $profile['cadence_seconds'],$profile['runtime_seconds'],
            $profile['safe_close_seconds'],$profile['max_remote_jobs'],
        );
        $observedResourcesPerMinute=$persisted/$window;
        $calculation['sustainable_resources_per_minute']=round(min(
            (float)$calculation['sustainable_resources_per_minute'],
            $observedResourcesPerMinute
        ),4);
        $calculation['sustainable_http_per_minute']=round(min(
            (float)$calculation['sustainable_http_per_minute'],
            $known/$window
        ),4);
        $arrival=(float)$calculation['arrival_rate_resources_per_minute'];
        $sustainable=(float)$calculation['sustainable_resources_per_minute'];
        $calculation['safety_margin_ratio']=round($arrival>0
            ?($sustainable-$arrival)/$arrival
            :($sustainable>0?1.0:0.0),4);
        $backlogStatement=$this->pdo->query(
            "SELECT
               (SELECT COUNT(*) FROM queue_core_jobs
                WHERE queue_domain='operational'
                  AND state IN ('pending','claimed','running','retry_wait','waiting_oauth'))
               +
               (SELECT COUNT(*) FROM queue_core_pending_capabilities
                WHERE state IN ('pending_b2','waiting_dependency','materialized'))"
        );
        $backlog=max(0,(int)$backlogStatement->fetchColumn());
        $catchup=$backlog===0
            ?0.0
            :(new QueueCoreCapacityService())->catchupMinutes($backlog,$sustainable,$arrival);
        return $calculation+[
            'measurement_window_minutes'=>$window,
            'measurement_jobs_arrived'=>$arrivals,
            'measurement_physical_http'=>$physical,
            'measurement_known_responses'=>$known,
            'measurement_resources_persisted'=>$persisted,
            'measurement_backlog_resources'=>$backlog,
            'catchup_minutes'=>$catchup,
        ];
    }

    /** @return array{ok:bool,id:int,manifest:array<string,mixed>} */
    public function certifyManifest(
        int $engineGeneration,
        string $contextHash,
        int $ttlSeconds = 3600,
    ): array {
        $manifest = (new QueueCoreDeploymentGateService($this->pdo))->runtimeManifestCheck();
        $passed = $manifest['ok'];
        $metrics = [
            'invalid_components' => max(0, $manifest['invalid_components']),
            'minimum_migration' => (string) ($manifest['minimum_migration'] ?? ''),
            'version' => (string) ($manifest['version'] ?? ''),
        ];
        $id = $this->record(
            $engineGeneration,
            'manifest',
            $passed,
            $contextHash,
            $metrics,
            $ttlSeconds,
        );
        return ['ok' => $passed, 'id' => $id, 'manifest' => $manifest];
    }

    /** @return array{format_valid:bool,table_count:int,data_statements:int,tables:array<string,true>,data_tables:array<string,true>,row_counts:array<string,int>} */
    private function inspectSqlBackup(string $path): array
    {
        $gzip=str_ends_with(strtolower($path),'.gz');
        $handle=$gzip?@gzopen($path,'rb'):@fopen($path,'rb');
        if($handle===false)return [
            'format_valid'=>false,'table_count'=>0,'data_statements'=>0,
            'tables'=>[],'data_tables'=>[],'row_counts'=>[],
        ];
        $tables=[];$dataTables=[];$rowCounts=[];$data=0;$activeDataTable=null;
        $required=array_fill_keys([
            'companies','users','app_settings','schema_migrations','meli_accounts',
            'meli_tokens','meli_orders','meli_order_items','meli_payments','meli_shipments',
        ],false);
        try{
            while(($line=$gzip?gzgets($handle):fgets($handle))!==false){
                if(preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?[`"]?([a-zA-Z0-9_]+)/i',$line,$match)===1){
                    $name=strtolower($match[1]);$tables[$name]=true;if(array_key_exists($name,$required))$required[$name]=true;
                }
                if(preg_match('/^(?:INSERT INTO|REPLACE INTO)\s+[`"]?([a-zA-Z0-9_]+)/i',$line,$match)===1){
                    $data++;$activeDataTable=strtolower($match[1]);$dataTables[$activeDataTable]=true;
                    $rowCounts[$activeDataTable]=$rowCounts[$activeDataTable]??0;
                    if(preg_match('/\bVALUES\s*\(/i',$line)===1)$rowCounts[$activeDataTable]++;
                    if(str_ends_with(rtrim($line),';'))$activeDataTable=null;
                }elseif(preg_match('/^COPY\s+[`"]?([a-zA-Z0-9_]+)/i',$line,$match)===1){
                    $data++;$dataTables[strtolower($match[1])]=true;
                }elseif($activeDataTable!==null&&str_starts_with(ltrim($line),'(')){
                    $rowCounts[$activeDataTable]++;
                    if(str_ends_with(rtrim($line),';'))$activeDataTable=null;
                }
            }
        }finally{$gzip?gzclose($handle):fclose($handle);}
        return [
            'format_valid'=>$tables!==[]&&$data>0&&!in_array(false,$required,true),
            'table_count'=>count($tables),'data_statements'=>$data,
            'tables'=>$tables,'data_tables'=>$dataTables,'row_counts'=>$rowCounts,
        ];
    }

    /**
     * Bind the approved artifact to the database that is about to be upgraded.
     * Queue Core tables are intentionally absent from a pre-B2 backup; every
     * pre-existing runtime table must be represented in the dump. Critical
     * commercial tables that currently contain rows must also contain a data
     * statement, so a schema-only or synthetic miniature cannot certify.
     *
     * @param array<string,true> $dumpTables
     * @param array<string,true> $dumpDataTables
     * @param array<string,int> $dumpRowCounts
     * @return array{current_table_count:int,missing_table_count:int,critical_data_missing_count:int,critical_row_count_mismatch_count:int}
     */
    private function compareBackupWithCurrentDatabase(
        array $dumpTables,
        array $dumpDataTables,
        array $dumpRowCounts,
        bool $compareLiveRows,
    ): array
    {
        $statement=$this->pdo->query(
            "SELECT LOWER(table_name)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_type='BASE TABLE'
               AND table_name NOT LIKE 'queue\\_core\\_%'
               AND table_name<>'queue_engine_control'"
        );
        $current=array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN));
        $missing=0;
        foreach($current as $table){
            if(!isset($dumpTables[strtolower($table)]))$missing++;
        }

        $criticalMissing=0;$rowCountMismatch=0;
        $dataCritical=['companies','users','app_settings','schema_migrations','meli_accounts','meli_tokens',
            'meli_orders','meli_order_items','meli_payments','meli_shipments'];
        $stableCounts=['companies','users','meli_accounts','meli_tokens','meli_orders','meli_order_items','meli_payments','meli_shipments'];
        foreach($dataCritical as $table){
            if(!in_array($table,$current,true))continue;
            $count=(int)$this->pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            if($compareLiveRows && $count>0 && !isset($dumpDataTables[$table])) {
                $criticalMissing++;
            }
            if($compareLiveRows
                && in_array($table,$stableCounts,true)
                && $count!==max(0,(int)($dumpRowCounts[$table]??0))) {
                $rowCountMismatch++;
            }
        }
        return [
            'current_table_count'=>count($current),
            'missing_table_count'=>$missing,
            'critical_data_missing_count'=>$criticalMissing,
            'critical_row_count_mismatch_count'=>$rowCountMismatch,
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values,float $quantile): float
    {
        if($values===[])return 0.0;
        $index=(int)ceil(max(0.0,min(1.0,$quantile))*count($values))-1;
        return max(0.001,(float)$values[max(0,min(count($values)-1,$index))]);
    }
}
