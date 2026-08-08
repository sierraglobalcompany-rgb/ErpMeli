<?php

declare(strict_types=1);

$codeRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-hf12-integration-' . bin2hex(random_bytes(6));
$privateRoot = $testRoot . DIRECTORY_SEPARATOR . 'private';
mkdir($privateRoot, 0700, true);
define('ERP_RELEASE_ROOT', $codeRoot);
define('ERP_INSTALLATION_ROOT', $testRoot);
define('ERP_SHARED_ROOT', $testRoot);
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $privateRoot);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $privateRoot;

require $codeRoot . '/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CurlMeliHttpTransport;
use App\Services\EmergencyControlService;
use App\Services\EmergencyOAuthRefreshService;
use App\Services\EmergencyOAuthRefreshTransportContext;
use App\Services\MeliApiClient;
use App\Services\MeliEmergencyStopService;
use App\Services\MeliHttpTransportInterface;

$databaseName = (string) Env::get('DB_NAME', '');
if (Env::get('HF12_TEST_DB', '') !== '1' || preg_match('/^hf12_test(?:_[a-z0-9]+)?$/', $databaseName) !== 1) {
    fwrite(STDERR, 'HF12_TEST_DB=1 y una DB_NAME efímera hf12_test* son obligatorios.' . PHP_EOL);
    exit(2);
}

$savedEnvironment = [];
$testEnvironment = [
    'APP_KEY' => 'base64:' . base64_encode(hash('sha256', 'hf12-integration-key', true)),
    'MELI_CLIENT_ID' => 'hf12-client-id',
    'MELI_CLIENT_SECRET' => 'CLIENT_SECRET_SENTINEL_HF12',
    'MELI_REDIRECT_URI' => 'https://localhost.invalid/oauth/callback',
    'MELI_API_BASE' => 'https://api.mercadolibre.invalid',
    'ML_WRITE_ENABLED' => 'false',
];
foreach ($testEnvironment as $key => $value) {
    $savedEnvironment[$key] = getenv($key);
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$failures = [];
$passed = 0;
$total = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$passed, &$total): void {
    $total++;
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $message;
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

try {
    Database::useProfile('cli');
    $pdo = Database::connection();
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
        throw new RuntimeException('La conexión no corresponde a la base efímera HF1.2 declarada.');
    }

    foreach ([
        'system_execution_attempts', 'system_logs', 'api_operation_metrics_hourly',
        'api_remote_permits', 'api_rhythm_states', 'api_budget_windows',
        'api_error_logs', 'api_request_logs', 'api_circuit_breakers',
        'api_manual_pauses', 'users', 'meli_tokens', 'meli_accounts', 'app_settings',
    ] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(160) PRIMARY KEY, setting_value MEDIUMTEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0, setting_group VARCHAR(80) NOT NULL DEFAULT "test"
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(255) NOT NULL, nickname VARCHAR(255) NULL,
        meli_user_id VARCHAR(80) NOT NULL, status VARCHAR(40) NOT NULL,
        last_error VARCHAR(500) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE meli_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, meli_account_id BIGINT UNSIGNED NOT NULL,
        access_token_encrypted MEDIUMTEXT NOT NULL, refresh_token_encrypted MEDIUMTEXT NOT NULL,
        expires_at DATETIME NOT NULL, token_type VARCHAR(40) NULL, scope TEXT NULL,
        refresh_version INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uq_hf12_token_account (meli_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT ""
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE api_manual_pauses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(20) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL, status VARCHAR(40) NOT NULL,
        paused_until DATETIME NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE api_circuit_breakers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, meli_account_id BIGINT UNSIGNED NULL,
        endpoint_path VARCHAR(255) NOT NULL, status VARCHAR(40) NOT NULL,
        blocked_until DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE api_request_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, safe_message TEXT NULL,
        diagnostic_id VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE api_error_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, safe_message TEXT NULL,
        response_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE system_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message TEXT NULL,
        context_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE system_execution_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, safe_message TEXT NULL,
        checkpoint_json LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $settings = $pdo->prepare(
        'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,"test")'
    );
    foreach ([
        'api.guard.enabled' => '0',
        'api.budget.enabled' => '0',
        'api.pacing.enabled' => '0',
        'oauth.refresh_lock_wait_seconds' => '1',
        'oauth.token_expiry_skew_seconds' => '120',
    ] as $key => $value) {
        $settings->execute([$key, $value]);
    }
    $pdo->prepare(
        "INSERT INTO meli_accounts (id,company_id,account_name,nickname,meli_user_id,status)
         VALUES (3,9,'BODEGA.DIGITAL.MEDELLIN','BODEGA.DIGITAL.MEDELLIN','333000','conectado')"
    )->execute();

    $oldAccess = 'ACCESS_TOKEN_OLD_SENTINEL_HF12';
    $oldRefresh = 'REFRESH_TOKEN_OLD_SENTINEL_HF12';
    $newAccess = 'ACCESS_TOKEN_NEW_SENTINEL_HF12';
    $newRefresh = 'REFRESH_TOKEN_NEW_SENTINEL_HF12';
    $bodySentinel = 'OAUTH_BODY_SENTINEL_HF12';
    $nonceSentinel = hash('sha256', 'NONCE_SENTINEL_HF12');
    $recoveryPath = $privateRoot . DIRECTORY_SEPARATOR . 'oauth-rotated-token-recovery.json';

    $resetAccount = static function (int $version = 7) use ($pdo, $oldAccess, $oldRefresh): void {
        $pdo->exec('DELETE FROM meli_tokens');
        $stmt = $pdo->prepare(
            'INSERT INTO meli_tokens
             (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,token_type,scope,refresh_version)
             VALUES (3,?,?,?,?,?,?)'
        );
        $stmt->execute([
            Crypto::encrypt($oldAccess),
            Crypto::encrypt($oldRefresh),
            '2026-08-04 05:58:23',
            'Bearer',
            'offline_access',
            $version,
        ]);
    };
    $clearEmergency = static function () use ($testRoot, $privateRoot): void {
        foreach ([
            EmergencyControlService::API_MARKER,
            EmergencyControlService::AUTOMATION_MARKER,
            EmergencyControlService::CANARY_MARKER,
            EmergencyControlService::OAUTH_REFRESH_MARKER,
            EmergencyControlService::LAST_CHANGE_MARKER,
        ] as $marker) {
            $path = $testRoot . DIRECTORY_SEPARATOR . $marker;
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        foreach ([
            'oauth-refresh-reservation.json',
            'oauth-rotated-token-recovery.json',
            'canary-reservation.json',
            'audit.jsonl',
        ] as $name) {
            @unlink($privateRoot . DIRECTORY_SEPARATOR . $name);
        }
    };
    $tokenRow = static function () use ($pdo): array {
        return $pdo->query('SELECT * FROM meli_tokens WHERE meli_account_id=3')->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // El storage específico del escrow se prueba en la última barrera. Una
    // ruta no publicable conserva used_calls=0 y produce cero HTTP.
    $resetAccount();
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar preflight de escrow no utilizable');
    @mkdir($recoveryPath, 0700);
    $preflightTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            throw new RuntimeException('El preflight fallido no debe abrir transporte.');
        }
    };
    $preflightService = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $preflightTransport))->refreshOAuthToken()
    );
    try {
        $preflightService->run(3, 'hf12-integration');
    } catch (Throwable) {
    }
    $preflightState = $control->status();
    $preflightOAuth = is_array($preflightState['oauth_refresh'] ?? null)
        ? $preflightState['oauth_refresh']
        : [];
    $preflightToken = $tokenRow();
    $check($preflightTransport->calls === 0 && (int) ($preflightOAuth['used_calls'] ?? -1) === 0,
        'RECOVERY_PREFLIGHT_FAILURE_HTTP o used_calls fue distinto de cero.');
    $check(Crypto::decrypt((string) $preflightToken['access_token_encrypted']) === $oldAccess
        && Crypto::decrypt((string) $preflightToken['refresh_token_encrypted']) === $oldRefresh
        && (int) $preflightToken['refresh_version'] === 7,
        'El preflight fallido modificó meli_tokens.');
    $check($control->apiStopped() && $control->automationStopped(),
        'El preflight fallido retiró una barrera de emergencia.');
    @rmdir($recoveryPath);

    // Flujo real: EmergencyOAuthRefreshService -> MeliApiClient -> OAuthTokenRefreshService.
    $resetAccount();
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar integración OAuth local');
    $transport = new class($newAccess, $newRefresh, $bodySentinel) implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function __construct(
            private readonly string $access,
            private readonly string $refresh,
            private readonly string $bodySentinel
        ) {}
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            return [
                'status' => 200,
                'headers' => [],
                'body' => [
                    'access_token' => $this->access,
                    'refresh_token' => $this->refresh,
                    'expires_in' => 21600,
                    'token_type' => 'Bearer',
                    'scope' => 'offline_access',
                    'ignored_body_sentinel' => $this->bodySentinel,
                ],
                'curl_error' => '',
                'duration_ms' => 1,
                'wire_bytes' => 180,
                'decoded_bytes' => 180,
            ];
        }
    };
    $service = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $transport))->refreshOAuthToken()
    );
    $result = $service->run(3, 'hf12-integration');
    $stored = $tokenRow();
    $check($transport->calls === 1, 'REAL_CLIENT_OAUTH_REFRESH_TEST no hizo exactamente un HTTP.');
    $check(Crypto::decrypt((string) $stored['access_token_encrypted']) === $newAccess,
        'REAL_OAUTH_TOKEN_PERSISTENCE_TEST no persistió el access token nuevo.');
    $check(Crypto::decrypt((string) $stored['refresh_token_encrypted']) === $newRefresh,
        'REAL_OAUTH_TOKEN_PERSISTENCE_TEST no persistió el refresh token rotado.');
    $check(strtotime((string) $stored['expires_at']) > time() + 300,
        'REAL_OAUTH_TOKEN_PERSISTENCE_TEST no dejó expires_at futuro.');
    $check((int) $stored['refresh_version'] === 8 && (int) $result['refresh_version'] === 8,
        'REAL_OAUTH_TOKEN_PERSISTENCE_TEST no incrementó refresh_version.');
    $check($control->apiStopped() && $control->automationStopped(),
        'El refresh real retiró una barrera de emergencia.');

    // Fallo del marcador después del 200 y después de persistir: nunca pierde
    // las credenciales rotadas ni dispara un segundo HTTP.
    $resetAccount();
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar fallo post persistencia');
    $postPersistTransport = new class($newAccess, $newRefresh) implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function __construct(private readonly string $access, private readonly string $refresh) {}
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            return [
                'status' => 200, 'headers' => [], 'curl_error' => '', 'duration_ms' => 1,
                'wire_bytes' => 120, 'decoded_bytes' => 120,
                'body' => [
                    'access_token' => $this->access,
                    'refresh_token' => $this->refresh,
                    'expires_in' => 21600,
                    'token_type' => 'Bearer',
                ],
            ];
        }
    };
    $postPersistLoader = static function (int $accountId) use ($pdo, $testRoot): ?array {
        $stmt = $pdo->prepare('SELECT expires_at,refresh_version FROM meli_tokens WHERE meli_account_id=?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $marker = $testRoot . DIRECTORY_SEPARATOR . EmergencyControlService::OAUTH_REFRESH_MARKER;
        @unlink($marker);
        @mkdir($marker, 0700);
        return is_array($row) ? $row : null;
    };
    $postPersistService = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $postPersistTransport))->refreshOAuthToken(),
        $postPersistLoader
    );
    $postPersistFailed = false;
    try {
        $postPersistService->run(3, 'hf12-integration');
    } catch (Throwable) {
        $postPersistFailed = true;
    }
    $storedAfterMarkerFailure = $tokenRow();
    $check($postPersistFailed && $postPersistTransport->calls === 1,
        'El fallo de marcador post-200 produjo retry o no fue observado.');
    $check(Crypto::decrypt((string) $storedAfterMarkerFailure['access_token_encrypted']) === $newAccess,
        'El fallo de marcador post-200 perdió el access token nuevo.');
    $check(Crypto::decrypt((string) $storedAfterMarkerFailure['refresh_token_encrypted']) === $newRefresh,
        'El fallo de marcador post-200 perdió el refresh token nuevo.');
    $check(strtotime((string) $storedAfterMarkerFailure['expires_at']) > time() + 300
        && (int) $storedAfterMarkerFailure['refresh_version'] === 8,
        'El fallo de marcador post-200 perdió expires_at o refresh_version.');
    $check($control->apiStopped() && $control->automationStopped(),
        'El fallo de marcador post-200 retiró una barrera de emergencia.');
    @rmdir($testRoot . DIRECTORY_SEPARATOR . EmergencyControlService::OAUTH_REFRESH_MARKER);

    // Fallo MariaDB después del 200: el refresh token rotado queda cifrado en
    // escrow y el siguiente intento lo aplica localmente con cero HTTP.
    $resetAccount();
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar recuperación durable por fallo DB');
    $dbFailureTransport = new class($pdo, $newAccess, $newRefresh) implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function __construct(
            private readonly PDO $pdo,
            private readonly string $access,
            private readonly string $refresh
        ) {}
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            $this->pdo->exec(
                'ALTER TABLE meli_tokens CHANGE refresh_version refresh_version_broken INT UNSIGNED NOT NULL DEFAULT 0'
            );
            return [
                'status' => 200, 'headers' => [], 'curl_error' => '', 'duration_ms' => 1,
                'wire_bytes' => 120, 'decoded_bytes' => 120,
                'body' => [
                    'access_token' => $this->access,
                    'refresh_token' => $this->refresh,
                    'expires_in' => 21600,
                    'token_type' => 'Bearer',
                    'scope' => 'offline_access',
                ],
            ];
        }
    };
    $dbFailureService = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $dbFailureTransport))->refreshOAuthToken()
    );
    $dbFailureObserved = false;
    try {
        $dbFailureService->run(3, 'hf12-integration');
    } catch (Throwable) {
        $dbFailureObserved = true;
    }
    $recoveryRaw = is_file($recoveryPath) ? (string) file_get_contents($recoveryPath) : '';
    $check($dbFailureObserved && $dbFailureTransport->calls === 1,
        'El fallo DB post-200 no quedó limitado a un único HTTP conocido.');
    $check($recoveryRaw !== ''
        && !str_contains($recoveryRaw, $newAccess)
        && !str_contains($recoveryRaw, $newRefresh),
        'El token rotado no quedó recuperable y cifrado después del fallo DB.');
    $pdo->exec(
        'ALTER TABLE meli_tokens CHANGE refresh_version_broken refresh_version INT UNSIGNED NOT NULL DEFAULT 0'
    );

    $recoveryTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            $this->calls++;
            throw new RuntimeException('La recuperación local no debe abrir transporte.');
        }
    };
    $recoveryService = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $recoveryTransport))->refreshOAuthToken()
    );
    $recoveryResult = $recoveryService->run(3, 'hf12-integration');
    $recoveredToken = $tokenRow();
    $check($recoveryTransport->calls === 0, 'La recuperación durable abrió un segundo HTTP.');
    $check(Crypto::decrypt((string) $recoveredToken['access_token_encrypted']) === $newAccess
        && Crypto::decrypt((string) $recoveredToken['refresh_token_encrypted']) === $newRefresh,
        'La recuperación durable no aplicó ambos tokens rotados.');
    $check((int) $recoveredToken['refresh_version'] === 8
        && (int) $recoveryResult['refresh_version'] === 8,
        'La recuperación durable no aplicó la generación exacta.');
    $check(!is_file($recoveryPath), 'El escrow durable no se retiró después del commit recuperado.');
    $check($control->apiStopped() && $control->automationStopped(),
        'La recuperación durable retiró una barrera de emergencia.');

    // Carrera posterior al preflight: el destino del escrow falla después del
    // HTTP y MariaDB también falla. La clase estable conserva el diagnóstico,
    // mantiene ambos frenos y nunca hace un segundo transporte.
    $resetAccount();
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar fallo combinado escrow y DB');
    $doubleFailureTransport = new class($pdo, $recoveryPath, $newAccess, $newRefresh) implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function __construct(
            private readonly PDO $pdo,
            private readonly string $recoveryPath,
            private readonly string $access,
            private readonly string $refresh
        ) {}
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            @mkdir($this->recoveryPath, 0700);
            $this->pdo->exec(
                'ALTER TABLE meli_tokens CHANGE refresh_version refresh_version_broken INT UNSIGNED NOT NULL DEFAULT 0'
            );
            return [
                'status' => 200, 'headers' => [], 'curl_error' => '', 'duration_ms' => 1,
                'wire_bytes' => 120, 'decoded_bytes' => 120,
                'body' => [
                    'access_token' => $this->access,
                    'refresh_token' => $this->refresh,
                    'expires_in' => 21600,
                    'token_type' => 'Bearer',
                    'scope' => 'offline_access',
                ],
            ];
        }
    };
    $doubleFailureService = new EmergencyOAuthRefreshService(
        $control,
        null,
        static fn (int $accountId): array => (new MeliApiClient($accountId, $doubleFailureTransport))->refreshOAuthToken()
    );
    try {
        $doubleFailureService->run(3, 'hf12-integration');
    } catch (Throwable) {
    }
    $doubleFailureState = $control->status();
    $doubleFailureOAuth = is_array($doubleFailureState['oauth_refresh'] ?? null)
        ? $doubleFailureState['oauth_refresh']
        : [];
    $check($doubleFailureTransport->calls === 1
        && ($doubleFailureOAuth['failure_class'] ?? '') === 'rotated_credential_recovery_unavailable',
        'El fallo combinado no conservó ROTATED_CREDENTIAL_RECOVERY_UNAVAILABLE.');
    $check($control->apiStopped() && $control->automationStopped(),
        'El fallo combinado retiró una barrera de emergencia.');
    @rmdir($recoveryPath);
    $pdo->exec(
        'ALTER TABLE meli_tokens CHANGE refresh_version_broken refresh_version INT UNSIGNED NOT NULL DEFAULT 0'
    );
    $doubleFailureToken = $tokenRow();
    $check(Crypto::decrypt((string) $doubleFailureToken['access_token_encrypted']) === $oldAccess
        && Crypto::decrypt((string) $doubleFailureToken['refresh_token_encrypted']) === $oldRefresh
        && (int) $doubleFailureToken['refresh_version'] === 7,
        'El fallo combinado alteró el token local anterior.');

    // Un 302 real no puede generar una segunda salida física.
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar redirect OAuth local');
    $redirectNonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('No se pudo reservar un puerto HTTP local.');
    }
    $socketName = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($socketName, ':'), 1);
    $hitsFile = $privateRoot . DIRECTORY_SEPARATOR . 'oauth-redirect-hits.jsonl';
    $router = $privateRoot . DIRECTORY_SEPARATOR . 'oauth-redirect-router.php';
    file_put_contents($router, <<<'PHP'
<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents((string) getenv('HF12_REDIRECT_HITS'), json_encode(['path' => $path]) . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/oauth/token') {
    http_response_code(302);
    header('Location: /oauth-second');
    echo '{}';
    return;
}
header('Content-Type: application/json');
echo '{}';
PHP
    );
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $privateRoot . '/server.out', 'a'], 2 => ['file', $privateRoot . '/server.err', 'a']],
        $pipes,
        $codeRoot,
        array_merge($_ENV, ['HF12_REDIRECT_HITS' => $hitsFile])
    );
    if (!is_resource($server)) {
        throw new RuntimeException('No se pudo iniciar el servidor HTTP local.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                break;
            }
            usleep(20_000);
        }
        $metadata = [
            'source' => EmergencyOAuthRefreshService::SOURCE,
            'job_type' => 'emergency_oauth_refresh',
            'company_id' => 9,
            'meli_account_id' => 3,
            'transport_meli_account_id' => 3,
            'expected_meli_user_id' => '333000',
        ];
        $redirectResult = EmergencyOAuthRefreshTransportContext::run(
            $redirectNonce,
            static fn (): array => ApiExecutionMetadataContext::run(
                $metadata,
                static fn (): array => (new CurlMeliHttpTransport())->request(
                    'POST',
                    'http://127.0.0.1:' . $port . '/oauth/token',
                    ['grant_type' => 'refresh_token'],
                    ['Accept: application/json'],
                    true,
                    ['timeout' => 2, 'connect_timeout' => 1]
                )
            )
        );
        usleep(50_000);
        $hits = is_file($hitsFile) ? file($hitsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [];
        $first = 0;
        $second = 0;
        foreach ($hits as $hitJson) {
            $hit = json_decode($hitJson, true);
            $first += is_array($hit) && ($hit['path'] ?? '') === '/oauth/token' ? 1 : 0;
            $second += is_array($hit) && ($hit['path'] ?? '') === '/oauth-second' ? 1 : 0;
        }
        $check((int) ($redirectResult['status'] ?? 0) === 302 && $first === 1 && $second === 0,
            'OAUTH_REDIRECT_SECOND_HTTP siguió el redirect local.');
        $control->failEmergencyOAuthRefreshAndBlock(
            'hf12-integration',
            'http_redirect_rejected',
            'OAUTH-HF12-REDIRECT',
            302
        );
    } finally {
        proc_terminate($server);
        proc_close($server);
    }

    // Una solicitud ordinaria que encuentra PAUSE_MELI_API en la última
    // barrera conserva la semántica de pausa y no intenta reclamar OAuth.
    try {
        ApiExecutionMetadataContext::run(
            ['source' => 'cron_v3_remote', 'meli_account_id' => 3],
            static fn () => (new MeliEmergencyStopService())->assertTransportAllowed(
                'GET',
                'https://api.mercadolibre.com/users/me'
            )
        );
        $check(false, 'La solicitud ordinaria cruzó PAUSE_MELI_API.');
    } catch (Throwable $blocked) {
        $check(str_contains($blocked->getMessage(), 'bloqueadas por mantenimiento'),
            'La solicitud ordinaria perdió la semántica de pausa controlada.');
    }

    // Si otro proceso retira PAUSE_MELI_API después de reservar OAuth, la
    // última barrera no puede redirigir esa reserva al permiso canario ni
    // permitir que se abra cURL.
    $clearEmergency();
    $control = new EmergencyControlService(null, static fn (): string => $nonceSentinel);
    $control->stopAll('hf12-integration', 'Preparar carrera de desbloqueo API');
    $apiRaceNonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    @unlink($testRoot . DIRECTORY_SEPARATOR . EmergencyControlService::API_MARKER);
    $apiRaceHttp = 0;
    try {
        EmergencyOAuthRefreshTransportContext::run(
            $apiRaceNonce,
            static fn () => ApiExecutionMetadataContext::run(
                [
                    'source' => EmergencyOAuthRefreshService::SOURCE,
                    'job_type' => 'emergency_oauth_refresh',
                    'company_id' => 9,
                    'meli_account_id' => 3,
                    'transport_meli_account_id' => 3,
                    'expected_meli_user_id' => '333000',
                ],
                static function () use (&$apiRaceHttp): void {
                    (new MeliEmergencyStopService())->assertTransportAllowed(
                        'POST',
                        'https://api.mercadolibre.com/oauth/token'
                    );
                    $apiRaceHttp++;
                }
            )
        );
    } catch (Throwable) {
        // La denegación local es el resultado contractual.
    }
    $check($apiRaceHttp === 0, 'BUSINESS_API_UNBLOCK_RACE_HTTP fue distinto de cero.');
    $control->failEmergencyOAuthRefreshAndBlock(
        'hf12-integration',
        'api_barrier_changed',
        'OAUTH-HF12-API-RACE'
    );

    // Crear las superficies observables después del transporte para no activar
    // presupuestos/rhythm en este test y comprobar que permanecen libres de raw secrets.
    $pdo->exec('CREATE TABLE api_budget_windows (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note LONGTEXT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states (scope_key VARCHAR(64) PRIMARY KEY, note LONGTEXT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note LONGTEXT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note LONGTEXT NULL) ENGINE=InnoDB');

    $observable = json_encode((new EmergencyControlService())->status(), JSON_THROW_ON_ERROR)
        . (string) file_get_contents($codeRoot . '/app/Recovery/EmergencyControlKernel.php');
    foreach ([
        'api_request_logs', 'api_error_logs', 'api_budget_windows', 'api_rhythm_states',
        'api_remote_permits', 'api_operation_metrics_hourly', 'system_logs', 'system_execution_attempts',
    ] as $table) {
        $observable .= json_encode($pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $observable .= (string) file_get_contents($item->getPathname());
        }
    }
    $check(!str_contains($observable, 'CLIENT_SECRET_SENTINEL_HF12')
        && !str_contains($observable, $oldRefresh)
        && !str_contains($observable, $newAccess)
        && !str_contains($observable, $newRefresh),
        'RAW_OAUTH_SECRETS_IN_OBSERVABLE_STORAGE fue distinto de cero.');
    $check(!str_contains($observable, $bodySentinel),
        'OAUTH_RESPONSE_BODY_SENTINEL_PERSISTED fue distinto de cero.');
    $check(!str_contains($observable, $nonceSentinel),
        'NONCE_IN_OBSERVABLE_STORAGE fue distinto de cero.');

    $transportSource = (string) file_get_contents($codeRoot . '/app/Services/CurlMeliHttpTransport.php');
    $controlSource = (string) file_get_contents($codeRoot . '/app/Services/EmergencyControlService.php');
    $check(!str_contains($transportSource, 'completeEmergencyOAuthRefreshTransport(')
        && !str_contains($controlSource, 'function completeEmergencyOAuthRefreshTransport('),
        'Permanece la transición OAuth obsoleta anterior a la persistencia.');
    $durableStart = strpos($controlSource, 'private function durableAtomicJson(');
    $durableEnd = strpos($controlSource, '/** @return array<string,mixed> */', (int) $durableStart);
    $durableSource = $durableStart !== false && $durableEnd !== false
        ? substr($controlSource, $durableStart, $durableEnd - $durableStart)
        : '';
    $flushPosition = strpos($durableSource, '@fflush(');
    $fileSyncPosition = strpos($durableSource, '@fsync($handle)');
    $renamePosition = strpos($durableSource, '@rename($temporary, $path)');
    $directorySyncPosition = strpos($durableSource, 'syncDirectoryDurably($directory)');
    $check($flushPosition !== false
        && $fileSyncPosition !== false
        && $renamePosition !== false
        && $directorySyncPosition !== false
        && $flushPosition < $fileSyncPosition
        && $fileSyncPosition < $renamePosition
        && $renamePosition < $directorySyncPosition
        && str_contains($controlSource, "DIRECTORY_SEPARATOR !== '/'")
        && str_contains($controlSource, 'fsync($handle)'),
        'La secuencia durable write/fflush/fsync/rename/directory-fsync no está completa.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo 'PASS emergency_oauth_refresh_real_integration_hf12 ' . $passed . '/' . $total . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'HF12 integration failure (' . get_debug_type($error) . '): ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($savedEnvironment as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
    $removeTree($testRoot);
}
