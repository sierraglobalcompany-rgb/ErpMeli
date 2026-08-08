<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Crypto;
use App\Core\Env;
use App\Core\AppPaths;
use App\Recovery\EmergencyControlKernel;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiGuardService;
use App\Services\CurlMeliHttpTransport;
use App\Services\EmergencyApiCanaryService;
use App\Services\EmergencyCanaryTransportContext;
use App\Services\EmergencyControlService;
use App\Services\ExecutionJournalService;
use App\Services\MeliApiClient;
use App\Services\MeliEmergencyStopService;
use App\Services\MeliHttpTransportInterface;

$root = dirname(__DIR__);
$testDatabase = (string) Env::get('DB_NAME', '');
if (Env::get('HF1_TEST_DB', '') !== '1' || preg_match('/^hf1_test(?:_[a-z0-9]+)?$/', $testDatabase) !== 1) {
    fwrite(STDERR, 'HF1_TEST_DB=1 y una DB_NAME efímera hf1_test* son obligatorios.' . PHP_EOL);
    exit(2);
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

$markerNames = [
    EmergencyControlService::API_MARKER,
    EmergencyControlService::AUTOMATION_MARKER,
    EmergencyControlService::CANARY_MARKER,
];
$configPath = $root . DIRECTORY_SEPARATOR . 'config.env';
$savedConfig = is_file($configPath) ? file_get_contents($configPath) : null;
$savedMarkers = [];
foreach ($markerNames as $name) {
    $path = $root . DIRECTORY_SEPARATOR . $name;
    $savedMarkers[$name] = is_file($path) ? file_get_contents($path) : null;
}
$savedEmergencyStorage = getenv('ERP_EMERGENCY_STORAGE_DIR');
$testPrivateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-hf11-private-' . bin2hex(random_bytes(6));
if (!mkdir($testPrivateDirectory, 0700, true) && !is_dir($testPrivateDirectory)) {
    fwrite(STDERR, 'No fue posible crear el almacenamiento privado efímero.' . PHP_EOL);
    exit(2);
}
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $testPrivateDirectory);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $testPrivateDirectory;

$restoreMarkers = static function () use (
    $root,
    $savedMarkers,
    $configPath,
    $savedConfig,
    $savedEmergencyStorage,
    $testPrivateDirectory
): void {
    foreach ($savedMarkers as $name => $contents) {
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
    if (is_string($savedConfig)) {
        file_put_contents($configPath, $savedConfig, LOCK_EX);
    } else {
        @unlink($configPath);
    }
    if (is_string($savedEmergencyStorage)) {
        putenv('ERP_EMERGENCY_STORAGE_DIR=' . $savedEmergencyStorage);
        $_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $savedEmergencyStorage;
    } else {
        putenv('ERP_EMERGENCY_STORAGE_DIR');
        unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
    }
    if (is_dir($testPrivateDirectory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testPrivateDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($testPrivateDirectory);
    }
};

try {
    if (!is_file($configPath)) {
        file_put_contents($configPath, "HF1_TEST_MODE=1\n", LOCK_EX);
    }
    Database::useProfile('cli');
    $pdo = Database::connection();
    $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($activeDatabase !== $testDatabase) {
        throw new RuntimeException('La conexión no corresponde a la base efímera declarada.');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(160) NOT NULL,
            setting_value MEDIUMTEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            setting_group VARCHAR(80) NOT NULL DEFAULT "test",
            UNIQUE KEY uq_app_settings_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_budget_windows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(40) NOT NULL,
            scope_key VARCHAR(255) NOT NULL,
            meli_account_id BIGINT UNSIGNED NULL,
            endpoint_path VARCHAR(255) NULL,
            job_type VARCHAR(80) NULL,
            window_started_at DATETIME NOT NULL,
            window_seconds INT UNSIGNED NOT NULL DEFAULT 900,
            request_limit INT UNSIGNED NOT NULL DEFAULT 0,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meli_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NOT NULL,
            access_token_encrypted MEDIUMTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uq_meli_tokens_account (meli_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_rhythm_states (
            scope_key VARCHAR(64) NOT NULL PRIMARY KEY,
            generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
            calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,
            block_started_at DATETIME(3) NULL,
            next_allowed_at DATETIME(3) NULL,
            block_pause_until DATETIME(3) NULL,
            last_dispatched_at DATETIME(3) NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_remote_permits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            permit_token CHAR(40) NOT NULL,
            owner_token CHAR(32) NOT NULL,
            generation BIGINT UNSIGNED NOT NULL,
            run_token VARCHAR(100) NULL,
            work_key VARCHAR(120) NULL,
            company_id BIGINT UNSIGNED NULL,
            meli_account_id BIGINT UNSIGNED NULL,
            endpoint_key VARCHAR(120) NOT NULL,
            job_type VARCHAR(80) NOT NULL,
            method VARCHAR(10) NOT NULL,
            status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",
            requested_interval_ms INT UNSIGNED NOT NULL,
            effective_interval_ms INT UNSIGNED NOT NULL,
            blocking_scope VARCHAR(80) NULL,
            http_status SMALLINT UNSIGNED NULL,
            created_at DATETIME(3) NOT NULL,
            dispatched_at DATETIME(3) NULL,
            completed_at DATETIME(3) NULL,
            released_at DATETIME(3) NULL,
            expires_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            UNIQUE KEY uq_hf11_remote_permit_token (permit_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_rhythm_penalties (
            scope_key VARCHAR(180) NOT NULL PRIMARY KEY,
            reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,
            blocked_until DATETIME(3) NULL,
            reduced_until DATETIME(3) NOT NULL,
            reason VARCHAR(80) NOT NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_request_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NULL,
            company_id BIGINT UNSIGNED NULL,
            scope_kind VARCHAR(20) NULL,
            request_id VARCHAR(100) NULL,
            method VARCHAR(10) NULL,
            endpoint_path VARCHAR(255) NULL,
            http_status INT NULL,
            duration_ms INT NULL,
            retry_after_seconds INT NULL,
            attempt INT NULL,
            was_blocked TINYINT(1) NOT NULL DEFAULT 0,
            safe_message VARCHAR(500) NULL,
            diagnostic_id VARCHAR(100) NULL,
            error_type VARCHAR(100) NULL,
            error_code VARCHAR(120) NULL,
            is_retryable TINYINT(1) NOT NULL DEFAULT 0,
            is_app_blocked_signal TINYINT(1) NOT NULL DEFAULT 0,
            outcome_class VARCHAR(80) NULL,
            reached_remote TINYINT(1) NULL,
            actionable TINYINT(1) NOT NULL DEFAULT 0,
            risk_signal TINYINT(1) NOT NULL DEFAULT 0,
            incident_key VARCHAR(120) NULL,
            execution_source VARCHAR(40) NULL,
            job_type VARCHAR(80) NULL,
            source_queue_key VARCHAR(80) NULL,
            source_work_id VARCHAR(100) NULL,
            operation_key VARCHAR(80) NULL,
            load_class VARCHAR(24) NULL,
            workload_units INT NULL,
            wire_bytes BIGINT NULL,
            decoded_bytes BIGINT NULL,
            response_item_count INT NULL,
            response_count_state VARCHAR(20) NULL,
            response_resource_unit VARCHAR(40) NULL,
            fanout_count INT NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_operation_metrics_hourly (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket_started_at DATETIME NOT NULL,
            account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meli_account_id BIGINT UNSIGNED NULL,
            operation_key VARCHAR(80) NOT NULL,
            load_class VARCHAR(24) NOT NULL,
            sample_count INT UNSIGNED NOT NULL DEFAULT 0,
            remote_count INT UNSIGNED NOT NULL DEFAULT 0,
            success_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            total_wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_fanout BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hf11_operation_hour (bucket_started_at,account_scope_key,operation_key,load_class)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_operation_metric_samples (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket_started_at DATETIME NOT NULL,
            account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meli_account_id BIGINT UNSIGNED NULL,
            operation_key VARCHAR(80) NOT NULL,
            load_class VARCHAR(24) NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            response_item_count INT UNSIGNED NOT NULL DEFAULT 0,
            fanout_count INT UNSIGNED NOT NULL DEFAULT 0,
            http_status INT NULL,
            reached_remote TINYINT(1) NOT NULL DEFAULT 0,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Estas tablas son efímeras y deben reproducir la forma productiva real;
    // de otro modo la prueba no observaría la frontera de persistencia.
    $pdo->exec('DROP TABLE IF EXISTS api_error_logs');
    $pdo->exec(
        'CREATE TABLE api_error_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NULL,
            request_id VARCHAR(100) NULL,
            method VARCHAR(10) NULL,
            endpoint_path VARCHAR(255) NULL,
            http_status INT NULL,
            error_code VARCHAR(120) NULL,
            safe_message VARCHAR(500) NULL,
            response_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec('DROP TABLE IF EXISTS system_logs');
    $pdo->exec(
        'CREATE TABLE system_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20) NOT NULL,
            message VARCHAR(500) NOT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meli_accounts (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            company_id BIGINT UNSIGNED NULL,
            account_name VARCHAR(255) NOT NULL DEFAULT "Cuenta QA",
            nickname VARCHAR(255) NULL,
            meli_user_id VARCHAR(80) NULL,
            status VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL DEFAULT "",
            name VARCHAR(255) NOT NULL DEFAULT "",
            role VARCHAR(40) NOT NULL DEFAULT "consulta",
            status TINYINT(1) NOT NULL DEFAULT 1,
            is_temporary TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            last_login_at DATETIME NULL,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_manual_pauses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(20) NOT NULL DEFAULT "app",
            meli_account_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL,
            pause_mode VARCHAR(20) NOT NULL DEFAULT "timed",
            paused_until DATETIME NULL,
            reason VARCHAR(500) NOT NULL DEFAULT "QA",
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resumed_by BIGINT UNSIGNED NULL,
            resumed_at DATETIME NULL,
            resume_reason VARCHAR(500) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_circuit_breakers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NULL,
            endpoint_path VARCHAR(255) NOT NULL DEFAULT "*",
            reason VARCHAR(120) NOT NULL DEFAULT "QA",
            http_status INT NULL,
            status VARCHAR(40) NOT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            blocked_until DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            last_message VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_execution_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_token CHAR(40) NOT NULL,
            component_key VARCHAR(80) NOT NULL,
            manual_campaign_id BIGINT UNSIGNED NULL,
            status ENUM("running","completed","interrupted","failed","skipped") NOT NULL DEFAULT "running",
            started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            heartbeat_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            stop_acquiring_at DATETIME(3) NULL,
            finished_at DATETIME(3) NULL,
            observed_runtime_ms INT UNSIGNED NOT NULL DEFAULT 0,
            approved_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            uncertain_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            end_reason VARCHAR(80) NULL,
            safe_message VARCHAR(500) NULL,
            diagnostic_id VARCHAR(80) NULL,
            UNIQUE KEY uq_system_execution_run_token (run_token),
            KEY idx_system_execution_run_recovery (component_key,status,heartbeat_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_execution_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            system_execution_run_id BIGINT UNSIGNED NOT NULL,
            manual_campaign_id BIGINT UNSIGNED NULL,
            manual_campaign_item_id BIGINT UNSIGNED NULL,
            company_id BIGINT UNSIGNED NULL,
            meli_account_id BIGINT UNSIGNED NULL,
            queue_key VARCHAR(80) NOT NULL,
            source_id VARCHAR(100) NOT NULL,
            operation_key VARCHAR(80) NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
            state ENUM(
                "reserved","local_started","budget_reserved","remote_dispatched",
                "response_received","result_applied","approved","uncertain","failed"
            ) NOT NULL DEFAULT "reserved",
            budget_reserved TINYINT(1) NOT NULL DEFAULT 0,
            reached_remote TINYINT(1) NULL,
            primary_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            derived_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_status SMALLINT UNSIGNED NULL,
            response_fingerprint CHAR(64) NULL,
            checkpoint_json TEXT NULL,
            safe_message VARCHAR(500) NULL,
            diagnostic_id VARCHAR(80) NULL,
            reserved_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            dispatched_at DATETIME(3) NULL,
            response_at DATETIME(3) NULL,
            result_applied_at DATETIME(3) NULL,
            approval_sequence BIGINT UNSIGNED NULL,
            approved_at DATETIME(3) NULL,
            completed_at DATETIME(3) NULL,
            UNIQUE KEY uq_execution_attempt_idempotency (idempotency_key),
            KEY idx_execution_attempt_recovery (state,reserved_at),
            CONSTRAINT fk_hf1_execution_attempt_run FOREIGN KEY (system_execution_run_id)
                REFERENCES system_execution_runs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $settings = [
        'api.guard.enabled' => '0',
        'api.guard.max_retry_attempts' => '1',
        'api.budget.enabled' => '1',
        'api.budget.window_seconds' => '900',
        'api.budget.global_requests_per_15m' => '300',
        'api.budget.endpoint_requests_per_15m' => '50',
        'api.budget.job_type_requests_per_15m' => '80',
        'api.budget.account_requests_per_15m' => '120',
        'api.budget.web_request_api_limit' => '10',
        'api.pacing.enabled' => '0',
        'notifications.api_budget_reserve_percent' => '0',
    ];
    $upsert = $pdo->prepare(
        'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
         VALUES (:setting_key,:setting_value,0,"test")
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0'
    );
    foreach ($settings as $key => $value) {
        $upsert->execute(['setting_key' => $key, 'setting_value' => $value]);
    }
    $pdo->exec('TRUNCATE TABLE api_budget_windows');
    $pdo->exec('TRUNCATE TABLE api_rhythm_states');
    $pdo->exec('TRUNCATE TABLE api_remote_permits');
    $pdo->exec('TRUNCATE TABLE api_rhythm_penalties');
    $pdo->exec('TRUNCATE TABLE meli_tokens');
    $pdo->exec('TRUNCATE TABLE meli_accounts');
    $pdo->exec('TRUNCATE TABLE schema_migrations');
    $pdo->exec('TRUNCATE TABLE api_manual_pauses');
    $pdo->exec('TRUNCATE TABLE api_circuit_breakers');
    $pdo->exec('TRUNCATE TABLE api_request_logs');
    $pdo->exec('TRUNCATE TABLE api_error_logs');
    $pdo->exec('TRUNCATE TABLE system_logs');
    $pdo->exec('TRUNCATE TABLE api_operation_metrics_hourly');
    $pdo->exec('TRUNCATE TABLE api_operation_metric_samples');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE system_execution_attempts');
    $pdo->exec('TRUNCATE TABLE system_execution_runs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO meli_accounts (id,company_id,account_name,nickname,meli_user_id,status)
                VALUES (1,1,'Cuenta QA','Seller QA','1','conectado')");
    $token = Crypto::encrypt('hf1-fake-token-never-sent');
    $tokenInsert = $pdo->prepare(
        'INSERT INTO meli_tokens (meli_account_id,access_token_encrypted,expires_at)
         VALUES (1,:token,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    );
    $tokenInsert->execute(['token' => $token]);

    $fakeTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;
        /** @var array<string,mixed> */
        public array $observedMetadata = [];

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            // Reproduce las dos barreras del transporte real sin abrir cURL.
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->observedMetadata = ApiExecutionMetadataContext::current();
            $this->calls++;
            $result = [
                'status' => 200,
                'headers' => [],
                'body' => ['id' => 1],
                'curl_error' => '',
                'duration_ms' => 1,
                'wire_bytes' => 8,
                'decoded_bytes' => 8,
            ];
            (new EmergencyControlService())->completeCanaryTransport(true, 200);
            return $result;
        }
    };
    $client = new MeliApiClient(1, $fakeTransport);
    $invoke = static function (array $meta) use ($client): array {
        return ApiExecutionMetadataContext::run(
            $meta,
            static fn (): array => $client->get('/users/me', [], $meta)
        );
    };

    $control = new EmergencyControlService();
    $control->stopAll('hf1-test', 'Integración local con transporte falso');
    $control->prepareApiStart('hf1-test', 'Canario manual local');
    $manualNonce = $control->reserveApiCanary(1, '1');
    $callsBeforeManual = $fakeTransport->calls;
    $manualResult = EmergencyCanaryTransportContext::run(
        $manualNonce,
        static fn (): array => $invoke([
            'source' => 'manual_emergency_canary',
            'job_type' => 'emergency_canary',
            'meli_account_id' => 1,
            'expected_meli_user_id' => '1',
        ])
    );
    $control->completeApiCanarySuccess($manualNonce, 1, (string) ($manualResult['id'] ?? ''));
    $canary = $control->status()['canary'] ?? null;
    $check($fakeTransport->calls === $callsBeforeManual + 1 && ($manualResult['id'] ?? null) === 1,
        'REAL_CLIENT_MANUAL_CANARY_TEST no alcanzó exactamente una vez el transporte falso.');
    $check(is_array($canary) && (int) ($canary['used_calls'] ?? 0) === 1
        && ($canary['last_result'] ?? '') === 'success'
        && !empty($canary['identity_verified']),
        'El permiso canario no quedó consumido con resultado conocido.');

    // El orquestador HF1.1 debe usar el mismo cliente real y una sola salida,
    // pero validar además que /users/me pertenece a la cuenta reservada.
    $control->prepareApiStart('hf1-test', 'Canario HF1.1 correcto');
    $callsBeforeService = $fakeTransport->calls;
    $service = new EmergencyApiCanaryService(
        $control,
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $fakeTransport)
    );
    $preflightMethod = (new ReflectionClass($service))->getMethod('preflight');
    $preflightResult = $preflightMethod->invoke($service, 1);
    $check(is_array($preflightResult)
        && !array_key_exists('access_token_encrypted', $preflightResult)
        && !str_contains(json_encode($preflightResult) ?: '', $token),
        'CANARY_PREFLIGHT_RETURNS_ENCRYPTED_TOKEN devolvió material cifrado al orquestador.');
    $serviceResult = $service->run(1, 'hf1-test');
    $serviceCanary = $control->status()['canary'] ?? null;
    $check($fakeTransport->calls === $callsBeforeService + 1
        && (int) ($serviceResult['account_id'] ?? 0) === 1
        && is_array($serviceCanary)
        && ($serviceCanary['last_result'] ?? '') === 'success'
        && !empty($serviceCanary['identity_verified']),
        'HF11_IDENTITY_BOUND_SUCCESS no certificó una sola llamada ligada a identidad.');
    $control->confirmApiStart('hf1-test', 'Identidad confirmada');
    $check(!$control->apiStopped() && ($control->status()['canary'] ?? null) === null,
        'CONFIRM_API no exigió y consumió la evidencia de identidad exitosa.');

    // Gate previo: con automatización ya activa, el orquestador debe fallar
    // antes de reservar o construir una salida física.
    $control->stopAll('hf11-test', 'Automation gate previo');
    $control->prepareApiStart('hf11-test', 'Automation gate previo');
    @unlink($root . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER);
    $callsBeforeRunning = $fakeTransport->calls;
    try {
        $service->run(1, 'hf11-test');
        $check(false, 'AUTOMATION_RUNNING_FROM_BEFORE permitió el canario.');
    } catch (RuntimeException) {
        $check($fakeTransport->calls === $callsBeforeRunning,
            'AUTOMATION_RUNNING_FROM_BEFORE cruzó la frontera HTTP.');
    }

    // Carrera obligatoria: la reserva se creó con Automation Stop, pero el
    // marcador desaparece antes de la barrera física. assertTransportAllowed
    // debe negar el claim antes de que el fake incremente su contador.
    $control->stopAll('hf11-test', 'Automation race físico');
    $control->prepareApiStart('hf11-test', 'Automation race físico');
    $automationRaceNonce = $control->reserveApiCanary(1, '1');
    @unlink($root . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER);
    $callsBeforeAutomationRace = $fakeTransport->calls;
    try {
        EmergencyCanaryTransportContext::run(
            $automationRaceNonce,
            static fn (): array => ApiExecutionMetadataContext::run(
                [
                    'source' => 'manual_emergency_canary',
                    'meli_account_id' => 1,
                    'transport_meli_account_id' => 1,
                    'expected_meli_user_id' => '1',
                ],
                static fn (): array => $fakeTransport->request(
                    'GET',
                    'https://api.mercadolibre.com/users/me',
                    [],
                    ['Accept: application/json'],
                    false,
                    ['timeout' => 1, 'connect_timeout' => 1]
                )
            )
        );
        $check(false, 'CANARY_CLAIM_AFTER_AUTOMATION_RESUME fue autorizado.');
    } catch (RuntimeException) {
        $automationRaceState = $control->status()['canary'] ?? null;
        $check($fakeTransport->calls === $callsBeforeAutomationRace
            && $control->apiStopped()
            && !$control->automationStopped()
            && is_array($automationRaceState)
            && ($automationRaceState['last_result'] ?? '') === 'failed'
            && ($automationRaceState['failure_class'] ?? '') === 'automation_resumed_before_transport'
            && (int) ($automationRaceState['used_calls'] ?? -1) === 0,
            'CANARY_HTTP_AFTER_AUTOMATION_RESUME no quedó en cero o perdió la evidencia segura.');
    }
    $control->stopAll('hf11-test', 'Restaurar estado después de carrera');

    // El secreto de reserva existe durante unos milisegundos exclusivamente en
    // almacenamiento privado y en el contexto privado del transporte. Nunca
    // entra al metadata observable, estado público, telemetría, logs o HTML.
    $nonceSentinel = 'NONCE_SENTINEL_' . bin2hex(random_bytes(18));
    $privateSentinelSeen = false;
    $publicSentinelSeenBeforeTransport = false;
    $sentinelControl = new EmergencyControlService(
        null,
        static fn (): string => $nonceSentinel
    );
    $sentinelControl->stopAll('hf1-test', 'Persistencia privada del nonce');
    $sentinelControl->prepareApiStart('hf1-test', 'Persistencia privada del nonce');
    $pdo->prepare("UPDATE app_settings SET setting_value='1' WHERE setting_key='api.guard.enabled'")->execute();
    $sentinelService = new EmergencyApiCanaryService(
        $sentinelControl,
        static function (int $accountId) use (
            $testPrivateDirectory,
            $root,
            $nonceSentinel,
            &$privateSentinelSeen,
            &$publicSentinelSeenBeforeTransport,
            $fakeTransport
        ): MeliApiClient {
            $privateContents = @file_get_contents(
                $testPrivateDirectory . DIRECTORY_SEPARATOR . 'canary-reservation.json'
            );
            $publicContents = @file_get_contents(
                $root . DIRECTORY_SEPARATOR . EmergencyControlService::CANARY_MARKER
            );
            $privateSentinelSeen = is_string($privateContents)
                && str_contains($privateContents, $nonceSentinel);
            $publicSentinelSeenBeforeTransport = is_string($publicContents)
                && str_contains($publicContents, $nonceSentinel);
            return new MeliApiClient($accountId, $fakeTransport);
        }
    );
    $sentinelService->run(1, 'hf1-test');
    $sentinelStatus = json_encode($sentinelControl->status(), JSON_UNESCAPED_SLASHES) ?: '';
    $sentinelPublicMarker = @file_get_contents(
        $root . DIRECTORY_SEPARATOR . EmergencyControlService::CANARY_MARKER
    );
    $sentinelPrivateMarker = @file_get_contents(
        $testPrivateDirectory . DIRECTORY_SEPARATOR . 'canary-reservation.json'
    );
    $databaseSentinelHits = 0;
    $columns = $pdo->prepare(
        "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json',
                             'binary','varbinary','tinyblob','blob','mediumblob','longblob')"
    );
    $columns->execute();
    foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $tableName = str_replace('`', '``', (string) $column['TABLE_NAME']);
        $columnName = str_replace('`', '``', (string) $column['COLUMN_NAME']);
        $needle = $pdo->quote('%' . $nonceSentinel . '%');
        $databaseSentinelHits += (int) $pdo->query(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE CAST(`{$columnName}` AS CHAR) LIKE {$needle}"
        )->fetchColumn();
    }
    $fileSentinelHits = 0;
    foreach ([
        $root . DIRECTORY_SEPARATOR . EmergencyControlService::CANARY_MARKER,
        $root . DIRECTORY_SEPARATOR . EmergencyControlService::LAST_CHANGE_MARKER,
        $testPrivateDirectory . DIRECTORY_SEPARATOR . 'emergency-audit.jsonl',
        $testPrivateDirectory . DIRECTORY_SEPARATOR . 'canary-reservation.json',
    ] as $candidate) {
        $contents = @file_get_contents($candidate);
        if (is_string($contents) && str_contains($contents, $nonceSentinel)) {
            $fileSentinelHits++;
        }
    }
    $kernelSource = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $check($privateSentinelSeen && !$publicSentinelSeenBeforeTransport,
        'CANARY_NONCE_PRIVATE_STORAGE_ONLY no aisló el secreto antes del transporte.');
    $check(!array_key_exists('canary_reservation_nonce', $fakeTransport->observedMetadata)
        && !str_contains(json_encode($fakeTransport->observedMetadata) ?: '', $nonceSentinel),
        'CANARY_NONCE_IN_OBSERVABLE_METADATA filtró el secreto al cliente/guard/telemetría.');
    $check($databaseSentinelHits === 0 && $fileSentinelHits === 0,
        'CANARY_NONCE_IN_OBSERVABLE_LOGS encontró el secreto en persistencia observable.');
    $check(!str_contains($sentinelStatus, $nonceSentinel)
        && (!is_string($sentinelPublicMarker) || !str_contains($sentinelPublicMarker, $nonceSentinel))
        && !is_string($sentinelPrivateMarker),
        'CANARY_NONCE_IN_PUBLIC_STATUS expuso el secreto o no eliminó la reserva privada.');
    $check(!str_contains($kernelSource, 'EmergencyCanaryTransportContext')
        && !str_contains($kernelSource, $nonceSentinel),
        'CANARY_NONCE_IN_HTML permitió que la capa de presentación acceda al secreto.');
    $sentinelControl->confirmApiStart('hf1-test', 'Prueba de persistencia completada');

    // Un body remoto no-2xx puede clasificarse únicamente en memoria. Ni su
    // mensaje, error o campos arbitrarios pueden cruzar observabilidad.
    $bodySentinel = 'BODY_SENTINEL_' . bin2hex(random_bytes(18));
    $bodyTransport = new class($bodySentinel) implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function __construct(private string $sentinel)
        {
        }
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            (new EmergencyControlService())->completeCanaryTransport(false, 401);
            return [
                'status' => 401,
                'headers' => [],
                'body' => [
                    'message' => $this->sentinel,
                    'error' => $this->sentinel,
                    'private_field' => $this->sentinel,
                ],
                'curl_error' => '',
                'duration_ms' => 1,
                'wire_bytes' => 128,
                'decoded_bytes' => 128,
            ];
        }
    };
    $pdo->exec('TRUNCATE TABLE api_budget_windows');
    $pdo->exec('TRUNCATE TABLE api_rhythm_states');
    $pdo->exec('TRUNCATE TABLE api_remote_permits');
    $pdo->exec('TRUNCATE TABLE api_rhythm_penalties');
    $pdo->exec('TRUNCATE TABLE api_request_logs');
    $pdo->exec('TRUNCATE TABLE api_error_logs');
    $pdo->exec('TRUNCATE TABLE system_logs');
    $pdo->exec('TRUNCATE TABLE api_operation_metrics_hourly');
    $pdo->exec('TRUNCATE TABLE api_operation_metric_samples');
    $bodyControl = new EmergencyControlService();
    $bodyControl->stopAll('hf11-test', 'Aislar body no aprobado');
    $bodyControl->prepareApiStart('hf11-test', 'Aislar body no aprobado');
    $bodyService = new EmergencyApiCanaryService(
        $bodyControl,
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $bodyTransport)
    );
    try {
        $bodyService->run(1, 'hf11-test');
        $check(false, 'CANARY_BODY_401 no terminó como fallo.');
    } catch (RuntimeException) {
        $bodyCanary = $bodyControl->status()['canary'] ?? null;
        $check($bodyTransport->calls === 1
            && $bodyControl->apiStopped()
            && is_array($bodyCanary)
            && ($bodyCanary['last_result'] ?? '') === 'failed',
            'CANARY_BODY_401 no restauró PAUSE_MELI_API con evidencia fallida.');
    }

    $bodyDatabaseHits = 0;
    $bodyColumns = $pdo->prepare(
        "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json',
                             'binary','varbinary','tinyblob','blob','mediumblob','longblob')"
    );
    $bodyColumns->execute();
    foreach ($bodyColumns->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $tableName = str_replace('`', '``', (string) $column['TABLE_NAME']);
        $columnName = str_replace('`', '``', (string) $column['COLUMN_NAME']);
        $needle = $pdo->quote('%' . $bodySentinel . '%');
        $bodyDatabaseHits += (int) $pdo->query(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE CAST(`{$columnName}` AS CHAR) LIKE {$needle}"
        )->fetchColumn();
    }
    $bodyFileHits = 0;
    $bodyFiles = [
        $root . DIRECTORY_SEPARATOR . EmergencyControlService::CANARY_MARKER,
        $root . DIRECTORY_SEPARATOR . EmergencyControlService::LAST_CHANGE_MARKER,
        $testPrivateDirectory . DIRECTORY_SEPARATOR . 'emergency-audit.jsonl',
        $testPrivateDirectory . DIRECTORY_SEPARATOR . 'canary-reservation.json',
        AppPaths::storage('logs/app.log'),
    ];
    foreach ($bodyFiles as $candidate) {
        $contents = @file_get_contents($candidate);
        if (is_string($contents) && str_contains($contents, $bodySentinel)) {
            $bodyFileHits++;
        }
    }
    $bodyStatus = json_encode($bodyControl->status(), JSON_UNESCAPED_SLASHES) ?: '';
    $bodyErrorRow = $pdo->query(
        "SELECT error_code,response_json FROM api_error_logs
         WHERE meli_account_id=1 AND method='GET' AND endpoint_path='/users/me'
         ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $check($bodyDatabaseHits === 0
        && $bodyFileHits === 0
        && !str_contains($bodyStatus, $bodySentinel)
        && !str_contains($kernelSource, $bodySentinel),
        'CANARY_RESPONSE_BODY_SENTINEL_PERSISTED encontró contenido remoto fuera de memoria.');
    $check(($bodyErrorRow['error_code'] ?? '') === 'CANARY_REMOTE_401'
        && ($bodyErrorRow['response_json'] ?? null) === null,
        'CANARY_ERROR_RESPONSE_JSON_EMPTY no conservó solo código local normalizado y body NULL.');

    // Token vencido/próximo a vencer: el orquestador falla antes de construir
    // transporte; por contrato tampoco existe llamada a refreshOAuthToken.
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND) WHERE meli_account_id=1");
    $control->stopApi('hf1-test', 'Preparar autorización vencida');
    $control->prepareApiStart('hf1-test', 'Autorización vencida');
    $callsBeforeExpired = $fakeTransport->calls;
    try {
        $service->run(1, 'hf1-test');
        $check(false, 'EXPIRED_TOKEN_CAUSES_ZERO_HTTP permitió ejecutar el canario.');
    } catch (RuntimeException $expired) {
        $check($fakeTransport->calls === $callsBeforeExpired
            && str_contains($expired->getMessage(), 'autorización'),
            'EXPIRED_TOKEN_CAUSES_ZERO_HTTP cruzó la frontera del transporte.');
    }
    $serviceSource = (string) file_get_contents($root . '/app/Services/EmergencyApiCanaryService.php');
    $check(!str_contains($serviceSource, 'refreshOAuthToken(')
        && !str_contains($serviceSource, "'/oauth/token'")
        && !str_contains($serviceSource, '"/oauth/token"'),
        'TOKEN_REFRESH_IS_NEVER_CALLED encontró una ruta de refresh en el orquestador canario.');
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE meli_account_id=1");

    // Una solicitud ajena o de otra cuenta no puede consumir la reserva.
    $control->prepareApiStart('hf1-test', 'Reserva ligada a operación');
    $boundNonce = $control->reserveApiCanary(1, '1');
    foreach ([
        ['source' => 'cron_v3_remote', 'meli_account_id' => 1, 'transport_meli_account_id' => 1, 'expected_meli_user_id' => '1'],
        ['source' => 'manual_emergency_canary', 'meli_account_id' => 2, 'transport_meli_account_id' => 2, 'expected_meli_user_id' => '1'],
    ] as $index => $wrongMetadata) {
        try {
            EmergencyCanaryTransportContext::run(
                $boundNonce,
                static fn () => ApiExecutionMetadataContext::run(
                    $wrongMetadata,
                    static fn () => (new MeliEmergencyStopService())->assertTransportAllowed('GET', 'https://api.mercadolibre.com/users/me')
                )
            );
            $check(false, $index === 0
                ? 'UNRELATED_REQUEST_CANNOT_CONSUME_CANARY fue autorizada.'
                : 'WRONG_ACCOUNT_CANNOT_CONSUME_CANARY fue autorizada.');
        } catch (Throwable) {
            $reserved = $control->status()['canary'] ?? null;
            $check(is_array($reserved)
                && ($reserved['state'] ?? '') === 'reserved'
                && (int) ($reserved['used_calls'] ?? -1) === 0,
                $index === 0
                    ? 'UNRELATED_REQUEST_CANNOT_CONSUME_CANARY gastó la reserva.'
                    : 'WRONG_ACCOUNT_CANNOT_CONSUME_CANARY gastó la reserva.');
        }
    }

    // Un 200 de otro seller es fallo: se restaura PAUSE_MELI_API y se conserva
    // la evidencia sin cuerpo ni Authorization.
    $wrongIdentityTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            (new EmergencyControlService())->completeCanaryTransport(true, 200);
            return ['status' => 200, 'headers' => [], 'body' => ['id' => 999], 'curl_error' => '', 'duration_ms' => 1, 'wire_bytes' => 10, 'decoded_bytes' => 10];
        }
    };
    $control->prepareApiStart('hf1-test', 'Identidad incorrecta');
    $wrongService = new EmergencyApiCanaryService(
        $control,
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $wrongIdentityTransport)
    );
    try {
        $wrongService->run(1, 'hf1-test');
        $check(false, 'USERS_ME_IDENTITY_MISMATCH_FAILS aceptó otro seller.');
    } catch (RuntimeException) {
        $failed = $control->status()['canary'] ?? null;
        $encoded = is_array($failed) ? json_encode($failed) : '';
        $check($wrongIdentityTransport->calls === 1
            && $control->apiStopped()
            && is_array($failed)
            && ($failed['last_result'] ?? '') === 'failed'
            && ($failed['failure_class'] ?? '') === 'account_identity_mismatch'
            && !str_contains((string) $encoded, 'Authorization')
            && !str_contains((string) $encoded, 'hf1-fake-token-never-sent')
            && !array_key_exists('body', $failed),
            'FAILED_CANARY_PRESERVES_EVIDENCE_AFTER_API_BLOCK no conservó evidencia segura.');
        try {
            $control->confirmApiStart('hf1-test', 'No permitido');
            $check(false, 'CONFIRM_API aceptó un 200 con identidad incorrecta.');
        } catch (RuntimeException) {
            $check($control->apiStopped(), 'CONFIRM_API retiró el bloqueo después de un fallo.');
        }
    }

    // Un 200 sin id también debe fallar, no basta el status HTTP.
    $missingIdentityTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;
        public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
        {
            (new MeliEmergencyStopService())->assertTransportAllowed($method, $url);
            $this->calls++;
            (new EmergencyControlService())->completeCanaryTransport(true, 200);
            return ['status' => 200, 'headers' => [], 'body' => [], 'curl_error' => '', 'duration_ms' => 1, 'wire_bytes' => 2, 'decoded_bytes' => 2];
        }
    };
    $control->prepareApiStart('hf1-test', 'Respuesta sin identidad');
    $missingService = new EmergencyApiCanaryService(
        $control,
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $missingIdentityTransport)
    );
    try {
        $missingService->run(1, 'hf1-test');
        $check(false, 'HTTP_200_WITHOUT_ID_FAILS aceptó una respuesta incompleta.');
    } catch (RuntimeException) {
        $missing = $control->status()['canary'] ?? null;
        $check($missingIdentityTransport->calls === 1
            && $control->apiStopped()
            && is_array($missing)
            && ($missing['failure_class'] ?? '') === 'response_schema_invalid',
            'HTTP_200_WITHOUT_ID_FAILS no bloqueó con diagnóstico seguro.');
    }

    // Contrato del transporte productivo: la primera salida consume el único
    // permiso canario justo antes de cURL; una segunda salida queda bloqueada.
    $control->stopAll('hf1-test', 'Preparar contrato canario del transporte real');
    $control->prepareApiStart('hf1-test', 'Canario local del transporte cURL');
    $transportNonce = $control->reserveApiCanary(1, '1');
    $transportMetadata = [
        'source' => 'manual_emergency_canary',
        'meli_account_id' => 1,
        'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '1',
    ];
    $realTransport = new CurlMeliHttpTransport();
    EmergencyCanaryTransportContext::run(
        $transportNonce,
        static fn (): array => ApiExecutionMetadataContext::run($transportMetadata, static fn (): array => $realTransport->request(
            'GET',
            'http://127.0.0.1:9/users/me',
            [],
            ['Accept: application/json'],
            false,
            ['timeout' => 1, 'connect_timeout' => 1]
        ))
    );
    $realCanary = $control->status()['canary'] ?? null;
    $check(is_array($realCanary) && (int) ($realCanary['used_calls'] ?? 0) === 1,
        'REAL_TRANSPORT_CANARY_GUARD no consumió el permiso en CurlMeliHttpTransport.');
    try {
        EmergencyCanaryTransportContext::run(
            $transportNonce,
            static fn (): array => ApiExecutionMetadataContext::run($transportMetadata, static fn (): array => $realTransport->request(
                'GET',
                'http://127.0.0.1:9/users/me',
                [],
                ['Accept: application/json'],
                false,
                ['timeout' => 1, 'connect_timeout' => 1]
            ))
        );
        $check(false, 'SINGLE_CANARY_TRANSPORT_ENFORCED permitió una segunda salida.');
    } catch (Throwable $blocked) {
        $check(str_contains($blocked->getMessage(), 'canaria ya fue utilizada'),
            'SINGLE_CANARY_TRANSPORT_ENFORCED no bloqueó por permiso ya consumido.');
    }

    // Un redirect HTTP no puede transformar la única llamada autorizada en
    // una segunda salida física. A responde 302 hacia B; B debe quedar en cero.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('No fue posible reservar el puerto local para probar redirects.');
    }
    $socketName = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $redirectPort = (int) substr(strrchr($socketName, ':'), 1);
    $redirectHits = $testPrivateDirectory . DIRECTORY_SEPARATOR . 'redirect-hits.jsonl';
    $redirectRouter = $testPrivateDirectory . DIRECTORY_SEPARATOR . 'redirect-router.php';
    file_put_contents($redirectRouter, <<<'PHP'
<?php
$hits = getenv('HF11_REDIRECT_HITS');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents($hits, json_encode(['path' => $path]) . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/users/me') {
    http_response_code(302);
    header('Location: /redirect-target');
    echo '{}';
    return;
}
header('Content-Type: application/json');
echo json_encode(['id' => 1]);
PHP
    );
    $redirectProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $redirectPort, $redirectRouter],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $testPrivateDirectory . DIRECTORY_SEPARATOR . 'redirect-out.log', 'a'],
            2 => ['file', $testPrivateDirectory . DIRECTORY_SEPARATOR . 'redirect-error.log', 'a'],
        ],
        $redirectPipes,
        $root,
        array_merge($_ENV, ['HF11_REDIRECT_HITS' => $redirectHits])
    );
    if (!is_resource($redirectProcess)) {
        throw new RuntimeException('No fue posible iniciar el servidor HTTP local de redirects.');
    }
    if (isset($redirectPipes[0]) && is_resource($redirectPipes[0])) {
        fclose($redirectPipes[0]);
    }
    try {
        $serverReady = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $redirectPort, $probeError, $probeMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $serverReady = true;
                break;
            }
            usleep(20_000);
        }
        if (!$serverReady) {
            throw new RuntimeException('El servidor HTTP local no quedó disponible.');
        }
        $control->stopAll('hf1-test', 'Preparar redirect canario local');
        $control->prepareApiStart('hf1-test', 'Redirect canario local');
        $redirectNonce = $control->reserveApiCanary(1, '1');
        $redirectMetadata = [
            'source' => 'manual_emergency_canary',
            'meli_account_id' => 1,
            'transport_meli_account_id' => 1,
            'expected_meli_user_id' => '1',
        ];
        $redirectResult = EmergencyCanaryTransportContext::run(
            $redirectNonce,
            static fn (): array => ApiExecutionMetadataContext::run(
                $redirectMetadata,
                static fn (): array => (new CurlMeliHttpTransport())->request(
                    'GET',
                    'http://127.0.0.1:' . $redirectPort . '/users/me',
                    [],
                    ['Accept: application/json'],
                    false,
                    ['timeout' => 2, 'connect_timeout' => 1]
                )
            )
        );
        usleep(50_000);
        $hitRows = is_file($redirectHits)
            ? array_values(array_filter(file($redirectHits, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []))
            : [];
        $firstHits = 0;
        $secondHits = 0;
        foreach ($hitRows as $hitRow) {
            $hit = json_decode($hitRow, true);
            $firstHits += is_array($hit) && ($hit['path'] ?? '') === '/users/me' ? 1 : 0;
            $secondHits += is_array($hit) && ($hit['path'] ?? '') === '/redirect-target' ? 1 : 0;
        }
        $check((int) ($redirectResult['status'] ?? 0) === 302
            && $firstHits === 1
            && $secondHits === 0,
            'CANARY_REDIRECT_SECOND_HTTP siguió el redirect o no alcanzó exactamente una vez A.');
    } finally {
        proc_terminate($redirectProcess);
        proc_close($redirectProcess);
    }

    $budgetCount = static fn (): int => (int) $pdo->query(
        'SELECT COALESCE(SUM(request_count),0) FROM api_budget_windows'
    )->fetchColumn();

    $callsBeforeCron = $fakeTransport->calls;
    $budgetBeforeCron = $budgetCount();
    ApiExecutionMetadataContext::resetRemoteDispatchCount();
    try {
        $invoke(['source' => 'cron_v3_remote', 'job_type' => 'order_exact']);
        $check(false, 'REAL_CLIENT_CRON_BLOCK_TEST no bloqueó el cliente real.');
    } catch (RuntimeException $blocked) {
        $check(str_contains($blocked->getMessage(), 'automatización se detuvo')
            && $fakeTransport->calls === $callsBeforeCron,
            'REAL_CLIENT_CRON_BLOCK_TEST cruzó la frontera del transporte.');
    }
    $check($budgetCount() === $budgetBeforeCron,
        'La reserva de presupuesto del Cron bloqueado no fue devuelta.');
    $check(ApiExecutionMetadataContext::remoteDispatchCount() === 0,
        'El Cron bloqueado fue contabilizado como transporte despachado.');

    // Carrera determinista: la protección inicial pasa sin marcador; el stop
    // aparece antes de invocar el cliente real, cuya segunda barrera debe ganar.
    $control->startApiWithoutCanary('hf1-test', 'Preparar carrera local');
    @unlink($root . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER);
    $journal = new ExecutionJournalService();
    $run = $journal->begin('hf1_real_journal_race', null, 20000);
    $executionGeneration = 7;
    $executionAttemptId = $journal->reserve((int) $run['id'], [
        'company_id' => 1,
        'meli_account_id' => 1,
        'queue_key' => 'order_exact',
        'source_id' => 'hf1-race-order',
        'operation_key' => 'order_exact',
        'lease_generation' => $executionGeneration,
    ]);
    $journal->localStarted($executionAttemptId);
    (new ApiGuardService())->assertAllowed(1, 'GET', '/users/me', ['source' => 'cron_v3_remote']);
    $control->stopAutomation('hf1-test', 'Stop entre guard inicial y transporte');
    $callsBeforeRace = $fakeTransport->calls;
    $budgetBeforeRace = $budgetCount();
    ApiExecutionMetadataContext::resetRemoteDispatchCount();
    try {
        $invoke([
            'source' => 'cron_v3_remote',
            'job_type' => 'order_exact',
            'execution_attempt_id' => $executionAttemptId,
            'execution_lease_generation' => $executionGeneration,
        ]);
        $check(false, 'REAL_CLIENT_RACE_TEST no bloqueó el cliente real.');
    } catch (RuntimeException $blocked) {
        $check(str_contains($blocked->getMessage(), 'automatización se detuvo')
            && $fakeTransport->calls === $callsBeforeRace,
            'REAL_CLIENT_RACE_TEST alcanzó el transporte falso.');
    }
    $activePermits = 0;
    try {
        $activePermits = (int) $pdo->query(
            "SELECT COUNT(*) FROM api_remote_permits WHERE status IN ('reserved','dispatched')"
        )->fetchColumn();
    } catch (Throwable) {
        // El contexto V3 usa el rate gate externo y no crea un permiso interno.
        $activePermits = 0;
    }
    $check($budgetCount() === $budgetBeforeRace,
        'REAL_CLIENT_RACE_TEST no compensó el presupuesto pre-transporte.');
    $check($activePermits === 0,
        'REAL_CLIENT_RACE_TEST dejó un permiso de ritmo interno activo.');
    $check(ApiExecutionMetadataContext::remoteDispatchCount() === 0,
        'REAL_CLIENT_RACE_TEST marcó dispatchBoundaryCrossed=true o un journal remoto inexistente.');
    $journalState = $pdo->prepare(
        'SELECT state,budget_reserved,reached_remote,dispatched_at,response_at
         FROM system_execution_attempts WHERE id=?'
    );
    $journalState->execute([$executionAttemptId]);
    $journalRow = $journalState->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(($journalRow['state'] ?? '') === 'local_started'
        && (int) ($journalRow['budget_reserved'] ?? 1) === 0
        && (int) ($journalRow['reached_remote'] ?? 1) === 0
        && ($journalRow['dispatched_at'] ?? null) === null
        && ($journalRow['response_at'] ?? null) === null,
        'REAL_JOURNAL_RACE_STATE dejó una reserva o frontera remota pegada.');
    $journal->finish((int) $run['id'], (float) $run['started_at'], 'blocked_before_remote');

    // Cada autoridad DB del diagnóstico se comprueba por separado. Una tabla
    // opcional ausente no puede degradar MariaDB completa ni ocultar checks posteriores.
    $migrationInsert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
    foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migration) {
        $migrationInsert->execute([basename($migration)]);
    }
    $pdo->prepare(
        "UPDATE app_settings SET setting_value='1' WHERE setting_key='api.guard.enabled'"
    )->execute();
    $diagnostic = $control->diagnoseApiReactivation();
    $check(($diagnostic['checks']['database']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['migrations']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['accounts_metadata']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['token_metadata']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['manual_pause_state']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['circuit_state']['status'] ?? '') === 'PASS'
        && ($diagnostic['checks']['api_guard']['status'] ?? '') === 'PASS',
        'El diagnóstico no aprobó autoridades locales válidas de forma independiente.');
    $pdo->exec('RENAME TABLE api_manual_pauses TO api_manual_pauses_hf1_missing');
    try {
        $isolated = $control->diagnoseApiReactivation();
        $check(($isolated['checks']['database']['status'] ?? '') === 'PASS'
            && ($isolated['checks']['manual_pause_state']['status'] ?? '') === 'UNKNOWN'
            && ($isolated['checks']['circuit_state']['status'] ?? '') === 'PASS'
            && ($isolated['ready'] ?? true) === false,
            'Un fallo de api_manual_pauses contaminó otros checks o UNKNOWN no bloqueó.');
        $kernelReflection = new ReflectionClass(EmergencyControlKernel::class);
        $kernel = $kernelReflection->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'control' => $control] as $propertyName => $propertyValue) {
            $property = $kernelReflection->getProperty($propertyName);
            $property->setValue($kernel, $propertyValue);
        }
        $readinessMethod = $kernelReflection->getMethod('readiness');
        $readiness = $readinessMethod->invoke($kernel);
        $check(($readiness['ready'] ?? true) === false
            && str_contains((string) ($readiness['message'] ?? ''), 'api_manual_pauses')
            && str_contains((string) ($readiness['message'] ?? ''), 'EMERGENCY_PRECONDITION_UNKNOWN'),
            'READINESS_UNKNOWN_IS_ACTIONABLE no identificó la autoridad UNKNOWN ni su código seguro.');
    } finally {
        $pdo->exec('RENAME TABLE api_manual_pauses_hf1_missing TO api_manual_pauses');
    }

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo 'PASS emergency_real_meli_client_integration_2351 ' . $passed . '/' . $total . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'HF1 integration failure (' . get_debug_type($error) . '): ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $restoreMarkers();
}
