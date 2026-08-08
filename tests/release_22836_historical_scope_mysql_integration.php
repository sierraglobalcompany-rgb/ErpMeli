<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN MySQL sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_release_22836_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    $pdo->exec(
        'CREATE TABLE app_settings (
            setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            setting_group VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE system_component_schema_contracts (
            component_key VARCHAR(100) NOT NULL PRIMARY KEY,
            required_migration VARCHAR(190) NOT NULL,
            contract_kind VARCHAR(30) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE app_versions (
            version VARCHAR(40) NOT NULL PRIMARY KEY,
            notes VARCHAR(500) NULL,
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $sql = (string) file_get_contents($root . '/database/migrations/216_historical_intervention_scoped_ack_2_28_36.sql');
    $pdo->exec($sql);
    $pdo->exec($sql);

    $pdo->exec(
        "INSERT INTO system_work_historical_reconciliations
            (queue_key,source_id,company_id,meli_account_id,observed_source_status,
             normalized_error_code,failure_class,diagnostic_id,safe_message,reconciliation_state,reconciled_by)
         VALUES
            ('order_enrichment','28',1,10,'error','legacy_local_failure','local','D-1','Mensaje seguro','ready_for_exact_retry',1),
            ('order_enrichment','28',1,11,'error','remote_result_uncertain','remote_result_uncertain','D-2','Mensaje seguro','remote_result_uncertain',1)"
    );
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM system_work_historical_reconciliations')->fetchColumn();
    if ($rows !== 2) {
        throw new RuntimeException('Dos cuentas no pudieron conservar evidencia independiente para el mismo source_id.');
    }

    $duplicateRejected = false;
    try {
        $pdo->exec(
            "INSERT INTO system_work_historical_reconciliations
                (queue_key,source_id,company_id,meli_account_id,observed_source_status,
                 normalized_error_code,failure_class,diagnostic_id,safe_message,reconciliation_state,reconciled_by)
             VALUES ('order_enrichment','28',1,10,'error','x','local','D-3','Mensaje seguro','ready_for_exact_retry',1)"
        );
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) {
        throw new RuntimeException('La unicidad no cercó empresa, cuenta, cola y recurso exacto.');
    }

    $settings = (int) $pdo->query(
        "SELECT COUNT(*) FROM app_settings
         WHERE (setting_key='cron.group_mutations_enabled' AND setting_value='0')
            OR (setting_key='api.health.scope_acknowledgement_required' AND setting_value='1')"
    )->fetchColumn();
    if ($settings !== 2) {
        throw new RuntimeException('No quedaron activos los contratos de intervención exacta y reconocimiento por scope.');
    }

    App\Core\Database::setConnection($pdo);
    $source = [
        'queue_key' => 'orders_sync',
        'source_id' => '44',
        'company_id' => 1,
        'meli_account_id' => 10,
        'source_status' => 'error',
        'source_generation' => 3,
        'source_updated_at' => '2026-08-02 10:00:00.000',
        'safe_error_message' => 'El proceso terminó antes de iniciar; Mercado Libre no fue consultado.',
    ];
    $reconciled = (new App\Services\HistoricalWorkReconciliationService())->reconcile($pdo, $source, 1);
    if ((int) ($reconciled['reached_remote'] ?? -1) !== 0
        || ($reconciled['historical_reconciliation']['state'] ?? '') !== 'ready_for_exact_retry') {
        throw new RuntimeException('La evidencia local sin transporte no quedó habilitada para reintento exacto.');
    }
    $foreignScope = $source;
    $foreignScope['meli_account_id'] = 11;
    $foreign = (new App\Services\HistoricalWorkReconciliationService())->applyLatest($foreignScope, $pdo);
    if (isset($foreign['historical_reconciliation'])) {
        throw new RuntimeException('La evidencia histórica se filtró hacia otra cuenta.');
    }
    echo "PASS release_22836_historical_scope_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
