<?php

declare(strict_types=1);

/**
 * Prueba conductual del coordinador interactivo de saneamiento.
 *
 * Usa la base local de desarrollo ya restaurada. Solo crea y elimina sesiones
 * técnicas de prueba; no modifica tablas comerciales ni ejecuta transporte
 * remoto.
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\BackupArchiveService;
use App\Services\BackupKeyringService;
use App\Services\DatabaseMaintenanceService;
use App\Services\PhysicalTableRecoveryService;

// El bootstrap de la aplicación instala un manejador pensado para páginas
// productivas. En una prueba CLI, cualquier excepción no capturada debe
// producir un código distinto de cero para que la release no certifique un
// fallo como si fuera un PASS.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

/** @param bool $condition */
function maintenanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return Throwable */
function maintenanceExpectFailure(callable $callback, string $contains): Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        maintenanceAssert(
            str_contains($error->getMessage(), $contains),
            'El error no explicó la causa esperada. Recibido: ' . $error->getMessage()
        );
        return $error;
    }
    throw new RuntimeException('La operación insegura no fue rechazada.');
}

/**
 * Crea una sesión técnica a partir de una huella v2 o posterior ya aprobada.
 *
 * @param array<string,mixed> $template
 */
function maintenanceCreateSession(PDO $pdo, array $template, int $userId, int $backupId): int
{
    /*
     * El fixture reutiliza un contenedor mínimo, pero cada sesión debe simular
     * una copia creada después del análisis y para ese contexto exacto.
     */
    $pdo->prepare(
        'UPDATE system_backup_archives
            SET context_type="database_sanitation",context_id=NULL,
                requested_by=:user_id,verified_at=UTC_TIMESTAMP(3)
          WHERE id=:id'
    )->execute(['user_id' => $userId, 'id' => $backupId]);
    $backupStatement = $pdo->prepare(
        'SELECT id,public_id,status,erp_version,size_bytes,table_count,row_count,
                verified_at,completed_at,checksum_sha256,manifest_sha256
         FROM system_backup_archives WHERE id=:id LIMIT 1'
    );
    $backupStatement->execute(['id' => $backupId]);
    $backup = $backupStatement->fetch(PDO::FETCH_ASSOC);
    maintenanceAssert(is_array($backup), 'La copia de prueba no existe.');
    $plan = json_decode((string) $template['plan_json'], true);
    $plan = is_array($plan) ? $plan : [];
    $plan['backup_binding'] = [
        ...$backup,
        'id' => $backupId,
        'file_available' => true,
        'fresh' => true,
    ];
    $plan['analysis'] = is_array($plan['analysis'] ?? null) ? $plan['analysis'] : [];
    $plan['analysis']['verified_backup'] = $plan['backup_binding'];
    $plan['protection'] = [
        'mode' => 'erp_backup',
        'ready' => true,
        'label' => 'Copia ERP verificada',
        'backup_id' => $backupId,
        'message' => 'La sesión de prueba usa una copia ERP verificada y fijada.',
        'confirmed_at' => gmdate('Y-m-d H:i:s'),
        'confirmed_by' => $userId,
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO database_maintenance_sessions
         (public_id,requested_by,backup_id,status,phase,dataset_key,dataset_position,
          generation,plan_json,counters_json,integrity_before_json,integrity_sha256,
          protection_mode,protection_status,protection_confirmed_by,protection_confirmed_at,
          safe_message)
         VALUES (:public_id,:user_id,:backup_id,"analyzed","analysis",NULL,0,1,
                 :plan,:counters,:integrity,:integrity_hash,
                 "erp_backup","ready",:confirmed_by,UTC_TIMESTAMP(3),:message)'
    );
    $integrity = (string) $template['integrity_before_json'];
    $stmt->execute([
        'public_id' => sprintf(
            '%08x-%04x-4%03x-8%03x-%012x',
            random_int(0, 0x7fffffff),
            random_int(0, 0xffff),
            random_int(0, 0xfff),
            random_int(0, 0xfff),
            random_int(0, 0x7fffffff)
        ),
        'user_id' => $userId,
        'confirmed_by' => $userId,
        'backup_id' => $backupId,
        'plan' => json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'counters' => json_encode([
            'rows_reviewed' => 0,
            'rows_archived' => 0,
            'rows_summarized' => 0,
            'rows_deleted' => 0,
            'payloads_externalized' => 0,
            'bytes_released' => 0,
            'archives' => 0,
            'errors' => 0,
        ], JSON_THROW_ON_ERROR),
        'integrity' => $integrity,
        'integrity_hash' => hash('sha256', $integrity),
        'message' => 'Sesión técnica local de prueba.',
    ]);
    $sessionId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'UPDATE system_backup_archives
            SET context_type="database_sanitation",context_id=:session_id,
                verified_at=UTC_TIMESTAMP(3)
          WHERE id=:id'
    )->execute(['session_id' => $sessionId, 'id' => $backupId]);
    return $sessionId;
}

/**
 * Construye un contenedor cifrado mínimo y verificable. La prueba necesita
 * comprobar el vínculo criptográfico, no duplicar un dump de cientos de MB.
 *
 * @return array{id:int,path:string}
 */
function maintenanceCreateVerifiedBackupFixture(PDO $pdo, int $userId, int $sessionId): array
{
    $directory = AppPaths::backups();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el directorio privado de prueba.');
    }
    $publicId = sprintf(
        '%08x-%04x-4%03x-8%03x-%012x',
        random_int(0, 0x7fffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0, 0xfff),
        random_int(0, 0x7fffffff)
    );
    $plain = $directory . '/qa-maintenance-' . bin2hex(random_bytes(6)) . '.sql.gz';
    $target = $directory . '/qa-maintenance-' . bin2hex(random_bytes(6)) . '.erpbackup';
    $archiveService = new BackupArchiveService();
    $canonical = new ReflectionMethod($archiveService, 'canonicalJson');
    $manifest = [
        'format' => 2,
        'created_at' => gmdate(DATE_ATOM),
        'timezone' => 'UTC',
        'erp_version' => '2.26.9',
        'purpose' => 'manual',
        'database' => ['engine' => 'MariaDB', 'fixture' => true],
        'migrations' => ['157_backup_lifecycle_sanitation_bridge_2_26_9.sql'],
        'tables' => [],
        'table_count' => 0,
        'row_count' => 0,
        'config_b64' => '',
    ];
    $manifest['manifest_sha256'] = hash(
        'sha256',
        (string) $canonical->invoke($archiveService, $manifest)
    );
    $gzip = gzopen($plain, 'wb6');
    if ($gzip === false) {
        throw new RuntimeException('No se pudo crear el fixture cifrado.');
    }
    gzwrite($gzip, "-- ERP-MELI-BACKUP-2\n");
    gzwrite(
        $gzip,
        '-- ERP MANIFEST V2 '
            . base64_encode((string) $canonical->invoke($archiveService, $manifest))
            . "\n-- ERP BACKUP COMPLETE format=2 tables=0 rows=0\n"
    );
    gzclose($gzip);
    $key = (new BackupKeyringService())->active();
    $encrypt = new ReflectionMethod($archiveService, 'encrypt');
    $encrypt->invoke($archiveService, $plain, $target, $key['id'], $key['key']);
    @unlink($plain);
    $verified = $archiveService->verify($target);
    maintenanceAssert(
        hash_equals(
            (string) $manifest['manifest_sha256'],
            (string) $verified['manifest_checksum']
        ),
        'El fixture cifrado no superó su verificación.'
    );
    $insert = $pdo->prepare(
        'INSERT INTO system_backup_archives
         (public_id,requested_by,purpose,status,erp_version,format_version,key_id,
           storage_name,size_bytes,table_count,row_count,checksum_sha256,
           manifest_sha256,requested_at,started_at,verified_at,completed_at,
           context_type,context_id)
         VALUES (:public_id,:user_id,"manual","ready","2.26.9",2,:key_id,
                  :storage_name,:size_bytes,0,0,:checksum,:manifest,
                  UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),
                  "database_sanitation",:context_id)'
    );
    $insert->execute([
        'public_id' => $publicId,
        'user_id' => $userId,
        'key_id' => $key['id'],
        'storage_name' => basename($target),
        'size_bytes' => filesize($target),
        'checksum' => hash_file('sha256', $target),
        'manifest' => $verified['manifest_checksum'],
        'context_id' => $sessionId,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'path' => $target];
}

$pdo = Database::connection();
$userId = (int) $pdo->query(
    'SELECT id FROM users
     WHERE role="admin" AND status=1
     ORDER BY id ASC LIMIT 1'
)->fetchColumn();
maintenanceAssert($userId > 0, 'No existe un administrador activo para la prueba local.');
$service = new DatabaseMaintenanceService();
$integrityMethod = new ReflectionMethod($service, 'integritySnapshot');
$integritySnapshot = $integrityMethod->invoke($service);
maintenanceAssert(
    is_array($integritySnapshot)
    && (int) ($integritySnapshot['snapshot_contract']['version'] ?? 0) >= 2,
    'No fue posible construir una huella vigente para la prueba.'
);
$template = [
    'plan_json' => json_encode([
        'analysis' => [],
        'backup_binding' => null,
    ], JSON_THROW_ON_ERROR),
    'integrity_before_json' => json_encode(
        $integritySnapshot,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ),
];
$createdSessions = [];
$unbound = $pdo->prepare(
    'INSERT INTO database_maintenance_sessions
     (public_id,requested_by,status,phase,plan_json,counters_json,
      integrity_before_json,integrity_sha256,safe_message)
     VALUES (:public_id,:user_id,"analyzed","analysis",:plan,:counters,
             :integrity,:integrity_hash,:message)'
);
$unbound->execute([
    'public_id' => sprintf(
        '%08x-%04x-4%03x-8%03x-%012x',
        random_int(0, 0x7fffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0, 0xfff),
        random_int(0, 0x7fffffff)
    ),
    'user_id' => $userId,
    'plan' => $template['plan_json'],
    'counters' => json_encode([], JSON_THROW_ON_ERROR),
    'integrity' => $template['integrity_before_json'],
    'integrity_hash' => hash('sha256', $template['integrity_before_json']),
    'message' => 'Análisis técnico sin copia.',
]);
$bindingSessionId = (int) $pdo->lastInsertId();
$createdSessions[] = $bindingSessionId;
$backupFixture = maintenanceCreateVerifiedBackupFixture($pdo, $userId, $bindingSessionId);
$backupId = $backupFixture['id'];
$backupPath = $backupFixture['path'];
$service->bindVerifiedBackup($bindingSessionId, $backupId, $userId);
$bound = $pdo->prepare(
    'SELECT backup_id,plan_json FROM database_maintenance_sessions WHERE id=:id'
);
$bound->execute(['id' => $bindingSessionId]);
$boundRow = $bound->fetch(PDO::FETCH_ASSOC);
$boundPlan = is_array($boundRow)
    ? json_decode((string) $boundRow['plan_json'], true)
    : null;
maintenanceAssert(
    is_array($boundRow)
    && (int) $boundRow['backup_id'] === $backupId
    && (int) ($boundPlan['backup_binding']['id'] ?? 0) === $backupId,
    'La copia verificada no quedó fijada a la sesión exacta.'
);
$completedSessionId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
$createdSessions[] = $completedSessionId;
$pdo->prepare(
    'UPDATE database_maintenance_sessions
     SET status="finished",phase="completed",completed_at=UTC_TIMESTAMP(3)
     WHERE id=:id'
)->execute(['id' => $completedSessionId]);
$markerApi = $root . '/PAUSE_MELI_API';
$markerApiTemporary = $root . '/PAUSE_MELI_API.maintenance-test';
$mutationFreeze = AppPaths::storage('cache/database-mutation-freeze.json');
$testStage = 'inicio';
$exitCode = 0;

try {
    // Propiedad de pestaña, idempotencia, pausa, continuación y cierre.
    $testStage = 'control de pestaña e idempotencia';
    $sessionId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
    $createdSessions[] = $sessionId;
    $tokenA = bin2hex(random_bytes(24));
    $service->start($sessionId, $userId, $tokenA);

    $first = $service->runInteractiveStep($sessionId, $userId, $tokenA);
    maintenanceAssert(is_array($first), 'La pestaña propietaria no ejecutó el primer micro-paso local.');
    maintenanceAssert(
        ($first['action']['kind'] ?? null) === 'legacy_notifications',
        'La sesión no comenzó por la clasificación legacy previa a la retención.'
    );
    $stepCount = $pdo->prepare(
        'SELECT COUNT(*) FROM database_maintenance_steps
         WHERE maintenance_session_id=:session_id'
    );
    $stepCount->execute(['session_id' => $sessionId]);
    maintenanceAssert(
        (int) $stepCount->fetchColumn() === 1,
        'Un micro-paso local creó más de un checkpoint.'
    );

    $paused = $service->pause($sessionId, $userId);
    maintenanceAssert($paused['status'] === 'paused', 'La pausa no quedó persistida.');
    maintenanceAssert(
        $service->runInteractiveStep($sessionId, $userId, $tokenA) === null,
        'La pestaña inició otro lote mientras la sesión estaba pausada.'
    );
    $service->start($sessionId, $userId, $tokenA);
    $resumed = $service->runInteractiveStep($sessionId, $userId, $tokenA);
    maintenanceAssert(is_array($resumed), 'La pestaña no retomó la sesión pausada.');
    maintenanceAssert(
        in_array($resumed['status']['status'] ?? '', ['running', 'completed'], true),
        'La continuación no conservó un estado válido.'
    );
    for ($sequence = 3; $sequence <= 64; $sequence++) {
        $pdo->prepare(
            'INSERT INTO database_maintenance_steps
             (maintenance_session_id,sequence_no,idempotency_key,generation,phase,
              dataset_key,status,rows_reviewed,result_json,completed_at)
             VALUES (:session_id,:sequence_no,:key_hash,1,"retention",
                     "notification_success","completed",1,"{}",UTC_TIMESTAMP(3))'
        )->execute([
            'session_id' => $sessionId,
            'sequence_no' => $sequence,
            'key_hash' => hash('sha256', 'maintenance-terminal-dummy-' . $sequence),
        ]);
    }
    $finished = $service->finish($sessionId, $userId);
    maintenanceAssert(
        in_array($finished['status'], ['running', 'pausing', 'finishing'], true)
        && ($finished['phase'] ?? '') === 'verify',
        'Finalizar no inició la verificación protegida.'
    );
    for ($attempt = 0; $attempt < 300 && $finished['status'] !== 'finished'; $attempt++) {
        $service->runCliStep($sessionId);
        $finished = $service->session($sessionId, $userId);
    }
    maintenanceAssert($finished['status'] === 'finished', 'Finalizar no cerró la sesión.');
    maintenanceAssert(
        (int) ($finished['steps_compacted_count'] ?? 0) >= 1,
        'Finalizar no compactó automáticamente la bitácora técnica.'
    );
    maintenanceExpectFailure(
        static fn () => $service->step(
            $sessionId,
            $userId,
            $tokenA,
            'maintenance-finished-repeat-' . bin2hex(random_bytes(12))
        ),
        'lease local válido'
    );

    // Un lote sin respuesta confirmada se pausa y no se repite.
    $testStage = 'recuperación de lote interrumpido';
    $staleId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
    $createdSessions[] = $staleId;
    $staleToken = bin2hex(random_bytes(24));
    $service->start($staleId, $userId, $staleToken);
    $pdo->prepare(
        'UPDATE database_maintenance_sessions
         SET control_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE)
         WHERE id=:id'
    )->execute(['id' => $staleId]);
    $pdo->prepare(
        'INSERT INTO database_maintenance_steps
         (maintenance_session_id,sequence_no,idempotency_key,generation,phase,
          dataset_key,status,started_at)
         VALUES (:session_id,1,:key_hash,1,"retention","notification_success",
                 "running",DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 30 SECOND))'
    )->execute([
        'session_id' => $staleId,
        'key_hash' => hash('sha256', 'maintenance-stale-original'),
    ]);
    maintenanceAssert(
        $service->runCliStep($staleId) === null,
        'El CLI repitió un lote cuyo resultado era incierto.'
    );
    $staleStatus = $service->session($staleId, $userId);
    maintenanceAssert($staleStatus['status'] === 'paused', 'El lote interrumpido no pausó la sesión.');
    $staleStepStatus = $pdo->prepare(
        'SELECT status FROM database_maintenance_steps
         WHERE maintenance_session_id=:session_id ORDER BY id LIMIT 1'
    );
    $staleStepStatus->execute(['session_id' => $staleId]);
    maintenanceAssert(
        $staleStepStatus->fetchColumn() === 'failed',
        'El lote interrumpido no quedó cerrado de forma explícita.'
    );

    // Un worker vencido no puede degradar el paso ni la sesión de la
    // generación que tomó el control después.
    $testStage = 'fencing de fallo tardío';
    $fencedId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
    $createdSessions[] = $fencedId;
    $currentControlOwner = hash('sha256', 'maintenance-current-control-owner');
    $currentLeaseOwner = hash('sha256', 'maintenance-current-lease-owner');
    $staleLeaseOwner = hash('sha256', 'maintenance-stale-lease-owner');
    $pdo->prepare(
        'UPDATE database_maintenance_sessions
         SET status="running",generation=2,control_token_hash=:control_owner,
             control_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),
             lease_owner=:lease_owner,lease_generation=2,
             lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE)
         WHERE id=:id'
    )->execute([
        'control_owner' => $currentControlOwner,
        'lease_owner' => $currentLeaseOwner,
        'id' => $fencedId,
    ]);
    $pdo->prepare(
        'INSERT INTO database_maintenance_steps
         (maintenance_session_id,sequence_no,idempotency_key,generation,lease_owner,phase,
          dataset_key,status)
         VALUES (:session_id,1,:key_hash,1,:lease_owner,"retention",
                 "notification_success","running")'
    )->execute([
        'session_id' => $fencedId,
        'key_hash' => hash('sha256', 'maintenance-stale-failure'),
        'lease_owner' => $staleLeaseOwner,
    ]);
    $staleFailureStepId = (int) $pdo->lastInsertId();
    $failStep = new ReflectionMethod($service, 'failStep');
    $failStep->invoke(
        $service,
        $fencedId,
        $userId,
        $staleFailureStepId,
        1,
        $staleLeaseOwner,
        hash('sha256', 'maintenance-stale-control-owner'),
        new RuntimeException('fallo tardío'),
        10
    );
    $fencedState = $pdo->prepare(
        'SELECT s.status AS session_status,p.status AS step_status
         FROM database_maintenance_sessions s
         INNER JOIN database_maintenance_steps p ON p.maintenance_session_id=s.id
         WHERE s.id=:id AND p.id=:step_id'
    );
    $fencedState->execute(['id' => $fencedId, 'step_id' => $staleFailureStepId]);
    $fencedRow = $fencedState->fetch(PDO::FETCH_ASSOC);
    maintenanceAssert(
        is_array($fencedRow)
        && $fencedRow['session_status'] === 'running'
        && $fencedRow['step_status'] === 'running',
        'Un worker vencido degradó la generación vigente.'
    );
    $pdo->prepare(
        'UPDATE database_maintenance_steps
         SET generation=2,lease_owner=:lease_owner WHERE id=:id'
    )->execute(['lease_owner' => $currentLeaseOwner, 'id' => $staleFailureStepId]);
    $failStep->invoke(
        $service,
        $fencedId,
        $userId,
        $staleFailureStepId,
        2,
        hash('sha256', 'maintenance-wrong-lease-owner'),
        $currentControlOwner,
        new RuntimeException('fallo de propietario ajeno'),
        11
    );
    $fencedState->execute(['id' => $fencedId, 'step_id' => $staleFailureStepId]);
    $fencedRow = $fencedState->fetch(PDO::FETCH_ASSOC);
    maintenanceAssert(
        is_array($fencedRow)
        && $fencedRow['session_status'] === 'running'
        && $fencedRow['step_status'] === 'running',
        'Un propietario ajeno degradó la sesión vigente.'
    );
    $failStep->invoke(
        $service,
        $fencedId,
        $userId,
        $staleFailureStepId,
        2,
        $currentLeaseOwner,
        $currentControlOwner,
        new RuntimeException('fallo vigente'),
        12
    );
    $fencedState->execute(['id' => $fencedId, 'step_id' => $staleFailureStepId]);
    $fencedRow = $fencedState->fetch(PDO::FETCH_ASSOC);
    maintenanceAssert(
        is_array($fencedRow)
        && $fencedRow['session_status'] === 'paused'
        && $fencedRow['step_status'] === 'failed',
        'El propietario vigente no pudo cerrar su propio fallo.'
    );

    // Aislamiento por usuario.
    $testStage = 'aislamiento por usuario';
    try {
        $service->session($sessionId, 999999);
        throw new RuntimeException('Una sesión ajena quedó accesible.');
    } catch (HttpException $error) {
        maintenanceAssert($error->status === 404, 'La sesión ajena no produjo 404 seguro.');
    }

    // La ausencia de la copia impide comenzar y no cambia datos.
    $testStage = 'copia verificada ausente';
    $missingBackupId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
    $createdSessions[] = $missingBackupId;
    $backupTemporary = $backupPath . '.maintenance-test';
    maintenanceAssert(@rename($backupPath, $backupTemporary), 'No se pudo aislar la copia para la prueba.');
    clearstatcache(true, $backupPath);
    try {
        $verifiedBackupMethod = new ReflectionMethod($service, 'verifiedBackup');
        maintenanceAssert(
            $verifiedBackupMethod->invoke($service, $backupId) === null,
            'El servicio siguió considerando disponible una copia retirada.'
        );
        maintenanceExpectFailure(
            static fn () => $service->start(
                $missingBackupId,
                $userId,
                bin2hex(random_bytes(24))
            ),
            'copia cifrada'
        );
    } finally {
        maintenanceAssert(@rename($backupTemporary, $backupPath), 'No se pudo restaurar la copia local.');
        clearstatcache(true, $backupPath);
    }

    // Un marcador físico ausente bloquea antes del primer lote.
    $testStage = 'marcador físico ausente';
    $unsafeId = maintenanceCreateSession($pdo, $template, $userId, $backupId);
    $createdSessions[] = $unsafeId;
    maintenanceAssert(@rename($markerApi, $markerApiTemporary), 'No se pudo aislar el marcador API.');
    clearstatcache(true, $markerApi);
    try {
        maintenanceExpectFailure(
            static fn () => $service->start(
                $unsafeId,
                $userId,
                bin2hex(random_bytes(24))
            ),
            'Detenga Mercado Libre'
        );
    } finally {
        maintenanceAssert(@rename($markerApiTemporary, $markerApi), 'No se pudo restaurar el marcador API.');
        clearstatcache(true, $markerApi);
    }

    // La recuperación física no acepta tablas o confirmaciones manipuladas.
    $testStage = 'guardas de recuperación física';
    maintenanceExpectFailure(
        static fn () => (new PhysicalTableRecoveryService())->rebuild(
            'api_request_logs',
            false
        ),
        'confirmación explícita'
    );
    maintenanceExpectFailure(
        static fn () => $service->rebuild($completedSessionId, $userId, 'users', true),
        'plan de recuperación'
    );

    echo json_encode([
        'status' => 'PASS',
        'idempotency' => true,
        'single_tab_control' => true,
        'pause_resume_finish' => true,
        'stale_step_recovery' => true,
        'stale_failure_fenced' => true,
        'user_isolation' => true,
        'verified_backup_guard' => true,
        'physical_safety_markers' => true,
        'physical_rebuild_guards' => true,
        'remote_transport' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Fallo en etapa: ' . $testStage . PHP_EOL);
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_file($markerApiTemporary) && !is_file($markerApi)) {
        @rename($markerApiTemporary, $markerApi);
    }
    if ($createdSessions !== []) {
        if (is_file($mutationFreeze)) {
            $freeze = json_decode((string) @file_get_contents($mutationFreeze), true);
            $owner = is_array($freeze) ? (string) ($freeze['owner'] ?? '') : '';
            foreach ($createdSessions as $createdSessionId) {
                if ($owner === 'session:' . $createdSessionId) {
                    @unlink($mutationFreeze);
                    break;
                }
            }
        }
        $placeholders = implode(',', array_fill(0, count($createdSessions), '?'));
        $cleanup = $pdo->prepare(
            'DELETE FROM database_maintenance_sessions WHERE id IN (' . $placeholders . ')'
        );
        $cleanup->execute($createdSessions);
    }
    if (isset($backupFixture['id'])) {
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=?')
            ->execute([(int) $backupFixture['id']]);
    }
    if (isset($backupFixture['path']) && is_string($backupFixture['path'])) {
        @unlink($backupFixture['path']);
    }
}
exit($exitCode);
