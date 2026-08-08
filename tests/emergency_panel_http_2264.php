<?php

declare(strict_types=1);

use App\Core\AppPaths;
use App\Services\EmergencyControlService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-emergency-http-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('No se pudo preparar el storage temporal.');
}
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $temporary);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $temporary;

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

$credential = (new EmergencyControlService($root))->provision('http-test');
$apiMarkerPath = $root . DIRECTORY_SEPARATOR . EmergencyControlService::API_MARKER;
$automationMarkerPath = $root . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER;
$lastChangeMarkerPath = $root . DIRECTORY_SEPARATOR . EmergencyControlService::LAST_CHANGE_MARKER;
$savedApiMarker = is_file($apiMarkerPath) ? file_get_contents($apiMarkerPath) : null;
$savedAutomationMarker = is_file($automationMarkerPath) ? file_get_contents($automationMarkerPath) : null;
$savedLastChangeMarker = is_file($lastChangeMarkerPath) ? file_get_contents($lastChangeMarkerPath) : null;
file_put_contents($apiMarkerPath, json_encode(['version' => 1, 'active' => true, 'reason' => 'HTTP test API stop'], JSON_THROW_ON_ERROR));
file_put_contents($automationMarkerPath, json_encode(['version' => 1, 'active' => true, 'reason' => 'HTTP test automation stop'], JSON_THROW_ON_ERROR));
$maintenanceMarkerPaths = [
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/database-mutation-freeze.json'),
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$savedMaintenanceMarkers = [];
foreach ($maintenanceMarkerPaths as $path) {
    $savedMaintenanceMarkers[$path] = is_file($path) ? file_get_contents($path) : null;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('No se pudo preparar el cache temporal de mantenimiento.');
    }
}
file_put_contents($maintenanceMarkerPaths[0], json_encode(['snapshot_id' => 'http-test', 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR));
file_put_contents($maintenanceMarkerPaths[1], json_encode(['purpose' => 'database_sanitation', 'owner' => 'http-test', 'context' => ['session_id' => 999]], JSON_THROW_ON_ERROR));
file_put_contents($maintenanceMarkerPaths[2], json_encode(['backup_id' => 999, 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR));
file_put_contents($maintenanceMarkerPaths[3], json_encode(['task' => 'backup', 'backup_id' => 999, 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR));
$port = random_int(22000, 39000);
$origin = 'http://127.0.0.1:' . $port;
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
$process = proc_open($command, [
    0 => ['file', 'NUL', 'r'],
    1 => ['file', $temporary . '/server.out', 'a'],
    2 => ['file', $temporary . '/server.err', 'a'],
], $pipes, $root, array_merge($_ENV, [
    'ERP_EMERGENCY_STORAGE_DIR' => $temporary,
    'APP_URL' => $origin,
    'SESSION_SECURE' => 'false',
]));
if (!is_resource($process)) {
    $removeTree($temporary);
    throw new RuntimeException('No se pudo iniciar el servidor HTTP local.');
}

/** @return array{status:int,body:string,cookies:array<string,string>,headers:list<string>} */
$request = static function (
    string $url,
    string $method = 'GET',
    array $cookies = [],
    ?string $body = null,
    array $headers = []
): array {
    $handle = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $responseHeaders[] = trim($line);
            return strlen($line);
        },
    ]);
    if ($cookies !== []) {
        curl_setopt(
            $handle,
            CURLOPT_COOKIE,
            implode('; ', array_map(
                static fn (string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                array_values($cookies)
            ))
        );
    }
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (!is_string($responseBody)) {
        throw new RuntimeException('La petición HTTP local no respondió.');
    }
    foreach ($responseHeaders as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $match) === 1) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $responseBody, 'cookies' => $cookies, 'headers' => $responseHeaders];
};

try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('El servidor HTTP local no quedó disponible.');
    }

    $login = $request($origin . '/stop.php');
    if (
        $login['status'] !== 200
        || !str_contains($login['body'], 'action="/stop.php"')
        || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $match) !== 1
    ) {
        throw new RuntimeException('No se pudo abrir el login de emergencia.');
    }
    $headerText = strtolower(implode("\n", $login['headers']));
    foreach (['cache-control: private, no-store', 'content-security-policy:', 'x-frame-options: deny', 'x-content-type-options: nosniff', 'referrer-policy: same-origin'] as $header) {
        if (!str_contains($headerText, $header)) {
            throw new RuntimeException('El acceso directo no entregó la cabecera segura: ' . $header);
        }
    }
    $aliasLogin = $request($origin . '/stop/');
    if ($aliasLogin['status'] !== 200 || !str_contains($aliasLogin['body'], 'action="/stop/"')) {
        throw new RuntimeException('El alias /stop/ no conserva un formulario autocontenido.');
    }
    $csrf = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $payload = http_build_query([
        '_token' => $csrf,
        'action' => 'login',
        'username' => EmergencyControlService::USERNAME,
        'password' => $credential['password'],
    ]);
    $missingOrigin = $request(
        $origin . '/stop.php',
        'POST',
        $login['cookies'],
        $payload,
        ['Content-Type: application/x-www-form-urlencoded']
    );
    if ($missingOrigin['status'] !== 403) {
        throw new RuntimeException('El login aceptó una mutación sin origen comprobable.');
    }
    $foreignOrigin = $request(
        $origin . '/stop.php',
        'POST',
        $login['cookies'],
        $payload,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://example.invalid',
        ]
    );
    if ($foreignOrigin['status'] !== 403) {
        throw new RuntimeException('El login aceptó un origen diferente.');
    }
    $authenticated = $request(
        $origin . '/stop.php',
        'POST',
        $login['cookies'],
        $payload,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/stop.php',
        ]
    );
    if ($authenticated['status'] !== 303) {
        throw new RuntimeException('El login de emergencia no redirigió de forma segura.');
    }
    $panel = $request(
        $origin . '/stop.php',
        'GET',
        $authenticated['cookies']
    );
    $panelChecks = [
        'status' => $panel['status'] === 200,
        'title' => str_contains($panel['body'], '<h1>Freno de mano</h1>'),
        'maintenance' => str_contains($panel['body'], 'MODO LECTURA LOCAL</small><strong>Modo lectura activo</strong>'),
        'local_action' => str_contains($panel['body'], 'Retirar modo lectura local'),
        'not_degraded' => !str_contains($panel['body'], 'El panel necesita revisión'),
    ];
    $failedPanelChecks = array_keys(array_filter($panelChecks, static fn (bool $ok): bool => !$ok));
    if ($failedPanelChecks !== []) {
        throw new RuntimeException('El panel autenticado falló en: ' . implode(',', $failedPanelChecks) . '.');
    }
    $aliasPanel = $request($origin . '/stop/', 'GET', $authenticated['cookies']);
    if ($aliasPanel['status'] !== 200 || !str_contains($aliasPanel['body'], '<h1>Freno de mano</h1>')) {
        throw new RuntimeException('El alias /stop/ no comparte la sesión de emergencia.');
    }
    if (preg_match('/name="_token" value="([^"]+)"/', $panel['body'], $panelTokenMatch) !== 1) {
        throw new RuntimeException('El panel no expuso un token CSRF para acciones autenticadas.');
    }
    $panelCsrf = html_entity_decode($panelTokenMatch[1], ENT_QUOTES, 'UTF-8');
    $startAutomationPayload = http_build_query([
        '_token' => $panelCsrf,
        'action' => 'start_automation',
        'reason' => 'Prueba local: habilitar automatización manteniendo API bloqueada',
        'password' => $credential['password'],
    ]);
    $startAutomation = $request(
        $origin . '/stop.php',
        'POST',
        $authenticated['cookies'],
        $startAutomationPayload,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/stop.php',
        ]
    );
    if ($startAutomation['status'] !== 303) {
        throw new RuntimeException('La activación de automatización no redirigió de forma segura.');
    }
    $automationPanel = $request($origin . '/stop.php', 'GET', $startAutomation['cookies']);
    if (
        $automationPanel['status'] !== 200
        || !str_contains($automationPanel['body'], 'AUTOMATIZACIÓN</small><strong>Activa</strong>')
        || !str_contains($automationPanel['body'], 'MERCADO LIBRE</small><strong>Bloqueada</strong>')
        || str_contains($automationPanel['body'], 'Complete las migraciones pendientes antes de habilitar Mercado Libre')
    ) {
        throw new RuntimeException('Automatización y API no quedaron separadas en el panel de emergencia.');
    }
    if (is_file($automationMarkerPath) || !is_file($apiMarkerPath)) {
        throw new RuntimeException('Activar automatización debe retirar solo PAUSE_ERP_AUTOMATION y conservar PAUSE_MELI_API.');
    }
    if (preg_match('/name="_token" value="([^"]+)"/', $automationPanel['body'], $clearTokenMatch) !== 1) {
        throw new RuntimeException('El panel actualizado no expuso CSRF para retirar mantenimiento.');
    }
    $clearPayload = http_build_query([
        '_token' => html_entity_decode($clearTokenMatch[1], ENT_QUOTES, 'UTF-8'),
        'action' => 'clear_maintenance',
        'reason' => 'Prueba local: retirar modo lectura atascado sin cambiar API',
        'password' => $credential['password'],
    ]);
    $clearMaintenance = $request(
        $origin . '/stop.php',
        'POST',
        $startAutomation['cookies'],
        $clearPayload,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/stop.php',
        ]
    );
    if ($clearMaintenance['status'] !== 303) {
        throw new RuntimeException('Retirar modo lectura local no redirigió de forma segura.');
    }
    foreach ($maintenanceMarkerPaths as $path) {
        if (is_file($path)) {
            throw new RuntimeException('El freno de mano no retiró el marcador local de mantenimiento: ' . basename($path));
        }
    }
    $clearedPanel = $request($origin . '/stop.php', 'GET', $clearMaintenance['cookies']);
    if (
        $clearedPanel['status'] !== 200
        || !str_contains($clearedPanel['body'], 'MODO LECTURA LOCAL</small><strong>El sitio está libre para operar</strong>')
        || !is_file($apiMarkerPath)
    ) {
        throw new RuntimeException('El panel no reflejó mantenimiento libre manteniendo Mercado Libre bloqueado.');
    }

    file_put_contents($automationMarkerPath, json_encode(['version' => 1, 'active' => true, 'reason' => 'HTTP test automation stop 2'], JSON_THROW_ON_ERROR));
    file_put_contents($maintenanceMarkerPaths[0], json_encode(['snapshot_id' => 'http-test-2', 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR));
    file_put_contents($maintenanceMarkerPaths[1], json_encode(['purpose' => 'database_sanitation', 'owner' => 'http-test', 'context' => ['session_id' => 1000]], JSON_THROW_ON_ERROR));
    if (preg_match('/name="_token" value="([^"]+)"/', $clearedPanel['body'], $siteTokenMatch) !== 1) {
        throw new RuntimeException('El panel no expuso CSRF para activar sitio local.');
    }
    $startLocalSitePayload = http_build_query([
        '_token' => html_entity_decode($siteTokenMatch[1], ENT_QUOTES, 'UTF-8'),
        'action' => 'start_local_site',
        'reason' => 'Prueba local: activar sitio sin abrir Mercado Libre',
        'password' => $credential['password'],
    ]);
    $startLocalSite = $request(
        $origin . '/stop.php',
        'POST',
        $clearMaintenance['cookies'],
        $startLocalSitePayload,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/stop.php',
        ]
    );
    if ($startLocalSite['status'] !== 303) {
        throw new RuntimeException('Activar sitio local no redirigió de forma segura.');
    }
    $sitePanel = $request($origin . '/stop.php', 'GET', $startLocalSite['cookies']);
    if (
        $sitePanel['status'] !== 200
        || !str_contains($sitePanel['body'], 'AUTOMATIZACIÓN</small><strong>Detenida</strong>')
        || !str_contains($sitePanel['body'], 'MERCADO LIBRE</small><strong>Bloqueada</strong>')
        || !str_contains($sitePanel['body'], 'MODO LECTURA LOCAL</small><strong>El sitio está libre para operar</strong>')
        || !is_file($apiMarkerPath)
        || !is_file($automationMarkerPath)
        || is_file($maintenanceMarkerPaths[0])
        || is_file($maintenanceMarkerPaths[1])
    ) {
        throw new RuntimeException('Activar sitio local no preservó API y automatización detenidas.');
    }
    echo "PASS emergency_authenticated_panel_2264\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    if (is_string($savedApiMarker)) {
        file_put_contents($apiMarkerPath, $savedApiMarker);
    } else {
        @unlink($apiMarkerPath);
    }
    if (is_string($savedAutomationMarker)) {
        file_put_contents($automationMarkerPath, $savedAutomationMarker);
    } else {
        @unlink($automationMarkerPath);
    }
    if (is_string($savedLastChangeMarker)) {
        file_put_contents($lastChangeMarkerPath, $savedLastChangeMarker);
    } else {
        @unlink($lastChangeMarkerPath);
    }
    foreach ($savedMaintenanceMarkers as $path => $contents) {
        if (is_string($contents)) {
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0770, true);
            }
            file_put_contents($path, $contents);
        } else {
            @unlink($path);
        }
    }
    $removeTree($temporary);
    putenv('ERP_EMERGENCY_STORAGE_DIR');
    unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
}
