<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn === '') {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio para 2.28.12.\n");
    exit(2);
}

$server = new PDO($dsn, (string) getenv('ERP_MIGRATOR_TEST_USER'), (string) getenv('ERP_MIGRATOR_TEST_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_cron_22812_' . bin2hex(random_bytes(5));
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-cron-22812-' . bin2hex(random_bytes(5));
$migrationRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'migrations';
mkdir($migrationRoot, 0770, true);

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, (string) getenv('ERP_MIGRATOR_TEST_USER'), (string) getenv('ERP_MIGRATOR_TEST_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT "connected"
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE order_resource_enrichment_jobs (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,last_error_message VARCHAR(500) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_cron_run_steps (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,deferred_count INT UNSIGNED NOT NULL DEFAULT 0,
        remote_call_count INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_work_queue_runs (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,inspected_count INT UNSIGNED NOT NULL DEFAULT 0,
        remote_call_count INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_component_schema_contracts (
        component_key VARCHAR(100) PRIMARY KEY,required_migration VARCHAR(190) NOT NULL,
        contract_kind VARCHAR(40) NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_versions (version VARCHAR(30) PRIMARY KEY,notes VARCHAR(500) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sync_recurring_rules (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,meli_account_id BIGINT UNSIGNED NOT NULL,
        rule_type VARCHAR(30) NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 0,next_due_at DATETIME NULL,
        allow_outside_hours TINYINT(1) NOT NULL DEFAULT 0,active_weekdays VARCHAR(30) NOT NULL DEFAULT "1,2,3,4,5,6,7",
        start_time TIME NOT NULL DEFAULT "00:00:00",end_time TIME NOT NULL DEFAULT "23:59:59",
        frequency_minutes INT NOT NULL DEFAULT 60,overlap_hours INT NOT NULL DEFAULT 2
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE cron_task_state (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,task_key VARCHAR(100) NOT NULL UNIQUE,
        status VARCHAR(30) NOT NULL DEFAULT "ready",next_run_at DATETIME NULL,last_started_at DATETIME NULL,
        last_run_token VARCHAR(64) NULL,attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error_message VARCHAR(500) NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    $migration = dirname(__DIR__) . '/database/migrations/192_cron_runtime_scope_observability_2_28_12.sql';
    copy($migration, $migrationRoot . '/192_cron_runtime_scope_observability_2_28_12.sql');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $migrationRoot);
    $first = $migrator->run();
    $second = $migrator->run();
    if (
        count($first) !== 1
        || ($first[0]['status'] ?? '') !== 'applied'
        || count($second) !== 1
        || ($second[0]['status'] ?? '') !== 'skip'
    ) {
        throw new RuntimeException('La migración 192 no fue idempotente.');
    }
    $columns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND (
           (TABLE_NAME='order_resource_enrichment_jobs' AND COLUMN_NAME IN ('last_error_diagnostic_id','last_error_code','failure_class','reached_remote'))
           OR (TABLE_NAME='system_cron_run_steps' AND COLUMN_NAME IN ('attempted_remote_call_count','blocked_remote_call_count'))
           OR (TABLE_NAME='system_work_queue_runs' AND COLUMN_NAME IN ('attempted_remote_call_count','blocked_remote_call_count'))
         )"
    )->fetchColumn();
    if ($columns !== 8) {
        throw new RuntimeException('La migración 192 no creó toda la telemetría requerida.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='question_sync_account_state'")->fetchColumn() !== 1) {
        throw new RuntimeException('Falta question_sync_account_state.');
    }

    $pdo->exec("INSERT INTO meli_accounts(id,status) VALUES(1,'connected')");
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES
        ('questions.sync_enabled','1',0,'questions'),
        ('questions.endpoint_confirmed','1',0,'questions'),
        ('sync.daily_enabled','1',0,'sync')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $pdo->exec("INSERT INTO sync_recurring_rules
        (meli_account_id,rule_type,enabled,next_due_at,allow_outside_hours)
        VALUES(1,'orders',1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),1)");
    $availability = (new App\Services\CronWorkAvailabilityService())->snapshot(['questions','recurring_sync']);
    if (($availability['questions']['known'] ?? false) !== true || (int) ($availability['questions']['work_count'] ?? 0) !== 1) {
        throw new RuntimeException('La disponibilidad de preguntas no encontró la cuenta vencida exacta.');
    }
    if (($availability['recurring_sync']['known'] ?? false) !== true || (int) ($availability['recurring_sync']['work_count'] ?? 0) !== 1) {
        throw new RuntimeException('La disponibilidad recurrente no encontró la regla vencida exacta.');
    }

    $pdo->exec("INSERT INTO cron_task_state(task_key,status,next_run_at,last_started_at)
        VALUES
        ('manual_campaign','ready',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),NULL),
        ('running_recent','running',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
        ('running_stale','running',UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
    $taskState = new App\Services\CronTaskStateService();
    if ($taskState->started('manual_campaign', 'RUN-NORMAL') !== false) {
        throw new RuntimeException('Un claim normal ignoró next_run_at futuro.');
    }
    if ($taskState->started('manual_campaign', 'RUN-DIRECTED', true) !== true) {
        throw new RuntimeException('El claim dirigido seleccionado no pudo adquirir la tarea futura.');
    }
    if ($taskState->recoverAbandoned(60) !== 1) {
        throw new RuntimeException('La recuperación stale modificó una cantidad incorrecta de tareas running.');
    }
    $states = $pdo->query("SELECT task_key,status FROM cron_task_state WHERE task_key LIKE 'running_%' ORDER BY task_key")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($states['running_recent'] ?? '') !== 'running' || ($states['running_stale'] ?? '') !== 'ready') {
        throw new RuntimeException('Running reciente/stale no quedó cercado correctamente.');
    }

    App\Services\ApiExecutionMetadataContext::resetRemoteDispatchCount();
    $coordinator = new App\Services\CronWorkCoordinator(null, 10, 'RUN-BLOCKED');
    $blocked = $coordinator->run('blocked_probe', 1, static function (): array {
        App\Services\ApiExecutionMetadataContext::markRemoteAttempted();
        App\Services\ApiExecutionMetadataContext::markRemoteBlocked();
        return ['status' => 'waiting_guard', 'processed' => 0, 'errors' => 0, 'deferred' => 1];
    });
    if ((int) ($blocked['attempted_remote_calls'] ?? 0) !== 1
        || (int) ($blocked['blocked_remote_calls'] ?? 0) !== 1
        || (int) ($blocked['remote_calls'] ?? 0) !== 0) {
        throw new RuntimeException('La telemetría confundió intento bloqueado con transporte remoto.');
    }
    fwrite(STDOUT, "cron_runtime_scope_22812_mysql_ok\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_file($migrationRoot . '/192_cron_runtime_scope_observability_2_28_12.sql')) {
        unlink($migrationRoot . '/192_cron_runtime_scope_observability_2_28_12.sql');
    }
    @rmdir($migrationRoot);
    @rmdir($temporaryRoot);
}
