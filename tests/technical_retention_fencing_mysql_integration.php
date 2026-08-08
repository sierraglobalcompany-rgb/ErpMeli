<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_retention_fence_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    define('ERP_RELEASE_ROOT', $root);
    define('ERP_SHARED_ROOT', sys_get_temp_dir() . '/erp-retention-fence-' . bin2hex(random_bytes(5)));
    putenv('APP_KEY=retention-fence-test-key-with-enough-entropy');
    putenv('ML_WRITE_ENABLED=false');
    require $root . '/vendor/autoload.php';
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();

    $availability = (new App\Services\CronWorkAvailabilityService())
        ->snapshot(['operational_maintenance']);
    if (
        empty($availability['operational_maintenance']['known'])
        || (int) $availability['operational_maintenance']['work_count'] !== 1
    ) {
        throw new RuntimeException('El mantenimiento periódico no quedó elegible.');
    }

    $first = (new App\Services\TechnicalRetentionCliService())->runStep(10);
    if (!empty($first['skipped']) || (int) ($first['errors'] ?? 0) !== 0) {
        throw new RuntimeException('El primer micro-lote cercado no terminó correctamente.');
    }
    $state = $pdo->query(
        'SELECT generation,lease_owner,lease_expires_at FROM system_retention_cli_state
         WHERE lane_key="technical"'
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($state) || $state['lease_owner'] !== null || $state['lease_expires_at'] !== null) {
        throw new RuntimeException('El micro-lote no liberó su lease cercado.');
    }

    $pdo->exec(
        'UPDATE system_retention_cli_state
         SET generation=41,lease_owner="foreign-owner",
             lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE)
         WHERE lane_key="technical"'
    );
    $busy = (new App\Services\TechnicalRetentionCliService())->runStep(10);
    if (($busy['reason'] ?? '') !== 'lease_busy') {
        throw new RuntimeException('Un segundo propietario pudo atravesar el lease vigente.');
    }
    $foreign = $pdo->query(
        'SELECT CONCAT(generation,":",lease_owner) FROM system_retention_cli_state
         WHERE lane_key="technical"'
    )->fetchColumn();
    if ($foreign !== '41:foreign-owner') {
        throw new RuntimeException('El intento perdedor modificó propietario o generación.');
    }

    $lost = false;
    try {
        (new App\Services\RetentionPolicyService())->runDatasetStep(
            'api_remote_permits',
            10,
            static function () use (&$lost): void {
                $lost = true;
                throw new App\Services\TechnicalRetentionLeaseLostException();
            }
        );
    } catch (App\Services\TechnicalRetentionLeaseLostException) {
        // Esperado: el pipeline no debe absorber ni continuar tras perder fencing.
    }
    if (!$lost) {
        throw new RuntimeException('El pipeline no comprobó el lease antes de mutar.');
    }

    echo "technical_retention_fencing_mysql_integration: OK\n";
} finally {
    App\Core\Database::setConnection($admin);
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
