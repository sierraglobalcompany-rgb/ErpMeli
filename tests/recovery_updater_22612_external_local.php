<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
if ($base === '' || !str_starts_with($base, 'http://localhost')) {
    fwrite(STDERR, "ERROR: APP_URL debe apuntar a localhost.\n");
    exit(2);
}

$email = 'qa-updater-22612@local.invalid';
$password = 'QaUpdater!22612-External-v7';
$cookie = AppPaths::storage('tmp/updater-22612-' . bin2hex(random_bytes(5)) . '.cookie');
$migrationName = '000_qa_updater_external_backup.sql';
$migrationPath = dirname(__DIR__) . '/database/migrations/' . $migrationName;
$markerPaths = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$markerState = [];
$userId = 0;
$failureMessage = null;
$pdo = Database::connection();

foreach ($markerPaths as $path) {
    $markerState[$path] = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);
}

/**
 * @return array{status:int,body:string,time:float,location:string}
 */
function updaterRequest22612(
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
        $headers[] = 'Referer: ' . $origin . '/erp-meli-local/actualizar.php';
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $started = microtime(true);
    $response = curl_exec($curl);
    $elapsed = microtime(true) - $started;
    if (!is_string($response)) {
        $message = curl_error($curl);
        unset($curl);
        throw new RuntimeException('Falló HTTP local: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerBytes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($response, 0, $headerBytes);
    $body = substr($response, $headerBytes);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headersRaw, $match) === 1) {
        $location = trim($match[1]);
    }
    unset($curl);

    return [
        'status' => $status,
        'body' => $body,
        'time' => $elapsed,
        'location' => $location,
    ];
}

try {
    file_put_contents(
        $migrationPath,
        "-- Prueba temporal de respaldo externo confirmado.\nSET @erp_meli_qa_updater_22612 = 1;\n",
        LOCK_EX
    );
    $active = (int) $pdo->query(
        "SELECT COUNT(*) FROM system_backup_archives
         WHERE deleted_at IS NULL
           AND status IN (
               'prepared','queued','creating','verifying',
               'ready_pending_release','cancel_requested','deleting'
           )"
    )->fetchColumn();
    if ($active !== 0) {
        throw new RuntimeException('Existe una copia activa; la prueba no alterará su estado.');
    }

    $pdo->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([$migrationName]);
    $pdo->prepare('DELETE FROM system_update_migrations WHERE migration_key=?')->execute([$migrationName]);
    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    $create = $pdo->prepare(
        'INSERT INTO users
           (name,email,password_hash,role,status,is_temporary,must_change_password)
         VALUES (?,?,?,"admin",1,0,0)'
    );
    $create->execute(['QA Actualizador 2.26.12', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $legacyBefore = (int) $pdo->query('SELECT COUNT(*) FROM system_update_backups')->fetchColumn();
    $archiveBefore = (int) $pdo->query('SELECT COUNT(*) FROM system_backup_archives WHERE deleted_at IS NULL')->fetchColumn();

    $login = updaterRequest22612($base . '/login.php', $cookie);
    if (
        $login['status'] !== 200
        || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('Login directo no entregó CSRF válido.');
    }
    $authenticated = updaterRequest22612(
        $base . '/login.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'email' => $email,
            'password' => $password,
        ],
        'http://localhost'
    );
    if (!in_array($authenticated['status'], [302, 303], true)) {
        throw new RuntimeException('El login rechazó el administrador temporal.');
    }

    $updater = updaterRequest22612($base . '/actualizar.php', $cookie);
    if (
        $updater['status'] !== 200
        || !str_contains($updater['body'], $migrationName)
        || !str_contains($updater['body'], 'value="external" checked')
        || preg_match('/name="_token" value="([^"]+)"/', $updater['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('El actualizador no mostró respaldo externo por defecto.');
    }

    $authorized = updaterRequest22612(
        $base . '/actualizar.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'action' => 'authorize',
            'password' => $password,
            'backup_choice' => 'external',
            'backup_waiver' => 'RESPALDO EXTERNO CONFIRMADO',
        ],
        'http://localhost'
    );
    if (!in_array($authorized['status'], [302, 303], true)) {
        throw new RuntimeException('Autorizar con respaldo externo no aplicó PRG seguro.');
    }
    $authorizedPage = updaterRequest22612($base . '/actualizar.php?result=advanced', $cookie);
    if (
        $authorizedPage['status'] !== 200
        || !str_contains($authorizedPage['body'], 'copia externa confirmada')
        || str_contains($authorizedPage['body'], 'Respaldo en preparación')
        || str_contains($authorizedPage['body'], 'respaldo necesita revisión')
        || preg_match('/name="_token" value="([^"]+)"/', $authorizedPage['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('La decisión de respaldo externo no quedó visible y estable.');
    }

    $migrated = updaterRequest22612(
        $base . '/actualizar.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'action' => 'migrate',
        ],
        'http://localhost'
    );
    if (!in_array($migrated['status'], [302, 303], true)) {
        throw new RuntimeException('Migrar con respaldo externo no aplicó PRG seguro.');
    }
    $migratedPage = updaterRequest22612($base . '/actualizar.php?result=stopped', $cookie);

    $migrationCheck = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $migrationCheck->execute([$migrationName]);
    if ((int) $migrationCheck->fetchColumn() !== 1) {
        $cleanBody = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $migratedPage['body']);
        throw new RuntimeException(
            'La migración temporal no se aplicó con respaldo externo confirmado. page='
            . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $cleanBody))), 0, 900)
        );
    }
    $legacyAfter = (int) $pdo->query('SELECT COUNT(*) FROM system_update_backups')->fetchColumn();
    $archiveAfter = (int) $pdo->query('SELECT COUNT(*) FROM system_backup_archives WHERE deleted_at IS NULL')->fetchColumn();
    if ($legacyAfter !== $legacyBefore || $archiveAfter !== $archiveBefore) {
        throw new RuntimeException('El respaldo externo creó una copia interna inesperada.');
    }

    echo json_encode([
        'status' => 'PASS',
        'external_backup_confirmed' => true,
        'migration_applied' => true,
        'internal_backup_created' => false,
        'remote_transport' => false,
        'authorize_ms' => round($authorized['time'] * 1000, 1),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $failureMessage = $error->getMessage();
} finally {
    @unlink($cookie);
    @unlink($migrationPath);
    $pdo->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([$migrationName]);
    $pdo->prepare('DELETE FROM system_update_migrations WHERE migration_key=?')->execute([$migrationName]);
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    } else {
        $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    }
    foreach ($markerState as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}

if (is_string($failureMessage)) {
    fwrite(STDERR, $failureMessage . PHP_EOL);
    exit(1);
}
