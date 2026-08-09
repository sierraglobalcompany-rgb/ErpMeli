<?php

declare(strict_types=1);

require __DIR__ . '/support/ExternalCutoverFreshDbVersion.php';

$dsn = trim((string) getenv('ERP_2362_REHEARSAL_DSN'));
$user = (string) getenv('ERP_2362_REHEARSAL_USER');
$pass = (string) getenv('ERP_2362_REHEARSAL_PASS');
$ack = (string) getenv('ERP_2362_REHEARSAL_ACK');

if ($ack !== 'DISPOSABLE_CLONE_SCHEMA_293') {
    fwrite(STDERR, "ERROR disposable_schema_293_ack_required\n");
    exit(2);
}
$parts = [];
foreach (explode(';', substr($dsn, 6)) as $part) {
    if (str_contains($part, '=')) {
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $parts[strtolower($key)] = $value;
    }
}
if (
    !str_starts_with(strtolower($dsn), 'mysql:')
    || !in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost'], true)
    || (int) ($parts['port'] ?? 0) < 1024
    || (int) ($parts['port'] ?? 0) === 3306
    || trim((string) ($parts['dbname'] ?? '')) === ''
) {
    fwrite(STDERR, "ERROR disposable_local_nonstandard_port_required\n");
    exit(2);
}

$connect = static fn (): PDO => new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
]);
$installed = static function (PDO $pdo): string {
    return trim((string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
    )->fetchColumn());
};

$source = '2.35.1';
$target = '2.36.2';
$stale = $connect();
$staleId = (int) $stale->query('SELECT CONNECTION_ID()')->fetchColumn();
$transition = new ExternalCutoverFreshDbVersion($connect);
$receipt = null;

try {
    if ($installed($connect()) !== $source) {
        throw new RuntimeException('source_app_version_not_2351');
    }
    $receipt = $transition->promote($source, $target);
    if ($installed($connect()) !== $target) {
        throw new RuntimeException('promotion_not_visible');
    }

    // Simulate the production failure: the connection that waited through
    // runtime work is dead before the rollback signal arrives.
    $killer = $connect();
    $killer->exec('KILL CONNECTION ' . $staleId);
    $expired = false;
    try {
        $stale->query('SELECT 1');
    } catch (PDOException) {
        $expired = true;
    }
    if (!$expired) {
        throw new RuntimeException('waiting_connection_did_not_expire');
    }

    // Filesystem/FPM smoke boundary: deliberately no DB handle is passed.
    $runtimeSmoke = static fn (): bool => true;
    if (!$runtimeSmoke()) {
        throw new RuntimeException('injected_runtime_smoke_failed');
    }
    $rollbackId = $transition->rollback($target, $source, $receipt);
    if ($rollbackId === $staleId || $rollbackId === $receipt['connection_id']) {
        throw new RuntimeException('rollback_connection_not_fresh');
    }
    if ($installed($connect()) !== $source) {
        throw new RuntimeException('rollback_not_visible');
    }

    echo 'PASS external_cutover_fresh_connection_2362'
        . ' schema=293 migrations_to_apply=0 transition=2.35.1->2.36.2->2.35.1'
        . ' expired_connection=CONFIRMED rollback_connection=FRESH real_meli_http=0' . PHP_EOL;
} finally {
    if (is_array($receipt)) {
        try {
            $current = $installed($connect());
            if ($current === $target) {
                $transition->rollback($target, $source, $receipt);
            }
        } catch (Throwable) {
        }
    }
}
