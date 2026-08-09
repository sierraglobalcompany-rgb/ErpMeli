<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\QueueEngineControlService;
use App\QueueCore\QueueCoreReadinessReceiptService;
use App\QueueCore\QueueCoreReleaseEvidenceService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
$option = static function (string $name, ?string $default = null) use ($arguments): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return trim(substr($argument, strlen($prefix)));
        }
    }
    return $default;
};

try {
    Database::useProfile('cli');
    $pdo = Database::connectionFresh();
    $engine = (new QueueEngineControlService($pdo))->snapshot();
    $type = strtolower((string) $option('type', ''));
    $readiness=new QueueCoreReadinessReceiptService($pdo);
    $operationalCapacity=$type==='capacity'&&$engine['active_engine']==='v4'&&$engine['readiness_mode']==='idle';
    if(!$operationalCapacity
        &&($engine['active_engine']!=='disabled'||$engine['readiness_mode']!=='preparing')){
        throw new RuntimeException('Queue Core release certification requires readiness mode.');
    }
    $generation=$operationalCapacity?max(0,(int)$engine['generation']-1):max(0,(int)$engine['generation']);
    $context=$readiness->currentContextHash($generation);
    if(!$operationalCapacity&&!hash_equals((string)$engine['readiness_context_hash'],$context)){
        throw new RuntimeException('Queue Core readiness context changed before certification.');
    }
    $ttl = max(60, min(86400, (int) $option('ttl', '3600')));
    $evidence = new QueueCoreReleaseEvidenceService($pdo);
    if ($type === 'backup') {
        $result = $evidence->certifyBackup(
            $generation,
            $context,
            (string) $option('file', ''),
            (string) $option('sha256', ''),
            $ttl,
        );
        $safe = [
            'ok' => $result['ok'],
            'type' => 'backup',
            'id' => $result['id'],
            'bytes' => $result['bytes'],
            'sha256' => $result['sha256'],
        ];
    } elseif ($type === 'manifest') {
        $result = $evidence->certifyManifest($generation, $context, $ttl);
        $safe = [
            'ok' => $result['ok'],
            'type' => 'manifest',
            'id' => $result['id'],
            'version' => (string) ($result['manifest']['version'] ?? ''),
            'minimum_migration' => (string) ($result['manifest']['minimum_migration'] ?? ''),
        ];
    } elseif ($type === 'capacity') {
        $profile=$readiness->runtimeProfile();
        $window=max(15,min(1440,(int)$option('window-minutes','60')));
        $backlog = max(0, (int) $option('backlog-resources', '0'));
        $calculation=$evidence->measuredCapacity($window,$profile);
        $catchup = (new \App\QueueCore\QueueCoreCapacityService())->catchupMinutes(
            $backlog,
            (float) $calculation['sustainable_resources_per_minute'],
            (float) $calculation['arrival_rate_resources_per_minute'],
        );
        $result = $evidence->certifyCapacity($generation, $context, $calculation, [
            'cadence_seconds' => $profile['cadence_seconds'],
            'runtime_seconds' => $profile['runtime_seconds'],
            'safe_close_seconds' => $profile['safe_close_seconds'],
            'max_remote_jobs' => $profile['max_remote_jobs'],
        ], $catchup, $ttl);
        $safe = [
            'ok' => $result['ok'],
            'type' => 'capacity',
            'id' => $result['id'],
            'calculation' => $result['calculation'],
            'catchup_minutes' => $catchup,
        ];
    } else {
        throw new InvalidArgumentException('Use --type=backup, --type=manifest or --type=capacity.');
    }
    fwrite(STDOUT, json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($safe['ok'] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'error_class' => strtolower((new ReflectionClass($error))->getShortName()),
        'reason' => 'release_certification_failed',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
