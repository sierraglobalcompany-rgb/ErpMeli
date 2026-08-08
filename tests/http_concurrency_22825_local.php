<?php

declare(strict_types=1);

/**
 * Certifica veinte GET simultáneos con una misma sesión local.
 *
 * Requiere QA_HTTP_BASE, QA_HTTP_USER y QA_HTTP_PASS. El cookie jar se
 * elimina al terminar y ninguna ruta de esta matriz crea o procesa trabajos.
 */

$base = rtrim((string) (getenv('QA_HTTP_BASE') ?: 'http://localhost/erp-meli-2288-qa'), '/');
$email = trim((string) getenv('QA_HTTP_USER'));
$password = (string) getenv('QA_HTTP_PASS');
$originParts = parse_url($base);
$origin = is_array($originParts)
    ? (string) ($originParts['scheme'] ?? 'http') . '://' . (string) ($originParts['host'] ?? 'localhost')
        . (isset($originParts['port']) ? ':' . (int) $originParts['port'] : '')
    : 'http://localhost';

if ($email === '' || $password === '') {
    fwrite(STDERR, "Defina QA_HTTP_USER y QA_HTTP_PASS para la prueba local.\n");
    exit(2);
}

$cookieJar = sys_get_temp_dir() . '/erp-meli-22825-' . bin2hex(random_bytes(8)) . '.cookies';

/** @return array{status:int,body:string} */
function request22825(string $url, string $cookieJar, ?array $post, string $origin): array
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
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json', 'Origin: ' . $origin],
    ]);
    if ($post !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
        ]);
    }
    $body = curl_exec($curl);
    if (!is_string($body)) {
        throw new RuntimeException('Falló la petición HTTP local.');
    }
    return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
}

try {
    $login = request22825($base . '/login', $cookieJar, null, $origin);
    if (preg_match('/name="_token" value="([^"]+)"/', $login['body'], $matches) !== 1) {
        throw new RuntimeException('El login local no entregó token CSRF.');
    }
    $authenticated = request22825($base . '/login', $cookieJar, [
        '_token' => $matches[1],
        'email' => $email,
        'password' => $password,
    ], $origin);
    if ($authenticated['status'] !== 200 || !str_contains($authenticated['body'], 'ERP Meli')) {
        throw new RuntimeException('No fue posible autenticar la sesión local.');
    }

    $routes = [
        '/settings/cron',
        '/settings/cron/overview.json',
        '/settings/cron/tasks.json',
        '/settings/api-health',
        '/settings/api-health/overview.json',
        '/settings/manual-processing',
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
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
        ]);
        curl_multi_add_handle($multi, $curl);
        $handles[] = ['handle' => $curl, 'route' => $route];
    }

    do {
        $multiStatus = curl_multi_exec($multi, $active);
        if ($multiStatus !== CURLM_OK) {
            throw new RuntimeException('El coordinador HTTP local devolvió un error.');
        }
        if ($active > 0) {
            curl_multi_select($multi, 0.25);
        }
    } while ($active > 0);

    $durations = [];
    $failures = [];
    foreach ($handles as $entry) {
        $curl = $entry['handle'];
        $body = (string) curl_multi_getcontent($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $duration = round((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000, 1);
        $durations[] = $duration;
        if ($status !== 200 || str_contains($body, 'No se pudo cargar esta página')) {
            $failures[] = ['route' => $entry['route'], 'status' => $status];
        }
        curl_multi_remove_handle($multi, $curl);
    }
    curl_multi_close($multi);
    sort($durations, SORT_NUMERIC);
    $p95 = (float) ($durations[(int) floor((count($durations) - 1) * 0.95)] ?? 0);
    echo json_encode([
        'status' => $failures === [] ? 'PASS' : 'FAIL',
        'requests' => count($durations),
        'p95_ms' => $p95,
        'max_ms' => (float) (end($durations) ?: 0),
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failures === [] ? 0 : 1);
} finally {
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}
