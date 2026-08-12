<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Services\AppVersionService;
use App\Services\InstalledVersionMarkerService;

require dirname(__DIR__) . '/bootstrap.php';

$base = rtrim((string) Env::get('APP_URL', ''), '/');
if (!str_starts_with($base, 'http://127.0.0.1:') || !str_ends_with($base, '/erp-meli')) {
    fwrite(STDERR, "queue_v4_http_requires_local_subfolder\n");
    exit(2);
}

$pdo = Database::connectionFresh();
$version = AppVersionService::fileVersion();
$email = 'queue-v4-2372-' . bin2hex(random_bytes(4)) . '@local.invalid';
$password = 'QueueV4!2372-' . bin2hex(random_bytes(8));
$cookie = sys_get_temp_dir() . '/queue-v4-2372-' . bin2hex(random_bytes(5)) . '.cookie';

/** @return array{status:int,body:string,content_type:string,elapsed_ms:float} */
function queueV4Http2372(string $url, string $cookie, ?array $post = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('curl_unavailable');
    }
    $parts = parse_url($url);
    $origin = (string) ($parts['scheme'] ?? 'http') . '://' . (string) ($parts['host'] ?? '127.0.0.1')
        . ':' . (int) ($parts['port'] ?? 80);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => ['Origin: ' . $origin, 'Referer: ' . $origin . '/erp-meli/settings/cron', 'Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $started = hrtime(true);
    $body = curl_exec($curl);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    if (!is_string($body)) {
        throw new RuntimeException('curl_failed:' . curl_error($curl));
    }
    return [
        'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'body' => $body,
        'content_type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
        'elapsed_ms' => $elapsedMs,
    ];
}

try {
    $pdo->prepare(
        'INSERT INTO users(name,email,password_hash,role,status,is_temporary,must_change_password)
         VALUES (?,?,?,"admin",1,0,0)'
    )->execute(['Queue V4 HTTP 2.37.2', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $pdo->prepare(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES ('app.version',?,0,'system')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0"
    )->execute([$version]);
    if (!(new InstalledVersionMarkerService())->write($version, '294_queue_v4_clean_greenfield_2_37_0.sql')) {
        throw new RuntimeException('marker_write_failed');
    }
    $encryptedRefresh = Crypto::encrypt('local-http-refresh');
    $company = $pdo->prepare('INSERT INTO companies(id,name,nit,status) VALUES (?,?,?,1)');
    $account = $pdo->prepare(
        'INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status)
         VALUES (?,?,?,?,"conectado")'
    );
    $token = $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR))'
    );
    foreach ([[1, 1, 'HTTP Company 1', 'HTTP-1', 1001], [2, 4, 'HTTP Company 4', 'HTTP-4', 1002], [3, 5, 'HTTP Company 5', 'HTTP-5', 1003]] as [$accountId, $companyId, $companyName, $nit, $remoteId]) {
        $company->execute([$companyId, $companyName, $nit]);
        $account->execute([$accountId, $companyId, 'Account ' . $accountId, $remoteId]);
        $token->execute([$accountId, Crypto::encrypt('local-http-access-' . $accountId), $encryptedRefresh]);
    }

    $login = queueV4Http2372($base . '/login', $cookie);
    if ($login['status'] !== 200 || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $loginCsrf) !== 1) {
        throw new RuntimeException('login_csrf_missing');
    }
    $auth = queueV4Http2372($base . '/login', $cookie, [
        '_token' => html_entity_decode($loginCsrf[1], ENT_QUOTES, 'UTF-8'),
        'email' => $email,
        'password' => $password,
    ]);
    if (!in_array($auth['status'], [302, 303], true)) {
        throw new RuntimeException('login_failed:' . $auth['status']);
    }
    $shell = queueV4Http2372($base . '/settings/cron', $cookie);
    if ($shell['status'] !== 200 || !str_contains($shell['body'], 'Comprobar y certificar')) {
        throw new RuntimeException('queue_v4_shell_failed');
    }
    if (preg_match('/action="[^"]*queue-v4\/readiness"[^>]*>\s*<input[^>]*name="_token" value="([^"]+)"/s', $shell['body'], $csrf) !== 1) {
        throw new RuntimeException('queue_v4_csrf_missing');
    }
    $status = queueV4Http2372($base . '/settings/cron/queue-v4.json', $cookie);
    $snapshot = json_decode($status['body'], true, 64, JSON_THROW_ON_ERROR);
    if ($status['status'] !== 200 || ($snapshot['state'] ?? '') !== 'READY_TO_TEST') {
        throw new RuntimeException('queue_v4_get_not_ready:' . $status['body']);
    }
    if ($status['elapsed_ms'] >= 5000) {
        throw new RuntimeException('queue_v4_get_too_slow:' . $status['elapsed_ms']);
    }
    $readiness = queueV4Http2372($base . '/settings/cron/queue-v4/readiness', $cookie, [
        '_token' => html_entity_decode($csrf[1], ENT_QUOTES, 'UTF-8'),
        'admin_password' => $password,
    ]);
    $result = json_decode($readiness['body'], true, 64, JSON_THROW_ON_ERROR);
    if ($readiness['status'] !== 200 || ($result['state'] ?? '') !== 'CERTIFIED') {
        throw new RuntimeException('queue_v4_post_failed:' . $readiness['body']);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() !== 0) {
        throw new RuntimeException('readiness_created_jobs');
    }
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'base_path' => '/erp-meli',
        'get_http' => 200,
        'get_state' => 'READY_TO_TEST',
        'get_elapsed_ms' => round($status['elapsed_ms'], 3),
        'post_http' => 200,
        'post_state' => 'CERTIFIED',
        'readiness_gets' => 3,
        'queue_jobs_created' => 0,
        'meli_business_writes' => 0,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
}
