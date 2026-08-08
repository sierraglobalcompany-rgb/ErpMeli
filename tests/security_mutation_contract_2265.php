<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$auth = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$update = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
$backup = (string) file_get_contents($root . '/app/Controllers/BackupController.php');
$reauth = (string) file_get_contents(
    $root . '/app/Services/AdministrativeReauthenticationService.php'
);
$rescue = (string) file_get_contents($root . '/launcher/rescue.php');
$maintenanceRecovery = (string) file_get_contents(
    $root . '/app/Recovery/DatabaseMaintenanceRecoveryKernel.php'
);
$restoreRecovery = (string) file_get_contents(
    $root . '/app/Recovery/RestoreRecoveryKernel.php'
);

$assert(
    substr_count($auth, 'SameOriginGuard::assertRequest(true)') >= 2,
    'Login y logout deben validar el origen además del CSRF.'
);
$assert(
    str_contains($update, 'private function validateMutation(): void')
        && str_contains($update, 'SameOriginGuard::assertRequest(true)')
        && substr_count($update, '$this->validateMutation();') >= 10,
    'Las mutaciones del actualizador no comparten el guard de origen.'
);
$assert(
    !str_contains($update, 'handleProtectedLink(')
        && str_contains($update, 'La actualización por enlace fue retirada'),
    'La ruta heredada /update-run todavía podría ejecutar migraciones.'
);
$assert(
    str_contains($backup, 'AdministrativeReauthenticationService')
        && !str_contains($backup, 'SELECT password_hash FROM users'),
    'Copias y restauración deben usar la reautenticación administrativa central.'
);
$assert(
    str_contains($reauth, "role='admin' AND status=1 AND is_temporary=0")
        && str_contains($reauth, 'public function requireRecent('),
    'La reautenticación no comprueba un administrador permanente y activo.'
);
$assert(
    str_contains($rescue, 'http_response_code(410)')
        && !str_contains($rescue, 'password_verify(')
        && !str_contains($rescue, 'current-release.json')
        && !str_contains($rescue, 'rename('),
    'El panel heredado de rescate todavía puede mutar la release.'
);
$assert(
    str_contains($maintenanceRecovery, '$error instanceof HttpException')
        && str_contains($maintenanceRecovery, 'renderFailure((string) $reported[\'message\'], $status)'),
    'El mantenimiento directo no conserva el HTTP 403 del guard de origen.'
);
$assert(
    str_contains($restoreRecovery, '$failure instanceof HttpException')
        && str_contains($restoreRecovery, 'http_response_code(max(400, min(599, $failure->status)))'),
    'La recuperación independiente no conserva el HTTP 403 del guard de origen.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "PASS security_mutation_contract_2265\n";
