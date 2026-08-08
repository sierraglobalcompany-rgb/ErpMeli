<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Services\ApiManualPauseException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CurlMeliHttpTransport;
use App\Services\EmergencyCanaryTransportContext;
use App\Services\EmergencyControlService;

$temporary = sys_get_temp_dir() . '/erp-meli-emergency-' . bin2hex(random_bytes(5));
$private = $temporary . '/private';
mkdir($temporary, 0700, true);
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $private);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $private;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }
};

try {
    $service = new EmergencyControlService($temporary);
    $credential = $service->provision('test-admin');
    $assert($credential['username'] === 'admin-emergencia', 'Usuario de emergencia inesperado.');
    $assert(strlen($credential['password']) >= 24, 'Contraseña sin entropía suficiente.');
    $assert(!in_array($credential['password'], ['12345678', 'password', 'admin'], true), 'Se generó una contraseña común.');
    $assert($service->authenticate('admin-emergencia', $credential['password'], '127.0.0.1')['ok'], 'La credencial generada no autentica.');
    $process = proc_open(
        [PHP_BINARY, $root . '/stop.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No fue posible renderizar el panel configurado.');
    }
    $rendered = (string) stream_get_contents($pipes[1]);
    $renderError = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $renderExit = proc_close($process);
    $assert(
        $renderExit === 0
        && $renderError === ''
        && str_contains($rendered, 'Entrar al control seguro')
        && !str_contains($rendered, 'Fatal error'),
        'La ruta real del panel configurado no se renderizó correctamente.'
    );

    for ($index = 0; $index < 5; $index++) {
        $failure = $service->authenticate('admin-emergencia', 'incorrecta', '192.0.2.1');
    }
    $locked = $service->authenticate('admin-emergencia', 'incorrecta', '192.0.2.1');
    $assert(!$locked['ok'] && $locked['reason'] === 'locked', 'Cinco intentos no activaron el bloqueo persistente.');

    $service->stopAll('test-admin', 'Prueba local');
    $status = $service->status();
    $assert($status['api'] === 'stopped' && $status['automation'] === 'stopped', 'El freno total no creó ambos marcadores.');
    $service->startAutomation('test-admin', 'Prueba local');
    $assert($service->status()['automation'] === 'enabled', 'La automatización no se reactivó por separado.');

    $service->stopAutomation('test-admin', 'Preparar canario local');
    $service->prepareApiStart('test-admin', 'Canario local');
    $assert($service->status()['api'] === 'canary', 'La API no entró en modo canario.');
    $nonce = $service->reserveApiCanary(1, '10001');
    $metadata = [
        'source' => 'manual_emergency_canary',
        'meli_account_id' => 1,
        'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '10001',
    ];
    EmergencyCanaryTransportContext::run(
        $nonce,
        static fn () => ApiExecutionMetadataContext::run(
            $metadata,
            static fn () => $service->claimCanaryTransport('GET', '/users/me')
        )
    );
    $blockedSecond = false;
    try {
        EmergencyCanaryTransportContext::run(
            $nonce,
            static fn () => ApiExecutionMetadataContext::run(
                $metadata,
                static fn () => $service->claimCanaryTransport('GET', '/users/me')
            )
        );
    } catch (ApiManualPauseException) {
        $blockedSecond = true;
    }
    $assert($blockedSecond, 'El canario permitió más de una salida.');
    $service->completeCanaryTransport(true, 200);
    $service->completeApiCanarySuccess($nonce, 1, '10001');
    $service->confirmApiStart('test-admin', 'Canario correcto');
    $assert($service->status()['api'] === 'enabled', 'El canario correcto no pudo cerrarse.');

    // El marcador físico del root aislado queda presente. La integración de
    // CurlMeliHttpTransport se cubre en emergency_real_meli_client_integration.
    $service->stopApi('test-admin', 'Verificar barrera física');
    $assert($service->apiStopped(), 'El freno físico no quedó presente en el root aislado.');

    echo "emergency_control_22513_ok\n";
} finally {
    $iterator = is_dir($temporary)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST)
        : null;
    if ($iterator !== null) {
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
    }
    @rmdir($temporary);
    putenv('ERP_EMERGENCY_STORAGE_DIR');
    unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
}
