<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$health = new App\Services\ApiHealthService();
$present = new ReflectionMethod($health, 'presentIncident');
$now = gmdate('Y-m-d H:i:s');
$local = $present->invoke($health, [
    'outcome_class' => 'policy_delay',
    'last_seen_at' => $now,
    'http_status' => null,
    'reached_remote' => 0,
    'safe_message' => 'Aplazado por política local',
]);
$assert(($local['transport_class'] ?? '') === 'LOCAL_RATE_LIMITED_PRETRANSPORT', 'LOCAL_CLASS_MISSING');
$assert(($local['transport_label'] ?? '') === 'Pausa preventiva local · sin HTTP remoto', 'LOCAL_LABEL_MISSING');
$assert(empty($local['rate_limit_signal']), 'LOCAL_DELAY_COUNTED_AS_REMOTE_429');

$remote = $present->invoke($health, [
    'outcome_class' => 'remote_error',
    'last_seen_at' => $now,
    'http_status' => 429,
    'reached_remote' => 1,
    'safe_message' => 'Rate limit remoto',
]);
$assert(($remote['transport_class'] ?? '') === 'REMOTE_HTTP_429', 'REMOTE_CLASS_MISSING');
$assert(($remote['transport_label'] ?? '') === 'Mercado Libre respondió HTTP 429', 'REMOTE_LABEL_MISSING');
$assert(!empty($remote['rate_limit_signal']), 'REMOTE_429_NOT_COUNTED');

$source = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$assert(!str_contains($source, 'MAX(l.reached_remote) reached_remote'), 'RAW_FALLBACK_MIXES_REACHED_REMOTE');
$assert(substr_count($source, 'GROUP_CONCAT(COALESCE(l.reached_remote,0) ORDER BY l.created_at DESC,l.id DESC') === 2, 'RAW_FALLBACK_LAST_EVENT_MISSING');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$assert(str_contains($controller, "'transport_class'"), 'CSV_TRANSPORT_CLASS_MISSING');

fwrite(STDOUT, 'API_HEALTH_TRANSPORT_TRUTH_23913=PASS checks=' . $checks . "\n");
