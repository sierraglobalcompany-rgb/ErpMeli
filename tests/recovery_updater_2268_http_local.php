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

$email = 'qa-updater-2268@local.invalid';
$password = 'QaUpdater!2268-Only-7vR';
$cookie = AppPaths::storage('tmp/updater-2268-' . bin2hex(random_bytes(5)) . '.cookie');
$markerPaths = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$pendingMigrationName = '000_qa_updater_identity_pending.sql';
$pendingMigrationPath = dirname(__DIR__) . '/database/migrations/' . $pendingMigrationName;
$markerState = [];
$backupId = 0;
$userId = 0;
$failureMessage = null;
$pdo = Database::connection();

foreach ($markerPaths as $path) {
    $markerState[$path] = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);
}

/** @return array{status:int,body:string,time:float,location:string} */
function updaterRequest2268(
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
        $pendingMigrationPath,
        "-- Prueba temporal para mantener el flujo del actualizador pendiente.\nSELECT 1;\n",
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

    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    $pdo->prepare('DELETE FROM login_attempts WHERE email_hash=?')->execute([hash('sha256', $email)]);
    $create = $pdo->prepare(
        'INSERT INTO users
           (name,email,password_hash,role,status,is_temporary,must_change_password)
         VALUES (?,?,?,"admin",1,0,0)'
    );
    $create->execute(['QA Actualizador 2.26.8', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $legacyBefore = (int) $pdo->query('SELECT COUNT(*) FROM system_update_backups')->fetchColumn();
    $migrationsBefore = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

    $login = updaterRequest2268($base . '/login.php', $cookie);
    if (
        $login['status'] !== 200
        || preg_match('/name="_token" value="([^"]+)"/', $login['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('Login directo no entregó un CSRF válido.');
    }
    $authenticated = updaterRequest2268(
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
        throw new RuntimeException('El login directo rechazó el administrador temporal de prueba.');
    }

    $updater = updaterRequest2268($base . '/actualizar.php', $cookie);
    if (
        $updater['status'] !== 200
        || !str_contains($updater['body'], 'Actualización lista para continuar')
        || !str_contains($updater['body'], 'Migraciones pendientes')
        || preg_match('/name="_token" value="([^"]+)"/', $updater['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('El actualizador local no mostró un estado pendiente utilizable.');
    }
    if (!str_contains($updater['body'], 'Confirmar desde el inicio de sesión')) {
        throw new RuntimeException('El actualizador no mostró la recuperación por login normal.');
    }
    $restart = updaterRequest2268(
        $base . '/actualizar.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'action' => 'restart_login',
        ],
        'http://localhost'
    );
    if (
        $restart['status'] !== 303
        || !str_contains($restart['location'], 'login.php?reauth=update')
    ) {
        throw new RuntimeException('La recuperación de identidad no abrió el login normal.');
    }

    $reauthLogin = updaterRequest2268($base . '/login.php?reauth=update', $cookie);
    if (
        $reauthLogin['status'] !== 200
        || !str_contains($reauthLogin['body'], 'Confirmar identidad y continuar')
        || !str_contains($reauthLogin['body'], htmlspecialchars($email, ENT_QUOTES, 'UTF-8'))
        || preg_match('/name="_token" value="([^"]+)"/', $reauthLogin['body'], $tokenMatch) !== 1
    ) {
        throw new RuntimeException('El login de recuperación no conservó el administrador esperado.');
    }
    $reauthenticated = updaterRequest2268(
        $base . '/login.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'email' => $email,
            'password' => $password,
        ],
        'http://localhost'
    );
    if ($reauthenticated['status'] !== 303) {
        throw new RuntimeException('El inicio de sesión normal no confirmó la identidad.');
    }

    $choicePage = updaterRequest2268($base . '/actualizar.php?result=reauthenticated', $cookie);
    if (
        $choicePage['status'] !== 200
        || !str_contains($choicePage['body'], 'Elija si usará una copia externa')
        || !str_contains($choicePage['body'], 'Usar respaldo externo y continuar')
        || !str_contains($choicePage['body'], 'value="external" checked')
        || !str_contains($choicePage['body'], 'RESPALDO EXTERNO CONFIRMADO')
        || !str_contains($choicePage['body'], 'Crear respaldo seguro local')
        || str_contains($choicePage['body'], 'La contraseña no coincide')
        || str_contains($choicePage['body'], 'respaldo necesita revisión')
    ) {
        throw new RuntimeException(
            'El login normal no mostró la decisión explícita de respaldo: status=' . $choicePage['status']
            . ' external=' . (str_contains($choicePage['body'], 'Usar respaldo externo y continuar') ? '1' : '0')
            . ' secure=' . (str_contains($choicePage['body'], 'Crear respaldo seguro local') ? '1' : '0')
            . ' password_error=' . (str_contains($choicePage['body'], 'La contraseña no coincide') ? '1' : '0')
            . ' text=' . substr(
                trim((string) preg_replace('/\s+/', ' ', strip_tags($choicePage['body']))),
                0,
                420
            )
        );
    }
    $archiveBeforeExplicitChoice = $pdo->prepare(
        'SELECT COUNT(*) FROM system_backup_archives WHERE requested_by=? AND purpose="pre_update" AND deleted_at IS NULL'
    );
    $archiveBeforeExplicitChoice->execute([$userId]);
    if ((int) $archiveBeforeExplicitChoice->fetchColumn() !== 0) {
        throw new RuntimeException('Confirmar identidad creó una copia automáticamente.');
    }
    if (preg_match('/name="_token" value="([^"]+)"/', $choicePage['body'], $tokenMatch) !== 1) {
        throw new RuntimeException('El actualizador no conservó CSRF para elegir el respaldo.');
    }

    $secureChoice = updaterRequest2268(
        $base . '/actualizar.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'action' => 'authorize',
            'password' => $password,
            'backup_choice' => 'secure',
        ],
        'http://localhost'
    );
    if (!in_array($secureChoice['status'], [302, 303], true)) {
        throw new RuntimeException('Elegir respaldo seguro local no aplicó PRG seguro.');
    }
    $pending = updaterRequest2268($base . '/actualizar.php?result=advanced', $cookie);
    if (
        $pending['status'] !== 200
        || !str_contains($pending['body'], 'Respaldo en preparación')
        || !str_contains($pending['body'], 'Ver estado del respaldo')
        || !str_contains($pending['body'], 'Recuperar respaldo')
        || !str_contains($pending['body'], 'Cancelar solicitud')
        || str_contains($pending['body'], 'La contraseña no coincide')
    ) {
        throw new RuntimeException(
            'Elegir respaldo seguro local no produjo el respaldo reanudable: status=' . $pending['status']
            . ' preparing=' . (str_contains($pending['body'], 'Respaldo en preparación') ? '1' : '0')
            . ' check=' . (str_contains($pending['body'], 'Ver estado del respaldo') ? '1' : '0')
            . ' password_error=' . (str_contains($pending['body'], 'La contraseña no coincide') ? '1' : '0')
            . ' text=' . substr(
                trim((string) preg_replace('/\s+/', ' ', strip_tags($pending['body']))),
                0,
                420
            )
        );
    }
    if (preg_match('/name="_token" value="([^"]+)"/', $pending['body'], $tokenMatch) !== 1) {
        throw new RuntimeException('El actualizador pendiente no conservó CSRF para comprobar el gate.');
    }

    $blockedMigration = updaterRequest2268(
        $base . '/actualizar.php',
        $cookie,
        [
            '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES, 'UTF-8'),
            'action' => 'migrate',
        ],
        'http://localhost'
    );
    if (!in_array($blockedMigration['status'], [302, 303], true)) {
        throw new RuntimeException('Migrar con respaldo pendiente no aplicó PRG seguro.');
    }
    $blockedPage = updaterRequest2268($base . '/actualizar.php?result=stopped', $cookie);
    if (
        $blockedPage['status'] !== 200
        || !str_contains($blockedPage['body'], 'El respaldo todavía')
        || str_contains($blockedPage['body'], 'La contraseña no coincide')
    ) {
        throw new RuntimeException('El gate de respaldo pendiente no mostró el mensaje correcto.');
    }
    $migrationsAfterBlockedAttempt = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($migrationsAfterBlockedAttempt !== $migrationsBefore) {
        throw new RuntimeException('El actualizador aplicó una migración aunque el respaldo seguía pendiente.');
    }

    $archive = $pdo->prepare(
        'SELECT id,status,purpose,format_version
           FROM system_backup_archives
          WHERE requested_by=? AND purpose="pre_update"
          ORDER BY id DESC LIMIT 1'
    );
    $archive->execute([$userId]);
    $row = $archive->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($row)
        || (string) $row['status'] !== 'queued'
        || (string) $row['purpose'] !== 'pre_update'
        || (int) $row['format_version'] !== 3
    ) {
        throw new RuntimeException('El actualizador no creó una copia V3 pendiente y exacta.');
    }
    $backupId = (int) $row['id'];
    $job = $pdo->prepare(
        'SELECT status,job_type FROM system_backup_jobs WHERE backup_id=? ORDER BY id LIMIT 1'
    );
    $job->execute([$backupId]);
    $jobRow = $job->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($jobRow)
        || (string) $jobRow['status'] !== 'pending'
        || (string) $jobRow['job_type'] !== 'create'
    ) {
        throw new RuntimeException('La copia V3 no quedó preparada para el lanzador CLI.');
    }
    $legacyAfter = (int) $pdo->query('SELECT COUNT(*) FROM system_update_backups')->fetchColumn();
    if ($legacyAfter !== $legacyBefore) {
        throw new RuntimeException('El actualizador todavía ejecutó el respaldo monolítico heredado.');
    }
    if (
        !is_file(AppPaths::storage('cache/local-maintenance-state.json'))
        || !is_file(AppPaths::storage('cache/database-snapshot-active.json'))
    ) {
        throw new RuntimeException('La coordinación única o el freeze de snapshot no quedó persistido.');
    }

    echo json_encode([
        'status' => 'PASS',
        'identity_confirmed' => true,
        'identity_path' => 'normal_login',
        'backup' => 'queued_v3_cli',
        'legacy_web_dump' => false,
        'migration_applied' => false,
        'remote_transport' => false,
        'reauthenticate_ms' => round($reauthenticated['time'] * 1000, 1),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $failureMessage = $error->getMessage();
} finally {
    @unlink($cookie);
    @unlink($pendingMigrationPath);
    if ($backupId > 0) {
        $pdo->prepare('DELETE FROM system_backup_audit_events WHERE backup_id=?')->execute([$backupId]);
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=?')->execute([$backupId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM system_backup_audit_events WHERE user_id=?')->execute([$userId]);
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
