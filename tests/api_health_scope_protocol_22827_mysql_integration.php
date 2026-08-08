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
$database = 'erp_health_22827_' . bin2hex(random_bytes(5));
$version = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($version, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    App\Services\SchemaInspectorService::clearCache();

    $pdo->exec("INSERT INTO users (name,email,password_hash,role,status,is_temporary) VALUES
        ('Admin parcial','health27-partial@example.invalid','irrelevant','admin',1,0),
        ('Admin global','health27-global@example.invalid','irrelevant','admin',1,0)");
    $partial = (int) $pdo->query("SELECT id FROM users WHERE email='health27-partial@example.invalid'")->fetchColumn();
    $global = (int) $pdo->query("SELECT id FROM users WHERE email='health27-global@example.invalid'")->fetchColumn();
    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Scope 27 A',1),('Scope 27 B',1)");
    $companyA = (int) $pdo->query("SELECT id FROM companies WHERE name='Scope 27 A'")->fetchColumn();
    $companyB = (int) $pdo->query("SELECT id FROM companies WHERE name='Scope 27 B'")->fetchColumn();
    $grant = $pdo->prepare("INSERT INTO user_company_access(user_id,company_id,access_role,granted_by) VALUES (?,?,'admin',?)");
    $grant->execute([$partial, $companyA, $global]);
    $grant->execute([$global, $companyA, $global]);
    $grant->execute([$global, $companyB, $global]);
    $account = $pdo->prepare("INSERT INTO meli_accounts(company_id,account_name,meli_user_id,status) VALUES (?,?,?,'conectado')");
    $account->execute([$companyA, 'Cuenta 27 A', 2282701]);
    $accountA = (int) $pdo->lastInsertId();
    $account->execute([$companyB, 'Cuenta 27 B', 2282702]);
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

    $pdo->exec("INSERT INTO api_manual_pauses
        (scope,meli_account_id,status,pause_mode,paused_until,reason,created_by,created_at)
        VALUES ('app',NULL,'active','indefinite',NULL,'Motivo global privado',{$global},UTC_TIMESTAMP())");

    $login($partial, 'health27-partial@example.invalid');
    $partialPause = (new App\Services\ApiManualPauseService())->summary([$accountA], false);
    if (empty($partialPause['active']) || empty($partialPause['global']) || empty($partialPause['redacted_application_pause'])) {
        throw new RuntimeException('La pausa global no se comunicó de forma redactada al alcance parcial.');
    }
    foreach ($partialPause['pauses'] as $pause) {
        if (($pause['scope'] ?? '') === 'app') {
            throw new RuntimeException('El alcance parcial recibió metadatos de la pausa global.');
        }
    }

    $guard = new App\Services\ApiGuardService();
    $classification = ['type' => 'remote_test', 'reached_remote' => true];
    $guard->recordRequest($accountA, 'P27-A', 'GET', '/orders/7001', 500, 20, null, 1, false, 'Timeout controlado', $classification, 'remote_timeout');
    $guard->recordRequest($accountB, 'P27-B', 'GET', '/orders/7002', 500, 20, null, 1, false, 'Timeout controlado', $classification, 'remote_timeout');
    $guard->recordRequest($accountA, 'P27-C', 'GET', '/orders/8001', 403, 20, null, 1, false, 'Permiso insuficiente', $classification, 'remote_forbidden');
    $incidentKey = (string) $pdo->query("SELECT incident_key FROM api_request_logs WHERE request_id='P27-A'")->fetchColumn();

    (new App\Services\ApiIncidentAcknowledgementService())->acknowledge($incidentKey, $partial, 'Revisado en A');

    $login($global, 'health27-global@example.invalid');
    $globalPause = (new App\Services\ApiManualPauseService())->summary([$accountA, $accountB], true);
    if (empty($globalPause['global_details_visible']) || ($globalPause['pauses'][0]['reason'] ?? '') !== 'Motivo global privado') {
        throw new RuntimeException('El scope aplicación explícito perdió el detalle autorizado de la pausa.');
    }
    $detail = (new App\Services\ApiHealthService())->incident($incidentKey);
    if (!is_array($detail) || ($detail['state'] ?? '') === 'reviewed' || $detail['acknowledgement'] !== null) {
        throw new RuntimeException('Reconocer la cuenta A marcó como revisada la evidencia pendiente de la cuenta B.');
    }

    $service = new App\Services\ApiHealthService();
    $page = $service->incidentPage(['hours' => 24], 1, 0);
    if ($page['total'] < 2 || !$page['truncated'] || $page['protocol'] !== 'partial' || count($page['rows']) !== 1) {
        throw new RuntimeException('La página de incidentes no declaró total o truncamiento real.');
    }

    $pdo->exec('DROP TABLE api_budget_windows');
    App\Services\SchemaInspectorService::clearCache();
    $budget = (new App\Services\ApiBudgetService())->summary(10, [$accountA, $accountB]);
    if ($budget['available'] || $budget['protocol'] !== 'unavailable') {
        throw new RuntimeException('La ausencia del presupuesto se presentó como vacío sano.');
    }

    echo "PASS api_health_scope_protocol_22827_mysql_integration ({$version})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
