<?php

declare(strict_types=1);

/**
 * Prueba conductual sobre un clon desechable del dump completo.
 *
 * Requiere:
 *   DB_NAME=erp_sanitize_audit_2265
 * El clon debe haberse actualizado de 140 a 153 y contener la fila histórica
 * "historical-row-must-survive".
 */

$database = (string) (getenv('DB_NAME') ?: '');
if (!str_starts_with($database, 'erp_sanitize_audit_')) {
    fwrite(STDERR, "Use exclusivamente un clon erp_sanitize_audit_*.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Database;
use App\Services\DatabaseMaintenanceService;
use App\Services\ImportedMeliDataResetService;

/** @param bool $condition */
function sanitationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connection();
$tableCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE"'
)->fetchColumn();
sanitationAssert($tableCount >= 230, 'El clon no contiene el esquema productivo completo.');

$retention = $pdo->query(
    'SELECT rows_reviewed,rows_archived,rows_deleted,safe_message
     FROM system_retention_runs
     WHERE safe_message="historical-row-must-survive" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
sanitationAssert(is_array($retention), 'La actualización 140→153 eliminó el historial de retención.');
sanitationAssert(
    (int) $retention['rows_reviewed'] === 123
    && (int) $retention['rows_archived'] === 100
    && (int) $retention['rows_deleted'] === 90,
    'La actualización alteró la evidencia histórica de retención.'
);

$campaign = $pdo->query(
    'SELECT status,total_items,total_units,completed_items,returned_items
     FROM manual_campaigns WHERE id=6 LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
sanitationAssert(is_array($campaign), 'La campaña #6 desapareció del clon.');
sanitationAssert(
    (string) $campaign['status'] === 'active'
    && (int) $campaign['total_items'] === 649
    && (int) $campaign['total_units'] === 681
    && (int) $campaign['completed_items'] === 0
    && (int) $campaign['returned_items'] === 0,
    'La actualización modificó la campaña #6.'
);

$userId = (int) $pdo->query(
    'SELECT id FROM users WHERE role="admin" AND status=1 ORDER BY id LIMIT 1'
)->fetchColumn();
sanitationAssert($userId > 0, 'El clon no conserva un administrador activo.');
$overview = (new ImportedMeliDataResetService())->overview($userId);
$activeIds = array_map(
    static fn (array $row): int => (int) ($row['id'] ?? 0),
    is_array($overview['active_campaigns'] ?? null) ? $overview['active_campaigns'] : []
);
sanitationAssert(
    in_array(6, $activeIds, true),
    'El restablecimiento extraordinario no presenta la campaña #6 como decisión explícita.'
);

$service = new DatabaseMaintenanceService();
$sessionId = 0;
try {
    $stmt = $pdo->prepare(
        'INSERT INTO database_maintenance_sessions
         (public_id,requested_by,status,phase,generation,plan_json,counters_json,
          integrity_before_json,integrity_sha256,safe_message)
         VALUES (UUID(),:user_id,"running","retention",1,"{}","{}","{}",
                 SHA2("{}",256),"Prueba de fencing sin ejecutar saneamiento.")'
    );
    $stmt->execute(['user_id' => $userId]);
    $sessionId = (int) $pdo->lastInsertId();

    $claim = new ReflectionMethod($service, 'claimCliStep');
    $release = new ReflectionMethod($service, 'releaseCliLease');
    $ownerA = str_repeat('a', 64);
    $ownerB = str_repeat('b', 64);
    $first = $claim->invoke($service, $sessionId, $ownerA, 'cli-' . str_repeat('1', 48));
    sanitationAssert(is_array($first), 'El primer worker no adquirió el lease.');
    $generationA = (int) ($first['generation'] ?? 0);
    sanitationAssert($generationA > 1, 'El claim no incrementó la generación.');
    sanitationAssert(
        $claim->invoke($service, $sessionId, $ownerB, 'cli-' . str_repeat('2', 48)) === null,
        'Un segundo worker adquirió un lease todavía vigente.'
    );

    $pdo->prepare(
        'UPDATE database_maintenance_sessions
         SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)
         WHERE id=:id'
    )->execute(['id' => $sessionId]);
    $pdo->prepare(
        'INSERT INTO database_maintenance_steps
         (maintenance_session_id,sequence_no,idempotency_key,generation,lease_owner,
          phase,dataset_key,status)
         VALUES (:session_id,1,SHA2("unfinished",256),:generation,:lease_owner,
                 "retention","notification_success","running")'
    )->execute([
        'session_id' => $sessionId,
        'generation' => $generationA,
        'lease_owner' => $ownerA,
    ]);
    sanitationAssert(
        $claim->invoke($service, $sessionId, $ownerB, 'cli-' . str_repeat('3', 48)) === null,
        'Un lote sin aprobar fue repetido automáticamente.'
    );
    $paused = $pdo->query(
        'SELECT status,generation,lease_owner FROM database_maintenance_sessions
         WHERE id=' . $sessionId
    )->fetch(PDO::FETCH_ASSOC);
    sanitationAssert(
        is_array($paused)
        && (string) $paused['status'] === 'paused'
        && (int) $paused['generation'] > $generationA
        && $paused['lease_owner'] === null,
        'El lote interrumpido no quedó pausado y cercado.'
    );

    $pdo->prepare(
        'UPDATE database_maintenance_sessions
         SET status="running",lease_owner=NULL,lease_expires_at=NULL
         WHERE id=:id'
    )->execute(['id' => $sessionId]);
    $second = $claim->invoke($service, $sessionId, $ownerB, 'cli-' . str_repeat('4', 48));
    sanitationAssert(is_array($second), 'El worker nuevo no pudo adquirir la sesión revisada.');
    $generationB = (int) ($second['generation'] ?? 0);
    $release->invoke($service, $sessionId, $ownerA, $generationA);
    $currentOwner = (string) $pdo->query(
        'SELECT COALESCE(lease_owner,"") FROM database_maintenance_sessions WHERE id=' . $sessionId
    )->fetchColumn();
    sanitationAssert(
        hash_equals($ownerB, $currentOwner) && $generationB > $generationA,
        'El worker vencido alteró el lease de la generación nueva.'
    );
    $pdo->prepare(
        'INSERT INTO database_maintenance_steps
         (maintenance_session_id,sequence_no,idempotency_key,generation,lease_owner,
          phase,dataset_key,status)
         VALUES (:session_id,2,SHA2("approved",256),:generation,:lease_owner,
                 "retention","notification_success","running")'
    )->execute([
        'session_id' => $sessionId,
        'generation' => $generationB,
        'lease_owner' => $ownerB,
    ]);
    $stepId = (int) $pdo->lastInsertId();
    $approve = new ReflectionMethod($service, 'approveStep');
    $approved = $approve->invoke(
        $service,
        $sessionId,
        $userId,
        $stepId,
        $generationB,
        $ownerB,
        [
            'kind' => 'legacy_notifications',
            'phase_complete' => false,
            'rows_reviewed' => 0,
            'message' => 'Checkpoint de fencing aprobado.',
        ],
        1
    );
    sanitationAssert(
        is_array($approved)
        && (string) ($approved['status']['status'] ?? '') === 'running',
        'El propietario vigente no pudo aprobar el checkpoint atómico.'
    );
    $release->invoke($service, $sessionId, $ownerB, $generationB);
} finally {
    if ($sessionId > 0) {
        $pdo->prepare('DELETE FROM database_maintenance_sessions WHERE id=:id')
            ->execute(['id' => $sessionId]);
    }
}

echo "OK sanitation retention 2.26.5: history, campaign #6 and fencing\n";
