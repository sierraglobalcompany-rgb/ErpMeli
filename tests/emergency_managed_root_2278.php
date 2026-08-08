<?php

declare(strict_types=1);

use App\Recovery\EmergencyControlKernel;
use App\Services\EmergencyControlService;

$root = dirname(__DIR__);
$installationRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-managed-root-' . bin2hex(random_bytes(6));
$emergencyStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-managed-emergency-' . bin2hex(random_bytes(6));
$releaseApiMarker = $root . DIRECTORY_SEPARATOR . 'PAUSE_MELI_API';
$releaseAutomationMarker = $root . DIRECTORY_SEPARATOR . 'PAUSE_ERP_AUTOMATION';
$savedReleaseApiMarker = is_file($releaseApiMarker) ? file_get_contents($releaseApiMarker) : null;
$savedReleaseAutomationMarker = is_file($releaseAutomationMarker) ? file_get_contents($releaseAutomationMarker) : null;
@unlink($releaseApiMarker);
@unlink($releaseAutomationMarker);

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

if (!mkdir($installationRoot, 0700, true) && !is_dir($installationRoot)) {
    throw new RuntimeException('No se pudo preparar raíz estable temporal.');
}
if (!mkdir($emergencyStorage, 0700, true) && !is_dir($emergencyStorage)) {
    throw new RuntimeException('No se pudo preparar storage de emergencia temporal.');
}

putenv('ERP_EMERGENCY_STORAGE_DIR=' . $emergencyStorage);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $emergencyStorage;

if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $installationRoot);
}
if (!defined('ERP_RELEASE_ROOT')) {
    define('ERP_RELEASE_ROOT', $root);
}
if (!defined('ERP_SHARED_ROOT')) {
    define('ERP_SHARED_ROOT', $installationRoot);
}

require $root . '/vendor/autoload.php';

try {
    $kernelClass = new ReflectionClass(EmergencyControlKernel::class);
    $kernel = $kernelClass->newInstanceWithoutConstructor();
    $constructor = $kernelClass->getConstructor();
    if (!$constructor instanceof ReflectionMethod) {
        throw new RuntimeException('EmergencyControlKernel no expone constructor.');
    }
    $constructor->invoke($kernel, $root);

    $property = $kernelClass->getProperty('control');
    $control = $property->getValue($kernel);
    if (!$control instanceof EmergencyControlService) {
        throw new RuntimeException('El kernel no preparó la autoridad de emergencia.');
    }

    $control->stopApi('managed-root-test', 'Debe escribirse en la raíz estable.');
    $stableMarker = $installationRoot . DIRECTORY_SEPARATOR . EmergencyControlService::API_MARKER;
    $releaseMarker = $root . DIRECTORY_SEPARATOR . EmergencyControlService::API_MARKER;

    if (!is_file($stableMarker)) {
        throw new RuntimeException('El freno de mano no escribió PAUSE_MELI_API en la raíz estable.');
    }
    if (is_file($releaseMarker)) {
        throw new RuntimeException('El freno de mano escribió PAUSE_MELI_API dentro de la release.');
    }

    $control->stopAutomation('managed-root-test', 'Debe escribirse en la raíz estable.');
    $automationMarker = $installationRoot . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER;
    if (!is_file($automationMarker)) {
        throw new RuntimeException('El freno de mano no escribió PAUSE_ERP_AUTOMATION en la raíz estable.');
    }

    echo "PASS emergency_managed_root_2278\n";
} finally {
    if (is_string($savedReleaseApiMarker)) {
        file_put_contents($releaseApiMarker, $savedReleaseApiMarker);
    } else {
        @unlink($releaseApiMarker);
    }
    if (is_string($savedReleaseAutomationMarker)) {
        file_put_contents($releaseAutomationMarker, $savedReleaseAutomationMarker);
    } else {
        @unlink($releaseAutomationMarker);
    }
    $removeTree($installationRoot);
    $removeTree($emergencyStorage);
    putenv('ERP_EMERGENCY_STORAGE_DIR');
    unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
}
