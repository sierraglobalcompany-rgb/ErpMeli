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
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
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
