<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$presenter = new App\Services\ApiLogRiskPresenter();
$local = $presenter->present([
    'http_status' => 429,
    'outcome_class' => 'policy_delay',
    'error_type' => 'api_rhythm_deferred',
]);
$assert($local['reached_remote'] === false, 'MISSING_TRANSPORT_MARKER_MUST_NOT_IMPLY_REMOTE');
$assert($local['title'] === 'Pausa preventiva local', 'LOCAL_429_COMPATIBILITY_VALUE_MISLABELLED');
$assert($local['risk'] === 'Bajo', 'LOCAL_429_COMPATIBILITY_VALUE_HIGH_RISK');

$remote = $presenter->present([
    'http_status' => 429,
    'reached_remote' => 1,
    'outcome_class' => 'remote_error',
]);
$assert($remote['reached_remote'] === true, 'REMOTE_429_TRANSPORT_LOST');
$assert($remote['title'] === 'Pausa solicitada por Mercado Libre', 'REMOTE_429_LABEL_LOST');

$logs = (string) file_get_contents($root . '/app/Services/LogQueryService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$incidents = (string) file_get_contents($root . '/app/Services/ApiIncidentReadModelService.php');
$assert(str_contains($logs, "if ((int) \$filters['http_status'] === 429)"), 'LOG_429_FILTER_NOT_REMOTE_ONLY');
$assert(str_contains($logs, "\$where[] = 'l.reached_remote=1';"), 'LOG_429_REMOTE_PREDICATE_MISSING');
$assert(str_contains($health, "if (\$httpStatusFilter === 429)"), 'HEALTH_429_FILTER_NOT_REMOTE_ONLY');
$assert(str_contains($incidents, "if (\$httpStatus === 429)"), 'INCIDENT_429_FILTER_NOT_REMOTE_ONLY');

fwrite(STDOUT, 'API_LOG_TRANSPORT_TRUTH_23914=PASS checks=' . $checks . ' real_meli_http=0' . PHP_EOL);
