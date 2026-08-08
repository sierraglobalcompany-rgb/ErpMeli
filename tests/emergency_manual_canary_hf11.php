<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiManualPauseException;
use App\Services\EmergencyCanaryTransportContext;
use App\Services\EmergencyControlService;

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-hf11-' . bin2hex(random_bytes(6));
$private = $temporary . DIRECTORY_SEPARATOR . 'private';
mkdir($private, 0700, true);
$previousPrivate = getenv('ERP_EMERGENCY_STORAGE_DIR');
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $private);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $private;

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

try {
    $control = new EmergencyControlService($temporary);
    $control->stopAll('hf11-test', 'Preparar contrato canario');
    $control->prepareApiStart('hf11-test', 'Prueba ligada');
    $nonce = $control->reserveApiCanary(7, '70001');
    $canary = $control->status()['canary'] ?? null;
    $check(is_array($canary)
        && ($canary['state'] ?? '') === 'reserved'
        && (int) ($canary['meli_account_id'] ?? 0) === 7
        && ($canary['expected_meli_user_id'] ?? '') === '70001'
        && ($canary['method'] ?? '') === 'GET'
        && ($canary['endpoint'] ?? '') === '/users/me'
        && ($canary['source'] ?? '') === 'manual_emergency_canary'
        && !array_key_exists('reservation_nonce', $canary)
        && !array_key_exists('canary_reservation_nonce', ApiExecutionMetadataContext::current()),
        'RESERVATION_BINDING no guardó todas las dimensiones autorizadas.');

    $base = [
        'source' => 'manual_emergency_canary',
        'meli_account_id' => 7,
        'transport_meli_account_id' => 7,
        'expected_meli_user_id' => '70001',
    ];
    foreach ([
        [array_replace($base, ['source' => 'cron_v3_remote']), $nonce],
        [array_replace($base, ['meli_account_id' => 8, 'transport_meli_account_id' => 8]), $nonce],
        [array_replace($base, ['transport_meli_account_id' => 8]), $nonce],
        [$base, str_repeat('0', 64)],
    ] as $index => [$invalid, $invalidNonce]) {
        try {
            EmergencyCanaryTransportContext::run(
                $invalidNonce,
                static fn () => ApiExecutionMetadataContext::run(
                    $invalid,
                    static fn () => $control->claimCanaryTransport('GET', '/users/me')
                )
            );
            $check(false, 'INVALID_RESERVATION_' . $index . ' fue autorizada.');
        } catch (ApiManualPauseException) {
            $state = $control->status()['canary'] ?? null;
            $check(is_array($state) && ($state['state'] ?? '') === 'reserved'
                && (int) ($state['used_calls'] ?? -1) === 0,
                'INVALID_RESERVATION_' . $index . ' consumió el permiso.');
        }
    }

    EmergencyCanaryTransportContext::run(
        $nonce,
        static fn () => ApiExecutionMetadataContext::run(
            $base,
            static fn () => $control->claimCanaryTransport('GET', '/users/me')
        )
    );
    $inFlight = $control->status()['canary'] ?? null;
    $check(is_array($inFlight) && ($inFlight['state'] ?? '') === 'in_flight'
        && (int) ($inFlight['used_calls'] ?? 0) === 1,
        'VALID_RESERVATION no cruzó exactamente una vez a in_flight.');
    try {
        EmergencyCanaryTransportContext::run(
            $nonce,
            static fn () => ApiExecutionMetadataContext::run(
                $base,
                static fn () => $control->claimCanaryTransport('GET', '/users/me')
            )
        );
        $check(false, 'SECOND_PARALLEL_REQUEST_ZERO_HTTP permitió un segundo claim.');
    } catch (ApiManualPauseException) {
        $check((int) (($control->status()['canary']['used_calls'] ?? 0)) === 1,
            'SECOND_PARALLEL_REQUEST_ZERO_HTTP alteró el único uso autorizado.');
    }

    $control->completeCanaryTransport(true, 200);
    $known = $control->status()['canary'] ?? null;
    $check(is_array($known) && ($known['state'] ?? '') === 'response_known'
        && !isset($known['identity_verified']),
        'HTTP_2XX_ONLY_RESPONSE_KNOWN trató 200 como éxito final.');
    try {
        $control->confirmApiStart('hf11-test', 'No debe confirmar');
        $check(false, 'CONFIRM_API_WITHOUT_IDENTITY fue permitido.');
    } catch (RuntimeException) {
        $check(true, 'CONFIRM_API_WITHOUT_IDENTITY quedó bloqueado.');
    }

    $control->failApiCanaryAndBlock('hf11-test', 'account_identity_mismatch', 'CANARY-HF11-TEST', 200);
    $failedStatus = $control->status();
    $failed = $failedStatus['canary'] ?? null;
    $check($control->apiStopped()
        && is_array($failed)
        && ($failed['last_result'] ?? '') === 'failed'
        && ($failed['failure_class'] ?? '') === 'account_identity_mismatch'
        && ($failed['failure_reference'] ?? '') === 'CANARY-HF11-TEST',
        'FAILED_CANARY_PRESERVES_EVIDENCE_AFTER_API_BLOCK perdió la evidencia.');

    $control->prepareApiStart('hf11-test', 'Nueva prueba');
    $successNonce = $control->reserveApiCanary(7, '70001');
    $successMetadata = $base;
    EmergencyCanaryTransportContext::run(
        $successNonce,
        static fn () => ApiExecutionMetadataContext::run(
            $successMetadata,
            static fn () => $control->claimCanaryTransport('GET', '/users/me')
        )
    );
    $control->completeCanaryTransport(true, 200);
    $control->completeApiCanarySuccess($successNonce, 7, '70001');
    $success = $control->status()['canary'] ?? null;
    $check(is_array($success) && ($success['last_result'] ?? '') === 'success'
        && !empty($success['identity_verified']),
        'IDENTITY_VERIFIED_SUCCESS no certificó la identidad exacta.');
    $control->confirmApiStart('hf11-test', 'Canario correcto');
    $check(!$control->apiStopped() && ($control->status()['canary'] ?? null) === null,
        'CONFIRM_API_SUCCESS no consumió la evidencia correcta.');

    // Dos procesos reales esperan la misma señal y compiten por el mismo
    // marcador. El lock del marcador debe autorizar exactamente uno.
    $control->stopAll('hf11-test', 'Preparar carrera concurrente');
    $control->prepareApiStart('hf11-test', 'Carrera concurrente');
    $parallelNonce = $control->reserveApiCanary(7, '70001');
    $parallelMetadata = $base;
    $childPath = $temporary . DIRECTORY_SEPARATOR . 'parallel-claim.php';
    $gatePath = $temporary . DIRECTORY_SEPARATOR . 'parallel-go';
    file_put_contents($childPath, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];

use App\Services\ApiExecutionMetadataContext;
use App\Services\EmergencyCanaryTransportContext;
use App\Services\EmergencyControlService;

$metadata = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 5;
while (!is_file($argv[4]) && microtime(true) < $deadline) {
    usleep(1000);
}
try {
    EmergencyCanaryTransportContext::run(
        $argv[5],
        static fn () => ApiExecutionMetadataContext::run(
            $metadata,
            static fn () => (new EmergencyControlService($argv[3]))->claimCanaryTransport('GET', '/users/me')
        )
    );
    fwrite(STDOUT, 'claimed');
} catch (Throwable) {
    fwrite(STDOUT, 'blocked');
}
PHP
    , LOCK_EX);
    $command = [
        PHP_BINARY,
        $childPath,
        $root . DIRECTORY_SEPARATOR . 'bootstrap.php',
        base64_encode(json_encode($parallelMetadata, JSON_THROW_ON_ERROR)),
        $temporary,
        $gatePath,
        $parallelNonce,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processes = [];
    foreach ([1, 2] as $ignored) {
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso concurrente del canario.');
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
            throw new RuntimeException('Falló el proceso concurrente del canario: ' . $childError);
        }
    }
    sort($parallelResults);
    $parallelState = $control->status()['canary'] ?? null;
    $check($parallelResults === ['blocked', 'claimed']
        && is_array($parallelState)
        && ($parallelState['state'] ?? '') === 'in_flight'
        && (int) ($parallelState['used_calls'] ?? 0) === 1,
        'SECOND_PARALLEL_REQUEST_ZERO_HTTP no garantizó un solo ganador físico.');
    $control->failApiCanaryAndBlock('hf11-test', 'parallel_test_complete', 'CANARY-HF11-PARALLEL');

    $serviceSource = (string) file_get_contents($root . '/app/Services/EmergencyApiCanaryService.php');
    $clientSource = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $kernelSource = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $check(!str_contains($serviceSource, 'refreshOAuthToken(')
        && !str_contains($serviceSource, '/oauth/token'),
        'CANARY_TOKEN_REFRESH_ALLOWED debe ser NO.');
    $check(str_contains($clientSource, "'manual_emergency_canary'")
        && str_contains($clientSource, '$singleDispatchAttempt ? 1'),
        'CANARY_MAX_PHYSICAL_HTTP no está fijado en uno.');
    $check(str_contains($kernelSource, "'run_api_canary'")
        && str_contains($kernelSource, 'name="account_id"')
        && !str_contains($kernelSource, 'canary_reservation_nonce')
        && !array_key_exists('reservation_nonce', (array) ($control->status()['canary'] ?? [])),
        'La UI no expone la acción ligada a cuenta o filtra el nonce.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo 'PASS emergency_manual_canary_hf11 ' . $passed . '/' . $total . PHP_EOL;
} finally {
    if ($previousPrivate === false) {
        putenv('ERP_EMERGENCY_STORAGE_DIR');
        unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
    } else {
        putenv('ERP_EMERGENCY_STORAGE_DIR=' . $previousPrivate);
        $_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $previousPrivate;
    }
    $remove($temporary);
}
