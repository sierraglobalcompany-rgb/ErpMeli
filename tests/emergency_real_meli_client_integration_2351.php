<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Crypto;
use App\Core\Env;
use App\Recovery\EmergencyControlKernel;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiGuardService;
use App\Services\CurlMeliHttpTransport;
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

$restoreMarkers = static function () use ($root, $savedMarkers, $configPath, $savedConfig): void {
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
        'CREATE TABLE IF NOT EXISTS meli_accounts (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            company_id BIGINT UNSIGNED NULL,
            account_name VARCHAR(255) NOT NULL DEFAULT "Cuenta QA",
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
    $pdo->exec('TRUNCATE TABLE meli_tokens');
    $pdo->exec('TRUNCATE TABLE meli_accounts');
    $pdo->exec('TRUNCATE TABLE schema_migrations');
    $pdo->exec('TRUNCATE TABLE api_manual_pauses');
    $pdo->exec('TRUNCATE TABLE api_circuit_breakers');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE system_execution_attempts');
    $pdo->exec('TRUNCATE TABLE system_execution_runs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO meli_accounts (id,status) VALUES (1,'conectado')");
    $token = Crypto::encrypt('hf1-fake-token-never-sent');
    $tokenInsert = $pdo->prepare(
        'INSERT INTO meli_tokens (meli_account_id,access_token_encrypted,expires_at)
         VALUES (1,:token,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    );
    $tokenInsert->execute(['token' => $token]);

    $fakeTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            // Reproduce las dos barreras del transporte real sin abrir cURL.
            (new MeliEmergencyStopService())->assertTransportAllowed();
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
    $callsBeforeManual = $fakeTransport->calls;
    $manualResult = $invoke(['source' => 'manual_emergency_canary', 'job_type' => 'emergency_canary']);
    $canary = $control->status()['canary'] ?? null;
    $check($fakeTransport->calls === $callsBeforeManual + 1 && ($manualResult['id'] ?? null) === 1,
        'REAL_CLIENT_MANUAL_CANARY_TEST no alcanzó exactamente una vez el transporte falso.');
    $check(is_array($canary) && (int) ($canary['used_calls'] ?? 0) === 1
        && ($canary['last_result'] ?? '') === 'success',
        'El permiso canario no quedó consumido con resultado conocido.');

    // Contrato del transporte productivo: la primera salida consume el único
    // permiso canario justo antes de cURL; una segunda salida queda bloqueada.
    $control->stopAll('hf1-test', 'Preparar contrato canario del transporte real');
    $control->prepareApiStart('hf1-test', 'Canario local del transporte cURL');
    $realTransport = new CurlMeliHttpTransport();
    $realTransport->request(
        'GET',
        'http://127.0.0.1:9/hf1-canary-contract',
        [],
        ['Accept: application/json'],
        false,
        ['timeout' => 1, 'connect_timeout' => 1]
    );
    $realCanary = $control->status()['canary'] ?? null;
    $check(is_array($realCanary) && (int) ($realCanary['used_calls'] ?? 0) === 1,
        'REAL_TRANSPORT_CANARY_GUARD no consumió el permiso en CurlMeliHttpTransport.');
    try {
        $realTransport->request(
            'GET',
            'http://127.0.0.1:9/hf1-canary-contract-second',
            [],
            ['Accept: application/json'],
            false,
            ['timeout' => 1, 'connect_timeout' => 1]
        );
        $check(false, 'SINGLE_CANARY_TRANSPORT_ENFORCED permitió una segunda salida.');
    } catch (Throwable $blocked) {
        $check(str_contains($blocked->getMessage(), 'canaria ya fue utilizada'),
            'SINGLE_CANARY_TRANSPORT_ENFORCED no bloqueó por permiso ya consumido.');
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
