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
require $root . '/vendor/autoload.php';

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_health_22824_' . bin2hex(random_bytes(5));
$version = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($version, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $pdo->exec("CREATE TABLE app_settings (
        setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
        setting_value MEDIUMTEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(191) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        is_temporary TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE companies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE user_company_access (
        user_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        access_role VARCHAR(40) NOT NULL,
        granted_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (user_id, company_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(191) NOT NULL,
        meli_user_id BIGINT UNSIGNED NULL,
        site_id VARCHAR(10) NULL DEFAULT 'MLC',
        status VARCHAR(40) NOT NULL DEFAULT 'conectado'
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_request_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NULL,
        company_id BIGINT UNSIGNED NULL,
        scope_kind VARCHAR(40) NULL DEFAULT 'account',
        request_id VARCHAR(191) NULL,
        method VARCHAR(10) NOT NULL,
        endpoint_path VARCHAR(255) NOT NULL,
        http_status INT NULL,
        duration_ms INT NULL,
        retry_after_seconds INT NULL,
        attempt INT NOT NULL DEFAULT 1,
        was_blocked TINYINT(1) NOT NULL DEFAULT 0,
        safe_message VARCHAR(500) NULL,
        diagnostic_id VARCHAR(80) NULL,
        error_type VARCHAR(100) NULL,
        error_code VARCHAR(120) NULL,
        is_retryable TINYINT(1) NOT NULL DEFAULT 0,
        is_app_blocked_signal TINYINT(1) NOT NULL DEFAULT 0,
        outcome_class VARCHAR(80) NULL,
        reached_remote TINYINT(1) NOT NULL DEFAULT 0,
        actionable TINYINT(1) NOT NULL DEFAULT 0,
        risk_signal TINYINT(1) NOT NULL DEFAULT 0,
        incident_key CHAR(64) NULL,
        execution_source VARCHAR(40) NULL,
        job_type VARCHAR(80) NULL,
        source_queue_key VARCHAR(80) NULL,
        source_work_id VARCHAR(100) NULL,
        operation_key VARCHAR(120) NULL,
        load_class VARCHAR(80) NULL,
        workload_units INT NOT NULL DEFAULT 1,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        KEY idx_api_logs_incident (incident_key, outcome_class, created_at),
        KEY idx_api_logs_scope (meli_account_id, company_id, scope_kind, created_at),
        KEY idx_api_logs_endpoint (endpoint_path, http_status, created_at)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_incident_acknowledgements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_key CHAR(64) NOT NULL,
        scope_key VARCHAR(191) NOT NULL,
        scope_kind VARCHAR(40) NOT NULL,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        acknowledged_by BIGINT UNSIGNED NOT NULL,
        acknowledged_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        acknowledged_through_at DATETIME(3) NOT NULL,
        note VARCHAR(500) NULL,
        UNIQUE KEY uq_api_incident_ack_scope (incident_key, scope_key)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_incident_acknowledgement_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_key CHAR(64) NOT NULL,
        scope_key VARCHAR(191) NOT NULL,
        scope_kind VARCHAR(40) NOT NULL,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        acknowledged_by BIGINT UNSIGNED NOT NULL,
        acknowledged_through_at DATETIME(3) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_circuit_breakers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NULL,
        endpoint_path VARCHAR(255) NOT NULL DEFAULT '*',
        reason VARCHAR(120) NOT NULL,
        http_status INT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'open',
        blocked_until DATETIME(3) NULL,
        last_message VARCHAR(500) NULL,
        error_type VARCHAR(80) NULL,
        failure_count INT NOT NULL DEFAULT 1,
        window_started_at DATETIME(3) NULL,
        next_retry_at DATETIME(3) NULL,
        scope VARCHAR(40) NOT NULL DEFAULT 'endpoint',
        closed_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_manual_pauses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(40) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        pause_mode VARCHAR(40) NOT NULL DEFAULT 'timed',
        paused_until DATETIME(3) NULL,
        reason VARCHAR(500) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        resumed_at DATETIME(3) NULL,
        resumed_by BIGINT UNSIGNED NULL,
        resume_reason VARCHAR(500) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_budget_windows (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(40) NOT NULL,
        scope_key VARCHAR(191) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        endpoint_path VARCHAR(255) NULL,
        job_type VARCHAR(80) NULL,
        window_started_at DATETIME(3) NOT NULL,
        window_seconds INT NOT NULL,
        request_limit INT NOT NULL,
        request_count INT NOT NULL DEFAULT 0,
        error_400_count INT NOT NULL DEFAULT 0,
        error_401_count INT NOT NULL DEFAULT 0,
        error_403_count INT NOT NULL DEFAULT 0,
        error_429_count INT NOT NULL DEFAULT 0,
        error_5xx_count INT NOT NULL DEFAULT 0,
        cooldown_until DATETIME(3) NULL,
        last_request_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_api_budget_window (scope_key, window_started_at, window_seconds),
        KEY idx_api_budget_account (meli_account_id, window_started_at)
    ) ENGINE=InnoDB");
    App\Services\SchemaInspectorService::clearCache();

    $pdo->exec("INSERT INTO users (name,email,password_hash,role,status,is_temporary) VALUES
        ('Admin parcial','health-partial@example.invalid','irrelevant','admin',1,0),
        ('Admin global','health-global@example.invalid','irrelevant','admin',1,0)");
    $partial = (int) $pdo->query("SELECT id FROM users WHERE email='health-partial@example.invalid'")->fetchColumn();
    $global = (int) $pdo->query("SELECT id FROM users WHERE email='health-global@example.invalid'")->fetchColumn();
    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Scope A',1),('Scope B',1)");
    $companyA = (int) $pdo->query("SELECT id FROM companies WHERE name='Scope A'")->fetchColumn();
    $companyB = (int) $pdo->query("SELECT id FROM companies WHERE name='Scope B'")->fetchColumn();
    $grant = $pdo->prepare("INSERT INTO user_company_access(user_id,company_id,access_role,granted_by) VALUES (?,?,'admin',?)");
    $grant->execute([$partial, $companyA, $global]);
    $grant->execute([$global, $companyA, $global]);
    $grant->execute([$global, $companyB, $global]);
    $account = $pdo->prepare("INSERT INTO meli_accounts(company_id,account_name,meli_user_id,status) VALUES (?,?,?,'conectado')");
    $account->execute([$companyA, 'Cuenta scope A', 2282401]);
    $accountA = (int) $pdo->lastInsertId();
    $account->execute([$companyB, 'Cuenta scope B', 2282402]);
    $accountB = (int) $pdo->lastInsertId();

    $login = static function (int $id, string $email): void {
        App\Core\Session::put('user', [
            'id' => $id,
            'name' => 'QA',
            'email' => $email,
            'role' => 'admin',
            'is_temporary' => 0,
            'session_generation' => (new App\Services\SessionGenerationService())->current(),
        ]);
    };

    $guard = new App\Services\ApiGuardService();
    $classification = ['type' => 'remote_test', 'reached_remote' => true];
    $guard->recordRequest($accountA, 'REQ-A', 'GET', '/orders/9981', 500, 20, null, 1, false, 'Timeout at 10:01', $classification, 'remote_timeout');
    $guard->recordRequest($accountB, 'REQ-B', 'GET', '/orders/9982', 500, 20, null, 1, false, 'Timeout at 10:02', $classification, 'remote_timeout');
    $guard->recordRequest(null, 'REQ-COMPANY', 'GET', '/orders/9983', 500, 20, null, 1, false, 'Timeout at 10:03', $classification, 'remote_timeout', ['company_id' => $companyA]);
    $guard->recordRequest(null, 'REQ-APP', 'GET', '/orders/9984', 500, 20, null, 1, false, 'Timeout at 10:04', $classification, 'remote_timeout');
    $guard->recordRequest($accountA, 'WAIT-A', 'GET', '/orders/search', null, 0, null, 1, true, 'Presupuesto preventivo agotado', ['type' => 'api_budget_exhausted'], 'budget');
    $incidentKey = (string) $pdo->query("SELECT incident_key FROM api_request_logs WHERE request_id='REQ-A'")->fetchColumn();
    if ($incidentKey === '') {
        throw new RuntimeException('No se persistió la clave estable del incidente.');
    }


    $login($partial, 'health-partial@example.invalid');
    $partialScope = (new App\Services\ApiHealthAccessScope())->snapshot();
    if ($partialScope['application']) {
        throw new RuntimeException('Un admin parcial obtuvo telemetría global por su rol.');
    }
    $partialRows = (new App\Services\ApiHealthService())->incidents(['hours' => 24], 50);
    $partialIncident = array_values(array_filter($partialRows, static fn(array $row): bool => ($row['incident_key'] ?? '') === $incidentKey))[0] ?? null;
    if (!is_array($partialIncident) || (int) $partialIncident['repetitions'] !== 2) {
        throw new RuntimeException('El scope parcial mezcló otra empresa o perdió su evento de empresa autorizado.');
    }
    foreach ($partialRows as $row) {
        if (($row['outcome_class'] ?? '') === 'policy_delay') {
            throw new RuntimeException('policy_delay apareció como incidente de Mercado Libre.');
        }
    }

    (new App\Services\ApiIncidentAcknowledgementService())->acknowledge($incidentKey, $partial, 'Revisión QA');
    $reviewed = (new App\Services\ApiHealthService())->incidents(['hours' => 24, 'status' => 'reviewed'], 50);
    if (count($reviewed) !== 1 || ($reviewed[0]['incident_key'] ?? '') !== $incidentKey) {
        throw new RuntimeException('El reconocimiento scoped no marcó únicamente el incidente visible.');
    }
    sleep(1);
    $guard->recordRequest($accountA, 'REQ-A-NEW', 'GET', '/orders/9991', 500, 20, null, 1, false, 'Timeout at 10:05', $classification, 'remote_timeout');
    $active = (new App\Services\ApiHealthService())->incidents(['hours' => 24, 'status' => 'active'], 50);
    if (!array_filter($active, static fn(array $row): bool => ($row['incident_key'] ?? '') === $incidentKey)) {
        throw new RuntimeException('Una ocurrencia posterior no reabrió el incidente reconocido.');
    }
    $selected = (new App\Services\ApiHealthService())->incidents(['hours' => 24, 'account_id' => $accountA], 50);
    $selectedIncident = array_values(array_filter($selected, static fn(array $row): bool => ($row['incident_key'] ?? '') === $incidentKey))[0] ?? null;
    if (!is_array($selectedIncident) || (int) $selectedIncident['repetitions'] !== 2) {
        throw new RuntimeException('El filtro de cuenta incluyó telemetría de empresa, aplicación u otra cuenta.');
    }
    $selectedSummary = (new App\Services\ApiHealthService())->summary(24, $accountA);
    foreach (($selectedSummary['stats'] ?? []) as $stat) {
        if ((int) ($stat['meli_account_id'] ?? 0) !== $accountA) {
            throw new RuntimeException('El resumen de cuenta mezcló telemetría de otra cuenta o empresa.');
        }
    }
    if (empty($selectedSummary['data_available'])) {
        throw new RuntimeException('El resumen scoped quedó indisponible con un esquema válido.');
    }

    $login($global, 'health-global@example.invalid');
    if (!(new App\Services\ApiHealthAccessScope())->snapshot()['application']) {
        throw new RuntimeException('El administrador con concesiones explícitas sobre todas las empresas no obtuvo scope aplicación.');
    }
    $globalRows = (new App\Services\ApiHealthService())->incidents(['hours' => 24], 50);
    $globalIncident = array_values(array_filter($globalRows, static fn(array $row): bool => ($row['incident_key'] ?? '') === $incidentKey))[0] ?? null;
    if (!is_array($globalIncident) || (int) $globalIncident['repetitions'] !== 5) {
        throw new RuntimeException('El scope global explícito no reunió account/company/application correctamente.');
    }

    echo "PASS api_health_incident_truth_22824_mysql_integration ({$version})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
