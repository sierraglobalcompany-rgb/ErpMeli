<?php

declare(strict_types=1);

/**
 * Prueba local de veinte GET autenticados con una misma sesión.
 *
 * Requiere QA_HTTP_USER y QA_HTTP_PASS en el entorno. No persiste la
 * contraseña y elimina el cookie jar al terminar.
 */

$base = rtrim((string) (getenv('QA_HTTP_BASE') ?: 'http://localhost/erp-meli-local'), '/');
$email = trim((string) getenv('QA_HTTP_USER'));
$password = (string) getenv('QA_HTTP_PASS');
$maintenanceSessionId = max(1, (int) (getenv('QA_MAINTENANCE_SESSION_ID') ?: 3));
$originParts = parse_url($base);
$origin = is_array($originParts)
    ? (string) ($originParts['scheme'] ?? 'http') . '://' . (string) ($originParts['host'] ?? 'localhost')
        . (isset($originParts['port']) ? ':' . (int) $originParts['port'] : '')
    : 'http://localhost';
if ($email === '' || $password === '') {
    fwrite(STDERR, "Defina QA_HTTP_USER y QA_HTTP_PASS para la prueba local.\n");
    exit(2);
}

$cookieJar = sys_get_temp_dir() . '/erp-meli-qa-' . bin2hex(random_bytes(8)) . '.cookies';

/** @return array{status:int,body:string,info:array<string,mixed>} */
function httpRequest(
    string $url,
    string $cookieJar,
    ?array $post = null,
    string $origin = 'http://localhost'
): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No se pudo iniciar el cliente HTTP local.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: text/html', 'Origin: ' . $origin],
    ]);
    if ($post !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
        ]);
    }
    $body = curl_exec($curl);
    if (!is_string($body)) {
        $message = curl_error($curl);
        throw new RuntimeException('Falló la petición HTTP local: ' . $message);
    }
    $info = curl_getinfo($curl);
    $status = (int) ($info['http_code'] ?? 0);
    return ['status' => $status, 'body' => $body, 'info' => $info];
}

try {
    $login = httpRequest($base . '/login', $cookieJar, null, $origin);
    if (
        preg_match('/name="_token" value="([^"]+)"/', $login['body'], $matches) !== 1
    ) {
        throw new RuntimeException('El login local no entregó un token CSRF.');
    }
    $authenticated = httpRequest($base . '/login', $cookieJar, [
        '_token' => $matches[1],
        'email' => $email,
        'password' => $password,
    ], $origin);
    if ($authenticated['status'] !== 200 || !str_contains($authenticated['body'], 'ERP Meli')) {
        throw new RuntimeException('No fue posible autenticar la sesión local de rendimiento.');
    }

    $routes = [
        '/',
        '/settings',
        '/settings/backups',
        '/settings/database-maintenance?id=' . $maintenanceSessionId,
        '/sales',
        '/sales-control',
        '/settings/cron',
        '/settings/diagnostics',
        '/settings/modules',
        '/mantenimiento.php?id=' . $maintenanceSessionId,
    ];
    $multi = curl_multi_init();
    $handles = [];
    for ($index = 0; $index < 20; $index++) {
        $route = $routes[$index % count($routes)];
        $curl = curl_init($base . $route);
        if ($curl === false) {
            throw new RuntimeException('No se pudo preparar una petición concurrente.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        curl_multi_add_handle($multi, $curl);
        $handles[] = ['handle' => $curl, 'route' => $route];
    }

    do {
        $status = curl_multi_exec($multi, $active);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('El coordinador HTTP local devolvió un error.');
        }
        if ($active > 0) {
            curl_multi_select($multi, 0.25);
        }
    } while ($active > 0);

    $results = [];
    foreach ($handles as $entry) {
        $curl = $entry['handle'];
        $body = curl_multi_getcontent($curl);
        $info = curl_getinfo($curl);
        $result = [
            'route' => $entry['route'],
            'status' => (int) ($info['http_code'] ?? 0),
            'duration_ms' => round((float) ($info['total_time'] ?? 0) * 1000, 1),
            'has_error_page' => str_contains((string) $body, 'No se pudo cargar esta página'),
        ];
        $results[] = $result;
        curl_multi_remove_handle($multi, $curl);
    }
    curl_multi_close($multi);

    $durations = array_column($results, 'duration_ms');
    sort($durations, SORT_NUMERIC);
    $percentile = static function (array $values, float $ratio): float {
        $position = (int) floor((count($values) - 1) * $ratio);
        return (float) ($values[max(0, $position)] ?? 0);
    };
    $failures = array_values(array_filter(
        $results,
        static fn (array $result): bool =>
            $result['status'] !== 200 || $result['has_error_page']
    ));
    $payload = [
        'status' => $failures === [] ? 'PASS' : 'FAIL',
        'requests' => count($results),
        'p50_ms' => $percentile($durations, 0.50),
        'p95_ms' => $percentile($durations, 0.95),
        'max_ms' => (float) (end($durations) ?: 0),
        'failures' => $failures,
        'results' => $results,
    ];
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($failures === [] ? 0 : 1);
} finally {
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}
