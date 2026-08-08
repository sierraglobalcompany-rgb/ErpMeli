<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$runtimeVersion = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
if (!str_starts_with($base, 'http://localhost')) {
    fwrite(STDERR, "ERROR: esta prueba solo puede ejecutarse contra localhost.\n");
    exit(2);
}

$email = 'qa-cron-22810-' . bin2hex(random_bytes(4)) . '@local.invalid';
$password = 'QaCron!22810-' . bin2hex(random_bytes(8));
$cookie = dirname(__DIR__) . '/storage/tmp/cron-http-22810-' . bin2hex(random_bytes(5)) . '.cookie';
$pdo = Database::connectionFresh();
$pdo->prepare(
    'INSERT INTO users (name,email,password_hash,role,status,is_temporary,must_change_password)
     VALUES (?,?,?,"admin",1,0,0)'
)->execute(['QA Cron 2.28.10', $email, password_hash($password, PASSWORD_DEFAULT)]);

/** @return array{status:int,body:string,time_ms:float,content_type:string} */
function cronHttp22810(string $url, string $cookie, ?array $post = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No fue posible iniciar cURL local.');
    }
    $parts = parse_url($url);
    $origin = (string) ($parts['scheme'] ?? 'http') . '://' . (string) ($parts['host'] ?? 'localhost')
        . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => ['Origin: ' . $origin, 'Referer: ' . $origin . '/'],
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $started = microtime(true);
    $body = curl_exec($curl);
    if (!is_string($body)) {
        $message = curl_error($curl);
        unset($curl);
        throw new RuntimeException('Falló HTTP local: ' . $message);
    }
    $result = [
        'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'body' => $body,
        'time_ms' => round((microtime(true) - $started) * 1000, 1),
        'content_type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
    ];
    unset($curl);
    return $result;
}

try {
    $login = cronHttp22810($base . '/login', $cookie);
    if ($login['status'] !== 200 || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $csrf) !== 1) {
        throw new RuntimeException('Login local no entregó CSRF.');
    }
    $auth = cronHttp22810($base . '/login', $cookie, [
        '_token' => html_entity_decode($csrf[1], ENT_QUOTES, 'UTF-8'),
        'email' => $email,
        'password' => $password,
    ]);
    if (!in_array($auth['status'], [302, 303], true)) {
        throw new RuntimeException('Login local rechazado: HTTP ' . $auth['status']);
    }

    $metrics = [];
    $shell = cronHttp22810($base . '/settings/cron', $cookie);
    if ($shell['status'] !== 200 || !str_contains($shell['body'], 'Centro de Automatización')) {
        preg_match('/<title>([^<]+)<\/title>/i', $shell['body'], $title);
        throw new RuntimeException(
            'La pantalla Cron no renderizó el shell operativo (HTTP ' . $shell['status']
            . ', ' . trim((string) ($title[1] ?? 'sin título')) . ').'
        );
    }
    $metrics['shell_ms'] = $shell['time_ms'];

    foreach (['overview.json', 'tasks.json', 'section.json'] as $endpoint) {
        $response = cronHttp22810($base . '/settings/cron/' . $endpoint, $cookie);
        $payload = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !str_contains($response['content_type'], 'application/json') || !is_array($payload)) {
            throw new RuntimeException(
                $endpoint . ' no respondió JSON real (HTTP ' . $response['status']
                . ', tipo ' . $response['content_type']
                . ', cuerpo ' . substr(trim(strip_tags($response['body'])), 0, 180) . ').'
            );
        }
        if (($payload['version'] ?? $payload['overview']['version'] ?? null) !== $runtimeVersion) {
            throw new RuntimeException($endpoint . ' no informó la versión del runtime.');
        }
        $metrics[$endpoint . '_ms'] = $response['time_ms'];
    }

    // La primera lectura puede construir el snapshot. Las siguientes deben
    // reutilizarlo para medir el presupuesto operativo real de polling.
    $warmOverview = [];
    $warmCacheStates = [];
    for ($sample = 0; $sample < 15; $sample++) {
        $response = cronHttp22810($base . '/settings/cron/overview.json', $cookie);
        $warmPayload = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($warmPayload)) {
            throw new RuntimeException('Una lectura caliente de overview.json falló.');
        }
        $warmOverview[] = $response['time_ms'];
        $warmCacheStates[] = (string) ($warmPayload['cache'] ?? 'unknown');
    }
    sort($warmOverview, SORT_NUMERIC);
    $p95Index = max(0, (int) ceil(count($warmOverview) * 0.95) - 1);
    $metrics['overview_warm_p95_ms'] = $warmOverview[$p95Index];
    if ($metrics['overview_warm_p95_ms'] >= 300.0) {
        throw new RuntimeException(
            'overview.json caliente excedió 300 ms p95: '
            . number_format($metrics['overview_warm_p95_ms'], 1, '.', '') . ' ms; caché='
            . implode(',', array_values(array_unique($warmCacheStates))) . '; muestras='
            . implode(',', array_map(static fn (float $value): string => number_format($value, 1, '.', ''), $warmOverview))
            . '.'
        );
    }

    $rhythmPage = cronHttp22810($base . '/settings/cron/rhythm', $cookie);
    if ($rhythmPage['status'] !== 200 || !str_contains($rhythmPage['body'], 'Ritmo')) {
        throw new RuntimeException('La configuración humana del ritmo no abrió correctamente.');
    }
    foreach ([
        '/settings/cron/rhythm/preview.json',
        '/settings/api-health/overview.json',
        '/settings/api-health/protection.json',
    ] as $endpoint) {
        $response = cronHttp22810($base . $endpoint, $cookie);
        $payload = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !str_contains($response['content_type'], 'application/json') || !is_array($payload)) {
            throw new RuntimeException($endpoint . ' no respondió como lectura JSON autenticada.');
        }
        $metrics[basename($endpoint) . '_ms'] = $response['time_ms'];
    }

    $multi = curl_multi_init();
    $handles = [];
    for ($index = 0; $index < 20; $index++) {
        $handle = curl_init($base . '/settings/cron/overview.json');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar la concurrencia HTTP local.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_COOKIEFILE => $cookie,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    $concurrentStarted = microtime(true);
    do {
        $state = curl_multi_exec($multi, $active);
        if ($active > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($active > 0 && $state === CURLM_OK);
    foreach ($handles as $handle) {
        $body = curl_multi_getcontent($handle);
        if ((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200
            || !is_string($body)
            || !is_array(json_decode($body, true))) {
            throw new RuntimeException('Una de las 20 lecturas simultáneas falló.');
        }
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    $metrics['concurrent_20_total_ms'] = round((microtime(true) - $concurrentStarted) * 1000, 1);

    echo json_encode([
        'status' => 'PASS',
        'metrics' => $metrics,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
}
