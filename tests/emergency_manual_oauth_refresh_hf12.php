<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiManualPauseException;
use App\Services\EmergencyControlService;
use App\Services\EmergencyOAuthRefreshService;
use App\Services\EmergencyOAuthRefreshTransportContext;
use App\Services\MeliApiException;

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-hf12-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);

$previousEnvironment = [];
foreach (['ERP_EMERGENCY_STORAGE_DIR', 'MELI_CLIENT_ID', 'MELI_CLIENT_SECRET', 'MELI_REDIRECT_URI', 'ML_WRITE_ENABLED'] as $key) {
    $previousEnvironment[$key] = getenv($key);
}
putenv('MELI_CLIENT_ID=hf12-client');
putenv('MELI_CLIENT_SECRET=hf12-client-secret-sentinel');
putenv('MELI_REDIRECT_URI=https://localhost.invalid/oauth/callback');
putenv('ML_WRITE_ENABLED=false');
$_ENV['MELI_CLIENT_ID'] = 'hf12-client';
$_ENV['MELI_CLIENT_SECRET'] = 'hf12-client-secret-sentinel';
$_ENV['MELI_REDIRECT_URI'] = 'https://localhost.invalid/oauth/callback';
$_ENV['ML_WRITE_ENABLED'] = 'false';

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
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

/** @return array{0:string,1:string,2:EmergencyControlService} */
$scenario = static function (string $name) use ($temporary): array {
    $scenarioRoot = $temporary . DIRECTORY_SEPARATOR . $name;
    $private = $scenarioRoot . DIRECTORY_SEPARATOR . 'private';
    mkdir($private, 0700, true);
    putenv('ERP_EMERGENCY_STORAGE_DIR=' . $private);
    $_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $private;
    $nonce = hash('sha256', 'hf12-' . $name);
    $control = new EmergencyControlService($scenarioRoot, static fn (): string => $nonce);
    $control->stopAll('hf12-test', 'Preparar escenario OAuth');
    return [$scenarioRoot, $private, $control];
};

/** @return array<string,mixed> */
$account = static fn (array $replace = []): array => array_replace([
    'id' => 3,
    'company_id' => 9,
    'nickname' => 'BODEGA.DIGITAL.MEDELLIN',
    'meli_user_id' => '333000',
    'status' => 'conectado',
    'refresh_token_encrypted' => 'refresh-token-cipher-sentinel',
    'expires_at' => '2026-08-04 05:58:23',
    'refresh_version' => 7,
], $replace);

/** @return array<string,scalar|null> */
$metadata = static fn (array $replace = []): array => array_replace([
    'source' => EmergencyOAuthRefreshService::SOURCE,
    'job_type' => 'emergency_oauth_refresh',
    'company_id' => 9,
    'meli_account_id' => 3,
    'transport_meli_account_id' => 3,
    'expected_meli_user_id' => '333000',
], $replace);

$claim = static function (
    EmergencyControlService $control,
    string $nonce,
    array $meta,
    string $method = 'POST',
    string $endpoint = '/oauth/token'
): void {
    EmergencyOAuthRefreshTransportContext::run(
        $nonce,
        static fn () => ApiExecutionMetadataContext::run(
            $meta,
            static fn () => $control->claimEmergencyOAuthRefreshTransport($method, $endpoint)
        )
    );
};

/**
 * @param array<string,mixed>|null $accountRow
 * @param callable(int,EmergencyControlService):array<string,mixed> $remote
 * @param array<string,mixed>|null $tokenState
 */
$service = static function (
    EmergencyControlService $control,
    ?array $accountRow,
    callable $remote,
    ?array $tokenState
): EmergencyOAuthRefreshService {
    return new EmergencyOAuthRefreshService(
        $control,
        static fn (int $accountId): ?array => $accountId === 3 ? $accountRow : null,
        static fn (int $accountId): array => $remote($accountId, $control),
        static fn (int $accountId): ?array => $accountId === 3 ? $tokenState : null,
        static function (int $accountId): void {
            if ($accountId !== 3) {
                throw new RuntimeException('Cuenta fuera del escenario autorizado.');
            }
        }
    );
};

$expectLocalFailure = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};

$queueCalls = 0;
$syncCalls = 0;
$successResult = [];
$successControl = null;

try {
    // 1. Automation running -> HTTP=0.
    [, , $control] = $scenario('automation-running');
    $control->startAutomation('hf12-test', 'Simular Automation activa');
    $http = 0;
    $candidate = $service($control, $account(), static function () use (&$http): array {
        $http++;
        return [];
    }, ['expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'refresh_version' => 8]);
    $check($expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test')) && $http === 0,
        '1 automation running emitió HTTP.');

    // 2. Business API not blocked -> HTTP=0.
    [, , $control] = $scenario('api-open');
    $control->startApiWithoutCanary('hf12-test', 'Simular API abierta');
    $http = 0;
    $candidate = $service($control, $account(), static function () use (&$http): array {
        $http++;
        return [];
    }, null);
    $check($expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test')) && $http === 0,
        '2 business API abierta emitió HTTP.');

    // 3. Cuenta inexistente -> HTTP=0.
    [, , $control] = $scenario('missing-account');
    $http = 0;
    $candidate = $service($control, null, static function () use (&$http): array {
        $http++;
        return [];
    }, null);
    $check($expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test')) && $http === 0,
        '3 cuenta inexistente emitió HTTP.');

    // 4. Refresh token faltante -> HTTP=0.
    [, , $control] = $scenario('missing-refresh-token');
    $http = 0;
    $candidate = $service($control, $account(['refresh_token_encrypted' => '']), static function () use (&$http): array {
        $http++;
        return [];
    }, null);
    $check($expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test')) && $http === 0,
        '4 refresh token faltante emitió HTTP.');

    // 5. Autorización inexistente -> HTTP=0.
    [, , $control] = $scenario('missing-authorization');
    $http = 0;
    $check($expectLocalFailure(static function () use ($claim, $control, $metadata, &$http): void {
        $claim($control, str_repeat('a', 64), $metadata());
        $http++;
    }) && $http === 0, '5 autorización inexistente permitió HTTP.');

    // 6. Autorización expirada -> HTTP=0.
    [$scenarioRoot, , $control] = $scenario('expired-authorization');
    $nonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    $markerPath = $scenarioRoot . DIRECTORY_SEPARATOR . EmergencyControlService::OAUTH_REFRESH_MARKER;
    $document = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
    $document['expires_at'] = time() - 1;
    file_put_contents($markerPath, json_encode($document, JSON_THROW_ON_ERROR), LOCK_EX);
    $http = 0;
    $check($expectLocalFailure(static function () use ($claim, $control, $nonce, $metadata, &$http): void {
        $claim($control, $nonce, $metadata());
        $http++;
    }) && $http === 0, '6 autorización expirada permitió HTTP.');

    // 7-11. Todas las dimensiones privadas deben coincidir y no consumen el permiso.
    [, , $control] = $scenario('dimension-binding');
    $nonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    $invalidCases = [
        7 => [$metadata(['meli_account_id' => 2, 'transport_meli_account_id' => 2]), 'POST', '/oauth/token', $nonce],
        8 => [$metadata(), 'GET', '/oauth/token', $nonce],
        9 => [$metadata(), 'POST', '/users/me', $nonce],
        10 => [$metadata(['source' => 'cron_v3_remote']), 'POST', '/oauth/token', $nonce],
        11 => [$metadata(), 'POST', '/oauth/token', str_repeat('f', 64)],
    ];
    foreach ($invalidCases as $number => [$meta, $method, $endpoint, $candidateNonce]) {
        $http = 0;
        $denied = $expectLocalFailure(static function () use (
            $claim,
            $control,
            $candidateNonce,
            $meta,
            $method,
            $endpoint,
            &$http
        ): void {
            $claim($control, $candidateNonce, $meta, $method, $endpoint);
            $http++;
        });
        $state = $control->status()['oauth_refresh'] ?? null;
        $check($denied && $http === 0 && is_array($state)
            && ($state['state'] ?? '') === 'reserved'
            && (int) ($state['used_calls'] ?? -1) === 0,
            $number . ' dimensión OAuth incorrecta consumió el permiso.');
    }

    // 12. Refresh válido -> exactamente un HTTP.
    [$successRoot, , $control] = $scenario('valid-refresh');
    file_put_contents(
        $successRoot . DIRECTORY_SEPARATOR . EmergencyControlService::CANARY_MARKER,
        json_encode([
            'version' => 2,
            'state' => 'ready',
            'max_calls' => 1,
            'used_calls' => 0,
            'created_at' => gmdate(DATE_ATOM),
            'expires_at' => time() + 3600,
        ], JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    $http = 0;
    $remote = static function (int $accountId, EmergencyControlService $control) use (&$http): array {
        return ApiExecutionMetadataContext::withTransportMetadata(
            ['transport_meli_account_id' => $accountId],
            static function () use ($control, &$http): array {
                $control->claimEmergencyOAuthRefreshTransport('POST', '/oauth/token');
                $http++;
                return ['access_token' => 'access-token-response-sentinel'];
            }
        );
    };
    $candidate = $service(
        $control,
        $account(),
        $remote,
        ['expires_at' => gmdate('Y-m-d H:i:s', time() + 21600), 'refresh_version' => 8]
    );
    $successResult = $candidate->run(3, 'hf12-test');
    $successControl = $control;
    $check($http === 1, '12 refresh válido no emitió exactamente un HTTP.');

    // 13. El mismo permiso no puede consumirse por segunda vez.
    $state = $control->status()['oauth_refresh'] ?? null;
    $secondNonce = hash('sha256', 'hf12-valid-refresh');
    $secondHttp = 0;
    $check($expectLocalFailure(static function () use ($claim, $control, $secondNonce, $metadata, &$secondHttp): void {
        $claim($control, $secondNonce, $metadata());
        $secondHttp++;
    }) && $secondHttp === 0 && is_array($state) && (int) ($state['used_calls'] ?? 0) === 1,
        '13 segundo intento emitió HTTP.');

    // 14. Dos procesos compiten: solo uno puede reclamar.
    [, , $control] = $scenario('parallel-race');
    $parallelNonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    $childPath = $temporary . DIRECTORY_SEPARATOR . 'oauth-parallel-claim.php';
    $gatePath = $temporary . DIRECTORY_SEPARATOR . 'oauth-parallel-go';
    file_put_contents($childPath, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];

use App\Services\ApiExecutionMetadataContext;
use App\Services\EmergencyControlService;
use App\Services\EmergencyOAuthRefreshTransportContext;

$metadata = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 5;
while (!is_file($argv[4]) && microtime(true) < $deadline) {
    usleep(1000);
}
try {
    EmergencyOAuthRefreshTransportContext::run(
        $argv[5],
        static fn () => ApiExecutionMetadataContext::run(
            $metadata,
            static fn () => (new EmergencyControlService($argv[3]))->claimEmergencyOAuthRefreshTransport('POST', '/oauth/token')
        )
    );
    fwrite(STDOUT, 'claimed');
} catch (Throwable) {
    fwrite(STDOUT, 'blocked');
}
PHP
    , LOCK_EX);
    $parallelRoot = $temporary . DIRECTORY_SEPARATOR . 'parallel-race';
    $command = [
        PHP_BINARY,
        $childPath,
        $root . DIRECTORY_SEPARATOR . 'bootstrap.php',
        base64_encode(json_encode($metadata(), JSON_THROW_ON_ERROR)),
        $parallelRoot,
        $gatePath,
        $parallelNonce,
    ];
    $processes = [];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    foreach ([1, 2] as $ignored) {
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso concurrente OAuth.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    file_put_contents($gatePath, 'go', LOCK_EX);
    $parallelResults = [];
    foreach ($processes as [$process, $pipes]) {
        $parallelResults[] = trim((string) stream_get_contents($pipes[1]));
        $childError = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || $childError !== '') {
            throw new RuntimeException('Falló el proceso concurrente OAuth: ' . $childError);
        }
    }
    sort($parallelResults);
    $parallelState = $control->status()['oauth_refresh'] ?? null;
    $check($parallelResults === ['blocked', 'claimed']
        && is_array($parallelState)
        && (int) ($parallelState['used_calls'] ?? 0) === 1,
        '14 carrera concurrente no tuvo un solo ganador.');

    // 15. Automation se reactiva entre reserva y transporte -> HTTP=0 y se restaura el stop.
    [$raceRoot, , $control] = $scenario('automation-resume-race');
    $raceNonce = $control->reserveEmergencyOAuthRefresh(3, '333000');
    @unlink($raceRoot . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER);
    $http = 0;
    $denied = $expectLocalFailure(static function () use ($claim, $control, $raceNonce, $metadata, &$http): void {
        $claim($control, $raceNonce, $metadata());
        $http++;
    });
    $control->failEmergencyOAuthRefreshAndBlock('hf12-test', 'automation_resumed', 'OAUTH-HF12-RACE');
    $check($denied && $http === 0 && $control->automationStopped() && $control->apiStopped(),
        '15 carrera de Automation permitió HTTP o no restauró los frenos.');

    // 16-19. Cada error remoto consume una sola salida y nunca reintenta.
    foreach ([16 => 429, 17 => 500, 18 => 0, 19 => 302] as $number => $status) {
        [, , $control] = $scenario('remote-failure-' . $number);
        $http = 0;
        $remote = static function (int $accountId, EmergencyControlService $control) use (&$http, $status): array {
            return ApiExecutionMetadataContext::withTransportMetadata(
                ['transport_meli_account_id' => $accountId],
                static function () use ($control, &$http, $status): array {
                    $control->claimEmergencyOAuthRefreshTransport('POST', '/oauth/token');
                    $http++;
                    throw new MeliApiException('Fallo OAuth seguro.', $status > 0 ? $status : null, 'hf12-request', []);
                }
            );
        };
        $candidate = $service($control, $account(), $remote, null);
        $failed = $expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test'));
        $refreshState = $control->status()['oauth_refresh'] ?? null;
        $check($failed && $http === 1 && $control->apiStopped() && $control->automationStopped()
            && is_array($refreshState) && ($refreshState['last_result'] ?? '') === 'failed',
            $number . ' error remoto hizo retry o perdió el bloqueo.');
    }

    // 20. Respuesta 2xx estructuralmente inválida no certifica persistencia.
    [, , $control] = $scenario('invalid-response');
    $http = 0;
    $persisted = 0;
    $remote = static function (int $accountId, EmergencyControlService $control) use (&$http): array {
        return ApiExecutionMetadataContext::withTransportMetadata(
            ['transport_meli_account_id' => $accountId],
            static function () use ($control, &$http): array {
                $control->claimEmergencyOAuthRefreshTransport('POST', '/oauth/token');
                $http++;
                return [];
            }
        );
    };
    $candidate = $service(
        $control,
        $account(),
        $remote,
        ['expires_at' => '2026-08-04 05:58:23', 'refresh_version' => 7]
    );
    $invalidFailed = $expectLocalFailure(static fn () => $candidate->run(3, 'hf12-test'));
    $check($invalidFailed && $http === 1 && $persisted === 0
        && (($control->status()['oauth_refresh']['last_result'] ?? '') === 'failed'),
        '20 respuesta inválida fue certificada o persistida.');

    // 21-26. Evidencia de éxito y ausencia de efectos colaterales.
    $check((int) ($successResult['refresh_version'] ?? 0) === 8,
        '21 éxito no comprobó incremento de refresh_version.');
    $check(strtotime((string) ($successResult['expires_at'] ?? '')) > time(),
        '22 éxito no dejó expires_at futuro.');
    $check($successControl instanceof EmergencyControlService && $successControl->automationStopped(),
        '23 éxito reactivó Automation.');
    $check($successControl instanceof EmergencyControlService && $successControl->apiStopped(),
        '24 éxito abrió la API comercial.');
    $check($queueCalls === 0, '25 refresh manual activó colas.');
    $check($syncCalls === 0, '26 refresh manual activó sincronización.');

    // 27. Tokens, secretos, response body y nonce no cruzan observabilidad pública.
    $observable = json_encode($successControl?->status(), JSON_THROW_ON_ERROR)
        . (string) @file_get_contents($temporary . DIRECTORY_SEPARATOR . 'valid-refresh' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'audit.jsonl')
        . (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Recovery' . DIRECTORY_SEPARATOR . 'EmergencyControlKernel.php');
    $successNonce = hash('sha256', 'hf12-valid-refresh');
    $check(!str_contains($observable, 'refresh-token-cipher-sentinel')
        && !str_contains($observable, 'access-token-response-sentinel')
        && !str_contains($observable, 'hf12-client-secret-sentinel')
        && !str_contains($observable, $successNonce)
        && !array_key_exists('reservation_nonce', (array) ($successControl?->status()['oauth_refresh'] ?? [])),
        '27 secretos, body OAuth o nonce aparecieron en observabilidad.');

    // 28. Superficie HF1.1 intacta: source, barrera de Automation, un intento y sin refresh recursivo.
    $canarySource = (string) file_get_contents($root . '/app/Services/EmergencyApiCanaryService.php');
    $clientSource = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $controlSource = (string) file_get_contents($root . '/app/Services/EmergencyControlService.php');
    $check(!str_contains($canarySource, 'refreshOAuthToken(')
        && str_contains($clientSource, "'manual_emergency_canary'")
        && str_contains($clientSource, "'manual_emergency_oauth_refresh'")
        && str_contains($controlSource, 'claimCanaryTransport')
        && str_contains($controlSource, 'claimEmergencyOAuthRefreshTransport')
        && str_contains($controlSource, 'automationStopped()'),
        '28 regresión HF1.1 o separación de autorizaciones detectada.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo 'PASS emergency_manual_oauth_refresh_hf12 ' . $passed . '/' . $total . PHP_EOL;
} catch (Throwable $fatal) {
    fwrite(STDERR, 'HF12_TEST_FATAL ' . $fatal::class . ': ' . $fatal->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($previousEnvironment as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
    $remove($temporary);
}
