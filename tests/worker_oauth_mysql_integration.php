<?php

declare(strict_types=1);

/**
 * Integración opcional de renovación OAuth y propiedad transaccional.
 *
 * Usa las mismas variables del test del migrador y crea una base temporal:
 * ERP_MIGRATOR_TEST_DSN, ERP_MIGRATOR_TEST_USER, ERP_MIGRATOR_TEST_PASS.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para ejecutar OAuth/worker con MySQL.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$databaseName = 'erp_worker_' . bin2hex(random_bytes(6));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = null;

try {
    define('ERP_SHARED_ROOT', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-worker-shared-' . bin2hex(random_bytes(4)));
    require $root . '/bootstrap.php';
    putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));

    $pdo = new PDO($dsn . ';dbname=' . $databaseName, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    \App\Core\Database::setConnection($pdo);
    $pdo->exec(
        'CREATE TABLE app_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(190) NOT NULL UNIQUE,
            setting_value LONGTEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            setting_group VARCHAR(80) NOT NULL DEFAULT "general",
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE meli_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(30) NOT NULL,
            last_error VARCHAR(500) NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE meli_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NOT NULL UNIQUE,
            access_token_encrypted TEXT NOT NULL,
            refresh_token_encrypted TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            scope TEXT NULL,
            refresh_version INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE api_budget_windows (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(40) NOT NULL,
            scope_key VARCHAR(255) NOT NULL,
            meli_account_id BIGINT UNSIGNED NULL,
            endpoint_path VARCHAR(255) NULL,
            job_type VARCHAR(80) NULL,
            window_started_at DATETIME NOT NULL,
            window_seconds INT UNSIGNED NOT NULL,
            request_limit INT UNSIGNED NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_400_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_401_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_403_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_429_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_request_at DATETIME NULL,
            cooldown_until DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_api_budget_window (scope_key,window_started_at,window_seconds)
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE api_workload_estimates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,module VARCHAR(80) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,
            action VARCHAR(120) NOT NULL,estimated_calls INT UNSIGNED NOT NULL,allowed_calls INT UNSIGNED NOT NULL,
            result VARCHAR(40) NOT NULL,safe_reason VARCHAR(500) NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        "INSERT INTO app_settings (setting_key,setting_value,setting_group) VALUES
        ('api.budget.enabled','1','api'),
        ('api.budget.window_seconds','900','api'),
        ('api.budget.global_requests_per_15m','300','api'),
        ('api.budget.account_requests_per_15m','120','api'),
        ('api.budget.endpoint_requests_per_15m','50','api'),
        ('api.budget.job_type_requests_per_15m','80','api'),
        ('oauth.refresh_lock_wait_seconds','1','oauth'),
        ('oauth.token_expiry_skew_seconds','120','oauth')"
    );
    $pdo->exec("INSERT INTO meli_accounts (id,status) VALUES (1,'error')");
    $insert = $pdo->prepare(
        'INSERT INTO meli_tokens
         (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,scope)
         VALUES (?,?,?,?,?)'
    );
    $insert->execute([
        1,
        \App\Core\Crypto::encrypt('old-access'),
        \App\Core\Crypto::encrypt('old-refresh'),
        gmdate('Y-m-d H:i:s', time() - 60),
        'read',
    ]);
    if ($pdo->inTransaction()) {
        throw new RuntimeException('El fixture llegó a OAuth con una transacción activa.');
    }

    $networkSawTransaction = null;
    $token = (new \App\Services\OAuthTokenRefreshService(1))->refresh(
        static function (array $payload) use ($pdo, &$networkSawTransaction): array {
            $networkSawTransaction = $pdo->inTransaction();
            if (($payload['refresh_token'] ?? '') !== 'old-refresh') {
                throw new RuntimeException('El refresh token no se descifró correctamente.');
            }
            return [
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 21600,
                'scope' => 'read',
            ];
        }
    );
    if ($networkSawTransaction !== false) {
        throw new RuntimeException('La renovación intentó usar red dentro de una transacción.');
    }
    if (\App\Core\Crypto::decrypt((string) $token['access_token_encrypted']) !== 'new-access') {
        throw new RuntimeException('El nuevo access token no quedó persistido.');
    }
    if ((string) $pdo->query('SELECT status FROM meli_accounts WHERE id=1')->fetchColumn() !== 'conectado') {
        throw new RuntimeException('La cuenta no volvió a estado conectado.');
    }
    $expiryCheck = new ReflectionMethod(\App\Services\MeliApiClient::class, 'tokenExpiresSoon');
    $timezoneBefore = date_default_timezone_get();
    date_default_timezone_set('America/Bogota');
    try {
        $client = new \App\Services\MeliApiClient(1);
        if ($expiryCheck->invoke($client, gmdate('Y-m-d H:i:s', time() - 60), 120) !== true) {
            throw new RuntimeException('MeliApiClient no interpretó expires_at como UTC.');
        }
    } finally {
        date_default_timezone_set($timezoneBefore);
    }

    $pdo->beginTransaction();
    (new \App\Services\ApiBudgetService())->reserve(1, 'GET', '/orders/123', [
        'job_type' => 'orders_event_sync',
        'source' => 'cron',
    ]);
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('ApiBudgetService revirtió una transacción que pertenecía al llamador.');
    }
    $pdo->rollBack();

    fwrite(STDOUT, "OK: OAuth renovó fuera de transacción y ApiBudget conservó la transacción del llamador.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $pdo = null;
    \App\Core\Database::setConnection($server);
    $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
}
