<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
if ($base === '' || !str_starts_with($base, 'http://localhost')) {
    fwrite(STDERR, "ERROR: APP_URL debe apuntar a localhost.\n");
    exit(2);
}

$email = 'qa-recovery-csrf@local.invalid';
$password = 'QaRecoveryCsrf!Only-9wF';
$cookie = AppPaths::storage('tmp/recovery-csrf-' . bin2hex(random_bytes(5)) . '.cookie');
$migrationPath = dirname(__DIR__) . '/database/migrations/999_qa_recovery_csrf_pending.sql';
$pdo = Database::connection();
$userId = 0;

/**
 * @param array<string,string> $headers
 * @param array<string,string>|null $post
 * @return array{status:int,body:string,location:string}
 */
function recoveryCsrfRequest(
    string $url,
    string $cookie,
    ?array $post = null,
    array $headers = []
): array {
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No fue posible iniciar cURL local.');
    }
    $httpHeaders = [];
    foreach ($headers as $name => $value) {
        $httpHeaders[] = $name . ': ' . $value;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_HEADER => true,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $response = curl_exec($curl);
    if (!is_string($response)) {
        $message = curl_error($curl);
        unset($curl);
        throw new RuntimeException('Falló HTTP local: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerBytes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($response, 0, $headerBytes);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headersRaw, $match) === 1) {
        $location = trim($match[1]);
    }
    $body = substr($response, $headerBytes);
    unset($curl);
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

/** @return non-empty-string */
function recoveryCsrfSessionId(string $cookie): string
{
    $contents = (string) file_get_contents($cookie);
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (str_starts_with($line, '#HttpOnly_')) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $columns = explode("\t", $line);
        if (count($columns) >= 7 && $columns[5] === 'erp_meli_session' && $columns[6] !== '') {
            return $columns[6];
        }
    }
    throw new RuntimeException('No se encontró la cookie de sesión del ERP.');
}

try {
    file_put_contents(
        $migrationPath,
        "-- Prueba local temporal: obliga al actualizador a renderizar el formulario.\nSELECT 1;\n",
        LOCK_EX
    );
    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    $insert = $pdo->prepare(
        'INSERT INTO users
           (name,email,password_hash,role,status,is_temporary,must_change_password)
         VALUES (?,?,?,"admin",1,0,0)'
    );
    $insert->execute(['QA CSRF recuperación', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();

    $login = recoveryCsrfRequest($base . '/login.php', $cookie);
    if (
        $login['status'] !== 200
        || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('El login no entregó el token inicial.');
    }
    $authenticated = recoveryCsrfRequest(
        $base . '/login.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'email' => $email,
            'password' => $password,
        ],
        [
            'Origin' => 'http://localhost',
            'Referer' => $base . '/login.php',
        ]
    );
    if ($authenticated['status'] !== 303) {
        throw new RuntimeException('No se pudo crear la sesión administrativa local.');
    }

    // Simula exactamente una sesión creada por una versión anterior: conserva
    // al administrador, pero todavía no tiene token CSRF.
    $targetSession = recoveryCsrfSessionId($cookie);
    Session::closeReadOnly();
    session_id($targetSession);
    Session::start();
    unset($_SESSION['_csrf']);
    session_write_close();

    $updater = recoveryCsrfRequest($base . '/actualizar.php', $cookie);
    if (
        $updater['status'] !== 200
        || preg_match('/name="_token" value="([^"]+)"/', $updater['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException(
            'El actualizador no regeneró el token faltante: HTTP '
            . $updater['status'] . ' ' . $updater['location'] . ' '
            . trim(strip_tags(substr($updater['body'], 0, 300)))
        );
    }
    $csrf = html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8');

    // Comprueba que el token del HTML quedó guardado realmente, no solamente
    // en el snapshot de memoria del GET.
    session_id($targetSession);
    Session::start();
    $persisted = is_string($_SESSION['_csrf'] ?? null) ? $_SESSION['_csrf'] : '';
    session_write_close();
    if ($persisted === '' || !hash_equals($persisted, $csrf)) {
        throw new RuntimeException('El token mostrado por el actualizador no quedó persistido.');
    }

    $rejected = recoveryCsrfRequest(
        $base . '/actualizar.php',
        $cookie,
        ['_token' => str_repeat('0', 64), 'action' => 'restart_login'],
        [
            'Origin' => 'http://localhost',
            'Referer' => $base . '/actualizar.php',
        ]
    );
    if (
        $rejected['status'] !== 303
        || !str_contains($rejected['location'], 'result=stopped')
    ) {
        throw new RuntimeException('El actualizador aceptó deliberadamente un token CSRF incorrecto.');
    }

    $restart = recoveryCsrfRequest(
        $base . '/actualizar.php',
        $cookie,
        ['_token' => $csrf, 'action' => 'restart_login'],
        [
            'Origin' => 'http://localhost',
            'Referer' => $base . '/actualizar.php',
        ]
    );
    if (
        $restart['status'] !== 303
        || !str_contains($restart['location'], 'login.php?reauth=update')
        || str_contains($restart['location'], 'result=stopped')
    ) {
        throw new RuntimeException(
            'El POST con el token recién regenerado fue rechazado: HTTP '
            . $restart['status'] . ' ' . $restart['location']
        );
    }

    echo json_encode([
        'status' => 'PASS',
        'legacy_session_without_csrf' => true,
        'token_persisted_before_session_close' => true,
        'invalid_token_rejected' => true,
        'post_accepted' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
    @unlink($migrationPath);
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    } else {
        $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    }
}
