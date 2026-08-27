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

putenv('ERP_API_HEALTH_DIRECT_FALLBACK_FOR_TESTS=1');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_health_2401_' . bin2hex(random_bytes(5));
$version = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = 'utf8mb4_unicode_ci';
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
    $pdo->exec("CREATE TABLE api_incident_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_key CHAR(64) NOT NULL,
        scope_key VARCHAR(80) NOT NULL,
        scope_kind ENUM('application','company','account') NOT NULL,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        outcome_class VARCHAR(40) NOT NULL,
        method VARCHAR(10) NOT NULL,
        endpoint_path VARCHAR(255) NOT NULL,
        http_status SMALLINT UNSIGNED NULL,
        error_type VARCHAR(80) NULL,
        error_code VARCHAR(120) NULL,
        safe_message VARCHAR(500) NULL,
        diagnostic_id VARCHAR(80) NULL,
        reached_remote TINYINT(1) NOT NULL DEFAULT 0,
        actionable TINYINT(1) NOT NULL DEFAULT 0,
        risk_signal TINYINT(1) NOT NULL DEFAULT 0,
        first_seen_at DATETIME(3) NOT NULL,
        last_seen_at DATETIME(3) NOT NULL,
        repetitions BIGINT UNSIGNED NOT NULL DEFAULT 1,
        last_log_id BIGINT UNSIGNED NOT NULL,
        UNIQUE KEY uq_api_incident_group_scope (incident_key,scope_key,outcome_class,method),
        KEY idx_api_incident_group_page (last_seen_at,id),
        KEY idx_api_incident_group_account (meli_account_id,last_seen_at,id),
        KEY idx_api_incident_group_company (company_id,last_seen_at,id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE api_incident_materializer_state (
        singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        heartbeat_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
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
    App\Services\SchemaInspectorService::clearCache();

    $pdo->exec("INSERT INTO users(name,email,password_hash,role,status,is_temporary)
        VALUES ('Admin','admin@example.invalid','irrelevant','admin',1,0)");
    $userId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO companies(name,status) VALUES ('A',1)");
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_company_access(user_id,company_id,access_role,granted_by) VALUES (?,?,?,?)')
        ->execute([$userId, $companyId, 'admin', $userId]);
    $pdo->prepare('INSERT INTO meli_accounts(company_id,account_name,meli_user_id,status) VALUES (?,?,?,?)')
        ->execute([$companyId, 'Cuenta A', 2401001, 'conectado']);
    $accountId = (int) $pdo->lastInsertId();
    App\Core\Session::put('user', [
        'id' => $userId,
        'name' => 'QA',
        'email' => 'admin@example.invalid',
        'role' => 'admin',
        'is_temporary' => 0,
        'session_generation' => (new App\Services\SessionGenerationService())->current(),
    ]);

    $remote429Key = hash('sha256', 'remote-429');
    $localPauseKey = hash('sha256', 'local-pause');
    $remote5xxKey = hash('sha256', 'remote-5xx');
    $insertLog = $pdo->prepare('INSERT INTO api_request_logs
        (meli_account_id,company_id,scope_kind,request_id,method,endpoint_path,http_status,safe_message,error_type,
         outcome_class,reached_remote,actionable,risk_signal,incident_key,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))');
    $insertLog->execute([$accountId, $companyId, 'account', 'remote-429', 'GET', '/billing/integration/group/ML/order/details',
        429, 'Mercado Libre respondió HTTP 429', 'http_429', 'remote_error', 1, 1, 1, $remote429Key]);
    $insertLog->execute([$accountId, $companyId, 'account', 'local-pause', 'GET', '/billing/integration/group/ML/order/details',
        429, 'Pausa preventiva local', 'api_rhythm_deferred', 'policy_delay', 0, 1, 1, $localPauseKey]);
    $insertLog->execute([$accountId, $companyId, 'account', 'remote-5xx', 'GET', '/orders/search',
        500, 'Mercado Libre respondió HTTP 500', 'http_5xx', 'remote_error', 1, 1, 1, $remote5xxKey]);
    $latest = (int) $pdo->query('SELECT MAX(id) FROM api_request_logs')->fetchColumn();
    $pdo->exec('INSERT INTO api_incident_materializer_state(singleton_id,last_log_id) VALUES (1,0)');

    $plan = $pdo->query(
        'EXPLAIN SELECT l.id FROM api_request_logs l
         WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 720 HOUR)
           AND l.incident_key IS NOT NULL
           AND l.outcome_class NOT IN ("success","expected_absence")
           AND (l.meli_account_id IN (' . $accountId . ') OR (l.scope_kind="company" AND l.company_id IN (' . $companyId . ')) OR l.scope_kind="application")
           AND l.outcome_class<>"policy_delay"
           AND l.reached_remote=1
           AND l.http_status=429
         ORDER BY l.created_at DESC,l.id DESC
         LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);

    $stalePage = (new App\Services\ApiHealthService())->incidentPage(
        ['hours' => 720, 'http_status' => 429, 'origin' => 'remote'],
        50,
        0
    );
    $assert($stalePage['protocol'] === 'degraded_direct', 'STALE_REMOTE_429_ROUTE_DID_NOT_USE_DIRECT_FALLBACK:' . json_encode(['page' => $stalePage, 'plan' => $plan], JSON_UNESCAPED_SLASHES));
    $assert($stalePage['total'] === 1, 'STALE_REMOTE_429_ROUTE_DID_NOT_SHOW_DIRECT_REMOTE_429');
    $assert(($stalePage['rows'][0]['transport_class'] ?? '') === 'REMOTE_HTTP_429', 'REMOTE_429_CLASS_MISSING');

    $staleHttpOnlyPage = (new App\Services\ApiHealthService())->incidentPage(
        ['hours' => 720, 'http_status' => 429],
        50,
        0
    );
    $assert($staleHttpOnlyPage['protocol'] === 'degraded_direct', 'STALE_HTTP_429_ROUTE_DID_NOT_USE_DIRECT_FALLBACK');
    $assert($staleHttpOnlyPage['total'] === 1, 'STALE_HTTP_429_ROUTE_DID_NOT_SHOW_DIRECT_REMOTE_429');
    $assert(($staleHttpOnlyPage['rows'][0]['transport_class'] ?? '') === 'REMOTE_HTTP_429', 'HTTP_429_ROUTE_NOT_REMOTE_429');

    $localPage = (new App\Services\ApiHealthService())->incidentPage(
        ['hours' => 720, 'origin' => 'protection'],
        50,
        0
    );
    $assert($localPage['total'] === 1, 'LOCAL_PREVENTIVE_PAUSE_NOT_VISIBLE_SEPARATELY');
    $assert(($localPage['rows'][0]['transport_class'] ?? '') === 'LOCAL_RATE_LIMITED_PRETRANSPORT', 'LOCAL_PAUSE_MIXED_WITH_REMOTE_429');

    $unsafe = new ReflectionMethod(App\Services\ApiHealthService::class, 'directIncidentFallbackSafe');
    $assert($unsafe->invoke(new App\Services\ApiHealthService(), ['1=1'], [], 720) === false, 'UNINDEXED_DIRECT_FALLBACK_WAS_ALLOWED');

    $pdo->prepare('UPDATE api_incident_materializer_state SET last_log_id=? WHERE singleton_id=1')->execute([$latest]);
    $currentEmptyGroupPage = (new App\Services\ApiHealthService())->incidentPage(
        ['hours' => 720, 'http_status' => 429, 'origin' => 'remote'],
        50,
        0
    );
    $assert($currentEmptyGroupPage['protocol'] === 'degraded_direct', 'CURRENT_EMPTY_GROUP_REMOTE_429_MUST_NOT_LOOK_AUTHORITATIVE_EMPTY');
    $assert($currentEmptyGroupPage['total'] === 1, 'CURRENT_EMPTY_GROUP_REMOTE_429_DID_NOT_SHOW_DIRECT_LOG_TRUTH');
    $assert(($currentEmptyGroupPage['rows'][0]['transport_class'] ?? '') === 'REMOTE_HTTP_429', 'CURRENT_EMPTY_GROUP_REMOTE_429_CLASS_MISSING');

    $pdo->prepare('INSERT INTO api_incident_groups
        (incident_key,scope_key,scope_kind,company_id,meli_account_id,outcome_class,method,endpoint_path,http_status,error_type,
         error_code,safe_message,diagnostic_id,reached_remote,actionable,risk_signal,first_seen_at,last_seen_at,repetitions,last_log_id)
        SELECT incident_key,CONCAT("account:",meli_account_id),"account",company_id,meli_account_id,outcome_class,method,
               endpoint_path,http_status,error_type,error_code,safe_message,diagnostic_id,reached_remote,actionable,risk_signal,
               created_at,created_at,1,id
        FROM api_request_logs WHERE request_id="remote-429"')
        ->execute();
    $pdo->prepare('UPDATE api_incident_materializer_state SET last_log_id=? WHERE singleton_id=1')->execute([$latest]);
    $currentPage = (new App\Services\ApiHealthService())->incidentPage(
        ['hours' => 720, 'http_status' => 429, 'origin' => 'remote'],
        50,
        0
    );
    $assert($currentPage['protocol'] === 'complete', 'CURRENT_READ_MODEL_NOT_USED');
    $assert($currentPage['total'] === 1, 'CURRENT_READ_MODEL_REMOTE_429_MISSING');
    $assert(($currentPage['rows'][0]['transport_class'] ?? '') === 'REMOTE_HTTP_429', 'CURRENT_REMOTE_429_CLASS_MISSING');

    $view = (string) file_get_contents($root . '/app/Views/settings/api_health_incidents.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $cron = (string) file_get_contents($root . '/app/Services/CronApiRiskSummaryService.php');
    $assert(str_contains($view, '$incidentLink') && str_contains($view, 'hours'), 'INCIDENT_QUICK_LINKS_DO_NOT_PRESERVE_WINDOW');
    $assert(str_contains($view, 'unavailable_summary_only'), 'HTML_UNAVAILABLE_DEGRADED_MODE_MISSING');
    $assert(str_contains($view, 'No se informa como cero'), 'HTML_UNAVAILABLE_MUST_NOT_LOOK_EMPTY');
    $assert(str_contains($controller, '$incidentsAvailable') && str_contains($controller, 'new ApiHealthService())->summary'), 'JSON_ROUTE_SUMMARY_CAN_STILL_POISON_INCIDENT_PAGE');
    $htmlMethodStart = strpos($controller, 'public function apiHealthIncidents(): void');
    $htmlMethodEnd = strpos($controller, 'public function apiHealthIncidentShow(): void', $htmlMethodStart);
    $htmlMethod = $htmlMethodStart === false || $htmlMethodEnd === false ? '' : substr($controller, $htmlMethodStart, $htmlMethodEnd - $htmlMethodStart);
    $assert(!str_contains($htmlMethod, "View::render('errors/500'"), 'HTML_INCIDENTS_ROUTE_MUST_NOT_RENDER_500_FOR_STALE_CATALOGUE');
    $assert(str_contains($cron, '/settings/api-health/incidents?hours=720&http_status=429&origin=remote'), 'CRON_REMOTE_429_LINK_NOT_BOUND_TO_720H_REMOTE_FILTER');

    echo "PASS api_health_remote_429_visibility_2401_mysql ({$version})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
