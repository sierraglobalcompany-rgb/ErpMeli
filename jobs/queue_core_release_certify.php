<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\QueueCoreCapacityService;
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
    if ($engine['active_engine'] !== 'disabled' || $engine['readiness_mode'] !== 'preparing') {
        throw new RuntimeException('Queue Core release certification requires disabled readiness mode.');
    }
    $generation = max(0, (int) $engine['generation']);
    $context = (new QueueCoreReadinessReceiptService($pdo))->currentContextHash($generation);
    if (!hash_equals((string) $engine['readiness_context_hash'], $context)) {
        throw new RuntimeException('Queue Core readiness context changed before certification.');
    }

    $type = strtolower((string) $option('type', ''));
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
        $arrival = max(0.0, (float) $option('arrival-resources-per-minute', '0'));
        $httpPerResource = max(0.01, (float) $option('http-per-resource', '3'));
        $p50 = max(0.0, (float) $option('p50-seconds', '1'));
        $p95 = max(0.01, (float) $option('p95-seconds', '5'));
        $safeHttp = max(0.0, (float) $option('safe-http-per-minute', '3'));
        $cadence = max(1, (int) $option('cadence-seconds', '60'));
        $runtime = max(1, (int) $option('runtime-seconds', '45'));
        $safeClose = max(0, (int) $option('safe-close-seconds', '10'));
        $maxRemote = max(1, (int) $option('max-remote-jobs', '3'));
        $backlog = max(0, (int) $option('backlog-resources', '0'));
        $capacity = new QueueCoreCapacityService();
        $calculation = $capacity->calculate(
            $arrival,
            $httpPerResource,
            $p50,
            $p95,
            $safeHttp,
            $cadence,
            $runtime,
            $safeClose,
            $maxRemote,
        );
        $catchup = $capacity->catchupMinutes(
            $backlog,
            (float) $calculation['sustainable_resources_per_minute'],
            $arrival,
        );
        $result = $evidence->certifyCapacity($generation, $context, $calculation, [
            'cadence_seconds' => $cadence,
            'runtime_seconds' => $runtime,
            'safe_close_seconds' => $safeClose,
            'max_remote_jobs' => $maxRemote,
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
