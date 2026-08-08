<?php

declare(strict_types=1);

/**
 * Certifica la migración 100 sobre una base temporal MySQL/MariaDB real.
 * El DSN no puede seleccionar una base existente.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar 100.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_manual_2196_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-manual-2196-' . bin2hex(random_bytes(5));
$migrations = $temporary . DIRECTORY_SEPARATOR . 'migrations';
@mkdir($migrations, 0770, true);
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
copy(
    $root . '/database/migrations/100_manual_processing_lifecycle_security_2_19_6.sql',
    $migrations . '/100_manual_processing_lifecycle_security_2_19_6.sql'
);

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    define('ERP_SHARED_ROOT', $temporary);
    require $root . '/bootstrap.php';
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    \App\Core\Database::setConnection($pdo);
    $pdo->exec('CREATE TABLE app_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(40) NOT NULL UNIQUE,
        notes VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_token CHAR(40) NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        mode ENUM("conservative","balanced","automatic","advanced") NOT NULL DEFAULT "automatic",
        scope_key VARCHAR(50) NOT NULL,
        status ENUM("draft","active","paused","finishing","completed","completed_with_issues","abandoned","failed") NOT NULL DEFAULT "draft",
        heartbeat_at DATETIME NULL,worker_heartbeat_at DATETIME NULL,
        grace_until DATETIME NULL,started_at DATETIME NULL,paused_at DATETIME NULL,finished_at DATETIME NULL,
        total_jobs INT UNSIGNED NOT NULL DEFAULT 0,completed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
        failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,remote_calls INT UNSIGNED NOT NULL DEFAULT 0,
        transferred_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,safe_message VARCHAR(500) NULL,
        diagnostic_id VARCHAR(80) NULL,last_worker_result VARCHAR(40) NULL,last_worker_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_manual_session_token(session_token)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        manual_processing_session_id BIGINT UNSIGNED NOT NULL,
        queue_key VARCHAR(80) NOT NULL,
        source_id VARCHAR(100) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        human_label VARCHAR(160) NOT NULL DEFAULT "Trabajo",
        content_summary VARCHAR(500) NULL,
        operation_key VARCHAR(80) NULL,
        load_class VARCHAR(24) NULL,
        status ENUM("pending","running","waiting","completed","partial","retry","failed","returned") NOT NULL DEFAULT "pending",
        lease_owner VARCHAR(100) NULL,
        lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME NULL,
        next_eligible_at DATETIME NULL,
        progress_current INT UNSIGNED NOT NULL DEFAULT 0,
        progress_total INT UNSIGNED NOT NULL DEFAULT 0,
        result_summary VARCHAR(500) NULL,
        diagnostic_id VARCHAR(80) NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_manual_item_claim (manual_processing_session_id,status,created_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_scopes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        manual_processing_session_id BIGINT UNSIGNED NOT NULL,
        queue_key VARCHAR(80) NOT NULL,
        account_scope_key VARCHAR(80) NOT NULL DEFAULT "*",
        meli_account_id BIGINT UNSIGNED NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        manual_processing_session_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        safe_message VARCHAR(500) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    $first = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (($first[0]['status'] ?? '') !== 'applied') {
        throw new RuntimeException('La migración 100 no se aplicó.');
    }
    $indexColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manual_processing_items'
           AND INDEX_NAME='idx_manual_item_eligible'"
    )->fetchColumn();
    if ($indexColumns !== 4) {
        throw new RuntimeException('El índice de elegibilidad no quedó completo.');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM app_settings
         WHERE setting_key IN (
           'manual_processing.visible_item_limit',
           'manual_processing.require_remote_samples',
           'manual_processing.finish_after_current_batch'
         )"
    )->fetchColumn() !== 3) {
        throw new RuntimeException('Faltan defaults seguros del procesador manual.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.19.6'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se registró 2.19.6.');
    }

    $pdo->exec(
        'INSERT INTO manual_processing_sessions
         (session_token,created_by_user_id,scope_key,status,heartbeat_at,grace_until,total_jobs)
         VALUES (REPEAT("a",40),7,"products","active",UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),2)'
    );
    $sessionId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO manual_processing_scopes
         (manual_processing_session_id,queue_key,account_scope_key,active)
         VALUES (?,"items_sync","*",1)'
    )->execute([$sessionId]);
    $pdo->prepare(
        'INSERT INTO manual_processing_items
         (manual_processing_session_id,queue_key,source_id,status,lease_owner,lease_generation,lease_expires_at)
         VALUES (?,"items_sync","running","running","worker-a",1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)),
                (?,"items_sync","pending","pending",NULL,0,NULL)'
    )->execute([$sessionId, $sessionId]);

    $manual = new \App\Services\ManualProcessingService();
    if (!$manual->finish($sessionId, 7)) {
        throw new RuntimeException('No fue posible iniciar el cierre seguro.');
    }
    $afterFinish = $pdo->query(
        'SELECT status FROM manual_processing_sessions WHERE id=' . $sessionId
    )->fetchColumn();
    if ($afterFinish !== 'finishing') {
        throw new RuntimeException('La sesión no esperó el micro-lote activo.');
    }
    $states = $pdo->query(
        'SELECT source_id,status FROM manual_processing_items
         WHERE manual_processing_session_id=' . $sessionId . ' ORDER BY id'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($states['running'] ?? null) !== 'running' || ($states['pending'] ?? null) !== 'returned') {
        throw new RuntimeException('Finalizar alteró el micro-lote activo o no devolvió el pendiente.');
    }
    if ($manual->claimNext($sessionId, 'worker-b') !== null) {
        throw new RuntimeException('Una sesión terminando reclamó trabajo nuevo.');
    }
    if (!(new \App\Services\ExecutionCoordinationService())->queueReservedForManual('items_sync')) {
        throw new RuntimeException('Cron no respetó la reserva durante la finalización.');
    }
    $running = $pdo->query(
        'SELECT * FROM manual_processing_items
         WHERE manual_processing_session_id=' . $sessionId . ' AND source_id="running"'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$manual->completeItem($running, [
        'status' => 'completed',
        'target_terminal' => true,
        'message' => 'Micro-lote completado.',
    ])) {
        throw new RuntimeException('No fue posible cerrar el micro-lote con su lease vigente.');
    }
    $final = $pdo->query(
        'SELECT status FROM manual_processing_sessions WHERE id=' . $sessionId
    )->fetchColumn();
    if ($final !== 'abandoned') {
        throw new RuntimeException('La sesión no devolvió el resto al cron después del micro-lote.');
    }
    if ((int) $pdo->query(
        'SELECT active FROM manual_processing_scopes WHERE manual_processing_session_id=' . $sessionId
    )->fetchColumn() !== 0) {
        throw new RuntimeException('La reserva de cron quedó activa después de finalizar.');
    }

    $second = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (count(array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución no fue idempotente.');
    }
    fwrite(STDOUT, "OK: migración 100 e idempotencia certificadas en base temporal.\n");
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
