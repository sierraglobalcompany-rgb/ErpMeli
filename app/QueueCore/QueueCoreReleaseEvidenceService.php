<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Services\QueueCoreDeploymentGateService;
use PDO;

/** Evidencia no secreta y ligada a generación/contexto para el CAS de activación. */
final class QueueCoreReleaseEvidenceService
{
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

    /** @return array{ok:bool,sha256:?string,bytes:int,format_valid:bool,table_count:int,data_statements:int} */
    public function verifyBackup(string $path, string $expectedSha256): array
    {
        $expected = strtolower(trim($expectedSha256));
        $exists = $path !== '' && is_file($path) && is_readable($path);
        $sha = $exists ? hash_file('sha256', $path) : false;
        $bytes = $exists ? max(0, (int) filesize($path)) : 0;
        $content = $exists ? $this->inspectSqlBackup($path) : [
            'format_valid' => false, 'table_count' => 0, 'data_statements' => 0,
        ];
        $ok = is_string($sha)
            && $bytes > 0
            && preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, strtolower($sha))
            && $content['format_valid'];
        return [
            'ok' => $ok,
            'sha256' => is_string($sha) ? strtoupper($sha) : null,
            'bytes' => $bytes,
        ] + $content;
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
        ?float $catchupMinutes,
        int $ttlSeconds = 3600,
    ): array {
        $arrival = (float) ($calculation['arrival_rate_resources_per_minute'] ?? 0.0);
        $sustainable = (float) ($calculation['sustainable_resources_per_minute'] ?? 0.0);
        $margin = (float) ($calculation['safety_margin_ratio'] ?? 0.0);
        $safeJobs = (int) ($calculation['safe_jobs_per_run'] ?? 0);
        $knownSample = (int) ($calculation['measurement_known_responses'] ?? 0);
        $persistedSample = (int) ($calculation['measurement_resources_persisted'] ?? 0);
        $profileValid = $profile['cadence_seconds'] > 0
            && $profile['runtime_seconds'] > $profile['safe_close_seconds']
            && $profile['max_remote_jobs'] > 0;
        $passed = $arrival >= 0.0
            && $sustainable > $arrival
            && $margin > 0.0
            && $safeJobs > 0
            && $knownSample >= 3
            && $persistedSample > 0
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
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE queue_domain='operational'
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)"
        );
        $jobs->execute([$window]);$arrivals=max(0,(int)$jobs->fetchColumn());
        $attempts=$this->pdo->prepare(
            "SELECT COALESCE(SUM(physical_http_calls),0) physical_http,
                    SUM(response_known_at IS NOT NULL) known_responses,
                    COALESCE(SUM(resources_persisted),0) resources_persisted
             FROM queue_core_attempts
             WHERE started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)"
        );
        $attempts->execute([$window]);$sample=$attempts->fetch(PDO::FETCH_ASSOC)?:[];
        $durations=$this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(MICROSECOND,started_at,response_known_at)/1000000 duration_seconds
             FROM queue_core_attempts
             WHERE started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? MINUTE)
               AND physical_http_calls=1 AND response_known_at IS NOT NULL
             ORDER BY duration_seconds LIMIT 2000"
        );
        $durations->execute([$window]);
        $values=array_map('floatval',$durations->fetchAll(PDO::FETCH_COLUMN));sort($values,SORT_NUMERIC);
        $known=max(0,(int)($sample['known_responses']??0));
        $persisted=max(0,(int)($sample['resources_persisted']??0));
        $physical=max(0,(int)($sample['physical_http']??0));
        $p50=$this->percentile($values,0.50);$p95=$this->percentile($values,0.95);
        $httpPerResource=$persisted>0?max(0.01,$physical/$persisted):PHP_FLOAT_MAX;
        $calculation=(new QueueCoreCapacityService())->calculate(
            $arrivals/$window,$httpPerResource,$p50,$p95,
            max(0.0,(float)$profile['safe_http_per_minute']),
            $profile['cadence_seconds'],$profile['runtime_seconds'],
            $profile['safe_close_seconds'],$profile['max_remote_jobs'],
        );
        return $calculation+[
            'measurement_window_minutes'=>$window,
            'measurement_jobs_arrived'=>$arrivals,
            'measurement_physical_http'=>$physical,
            'measurement_known_responses'=>$known,
            'measurement_resources_persisted'=>$persisted,
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

    /** @return array{format_valid:bool,table_count:int,data_statements:int} */
    private function inspectSqlBackup(string $path): array
    {
        $gzip=str_ends_with(strtolower($path),'.gz');
        $handle=$gzip?@gzopen($path,'rb'):@fopen($path,'rb');
        if($handle===false)return ['format_valid'=>false,'table_count'=>0,'data_statements'=>0];
        $tables=[];$data=0;$required=['meli_accounts'=>false,'meli_orders'=>false,'schema_migrations'=>false];
        try{
            while(($line=$gzip?gzgets($handle):fgets($handle))!==false){
                if(preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?[`"]?([a-zA-Z0-9_]+)/i',$line,$match)===1){
                    $name=strtolower($match[1]);$tables[$name]=true;if(array_key_exists($name,$required))$required[$name]=true;
                }
                if(preg_match('/^(?:INSERT INTO|REPLACE INTO|COPY )/i',$line)===1)$data++;
            }
        }finally{$gzip?gzclose($handle):fclose($handle);}
        return [
            'format_valid'=>$tables!==[]&&$data>0&&!in_array(false,$required,true),
            'table_count'=>count($tables),'data_statements'=>$data,
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
