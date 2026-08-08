<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\AppPaths;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
if ($base === '' || !str_starts_with($base, 'http://localhost')) {
    fwrite(STDERR, "ERROR: APP_URL debe apuntar a localhost.\n");
    exit(2);
}

$email = 'qa2265@local.invalid';
$password = 'QaLocal!2265-Only-9zK';
$cookie = dirname(__DIR__) . '/storage/tmp/http-cert-2265-' . bin2hex(random_bytes(5)) . '.cookie';
$snapshotMarker = AppPaths::storage('cache/database-snapshot-active.json');
$snapshotExisted = is_file($snapshotMarker);
$snapshotOriginal = $snapshotExisted ? file_get_contents($snapshotMarker) : null;
if (!$snapshotExisted) {
    $snapshotDirectory = dirname($snapshotMarker);
    if (!is_dir($snapshotDirectory)) {
        mkdir($snapshotDirectory, 0750, true);
    }
    file_put_contents(
        $snapshotMarker,
        json_encode([
            'snapshot_id' => 'http-cert-2265',
            'format' => 3,
            'heartbeat_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR),
        LOCK_EX
    );
}
$pdo = Database::connection();
$pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
$create = $pdo->prepare(
    'INSERT INTO users
       (name,email,password_hash,role,status,is_temporary,must_change_password)
     VALUES (?,?,?,"admin",1,0,0)'
);
$create->execute(['QA Local 2.26.5', $email, password_hash($password, PASSWORD_DEFAULT)]);

/** @return array{status:int,body:string,time:float} */
function request2265(
    string $url,
    string $cookie,
    ?array $post = null,
    ?string $origin = null
): array {
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No fue posible iniciar cURL local.');
    }
    $headers = [];
    if ($origin !== null) {
        $headers[] = 'Origin: ' . $origin;
        $headers[] = 'Referer: ' . $origin . '/erp-meli-local/login';
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $started = microtime(true);
    $body = curl_exec($curl);
    $elapsed = microtime(true) - $started;
    if (!is_string($body)) {
        $message = curl_error($curl);
        unset($curl);
        throw new RuntimeException('Falló HTTP local: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    return ['status' => $status, 'body' => $body, 'time' => $elapsed];
}

try {
    $login = request2265($base . '/login', $cookie);
    if ($login['status'] !== 200
        || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $match) !== 1) {
        throw new RuntimeException('Login local no entregó un CSRF válido.');
    }
    $authenticated = request2265(
        $base . '/login',
        $cookie,
        [
            '_token' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
            'email' => $email,
            'password' => $password,
        ],
        'http://localhost'
    );
    if (!in_array($authenticated['status'], [302, 303], true)) {
        $title = preg_match('/<title>([^<]+)<\/title>/i', $authenticated['body'], $titleMatch) === 1
            ? trim(html_entity_decode($titleMatch[1], ENT_QUOTES, 'UTF-8'))
            : 'sin título';
        throw new RuntimeException(
            'La autenticación HTTP local fue rechazada (HTTP '
            . $authenticated['status'] . ', ' . $title . ').'
        );
    }

    $routes = [
        '/',
        '/settings',
        '/settings/cron',
        '/settings/diagnostics',
        '/settings/modules',
        '/sales',
        '/sales-control',
        '/settings/database-maintenance',
        '/settings/backups',
    ];
    $metrics = [];
    foreach ($routes as $route) {
        $result = request2265($base . $route, $cookie);
        if (!in_array($result['status'], [200, 302, 303], true)) {
            throw new RuntimeException('Ruta local no disponible: ' . $route);
        }
        if (preg_match('/SQLSTATE|DB_PASS|access_token|refresh_token/i', $result['body']) === 1) {
            throw new RuntimeException('Una ruta local expuso información sensible: ' . $route);
        }
        $metrics[$route] = round($result['time'] * 1000, 1);
    }

    $multi = curl_multi_init();
    $handles = [];
    for ($index = 0; $index < 20; $index++) {
        $handle = curl_init($base . '/');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar concurrencia local.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEFILE => $cookie,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    $started = microtime(true);
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('Falló la prueba HTTP concurrente.');
        }
        if ($running > 0) {
            curl_multi_select($multi, 0.25);
        }
    } while ($running > 0);
    $concurrentMs = round((microtime(true) - $started) * 1000, 1);
    foreach ($handles as $handle) {
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!in_array($httpStatus, [200, 302, 303], true)) {
            throw new RuntimeException('Una petición concurrente no terminó correctamente.');
        }
        curl_multi_remove_handle($multi, $handle);
        unset($handle);
    }
    curl_multi_close($multi);

    echo json_encode([
        'status' => 'PASS',
        'routes_ms' => $metrics,
        'same_session_requests' => 20,
        'concurrent_total_ms' => $concurrentMs,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    if (!$snapshotExisted) {
        @unlink($snapshotMarker);
    } elseif (is_string($snapshotOriginal)) {
        file_put_contents($snapshotMarker, $snapshotOriginal, LOCK_EX);
    }
}
