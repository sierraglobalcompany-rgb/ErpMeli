<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class BackupCenterService
{
    /** @return array<string,mixed> */
    public function overview(?int $preferredBackupId = null): array
    {
        $pdo = Database::connection();
        $schema = new SchemaInspectorService();
        $contextColumns = $schema->hasColumn(
            'system_backup_archives',
            'context_type'
        )
            ? ',context_type,context_id'
            : ',"general" AS context_type,NULL AS context_id';
        $executionModeColumn = $schema->hasColumn('system_backup_archives', 'execution_mode')
            ? ',execution_mode'
            : ',"browser" AS execution_mode';
        $archives = $pdo->query(
            "SELECT id,public_id,purpose,status,erp_version,format_version,key_id,size_bytes,
                    table_count,row_count,checksum_sha256,safe_error_message,requested_at,
                    started_at,heartbeat_at,verified_at,completed_at,expires_at
                    {$contextColumns}{$executionModeColumn}
             FROM system_backup_archives
             WHERE deleted_at IS NULL
             ORDER BY id DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        $archives = $this->attachJobProgress($pdo, $archives);
        $latest = null;
        foreach ($archives as $archive) {
            if ((string) $archive['status'] === 'ready') {
                $latest = $archive;
                break;
            }
        }
        $directory = AppPaths::backups();
        $free = is_dir($directory) ? @disk_free_space($directory) : @disk_free_space(dirname($directory));
        $active = $this->active($preferredBackupId);
        if (is_array($active)) {
            foreach ($archives as $archive) {
                if ((int) $archive['id'] === (int) $active['id']) {
                    $active = $archive;
                    break;
                }
            }
        }
        $progress = $this->progressPresenter($active);
        if (is_array($active)) {
            $active['progress'] = $progress;
            foreach ($archives as &$archive) {
                if ((int) $archive['id'] === (int) $active['id']) {
                    $archive['progress'] = $progress;
                    break;
                }
            }
            unset($archive);
        }
        return [
            'archives' => $archives,
            'latest' => $latest,
            'free_bytes' => is_float($free) ? (int) $free : null,
            'key_ready' => (new BackupKeyringService())->hasExternalKey(),
            'key_exported' => (int) $pdo->query(
                "SELECT COUNT(*) FROM system_backup_audit_events
                 WHERE event_type='recovery_key_exported' AND outcome='success'"
            )->fetchColumn() > 0,
            'active' => $active,
            'runtime' => $this->runtimeSummary($active),
            'progress' => $progress,
        ];
    }

    /**
     * Resumen liviano para polling del monitor de copias por navegador.
     *
     * La vista completa necesita historial y estado de llave; el monitor no.
     * Evitar reenviar hasta 100 copias en cada ciclo mantiene visible el avance
     * sin inflar el HTML/JSON ni repetir consultas auxiliares.
     *
     * @return array<string,mixed>
     */
    public function statusOverview(?int $preferredBackupId = null): array
    {
        $active = $this->active($preferredBackupId);
        $progress = $this->progressPresenter($active);
        if (is_array($active)) {
            $active['progress'] = $progress;
        }

        return [
            'ok' => true,
            'active' => $active,
            'runtime' => $this->runtimeSummary($active),
            'progress' => $progress,
            'server_time_utc' => gmdate(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed>|null */
    public function active(?int $preferredBackupId = null): ?array
    {
        $pdo = Database::connection();
        $executionModeColumn = (new SchemaInspectorService())->hasColumn('system_backup_archives', 'execution_mode')
            ? ',execution_mode'
            : ',"browser" AS execution_mode';
        if ($preferredBackupId !== null && $preferredBackupId > 0) {
            $preferred = $pdo->prepare(
                "SELECT id,public_id,purpose,status,requested_at,started_at,heartbeat_at{$executionModeColumn}
                 FROM system_backup_archives
                 WHERE id=:id
                   AND deleted_at IS NULL
                   AND status IN (
                        'prepared','queued','creating','verifying',
                        'ready_pending_release','cancel_requested','deleting'
                   )
                 LIMIT 1"
            );
            $preferred->execute(['id' => $preferredBackupId]);
            $row = $preferred->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
        $stmt = $pdo->query(
            "SELECT id,public_id,purpose,status,requested_at,started_at,heartbeat_at{$executionModeColumn}
             FROM system_backup_archives
             WHERE deleted_at IS NULL
               AND status IN (
                    'prepared','queued','creating','verifying',
                    'ready_pending_release','cancel_requested','deleting'
               )
             ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{id:int,public_id:string} */
    public function enqueue(
        int $userId,
        string $purpose = 'manual',
        string $contextType = 'general',
        ?int $contextId = null,
        string $executionMode = 'browser'
    ): array
    {
        if (!in_array($purpose, ['manual', 'pre_update'], true)) {
            throw new RuntimeException('El propósito de la copia no es válido.');
        }
        if (!in_array($contextType, ['general', 'database_sanitation', 'direct_update'], true)) {
            throw new RuntimeException('El contexto de la copia no es válido.');
        }
        $executionMode = $this->normalizeExecutionMode($executionMode);
        if ($contextType === 'database_sanitation') {
            $contextId = max(0, (int) $contextId);
            if ($contextId < 1 || !$this->maintenanceSessionBelongsTo($contextId, $userId)) {
                throw new RuntimeException('La sesión de saneamiento no está disponible.');
            }
        } elseif ($contextType === 'direct_update') {
            $contextId = null;
        } else {
            $contextId = null;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $active = $pdo->query(
                "SELECT id FROM system_backup_archives
                 WHERE deleted_at IS NULL
                   AND status IN (
                        'prepared','queued','creating','verifying',
                        'ready_pending_release','cancel_requested','deleting'
                   )
                 FOR UPDATE"
            )->fetchColumn();
            if ($active !== false) {
                throw new RuntimeException('Ya existe una copia en preparación.');
            }
            $publicId = $this->uuid();
            $expires = $purpose === 'pre_update'
                ? 'DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 DAY)'
                : 'NULL';
            $schema = new SchemaInspectorService();
            $contextReady = $schema->hasColumn(
                'system_backup_archives',
                'context_type'
            );
            $executionModeReady = $schema->hasColumn('system_backup_archives', 'execution_mode');
            if (
                $contextType === 'direct_update'
                && $contextReady
                && !$this->archiveContextSupports($pdo, 'direct_update')
            ) {
                /*
                 * El actualizador necesita poder pedir su respaldo antes de
                 * aplicar la migración que agrega direct_update al ENUM.
                 * En ese caso purpose=pre_update conserva la identidad exacta
                 * y la sesión de recuperación mantiene el vínculo.
                 */
                $contextType = 'general';
            }
            if ($contextType !== 'general' && !$contextReady) {
                throw new RuntimeException(
                    'Complete las migraciones de copias antes de vincular este respaldo.'
                );
            }
            $parameters = [
                'public_id' => $publicId,
                'user_id' => $userId,
                'purpose' => $purpose,
                'version' => AppVersionService::fileVersion(),
            ];
            if ($contextReady && $executionModeReady) {
                $stmt = $pdo->prepare(
                    "INSERT INTO system_backup_archives
                     (public_id,requested_by,purpose,status,erp_version,expires_at,context_type,context_id,execution_mode)
                     VALUES (:public_id,:user_id,:purpose,'queued',:version,{$expires},:context_type,:context_id,:execution_mode)"
                );
                $parameters['context_type'] = $contextType;
                $parameters['context_id'] = $contextId;
                $parameters['execution_mode'] = $executionMode;
            } elseif ($contextReady) {
                $stmt = $pdo->prepare(
                    "INSERT INTO system_backup_archives
                     (public_id,requested_by,purpose,status,erp_version,expires_at,context_type,context_id)
                     VALUES (:public_id,:user_id,:purpose,'queued',:version,{$expires},:context_type,:context_id)"
                );
                $parameters['context_type'] = $contextType;
                $parameters['context_id'] = $contextId;
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO system_backup_archives
                     (public_id,requested_by,purpose,status,erp_version,expires_at)
                     VALUES (:public_id,:user_id,:purpose,'queued',:version,{$expires})"
                );
            }
            $stmt->execute($parameters);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO system_backup_jobs (backup_id,job_type,status)
                 VALUES (:backup_id,'create','pending')"
            )->execute(['backup_id' => $id]);
            $this->audit($pdo, $id, $userId, 'backup_requested', 'success', ['purpose' => $purpose]);
            $pdo->commit();
            (new BackupArchiveV3Service())->activateFreeze($publicId);
            if ($executionMode === 'cli') {
                $this->publishBackupIfCli(
                    $id,
                    $publicId,
                    'pending',
                    '',
                    0,
                    'create',
                    true
                );
            }
            return ['id' => $id, 'public_id' => $publicId];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function processRequested(?array $forcedRequest = null, array $limits = []): array
    {
        $requestService = new BackupMaintenanceRequestService();
        $request = $forcedRequest;
        $backgroundRequest = $forcedRequest === null;
        if ($request === null) {
            $coordinator = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
                AppPaths::sharedRoot(),
                AppPaths::installationRoot()
            );
            $request = $coordinator['valid'] && $coordinator['task'] === 'backup'
                ? [
                    'backup_id' => (int) $coordinator['backup_id'],
                    'public_id' => (string) $coordinator['public_id'],
                ]
                : $requestService->consumeValid();
            if ($request !== null && $this->isBrowserBackup((int) $request['backup_id'])) {
                (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                    (int) $request['backup_id'],
                    (string) $request['public_id']
                );
                $requestService->clearIfMatches((int) $request['backup_id'], (string) $request['public_id']);
                return [
                    'result' => 'empty',
                    'backup_id' => (int) $request['backup_id'],
                    'message' => 'Esta copia se continúa desde la pestaña del navegador.',
                    'remote_transport' => false,
                ];
            }
        }
        $pdo = Database::connection();
        $lifecycle = $this->supportsDeletionLifecycle();
        $safeCancellation = $this->supportsSafeCancellationLifecycle();
        $pdo->beginTransaction();
        try {
            if ($request !== null) {
                $backupId = (int) ($request['backup_id'] ?? $request['id'] ?? 0);
                $publicId = trim((string) ($request['public_id'] ?? ''));
                if ($backupId <= 0) {
                    $pdo->commit();
                    return ['result' => 'empty', 'message' => 'La solicitud de copia no contiene un identificador válido.'];
                }
                if ($publicId === '') {
                    $lookup = $pdo->prepare(
                        'SELECT public_id FROM system_backup_archives WHERE id=:id AND deleted_at IS NULL LIMIT 1'
                    );
                    $lookup->execute(['id' => $backupId]);
                    $resolvedPublicId = $lookup->fetchColumn();
                    if (!is_string($resolvedPublicId) || $resolvedPublicId === '') {
                        $pdo->commit();
                        return ['result' => 'empty', 'message' => 'La copia solicitada ya no necesita procesamiento.'];
                    }
                    $publicId = $resolvedPublicId;
                }
                $request['backup_id'] = $backupId;
                $request['public_id'] = $publicId;
            }
            if ($request !== null) {
                $available = $lifecycle
                    ? " AND (j.available_at IS NULL OR j.available_at<=UTC_TIMESTAMP(3))"
                    : '';
                $order = $lifecycle
                    ? "ORDER BY IF(b.status IN ('cancel_requested','deleting','ready_pending_release'),FIELD(j.job_type,'cleanup','create','verify'),
                                FIELD(j.job_type,'create','verify','cleanup')),j.id"
                    : "ORDER BY FIELD(j.job_type,'create','verify'),j.id";
                $stmt = $pdo->prepare(
                    "SELECT j.id AS job_id,j.job_type,j.status AS job_status,j.lease_generation,
                            j.lease_expires_at,j.checkpoint_json,
                            b.id,b.public_id,b.purpose,b.status
                     FROM system_backup_jobs j
                     INNER JOIN system_backup_archives b ON b.id=j.backup_id
                     WHERE b.id=:id AND b.public_id=:public_id
                       AND j.status IN ('pending','running'){$available}
                     {$order}
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute(['id' => $request['backup_id'], 'public_id' => $request['public_id']]);
            } else {
                $available = $lifecycle
                    ? " AND (j.available_at IS NULL OR j.available_at<=UTC_TIMESTAMP(3))"
                    : '';
                $executionModeFilter = (new SchemaInspectorService())->hasColumn(
                    'system_backup_archives',
                    'execution_mode'
                )
                    ? " AND b.execution_mode='cli'"
                    : ' AND 1=0';
                $archiveStates = $safeCancellation
                    ? "'prepared','queued','creating','verifying','ready_pending_release','cancel_requested','deleting'"
                    : ($lifecycle
                    ? "'prepared','queued','creating','verifying','ready_pending_release','deleting'"
                    : "'prepared','queued','creating','verifying'");
                $order = $lifecycle
                    ? "ORDER BY IF(b.status IN ('cancel_requested','deleting','ready_pending_release'),FIELD(j.job_type,'cleanup','create','verify'),
                                FIELD(j.job_type,'create','verify','cleanup')),b.requested_at,j.id"
                    : 'ORDER BY b.requested_at,j.id';
                $stmt = $pdo->query(
                    "SELECT j.id AS job_id,j.job_type,j.status AS job_status,j.lease_generation,
                            j.lease_expires_at,j.checkpoint_json,
                            b.id,b.public_id,b.purpose,b.status
                     FROM system_backup_jobs j
                     INNER JOIN system_backup_archives b ON b.id=j.backup_id
                     WHERE j.status IN ('pending','running')
                       AND (j.lease_owner IS NULL OR j.lease_expires_at IS NULL
                            OR j.lease_expires_at<UTC_TIMESTAMP(3))
                       AND b.status IN ({$archiveStates}){$available}{$executionModeFilter}
                     {$order} LIMIT 1 FOR UPDATE"
                );
            }
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job) || (string) $job['status'] === 'deleted') {
                if ($request !== null && $lifecycle) {
                    $reconciled = $this->reconcileForcedRequestWithoutJob(
                        $pdo,
                        (int) $request['backup_id'],
                        (string) $request['public_id'],
                        $requestService
                    );
                    if ($reconciled !== null) {
                        $pdo->commit();
                        return $reconciled;
                    }
                    $waiting = $pdo->prepare(
                        "SELECT status,delete_not_before_at
                           FROM system_backup_archives
                          WHERE id=:id AND public_id=:public_id AND deleted_at IS NULL
                          LIMIT 1"
                    );
                    $waiting->execute([
                        'id' => $request['backup_id'],
                        'public_id' => $request['public_id'],
                    ]);
                    $waitingArchive = $waiting->fetch(PDO::FETCH_ASSOC);
                    if (
                        is_array($waitingArchive)
                        && in_array(
                            (string) $waitingArchive['status'],
                            ['cancel_requested', 'deleting'],
                            true
                        )
                    ) {
                        $pdo->commit();
                        $availableAt = (string) ($waitingArchive['delete_not_before_at'] ?? '');
                        return [
                            'result' => 'deferred',
                            'backup_id' => $request['backup_id'],
                            'available_at' => $availableAt,
                            'message' => $availableAt !== ''
                                ? 'La limpieza esperará hasta ' . $availableAt . ' UTC para no cortar un micro-lote activo.'
                                : 'La limpieza espera que termine el micro-lote anterior.',
                        ];
                    }
                }
                $pdo->commit();
                if ($request !== null) {
                    $final = Database::connection()->prepare(
                        'SELECT status FROM system_backup_archives
                          WHERE id=:id AND public_id=:public_id LIMIT 1'
                    );
                    $final->execute([
                        'id' => $request['backup_id'],
                        'public_id' => $request['public_id'],
                    ]);
                    $finalStatus = (string) ($final->fetchColumn() ?: '');
                    if ($finalStatus === 'ready') {
                        $requestService->clearIfMatches((int) $request['backup_id'], (string) $request['public_id']);
                        (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                            (int) $request['backup_id'],
                            (string) $request['public_id']
                        );
                        $this->releaseSnapshotFreeze((string) $request['public_id']);
                        return [
                            'result' => 'completed',
                            'backup_id' => (int) $request['backup_id'],
                            'message' => 'La copia ya está lista y verificada.',
                        ];
                    }
                    if ($finalStatus === 'deleted') {
                        $requestService->clearIfMatches((int) $request['backup_id'], (string) $request['public_id']);
                        (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                            (int) $request['backup_id'],
                            (string) $request['public_id']
                        );
                        $this->releaseSnapshotFreeze((string) $request['public_id']);
                        return [
                            'result' => 'deleted',
                            'backup_id' => (int) $request['backup_id'],
                            'message' => 'La solicitud fue cancelada o limpiada; cree una copia nueva si necesita protección.',
                        ];
                    }
                    $this->releaseSnapshotFreeze((string) $request['public_id']);
                    $requestService->clearIfMatches((int) $request['backup_id'], (string) $request['public_id']);
                }
                return ['result' => 'empty', 'message' => 'La solicitud ya no necesita procesamiento.'];
            }
            if (
                (string) $job['job_status'] === 'running'
                && !empty($job['lease_expires_at'])
                && strtotime((string) $job['lease_expires_at'] . ' UTC') > time()
            ) {
                $pdo->commit();
                return ['result' => 'locked', 'message' => 'La copia ya está siendo procesada.'];
            }
            $owner = 'backup-' . bin2hex(random_bytes(8));
            $generation = (int) $job['lease_generation'] + 1;
            $leaseSeconds = isset($limits['lease_seconds'])
                ? max(10, min(600, (int) $limits['lease_seconds']))
                : 600;
            $pdo->prepare(
                "UPDATE system_backup_jobs
                 SET status='running',lease_owner=:owner,lease_generation=:generation,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL :lease_seconds SECOND),
                     started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),heartbeat_at=UTC_TIMESTAMP(3),
                     attempts=attempts+1
                 WHERE id=:id"
            )->execute([
                'owner' => $owner,
                'generation' => $generation,
                'lease_seconds' => $leaseSeconds,
                'id' => $job['job_id'],
            ]);
            if (
                (string) $job['job_type'] === 'cleanup'
                && (string) $job['status'] === 'cancel_requested'
            ) {
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='deleting',heartbeat_at=UTC_TIMESTAMP(3)
                      WHERE id=:id AND status='cancel_requested'"
                )->execute(['id' => $job['id']]);
                $job['status'] = 'deleting';
            }
            if ((string) $job['job_type'] !== 'cleanup') {
                $archiveState = (string) $job['job_type'] === 'verify' ? 'verifying' : 'creating';
                $pdo->prepare(
                    "UPDATE system_backup_archives
                     SET status=:status,started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),
                         heartbeat_at=UTC_TIMESTAMP(3),safe_error_code=NULL,safe_error_message=NULL
                     WHERE id=:id AND status<>'deleting'"
                )->execute(['status' => $archiveState, 'id' => $job['id']]);
            }
            $pdo->commit();

            if ((string) $job['job_type'] === 'cleanup') {
                return (string) $job['status'] === 'ready_pending_release'
                    ? $this->processReleaseCleanup($job, $owner, $generation, $requestService)
                    : $this->processDeletionCleanup($job, $owner, $generation, $requestService);
            }

            if ((string) $job['job_type'] === 'verify') {
                $archiveRow = $this->archive((int) $job['id']);
                $verified = (new BackupArchiveService())->verify($this->archivePath($archiveRow));
                $checksum = (string) hash_file('sha256', $this->archivePath($archiveRow));
                if (
                    !hash_equals((string) $archiveRow['checksum_sha256'], $checksum)
                    || !hash_equals((string) $archiveRow['manifest_sha256'], $verified['manifest_checksum'])
                ) {
                    throw new RuntimeException('La copia cambió después de su creación.');
                }
                $pdo->beginTransaction();
                $guard = $pdo->prepare(
                    "UPDATE system_backup_jobs
                     SET status='completed',completed_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                         lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
                );
                $guard->execute(['id' => $job['job_id'], 'owner' => $owner, 'generation' => $generation]);
                if ($guard->rowCount() !== 1) {
                    throw new RuntimeException('La autorización del lote venció antes de verificar la copia. Reintente desde esta misma página.');
                }
                $pdo->prepare(
                    "UPDATE system_backup_archives
                     SET status='ready',verified_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3)
                     WHERE id=:id"
                )->execute(['id' => $job['id']]);
                $this->audit($pdo, (int) $job['id'], null, 'backup_verified', 'success', []);
                $pdo->commit();
                $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
                $this->bindMaintenanceContext((int) $job['id']);
                return ['result' => 'completed', 'backup_id' => (int) $job['id']];
            }

            $checkpoint = json_decode((string) ($job['checkpoint_json'] ?? '{}'), true);
            $checkpoint = is_array($checkpoint) ? $checkpoint : [];
            $checkpoint['_execution_tag'] = 'g' . $generation . '-'
                . substr(hash('sha256', $owner), 0, 12);
            $backupSettings = new AppSettingsService();
            $rowLimit = isset($limits['row_limit'])
                ? max(1, min(5000, (int) $limits['row_limit']))
                : max(1, min(5000, $backupSettings->int('backup.chunk_row_limit', 500)));
            $deadline = isset($limits['deadline_seconds'])
                ? max(2, min(20, (int) $limits['deadline_seconds']))
                : max(2, min(20, $backupSettings->int('backup.chunk_deadline_seconds', 12)));
            try {
                $chunk = (new BackupArchiveService())->processChunk(
                    (string) $job['public_id'],
                    (string) $job['purpose'],
                    $checkpoint,
                    $rowLimit,
                    $deadline
                );
            } catch (Throwable $error) {
                (new BackupArchiveV3Service())->cleanupExecutionArtifacts(
                    (string) $job['public_id'],
                    (string) $checkpoint['_execution_tag']
                );
                $this->failRunningBackupJob(
                    (int) $job['id'],
                    (int) $job['job_id'],
                    $owner,
                    $generation,
                    'backup_chunk_failed',
                    'La copia no pudo completarse. Puede limpiar los restos y crear una copia nueva.'
                );
                $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
                $this->releaseSnapshotFreeze((string) $job['public_id']);

                return [
                    'result' => 'failed',
                    'backup_id' => (int) $job['id'],
                    'message' => 'La copia no pudo completarse. Puede limpiar los restos y crear una copia nueva.',
                ];
            }
            if (empty($chunk['complete'])) {
                $guard = $pdo->prepare(
                    "UPDATE system_backup_jobs
                     SET status='pending',checkpoint_json=:checkpoint,heartbeat_at=UTC_TIMESTAMP(3),
                         lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation
                       AND status='running'"
                );
                $guard->execute([
                    'checkpoint' => json_encode(
                        $chunk['checkpoint'],
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'id' => $job['job_id'],
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                if ($guard->rowCount() !== 1) {
                    (new BackupArchiveV3Service())->cleanupExecutionArtifacts(
                        (string) $job['public_id'],
                        (string) ($chunk['checkpoint']['_execution_tag'] ?? '')
                    );
                    $this->publishBackupIfCli(
                        (int) $job['id'],
                        (string) $job['public_id'],
                        'pending',
                        '',
                        $generation,
                        'create',
                        true
                    );
                    return [
                        'result' => 'deferred',
                        'backup_id' => (int) $job['id'],
                        'message' => 'El micro-lote perdió su lease antes de aprobar el checkpoint. '
                            . 'Se conservará el último punto aprobado y se reintentará sin repetir datos guardados.',
                    ];
                }
                $pdo->prepare(
                    "UPDATE system_backup_archives
                     SET status='creating',format_version=3,heartbeat_at=UTC_TIMESTAMP(3)
                     WHERE id=:id"
                )->execute(['id' => $job['id']]);
                $this->publishBackupIfCli(
                    (int) $job['id'],
                    (string) $job['public_id'],
                    'pending',
                    '',
                    $generation,
                    'create',
                    true
                );
                return ['result' => 'partial', 'backup_id' => (int) $job['id']];
            }
            $archive = is_array($chunk['archive'] ?? null) ? $chunk['archive'] : [];
            if ($archive === []) {
                throw new RuntimeException('El respaldo terminó sin un archivo verificable.');
            }
            $pdo->beginTransaction();
            $guard = $pdo->prepare(
                "UPDATE system_backup_jobs
                 SET status='completed',checkpoint_json=:checkpoint,
                     completed_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                     lease_owner=NULL,lease_expires_at=NULL
                 WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
            );
            $guard->execute([
                'checkpoint' => json_encode(
                    $chunk['checkpoint'],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'id' => $job['job_id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($guard->rowCount() !== 1) {
                (new BackupArchiveV3Service())->cleanupExecutionArtifacts(
                    (string) $job['public_id'],
                    (string) ($chunk['checkpoint']['_execution_tag'] ?? '')
                );
                $this->publishBackupIfCli(
                    (int) $job['id'],
                    (string) $job['public_id'],
                    'pending',
                    '',
                    $generation,
                    'create',
                    true
                );
                return [
                    'result' => 'deferred',
                    'backup_id' => (int) $job['id'],
                    'message' => 'El micro-lote terminó el archivo, pero perdió su lease antes de aprobarlo. '
                        . 'Se limpiaron los artefactos temporales y se reintentará desde el último checkpoint.',
                ];
            }
            $pdo->prepare(
                "UPDATE system_backup_archives
                  SET status='ready_pending_release',format_version=:format,key_id=:key_id,storage_name=:storage_name,
                     size_bytes=:size,table_count=:tables,row_count=:rows,checksum_sha256=:checksum,
                     manifest_sha256=:manifest,verified_at=UTC_TIMESTAMP(3),completed_at=UTC_TIMESTAMP(3),
                     heartbeat_at=UTC_TIMESTAMP(3)
                 WHERE id=:id"
            )->execute([
                'format' => max(2, (int) ($archive['format'] ?? 3)),
                'key_id' => $archive['key_id'],
                'storage_name' => $archive['storage_name'],
                'size' => $archive['size'],
                'tables' => $archive['tables'],
                'rows' => $archive['rows'],
                'checksum' => $archive['checksum'],
                'manifest' => $archive['manifest_checksum'],
                'id' => $job['id'],
            ]);
            $insert = $pdo->prepare(
                "INSERT INTO system_backup_table_checks
                 (backup_id,table_name,row_count,structure_sha256,data_sha256,verified_at)
                 VALUES (:backup_id,:table_name,:row_count,:structure_hash,:data_hash,UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE row_count=VALUES(row_count),
                 structure_sha256=VALUES(structure_sha256),data_sha256=VALUES(data_sha256),
                 verified_at=VALUES(verified_at)"
            );
            foreach ($archive['table_checks'] as $check) {
                $insert->execute([
                    'backup_id' => $job['id'],
                    'table_name' => $check['table'],
                    'row_count' => $check['rows'],
                    'structure_hash' => $check['structure_sha256'],
                    'data_hash' => $check['data_sha256'],
                ]);
            }
            $this->audit($pdo, (int) $job['id'], null, 'backup_completed', 'success', [
                'tables' => $archive['tables'],
                'rows' => $archive['rows'],
            ]);
            $pdo->prepare(
                "INSERT INTO system_backup_jobs
                    (backup_id,job_type,status,available_at)
                 VALUES (:backup_id,'cleanup','pending',UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    status='pending',available_at=UTC_TIMESTAMP(3),
                    lease_owner=NULL,lease_expires_at=NULL,
                    safe_error_code=NULL,safe_error_message=NULL,completed_at=NULL"
            )->execute(['backup_id' => $job['id']]);
            $pdo->commit();
            $this->publishBackupIfCli(
                (int) $job['id'],
                (string) $job['public_id'],
                'ready_pending_release',
                '',
                0,
                'cleanup',
                true
            );
            return ['result' => 'partial', 'backup_id' => (int) $job['id']];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $ownedFailure = false;
            try {
                $failed = $pdo->prepare(
                    "UPDATE system_backup_jobs SET status='failed',safe_error_code='backup_failed',
                     safe_error_message='La copia no pudo completarse.',completed_at=UTC_TIMESTAMP(3),
                     lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:job_id AND status='running'
                       AND lease_owner=:owner AND lease_generation=:generation"
                );
                $failed->execute([
                    'job_id' => $job['job_id'] ?? 0,
                    'owner' => $owner ?? '',
                    'generation' => $generation ?? 0,
                ]);
                $ownedFailure = $failed->rowCount() === 1;
                if ($ownedFailure) {
                    $pdo->prepare(
                        "UPDATE system_backup_archives SET status='failed',
                         safe_error_code='backup_failed',
                         safe_error_message='La copia no pudo completarse.',
                         completed_at=UTC_TIMESTAMP(3)
                         WHERE id=:id AND status IN ('creating','verifying','queued','prepared')"
                    )->execute(['id' => (int) ($job['id'] ?? 0)]);
                }
            } catch (Throwable) {
            }
            /*
             * El micro-lote actual usa un nombre que incluye la generación.
             * Si falló antes de devolver el checkpoint, cleanup($checkpoint)
             * todavía no conoce sus archivos nuevos. Retiramos siempre los
             * artefactos de esta generación exacta; nunca toca fragmentos
             * aprobados por un worker anterior o posterior.
             */
            if (
                isset($checkpoint)
                && is_array($checkpoint)
                && isset($job)
                && is_array($job)
            ) {
                (new BackupArchiveV3Service())->cleanupExecutionArtifacts(
                    (string) ($job['public_id'] ?? ''),
                    (string) ($checkpoint['_execution_tag'] ?? '')
                );
            }
            if ($ownedFailure) {
                if (isset($checkpoint) && is_array($checkpoint)) {
                    (new BackupArchiveV3Service())->cleanup($checkpoint);
                }
                if (isset($job) && is_array($job)) {
                    $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
                }
            }
            throw $error;
        }
    }

    private function failRunningBackupJob(
        int $backupId,
        int $jobId,
        string $owner,
        int $generation,
        string $code,
        string $message
    ): bool {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $failed = $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='failed',safe_error_code=:code,safe_error_message=:message,
                        completed_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                        lease_owner=NULL,lease_expires_at=NULL
                  WHERE id=:job_id AND backup_id=:backup_id AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation"
            );
            $failed->execute([
                'code' => $code,
                'message' => $message,
                'job_id' => $jobId,
                'backup_id' => $backupId,
                'owner' => $owner,
                'generation' => $generation,
            ]);
            $owned = $failed->rowCount() === 1;
            if ($owned) {
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='failed',safe_error_code=:code,safe_error_message=:message,
                            completed_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3)
                      WHERE id=:backup_id
                        AND status IN ('prepared','queued','creating','verifying','ready_pending_release')"
                )->execute([
                    'code' => $code,
                    'message' => $message,
                    'backup_id' => $backupId,
                ]);
                $this->audit($pdo, $backupId, null, 'backup_failed', 'error', ['code' => $code]);
            }
            $pdo->commit();

            return $owned;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function reconcileForcedRequestWithoutJob(
        PDO $pdo,
        int $backupId,
        string $publicId,
        BackupMaintenanceRequestService $requestService
    ): ?array {
        $archive = $pdo->prepare(
            'SELECT *
               FROM system_backup_archives
              WHERE id=:id AND public_id=:public_id AND deleted_at IS NULL
              LIMIT 1 FOR UPDATE'
        );
        $archive->execute(['id' => $backupId, 'public_id' => $publicId]);
        $row = $archive->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'ready') {
            $requestService->clearIfMatches($backupId, $publicId);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            $this->releaseSnapshotFreeze($publicId);
            return ['result' => 'completed', 'backup_id' => $backupId];
        }
        if ($status === 'deleted') {
            $requestService->clearIfMatches($backupId, $publicId);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            $this->releaseSnapshotFreeze($publicId);
            return ['result' => 'completed', 'backup_id' => $backupId];
        }

        $jobSummary = $pdo->prepare(
            "SELECT
                SUM(job_type='create' AND status='completed') AS create_completed,
                SUM(job_type='cleanup' AND status='completed') AS cleanup_completed,
                SUM(status IN ('pending','running','cancel_requested')) AS open_jobs
               FROM system_backup_jobs
              WHERE backup_id=:backup_id"
        );
        $jobSummary->execute(['backup_id' => $backupId]);
        $jobs = $jobSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($jobs['open_jobs'] ?? 0) > 0) {
            return null;
        }

        $storageName = basename((string) ($row['storage_name'] ?? ''));
        if ($storageName === '') {
            $checkpoint = $pdo->prepare(
                "SELECT checkpoint_json
                   FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND job_type='create'
                  ORDER BY id DESC LIMIT 1"
            );
            $checkpoint->execute(['backup_id' => $backupId]);
            $decoded = json_decode((string) ($checkpoint->fetchColumn() ?: ''), true);
            if (is_array($decoded)) {
                $storageName = basename((string) ($decoded['storage_name'] ?? ''));
            }
        }
        $path = $storageName !== '' ? AppPaths::backups() . '/' . $storageName : '';
        $fileReady = $path !== '' && is_file($path);

        if ($fileReady) {
            $verified = (new BackupArchiveService())->verify($path);
            $checksum = (string) hash_file('sha256', $path);
            $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='ready',storage_name=:storage_name,size_bytes=:size,
                        checksum_sha256=:checksum,manifest_sha256=:manifest,
                        verified_at=COALESCE(verified_at,UTC_TIMESTAMP(3)),
                        completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                        heartbeat_at=UTC_TIMESTAMP(3),
                        safe_error_code=NULL,safe_error_message=NULL
                  WHERE id=:id"
            )->execute([
                'storage_name' => $storageName,
                'size' => (int) filesize($path),
                'checksum' => $checksum,
                'manifest' => (string) $verified['manifest_checksum'],
                'id' => $backupId,
            ]);
            $this->audit($pdo, $backupId, null, 'backup_reconciled_ready', 'success', []);
            $requestService->clearIfMatches($backupId, $publicId);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            $this->releaseSnapshotFreeze($publicId);
            $this->bindMaintenanceContext($backupId);
            return ['result' => 'completed', 'backup_id' => $backupId, 'message' => 'La copia se reconcilió y quedó verificada.'];
        }

        if ((int) ($jobs['cleanup_completed'] ?? 0) > 0 || in_array($status, ['cancel_requested', 'deleting'], true)) {
            $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='deleted',deleted_at=COALESCE(deleted_at,UTC_TIMESTAMP(3)),
                        completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                        heartbeat_at=UTC_TIMESTAMP(3),
                        safe_error_code=NULL,safe_error_message=NULL
                  WHERE id=:id"
            )->execute(['id' => $backupId]);
            $this->audit($pdo, $backupId, null, 'backup_reconciled_deleted', 'success', []);
            $requestService->clearIfMatches($backupId, $publicId);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            $this->releaseSnapshotFreeze($publicId);
            return ['result' => 'completed', 'backup_id' => $backupId, 'message' => 'La solicitud incompleta se limpió correctamente.'];
        }

        if ((int) ($jobs['create_completed'] ?? 0) > 0) {
            $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='failed',completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                        heartbeat_at=UTC_TIMESTAMP(3),
                        safe_error_code='backup_file_missing',
                        safe_error_message='La copia terminó sin archivo verificable.'
                  WHERE id=:id"
            )->execute(['id' => $backupId]);
            $this->audit($pdo, $backupId, null, 'backup_reconciled_failed', 'error', [
                'reason' => 'file_missing',
            ]);
            $requestService->clearIfMatches($backupId, $publicId);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            $this->releaseSnapshotFreeze($publicId);
            return ['result' => 'failed', 'backup_id' => $backupId, 'message' => 'La copia quedó fallida porque no existe un archivo verificable.'];
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function processInteractiveStep(int $backupId, int $userId): array
    {
        if ($backupId < 1) {
            throw new RuntimeException('La copia no está disponible.');
        }
        $archive = $this->archive($backupId);
        if ((int) ($archive['requested_by'] ?? 0) !== $userId) {
            throw new \App\Core\HttpException(404, 'La copia solicitada no existe.');
        }
        $status = (string) ($archive['status'] ?? '');
        $snapshotConflict = $this->snapshotConflict((string) $archive['public_id']);
        if ($snapshotConflict !== null) {
            throw new RuntimeException(
                $snapshotConflict > 0
                    ? 'Existe otra protección de datos activa. Use Continuar, Cancelar o Limpiar la copia #' . $snapshotConflict . ' antes de avanzar esta copia.'
                    : 'Existe otra protección de datos activa. Use Continuar, Cancelar o Limpiar la copia pendiente antes de avanzar esta copia.'
            );
        }
        if ($status === 'ready') {
            return [
                'result' => 'completed',
                'backup_id' => $backupId,
                'message' => 'La copia ya está lista y verificada.',
                'overview' => $this->overview($backupId),
            ];
        }
        if (!in_array(
            $status,
            ['prepared', 'queued', 'creating', 'verifying', 'ready_pending_release', 'cancel_requested', 'deleting'],
            true
        )) {
            throw new RuntimeException('Esta copia no puede continuar desde el navegador.');
        }
        $beforeProgress = $this->progressPresenter($archive);
        $result = $this->processRequested(
            [
                'backup_id' => $backupId,
                'public_id' => (string) $archive['public_id'],
            ],
            [
                'row_limit' => 500,
                'deadline_seconds' => 2,
                'lease_seconds' => 30,
            ]
        );
        $overview = $this->overview($backupId);
        $afterProgress = is_array($overview['progress'] ?? null) ? $overview['progress'] : [];
        $result['previous_status'] = $status;
        $result['status'] = (string) ($overview['active']['status'] ?? $result['result'] ?? $status);
        $result['rows_processed_in_step'] = max(
            0,
            (int) ($afterProgress['rows_processed'] ?? 0)
                - (int) ($beforeProgress['rows_processed'] ?? 0)
        );
        $result['current_table'] = $afterProgress['current_table'] ?? null;
        $result['message'] = (string) (
            $result['message']
            ?? $afterProgress['message']
            ?? 'Micro-lote local aprobado.'
        );
        $result['should_continue'] = in_array(
            (string) ($overview['active']['status'] ?? ''),
            ['prepared', 'queued', 'creating', 'verifying', 'ready_pending_release', 'cancel_requested', 'deleting'],
            true
        );
        $result['overview'] = $overview;
        return $result;
    }

    /** @return array{token:string,expires_at:string} */
    public function issueDownloadGrant(int $backupId, int $userId): array
    {
        $pdo = Database::connection();
        $archive = $this->archive($backupId);
        if ((string) $archive['status'] !== 'ready') {
            throw new RuntimeException('La copia todavía no está lista para descargar.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $seconds = max(60, min(900, (int) (new AppSettingsService())->get('backup.download_grant_seconds', '300')));
        $stmt = $pdo->prepare(
            "INSERT INTO system_backup_download_grants
             (backup_id,user_id,token_hash,expires_at)
             VALUES (:backup_id,:user_id,:hash,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL :seconds SECOND))"
        );
        $stmt->bindValue('backup_id', $backupId, PDO::PARAM_INT);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('hash', hash('sha256', $token));
        $stmt->bindValue('seconds', $seconds, PDO::PARAM_INT);
        $stmt->execute();
        $this->audit($pdo, $backupId, $userId, 'download_grant_created', 'success', []);
        return ['token' => $token, 'expires_at' => gmdate(DATE_ATOM, time() + $seconds)];
    }

    /** @return array<string,mixed> */
    public function consumeDownloadGrant(string $token, int $userId): array
    {
        $hash = hash('sha256', $token);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT g.id AS grant_id,b.*
                 FROM system_backup_download_grants g
                 INNER JOIN system_backup_archives b ON b.id=g.backup_id
                 WHERE g.token_hash=:hash AND g.user_id=:user_id
                   AND g.consumed_at IS NULL AND g.expires_at>UTC_TIMESTAMP(3)
                   AND b.status='ready' AND b.deleted_at IS NULL
                 FOR UPDATE"
            );
            $stmt->execute(['hash' => $hash, 'user_id' => $userId]);
            $archive = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($archive)) {
                throw new RuntimeException('La autorización de descarga no es válida o expiró.');
            }
            // La concesión permanece utilizable hasta su vencimiento para que
            // el navegador pueda reanudar una descarga mediante HTTP Range.
            $this->audit($pdo, (int) $archive['id'], $userId, 'backup_downloaded', 'success', []);
            $pdo->commit();
            $path = $this->archivePath($archive);
            if (!is_file($path)) {
                throw new RuntimeException('El archivo de la copia ya no está disponible.');
            }
            $archive['_path'] = $path;
            return $archive;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function queueVerification(int $backupId, int $userId): void
    {
        $archive = $this->archive($backupId);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ((string) $archive['status'] !== 'ready') {
                throw new RuntimeException('La copia no está disponible para comprobarla.');
            }
            $pdo->prepare(
                "INSERT INTO system_backup_jobs (backup_id,job_type,status)
                 VALUES (:backup_id,'verify','pending')
                 ON DUPLICATE KEY UPDATE status='pending',lease_owner=NULL,lease_expires_at=NULL,
                 safe_error_code=NULL,safe_error_message=NULL,completed_at=NULL"
            )->execute(['backup_id' => $backupId]);
            $pdo->prepare(
                "UPDATE system_backup_archives SET status='verifying' WHERE id=:id"
            )->execute(['id' => $backupId]);
            $this->audit($pdo, $backupId, $userId, 'backup_verification_requested', 'success', []);
            $pdo->commit();
            $this->publishBackupIfCli(
                $backupId,
                (string) $archive['public_id'],
                'pending',
                '',
                0,
                'verify',
                true
            );
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{status:string,message:string} */
    public function delete(int $backupId, int $userId): array
    {
        $pdo = Database::connection();
        if (!$this->supportsDeletionLifecycle()) {
            return $this->deleteBeforeLifecycleMigration($pdo, $backupId, $userId);
        }

        $pdo->beginTransaction();
        try {
            $archive = $this->archiveForUpdate($pdo, $backupId, true);
            if ((string) $archive['status'] === 'deleted') {
                $pdo->commit();
                return ['status' => 'deleted', 'message' => 'La copia ya estaba eliminada.'];
            }
            if (in_array((string) $archive['status'], ['cancel_requested', 'deleting'], true)) {
                $pdo->commit();
                $this->publishBackupCoordination($backupId, (string) $archive['public_id']);
                return [
                    'status' => (string) $archive['status'],
                    'message' => 'La cancelación ya está solicitada y continuará con el siguiente micro-paso local.',
                ];
            }

            $jobs = $pdo->prepare(
                "SELECT id,status,lease_owner,lease_generation,lease_expires_at,
                        heartbeat_at,checkpoint_json
                   FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND job_type IN ('create','verify')
                  FOR UPDATE"
            );
            $jobs->execute(['backup_id' => $backupId]);
            $jobRows = $jobs->fetchAll(PDO::FETCH_ASSOC);
            $emptyRequest = in_array(
                (string) $archive['status'],
                ['prepared', 'queued'],
                true
            ) && trim((string) ($archive['storage_name'] ?? '')) === ''
                && !$this->hasArchiveArtifacts($archive);
            foreach ($jobRows as $job) {
                $checkpoint = json_decode(
                    (string) ($job['checkpoint_json'] ?? ''),
                    true
                );
                if (
                    (string) ($job['status'] ?? '') === 'running'
                    || trim((string) ($job['lease_owner'] ?? '')) !== ''
                    || !empty($job['lease_expires_at'])
                    || !empty($job['heartbeat_at'])
                    || (is_array($checkpoint) && $checkpoint !== [])
                ) {
                    $emptyRequest = false;
                    break;
                }
            }
            if ($emptyRequest) {
                $pdo->prepare(
                    "UPDATE system_backup_jobs
                        SET status='cancelled',lease_generation=lease_generation+1,
                            lease_owner=NULL,lease_expires_at=NULL,
                            completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                            safe_error_code='cancelled_empty',
                            safe_error_message='La solicitud vacía fue cancelada.'
                      WHERE backup_id=:backup_id
                        AND status IN ('pending','failed','cancel_requested')"
                )->execute(['backup_id' => $backupId]);
                $pdo->prepare(
                    "UPDATE system_backup_download_grants
                        SET consumed_at=COALESCE(consumed_at,UTC_TIMESTAMP(3))
                      WHERE backup_id=:backup_id"
                )->execute(['backup_id' => $backupId]);
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='deleted',deleted_at=UTC_TIMESTAMP(3),
                            delete_requested_at=UTC_TIMESTAMP(3),
                            delete_requested_by=:user_id,storage_name=NULL,
                            safe_error_code=NULL,safe_error_message=NULL
                      WHERE id=:id AND status IN ('prepared','queued')"
                )->execute(['user_id' => $userId, 'id' => $backupId]);
                $this->audit(
                    $pdo,
                    $backupId,
                    $userId,
                    'backup_empty_request_cancelled',
                    'success',
                    []
                );
                $pdo->commit();
                try {
                    (new BackupMaintenanceRequestService())->clearIfMatches(
                        $backupId,
                        (string) $archive['public_id']
                    );
                    $coordinator = new LocalMaintenanceCoordinator();
                    $coordinator->clearCoordinatorIfMatches(
                        $backupId,
                        (string) $archive['public_id']
                    );
                    $this->releaseSnapshotFreeze((string) $archive['public_id']);
                } catch (Throwable) {
                    // La fila ya quedó cercada y eliminada. La reconciliación
                    // posterior puede retirar un marcador local remanente.
                }
                return [
                    'status' => 'deleted',
                    'message' => 'La solicitud vacía fue cancelada y liberada.',
                ];
            }
            $notBefore = null;
            foreach ($jobRows as $job) {
                if (
                    (string) ($job['status'] ?? '') === 'running'
                    && !empty($job['lease_expires_at'])
                    && strtotime((string) $job['lease_expires_at'] . ' UTC') > time()
                ) {
                    $candidate = (string) $job['lease_expires_at'];
                    if ($notBefore === null || strcmp($candidate, $notBefore) > 0) {
                        $notBefore = $candidate;
                    }
                }
            }
            if ($this->supportsSafeCancellationLifecycle()) {
                $pdo->prepare(
                    "UPDATE system_backup_jobs
                        SET status='cancel_requested',lease_generation=lease_generation+1,
                            safe_error_code='cancellation_requested',
                            safe_error_message='La cancelación fue solicitada por un administrador.'
                      WHERE backup_id=:backup_id AND job_type IN ('create','verify')
                        AND status IN ('pending','running')"
                )->execute(['backup_id' => $backupId]);
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='cancel_requested',cancel_requested_at=UTC_TIMESTAMP(3),
                            delete_requested_at=UTC_TIMESTAMP(3),
                            delete_requested_by=:user_id,delete_not_before_at=:not_before,
                            control_generation=control_generation+1,
                            maintenance_phase='cancel_requested',
                            safe_error_code=NULL,safe_error_message=NULL
                      WHERE id=:id"
                )->execute([
                    'user_id' => $userId,
                    'not_before' => $notBefore,
                    'id' => $backupId,
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE system_backup_jobs
                        SET status='cancelled',lease_generation=lease_generation+1,
                            lease_owner=NULL,completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                            safe_error_code='deletion_requested',
                            safe_error_message='La eliminación fue solicitada por un administrador.'
                      WHERE backup_id=:backup_id AND job_type IN ('create','verify')
                        AND status IN ('pending','running')"
                )->execute(['backup_id' => $backupId]);
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='deleting',delete_requested_at=UTC_TIMESTAMP(3),
                            delete_requested_by=:user_id,delete_not_before_at=:not_before,
                            safe_error_code=NULL,safe_error_message=NULL
                      WHERE id=:id"
                )->execute([
                    'user_id' => $userId,
                    'not_before' => $notBefore,
                    'id' => $backupId,
                ]);
            }
            $pdo->prepare(
                "INSERT INTO system_backup_jobs
                    (backup_id,job_type,status,available_at)
                 VALUES (:backup_id,'cleanup','pending',:available_at)
                 ON DUPLICATE KEY UPDATE
                    status='pending',
                    available_at=VALUES(available_at),lease_owner=NULL,lease_expires_at=NULL,
                    safe_error_code=NULL,safe_error_message=NULL,completed_at=NULL"
            )->execute(['backup_id' => $backupId, 'available_at' => $notBefore]);
            $this->audit($pdo, $backupId, $userId, 'backup_deletion_requested', 'success', [
                'previous_status' => (string) $archive['status'],
                'waits_for_active_lot' => $notBefore !== null,
            ]);
            $pdo->commit();
            $this->publishBackupCoordination($backupId, (string) $archive['public_id']);
            return [
                'status' => $this->supportsSafeCancellationLifecycle()
                    ? 'cancel_requested'
                    : 'deleting',
                'message' => $notBefore !== null
                    ? 'La eliminación quedó solicitada. Esperará a que termine el micro-lote activo.'
                    : 'La eliminación quedó solicitada y continuará con el siguiente micro-paso local.',
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{status:string,message:string} */
    public function recover(int $backupId, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $archive = $this->archiveForUpdate($pdo, $backupId, false);
            $status = (string) $archive['status'];
            if (!in_array(
                $status,
                [
                    'prepared', 'queued', 'creating', 'verifying',
                    'ready_pending_release', 'cancel_requested', 'deleting',
                ],
                true
            )) {
                throw new RuntimeException('Esta copia no necesita recuperar su señal de ejecución.');
            }
            $preferredType = in_array(
                $status,
                ['ready_pending_release', 'cancel_requested', 'deleting'],
                true
            ) ? 'cleanup' : ($status === 'verifying' ? 'verify' : 'create');
            $statement = $pdo->prepare(
                "SELECT id,job_type,status,lease_owner,lease_generation,lease_expires_at,
                        checkpoint_json
                   FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND job_type=:job_type
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $statement->execute([
                'backup_id' => $backupId,
                'job_type' => $preferredType,
            ]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($job)
                || !in_array((string) ($job['status'] ?? ''), ['pending', 'running'], true)
            ) {
                throw new RuntimeException(
                    'La copia no conserva un trabajo ejecutable. Cancele la solicitud vacía '
                    . 'o limpie sus restos antes de crear otra.'
                );
            }
            $leaseUntil = !empty($job['lease_expires_at'])
                ? strtotime((string) $job['lease_expires_at'] . ' UTC')
                : false;
            if (
                (string) $job['status'] === 'running'
                && trim((string) ($job['lease_owner'] ?? '')) !== ''
                && is_int($leaseUntil)
                && $leaseUntil > time()
            ) {
                throw new RuntimeException('La copia todavía tiene un micro-lote vigente. Espere a que termine o a que venza su reserva.');
            }
            $checkpoint = json_decode((string) ($job['checkpoint_json'] ?? ''), true);
            $checkpoint = is_array($checkpoint) ? $checkpoint : [];
            if (
                in_array($preferredType, ['create', 'verify'], true)
                && $checkpoint === []
                && !$this->hasArchiveArtifacts($archive)
            ) {
                throw new RuntimeException(
                    'La solicitud no alcanzó a crear un checkpoint ni un archivo. '
                    . 'Cancélela de forma segura y cree una copia nueva.'
                );
            }
            if (
                $preferredType === 'create'
                && $checkpoint !== []
                && !$this->checkpointArtifactsAvailable($checkpoint)
            ) {
                throw new RuntimeException(
                    'El checkpoint referencia fragmentos que ya no están disponibles. '
                    . 'Solicite su limpieza antes de crear otra copia.'
                );
            }
            if ($preferredType === 'verify') {
                $path = $this->archivePath($archive);
                if (!is_file($path) || !BackupArchiveV3Service::matches($path)) {
                    throw new RuntimeException(
                        'El archivo que debía verificarse no está disponible o no es compatible.'
                    );
                }
            }
            $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='pending',lease_owner=NULL,lease_expires_at=NULL,
                        available_at=UTC_TIMESTAMP(3),safe_error_code=NULL,
                        safe_error_message=NULL,completed_at=NULL
                  WHERE id=:id AND status IN ('pending','running')"
            )->execute(['id' => (int) $job['id']]);
            $this->audit(
                $pdo,
                $backupId,
                $userId,
                'backup_signal_recovered',
                'success',
                [
                    'status' => $status,
                    'job_type' => $preferredType,
                    'generation' => (int) $job['lease_generation'],
                ]
            );
            $pdo->commit();
            if (!in_array($status, ['cancel_requested', 'deleting'], true)) {
                (new BackupArchiveV3Service())->activateFreeze((string) $archive['public_id']);
            }
            $this->publishBackupIfCli(
                $backupId,
                (string) $archive['public_id'],
                'pending',
                '',
                (int) $job['lease_generation'],
                $preferredType,
                true
            );
            return [
                'status' => $status,
                'message' => $preferredType === 'cleanup'
                    ? 'Se recuperó la limpieza cercada de esta copia.'
                    : 'Se recuperó el checkpoint exacto. La copia continuará sin empezar de nuevo.',
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function ownsControlMutation(int $backupId): bool
    {
        if ($backupId < 1) {
            return false;
        }
        try {
            $archive = $this->archive($backupId);
            return in_array(
                (string) $archive['status'],
                [
                    'prepared', 'queued', 'creating', 'verifying',
                    'ready_pending_release', 'cancel_requested', 'deleting',
                ],
                true
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function canEnterControlMutation(int $backupId): bool
    {
        if ($backupId < 1) {
            return false;
        }
        try {
            $archive = $this->archive($backupId);
            return in_array(
                (string) $archive['status'],
                [
                    'prepared', 'queued', 'creating', 'verifying',
                    'ready_pending_release', 'cancel_requested', 'deleting',
                ],
                true
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function processReleaseCleanup(
        array $job,
        string $owner,
        int $generation,
        BackupMaintenanceRequestService $requestService
    ): array {
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $archive = $this->archiveForUpdate($pdo, (int) $job['id'], true);
            if ((string) $archive['status'] === 'ready') {
                $pdo->commit();
                $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
                (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                    (int) $job['id'],
                    (string) $job['public_id']
                );
                $this->releaseSnapshotFreeze((string) $job['public_id']);
                return ['result' => 'completed', 'backup_id' => (int) $job['id']];
            }
            if ((string) $archive['status'] !== 'ready_pending_release') {
                throw new RuntimeException('La copia ya no está autorizada para liberar su protección.');
            }
            $checkpointStatement = $pdo->prepare(
                "SELECT checkpoint_json
                   FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND job_type='create'
                  ORDER BY id DESC LIMIT 1"
            );
            $checkpointStatement->execute(['backup_id' => (int) $job['id']]);
            $checkpoint = json_decode((string) ($checkpointStatement->fetchColumn() ?: ''), true);
            if (!is_array($checkpoint) || $checkpoint === []) {
                throw new RuntimeException('La copia terminada no conserva su checkpoint de liberación.');
            }
            $lease = $pdo->prepare(
                "SELECT COUNT(*) FROM system_backup_jobs
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation
                  FOR UPDATE"
            );
            $lease->execute([
                'id' => (int) $job['job_id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ((int) $lease->fetchColumn() !== 1) {
                throw new RuntimeException('La autorización del lote venció antes de liberar la copia. Reintente desde esta misma página.');
            }
            (new BackupArchiveV3Service())->cleanup($checkpoint);

            $guard = $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='completed',completed_at=UTC_TIMESTAMP(3),
                        heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation"
            );
            $guard->execute([
                'id' => (int) $job['job_id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($guard->rowCount() !== 1) {
                throw new RuntimeException('La autorización del lote venció antes de liberar la copia. Reintente desde esta misma página.');
            }
            $released = $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='ready',heartbeat_at=UTC_TIMESTAMP(3),
                        safe_error_code=NULL,safe_error_message=NULL
                  WHERE id=:id AND status='ready_pending_release'"
            );
            $released->execute(['id' => (int) $job['id']]);
            if ($released->rowCount() !== 1) {
                throw new RuntimeException('La copia cambió antes de liberar su protección.');
            }
            $this->audit($pdo, (int) $job['id'], null, 'backup_protection_released', 'success', []);
            $pdo->commit();
            $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                (int) $job['id'],
                (string) $job['public_id']
            );
            $this->releaseSnapshotFreeze((string) $job['public_id']);
            $this->bindMaintenanceContext((int) $job['id']);
            return ['result' => 'completed', 'backup_id' => (int) $job['id']];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retry = $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='pending',available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),
                        lease_owner=NULL,lease_expires_at=NULL,
                        safe_error_code='release_retry',
                        safe_error_message='La liberación local se reintentará.'
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation"
            );
            $retry->execute([
                'id' => (int) ($job['job_id'] ?? 0),
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($retry->rowCount() === 1) {
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET safe_error_code='release_retry',
                            safe_error_message='La liberación local se reintentará.'
                      WHERE id=:id AND status='ready_pending_release'"
                )->execute(['id' => (int) ($job['id'] ?? 0)]);
                $this->publishBackupIfCli(
                    (int) $job['id'],
                    (string) $job['public_id'],
                    'pending',
                    '',
                    $generation,
                    'cleanup',
                    true
                );
            }
            return [
                'result' => 'deferred',
                'backup_id' => (int) ($job['id'] ?? 0),
                'message' => 'La liberación local quedó programada para reintento.',
            ];
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function processDeletionCleanup(
        array $job,
        string $owner,
        int $generation,
        BackupMaintenanceRequestService $requestService
    ): array {
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $archive = $this->archiveForUpdate($pdo, (int) $job['id'], true);
            if ((string) $archive['status'] === 'deleted') {
                $pdo->commit();
                $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
                (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                    (int) $job['id'],
                    (string) $job['public_id']
                );
                $this->releaseSnapshotFreeze((string) $job['public_id']);
                return ['result' => 'completed', 'backup_id' => (int) $job['id']];
            }
            if ((string) $archive['status'] !== 'deleting') {
                throw new RuntimeException('La copia ya no está autorizada para limpieza.');
            }
            $lease = $pdo->prepare(
                "SELECT COUNT(*) FROM system_backup_jobs
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation
                  FOR UPDATE"
            );
            $lease->execute([
                'id' => (int) $job['job_id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ((int) $lease->fetchColumn() !== 1) {
                throw new RuntimeException('La autorización del lote venció antes de limpiar archivos. Reintente desde esta misma página.');
            }

            $checkpoints = $pdo->prepare(
                "SELECT checkpoint_json FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND job_type IN ('create','verify')"
            );
            $checkpoints->execute(['backup_id' => (int) $job['id']]);
            foreach ($checkpoints->fetchAll(PDO::FETCH_COLUMN) as $encoded) {
                $checkpoint = json_decode((string) $encoded, true);
                if (is_array($checkpoint) && $checkpoint !== []) {
                    (new BackupArchiveV3Service())->cleanup($checkpoint);
                }
            }
            $this->removeArchiveArtifacts($archive);

            $guard = $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='completed',completed_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                        lease_owner=NULL,lease_expires_at=NULL
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation"
            );
            $guard->execute([
                'id' => (int) $job['job_id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($guard->rowCount() !== 1) {
                throw new RuntimeException('La autorización del lote venció antes de aprobar la eliminación. Reintente desde esta misma página.');
            }
            $pdo->prepare(
                "UPDATE system_backup_download_grants
                    SET consumed_at=COALESCE(consumed_at,UTC_TIMESTAMP(3))
                  WHERE backup_id=:backup_id"
            )->execute(['backup_id' => (int) $job['id']]);
            $deleted = $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='deleted',deleted_at=UTC_TIMESTAMP(3),storage_name=NULL,
                        heartbeat_at=UTC_TIMESTAMP(3),safe_error_code=NULL,safe_error_message=NULL
                  WHERE id=:id AND status='deleting'"
            );
            $deleted->execute(['id' => (int) $job['id']]);
            if ($deleted->rowCount() !== 1) {
                throw new RuntimeException('La copia cambió de estado antes de aprobar la eliminación.');
            }
            $this->audit($pdo, (int) $job['id'], null, 'backup_deleted', 'success', []);
            $pdo->commit();
            $this->releaseSnapshotFreeze((string) $job['public_id']);
            $requestService->clearIfMatches((int) $job['id'], (string) $job['public_id']);
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches(
                (int) $job['id'],
                (string) $job['public_id']
            );
            return ['result' => 'completed', 'backup_id' => (int) $job['id']];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retry = $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='pending',available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),
                        lease_owner=NULL,lease_expires_at=NULL,
                        safe_error_code='cleanup_retry',
                        safe_error_message='La limpieza local se reintentará.'
                  WHERE id=:id AND job_type='cleanup' AND status='running'
                    AND lease_owner=:owner AND lease_generation=:generation"
            );
            $retry->execute([
                'id' => (int) ($job['job_id'] ?? 0),
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($retry->rowCount() === 1) {
                $pdo->prepare(
                    "UPDATE system_backup_archives
                        SET status='deleting',safe_error_code='cleanup_retry',
                            safe_error_message='La limpieza local se reintentará.'
                      WHERE id=:id AND status='deleting'"
                )->execute(['id' => (int) ($job['id'] ?? 0)]);
                $this->publishBackupIfCli(
                    (int) $job['id'],
                    (string) $job['public_id'],
                    'pending',
                    '',
                    $generation,
                    'cleanup',
                    true
                );
            }
            return [
                'result' => 'deferred',
                'backup_id' => (int) ($job['id'] ?? 0),
                'message' => 'La limpieza local quedó programada para reintento.',
            ];
        }
    }

    /** @return array{status:string,message:string} */
    private function deleteBeforeLifecycleMigration(PDO $pdo, int $backupId, int $userId): array
    {
        $pdo->beginTransaction();
        try {
            $archive = $this->archiveForUpdate($pdo, $backupId, true);
            if ((string) $archive['status'] === 'deleted') {
                $pdo->commit();
                return ['status' => 'deleted', 'message' => 'La copia ya estaba eliminada.'];
            }
            if (
                !in_array((string) $archive['status'], ['prepared', 'queued', 'failed'], true)
                || trim((string) ($archive['storage_name'] ?? '')) !== ''
            ) {
                throw new RuntimeException(
                    'Complete primero la migración 157 para eliminar una copia que ya tiene contenido.'
                );
            }
            $running = $pdo->prepare(
                "SELECT COUNT(*) FROM system_backup_jobs
                  WHERE backup_id=:backup_id AND status='running'
                    AND lease_expires_at>UTC_TIMESTAMP(3)"
            );
            $running->execute(['backup_id' => $backupId]);
            if ((int) $running->fetchColumn() > 0) {
                throw new RuntimeException('La copia todavía tiene un micro-lote activo.');
            }
            $pdo->prepare(
                "UPDATE system_backup_jobs
                    SET status='failed',lease_generation=lease_generation+1,lease_owner=NULL,
                        lease_expires_at=NULL,completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3)),
                        safe_error_code='cancelled_before_lifecycle',
                        safe_error_message='Cancelada antes de instalar el ciclo de vida de copias.'
                  WHERE backup_id=:backup_id AND status IN ('pending','running')"
            )->execute(['backup_id' => $backupId]);
            $pdo->prepare(
                "UPDATE system_backup_archives
                    SET status='deleted',deleted_at=UTC_TIMESTAMP(3),storage_name=NULL
                  WHERE id=:id"
            )->execute(['id' => $backupId]);
            $this->audit($pdo, $backupId, $userId, 'backup_deleted_before_lifecycle', 'success', [
                'previous_status' => (string) $archive['status'],
            ]);
            $pdo->commit();
            $this->releaseSnapshotFreeze((string) $archive['public_id']);
            (new BackupMaintenanceRequestService())->clearIfMatches(
                $backupId,
                (string) $archive['public_id']
            );
            return [
                'status' => 'deleted',
                'message' => 'La solicitud vacía fue cancelada. Ya puede completar la actualización.',
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function archiveForUpdate(
        PDO $pdo,
        int $backupId,
        bool $includeDeleted,
        bool $lock = true
    ): array {
        $sql = 'SELECT * FROM system_backup_archives WHERE id=:id'
            . ($includeDeleted ? '' : ' AND deleted_at IS NULL')
            . ' LIMIT 1'
            . ($lock && $pdo->inTransaction() ? ' FOR UPDATE' : '');
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $backupId]);
        $archive = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new \App\Core\HttpException(404, 'La copia solicitada no existe.');
        }
        return $archive;
    }

    /** @param array<string,mixed> $archive */
    private function removeArchiveArtifacts(array $archive): void
    {
        foreach ($this->archiveArtifactPaths($archive) as $candidate) {
            if (is_file($candidate) && !@unlink($candidate)) {
                throw new RuntimeException('No fue posible eliminar un fragmento cifrado de la copia.');
            }
        }
    }

    /** @param array<string,mixed> $archive @return list<string> */
    private function archiveArtifactPaths(array $archive): array
    {
        $directory = AppPaths::backups();
        $storageName = basename((string) ($archive['storage_name'] ?? ''));
        $candidates = [];
        if ($storageName !== '') {
            $candidates[] = $directory . '/' . $storageName;
            $candidates[] = $directory . '/' . $storageName . '.part';
        }
        $safeId = $this->snapshotId((string) $archive['public_id']);
        foreach (glob($directory . '/erp-v3-' . $safeId . '-*') ?: [] as $candidate) {
            if (is_file($candidate)) {
                $candidates[] = $candidate;
            }
        }
        return array_values(array_unique($candidates));
    }

    /** @param array<string,mixed> $archive */
    private function hasArchiveArtifacts(array $archive): bool
    {
        foreach ($this->archiveArtifactPaths($archive) as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $checkpoint */
    private function checkpointArtifactsAvailable(array $checkpoint): bool
    {
        $chunks = is_array($checkpoint['chunks'] ?? null)
            ? $checkpoint['chunks']
            : [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                return false;
            }
            $name = basename((string) ($chunk['name'] ?? ''));
            $hash = strtolower((string) ($chunk['sha256'] ?? ''));
            $path = AppPaths::backups() . '/' . $name;
            if (
                $name === ''
                || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                || !is_file($path)
                || !hash_equals($hash, strtolower((string) hash_file('sha256', $path)))
            ) {
                return false;
            }
        }
        return true;
    }

    private function publishBackupCoordination(int $backupId, string $publicId): void
    {
        if ($this->isBrowserBackup($backupId)) {
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            return;
        }
        $statement = Database::connection()->prepare(
            "SELECT job_type,status,lease_owner,lease_generation
               FROM system_backup_jobs
              WHERE backup_id=:backup_id
                AND status IN ('pending','running','cancel_requested')
              ORDER BY
                    CASE job_type WHEN 'cleanup' THEN 0 WHEN 'create' THEN 1 ELSE 2 END,
                    id DESC
              LIMIT 1"
        );
        $statement->execute(['backup_id' => $backupId]);
        $job = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            return;
        }
        $this->publishBackupIfCli(
            $backupId,
            $publicId,
            (string) $job['status'],
            (string) ($job['lease_owner'] ?? ''),
            max(0, (int) ($job['lease_generation'] ?? 0)),
            (string) $job['job_type'],
            true
        );
    }

    private function publishBackupIfCli(
        int $backupId,
        string $publicId,
        string $phase,
        string $owner,
        int $generation,
        string $jobType,
        bool $freeze
    ): void {
        if ($this->isBrowserBackup($backupId)) {
            (new LocalMaintenanceCoordinator())->clearCoordinatorIfMatches($backupId, $publicId);
            return;
        }
        (new LocalMaintenanceCoordinator())->publishBackup(
            $backupId,
            $publicId,
            $phase,
            $owner,
            $generation,
            $jobType,
            $freeze
        );
    }

    private function isBrowserBackup(int $backupId): bool
    {
        if ($backupId < 1) {
            return true;
        }
        if (!(new SchemaInspectorService())->hasColumn('system_backup_archives', 'execution_mode')) {
            return true;
        }
        $statement = Database::connection()->prepare(
            "SELECT COALESCE(execution_mode, 'browser') FROM system_backup_archives WHERE id=:id LIMIT 1"
        );
        $statement->execute(['id' => $backupId]);
        return $this->normalizeExecutionMode((string) ($statement->fetchColumn() ?: 'browser')) !== 'cli';
    }

    private function normalizeExecutionMode(string $executionMode): string
    {
        $executionMode = strtolower(trim($executionMode));
        if ($executionMode === 'cli') {
            return 'cli';
        }
        if ($executionMode === '' || $executionMode === 'browser') {
            return 'browser';
        }
        throw new RuntimeException('El modo de ejecución del respaldo no es válido.');
    }

    private function releaseSnapshotFreeze(string $publicId): void
    {
        $path = AppPaths::storage('cache/database-snapshot-active.json');
        $marker = json_decode((string) @file_get_contents($path), true);
        if (
            is_array($marker)
            && hash_equals($this->snapshotId($publicId), (string) ($marker['snapshot_id'] ?? ''))
        ) {
            @unlink($path);
        }
    }

    private function snapshotId(string $publicId): string
    {
        return substr(preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup', 0, 24);
    }

    private function snapshotConflict(string $publicId): ?int
    {
        $path = AppPaths::storage('cache/database-snapshot-active.json');
        $marker = json_decode((string) @file_get_contents($path), true);
        if (!is_array($marker)) {
            return null;
        }
        $snapshotReference = strtolower((string) ($marker['snapshot_id'] ?? ''));
        if ($snapshotReference === '' || hash_equals($this->snapshotId($publicId), $snapshotReference)) {
            return null;
        }
        $backupId = max(0, (int) ($marker['backup_id'] ?? $marker['archive_id'] ?? 0));
        return $backupId > 0 ? $backupId : 0;
    }

    private function supportsDeletionLifecycle(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasColumn('system_backup_archives', 'delete_requested_at')
            && $schema->hasColumn('system_backup_jobs', 'available_at');
    }

    private function supportsSafeCancellationLifecycle(): bool
    {
        return (new SchemaInspectorService())->hasColumn(
            'system_backup_archives',
            'cancel_requested_at'
        );
    }

    private function archiveContextSupports(PDO $pdo, string $value): bool
    {
        try {
            $statement = $pdo->prepare(
                "SELECT COLUMN_TYPE
                   FROM information_schema.COLUMNS
                  WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
                    AND BINARY TABLE_NAME=BINARY 'system_backup_archives'
                    AND BINARY COLUMN_NAME=BINARY 'context_type'
                  LIMIT 1"
            );
            $statement->execute();
            $type = (string) ($statement->fetchColumn() ?: '');
            return str_contains($type, "'" . $value . "'");
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function archive(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM system_backup_archives WHERE id=:id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \App\Core\HttpException(404, 'La copia solicitada no existe.');
        }
        return $row;
    }

    public function recordRecoveryKeyExport(int $userId): void
    {
        $this->audit(
            Database::connection(),
            null,
            $userId,
            'recovery_key_exported',
            'success',
            ['format' => 1]
        );
    }

    public function recordRecoveryKeyImport(int $userId, int $count): void
    {
        $this->audit(
            Database::connection(),
            null,
            $userId,
            'recovery_key_imported',
            'success',
            ['keys_imported' => max(0, $count)]
        );
    }

    /** @param array<string,mixed> $archive */
    public function pathForArchive(array $archive): string
    {
        return $this->archivePath($archive);
    }

    /** @param array<string,mixed> $archive */
    private function archivePath(array $archive): string
    {
        $name = basename((string) ($archive['storage_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('La copia no tiene un archivo asociado.');
        }
        return AppPaths::backups() . '/' . $name;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(PDO $pdo, ?int $backupId, ?int $userId, string $event, string $outcome, array $metadata): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO system_backup_audit_events
             (backup_id,user_id,event_type,outcome,metadata_json)
             VALUES (:backup_id,:user_id,:event,:outcome,:metadata)"
        );
        $stmt->execute([
            'backup_id' => $backupId,
            'user_id' => $userId,
            'event' => $event,
            'outcome' => $outcome,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * @param list<array<string,mixed>> $archives
     * @return list<array<string,mixed>>
     */
    private function attachJobProgress(PDO $pdo, array $archives): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $archive): int => max(0, (int) ($archive['id'] ?? 0)),
            $archives
        )));
        if ($ids === []) {
            return $archives;
        }
        $availableColumn = $this->supportsDeletionLifecycle()
            ? ',available_at'
            : ',NULL AS available_at';
        $jobs = $pdo->query(
            'SELECT id,backup_id,job_type,status,checkpoint_json,heartbeat_at,started_at,
                    lease_expires_at' . $availableColumn . ',safe_error_code,safe_error_message
               FROM system_backup_jobs
              WHERE backup_id IN (' . implode(',', $ids) . ')
              ORDER BY backup_id ASC,
                    CASE status WHEN "running" THEN 0 WHEN "pending" THEN 1 ELSE 2 END,
                    id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byBackup = [];
        foreach ($jobs as $job) {
            $backupId = (int) ($job['backup_id'] ?? 0);
            if ($backupId < 1 || isset($byBackup[$backupId])) {
                continue;
            }
            $checkpoint = json_decode((string) ($job['checkpoint_json'] ?? ''), true);
            $checkpoint = is_array($checkpoint) ? $checkpoint : [];
            $tables = is_array($checkpoint['tables'] ?? null)
                ? array_values($checkpoint['tables'])
                : [];
            $tableIndex = max(0, (int) ($checkpoint['table_index'] ?? 0));
            $byBackup[$backupId] = [
                'id' => (int) $job['id'],
                'type' => (string) $job['job_type'],
                'status' => (string) $job['status'],
                'started_at' => $job['started_at'],
                'heartbeat_at' => $job['heartbeat_at'],
                'lease_expires_at' => $job['lease_expires_at'],
                'available_at' => $job['available_at'],
                'safe_error_code' => $job['safe_error_code'],
                'safe_error_message' => $job['safe_error_message'],
                'stage' => (string) ($checkpoint['stage'] ?? ''),
                'current_table' => isset($tables[$tableIndex])
                    ? (string) $tables[$tableIndex]
                    : null,
                'tables_completed' => min($tableIndex, count($tables)),
                'tables_total' => count($tables),
                'rows_processed' => max(0, (int) ($checkpoint['rows'] ?? 0)),
                'chunks_completed' => is_array($checkpoint['chunks'] ?? null)
                    ? count($checkpoint['chunks'])
                    : 0,
            ];
        }
        foreach ($archives as &$archive) {
            $archive['job'] = $byBackup[(int) $archive['id']] ?? null;
        }
        unset($archive);
        return $archives;
    }

    /**
     * @param array<string,mixed>|null $active
     * @return array<string,mixed>
     */
    private function progressPresenter(?array $active): array
    {
        $status = (string) ($active['status'] ?? '');
        $job = is_array($active['job'] ?? null) ? $active['job'] : [];
        $tablesTotal = max(0, (int) ($job['tables_total'] ?? 0));
        $tablesCompleted = min(max(0, (int) ($job['tables_completed'] ?? 0)), max(0, $tablesTotal));
        $rowsProcessed = max(0, (int) ($job['rows_processed'] ?? 0));
        $chunksCompleted = max(0, (int) ($job['chunks_completed'] ?? 0));
        $currentTable = trim((string) ($job['current_table'] ?? ''));
        $startedAt = $this->firstNonEmptyString(
            $job['started_at'] ?? null,
            $active['started_at'] ?? null,
            $active['requested_at'] ?? null
        );
        $lastAdvanceAt = $this->firstNonEmptyString(
            $job['heartbeat_at'] ?? null,
            $active['heartbeat_at'] ?? null,
            $active['started_at'] ?? null,
            $active['requested_at'] ?? null
        );
        $now = time();
        $elapsed = $this->secondsSince($startedAt, $now);
        $lastAdvanceSeconds = $this->secondsSince($lastAdvanceAt, $now);
        $isActive = in_array($status, [
            'prepared',
            'queued',
            'creating',
            'verifying',
            'ready_pending_release',
            'cancel_requested',
            'deleting',
        ], true);
        $isStale = $isActive && $lastAdvanceSeconds !== null && $lastAdvanceSeconds > 90;

        $percent = 0.0;
        if ($status === 'ready') {
            $percent = 100.0;
        } elseif ($status === 'ready_pending_release') {
            $percent = 99.0;
        } elseif ($status === 'verifying') {
            $percent = max(98.0, $tablesTotal > 0 ? ($tablesCompleted / max(1, $tablesTotal)) * 100 : 0.0);
        } elseif ($tablesTotal > 0) {
            $percent = ($tablesCompleted / max(1, $tablesTotal)) * 100;
            if ($status !== 'ready') {
                $percent = min(97.9, $percent);
            }
        }
        $percent = round(max(0.0, min(100.0, $percent)), 1);

        $etaMin = null;
        $etaMax = null;
        if ($percent >= 1.0 && $percent < 100.0 && $elapsed !== null && $elapsed >= 10 && $chunksCompleted >= 2) {
            $remaining = max(0, (int) round($elapsed * ((100.0 - $percent) / max(1.0, $percent))));
            $etaMin = max(5, (int) floor($remaining * 0.75));
            $etaMax = max($etaMin + 5, (int) ceil($remaining * 1.35 + 10));
        }

        $labels = $this->backupStatusLabels();
        $message = $this->progressMessage($status, $tablesTotal, $isStale, (string) ($job['safe_error_message'] ?? ''));
        $nowText = $currentTable !== ''
            ? 'Respaldando ' . $currentTable
            : ($tablesTotal > 0 ? 'Verificando el avance guardado' : 'Preparando primera tabla');
        $nextText = $this->progressNextAction($status, $tablesTotal);
        $mode = $this->normalizeExecutionMode((string) ($active['execution_mode'] ?? 'browser'));

        return [
            'percent' => $percent,
            'status_label' => $labels[$status] ?? ($status !== '' ? $status : 'Sin copia activa'),
            'phase_label' => $this->progressPhaseLabel($status, $tablesTotal, $currentTable),
            'mode' => $mode,
            'tables_completed' => $tablesCompleted,
            'tables_total' => $tablesTotal,
            'rows_processed' => $rowsProcessed,
            'chunks_completed' => $chunksCompleted,
            'current_table' => $currentTable !== '' ? $currentTable : null,
            'elapsed_seconds' => $elapsed,
            'eta_seconds_min' => $etaMin,
            'eta_seconds_max' => $etaMax,
            'last_advance_at' => $lastAdvanceAt,
            'is_stale' => $isStale,
            'blocking_reason' => $isStale ? 'stale_browser_progress' : null,
            'owner_tab' => $mode === 'browser' ? 'browser' : 'cli',
            'can_step' => $mode === 'browser' && in_array($status, [
                'prepared', 'queued', 'creating', 'verifying',
                'ready_pending_release', 'cancel_requested', 'deleting',
            ], true),
            'can_cancel' => in_array($status, [
                'prepared', 'queued', 'creating', 'verifying',
                'ready_pending_release', 'cancel_requested',
            ], true),
            'can_cleanup' => in_array($status, ['failed', 'cancel_requested', 'deleting'], true),
            'return_to' => $this->progressReturnTo($active),
            'next_button_label' => $this->progressButtonLabel($status),
            'message' => $message,
            'now' => $nowText,
            'next' => $nextText,
            'activity' => $this->progressActivity($status, $lastAdvanceAt, $currentTable, $chunksCompleted, $rowsProcessed),
        ];
    }

    /** @return array<string,string> */
    private function backupStatusLabels(): array
    {
        return [
            'prepared' => 'Preparada para continuar',
            'queued' => 'Lista para avanzar en esta pestaña',
            'creating' => 'Creando copia',
            'verifying' => 'Verificando copia',
            'ready_pending_release' => 'Liberando protección',
            'ready' => 'Lista y verificada',
            'failed' => 'Necesita revisión',
            'cancel_requested' => 'Cancelación solicitada',
            'deleting' => 'Limpiando restos',
        ];
    }

    private function progressMessage(string $status, int $tablesTotal, bool $isStale, string $safeError): string
    {
        if ($safeError !== '') {
            return $safeError;
        }
        if ($status === 'ready') {
            return 'Lista y verificada. La copia cifrada quedó disponible para descarga o saneamiento.';
        }
        if ($status === 'failed') {
            return 'El último lote no pudo terminar. Revise el detalle y reintente el lote cuando esté listo.';
        }
        if ($isStale) {
            return 'No se ha confirmado un avance reciente. Puede recargar la página o reintentar el lote.';
        }
        if ($tablesTotal < 1) {
            return 'Preparando primera tabla. Esta pestaña está creando la copia por lotes locales.';
        }
        if ($status === 'verifying') {
            return 'La copia terminó de recorrer tablas y ahora valida manifiesto, checksum y fragmentos.';
        }
        if ($status === 'ready_pending_release') {
            return 'La copia fue verificada y está liberando la protección de datos.';
        }
        return 'Esta pestaña está creando la copia por lotes locales. Puede dejarla abierta. Si la cierra, podrá continuar después. No se consultará Mercado Libre.';
    }

    private function progressNextAction(string $status, int $tablesTotal): string
    {
        return match ($status) {
            'ready' => 'Usar esta copia para continuar o descargarla.',
            'failed' => 'Reintentar el lote después de revisar el mensaje.',
            'verifying' => 'Confirmar manifiesto y checksum.',
            'ready_pending_release' => 'Liberar protección local y cerrar la copia.',
            'cancel_requested', 'deleting' => 'Finalizar limpieza cercada de esta copia.',
            default => $tablesTotal > 0
                ? 'Continuar con el siguiente micro-lote local.'
                : 'Abrir el primer checkpoint aprobado.',
        };
    }

    private function progressPhaseLabel(string $status, int $tablesTotal, string $currentTable): string
    {
        return match ($status) {
            'ready' => 'Lista y verificada',
            'failed' => 'Necesita revisión',
            'verifying' => 'Verificando manifiesto',
            'ready_pending_release' => 'Cerrando manifiesto',
            'cancel_requested', 'deleting' => 'Limpiando restos',
            default => $currentTable !== ''
                ? 'Exportando tabla ' . $currentTable
                : ($tablesTotal > 0 ? 'Reanudando checkpoint' : 'Preparando estructura'),
        };
    }

    /** @param array<string,mixed>|null $active */
    private function progressReturnTo(?array $active): ?string
    {
        $contextType = (string) ($active['context_type'] ?? '');
        $contextId = max(0, (int) ($active['context_id'] ?? 0));
        if ($contextType === 'database_sanitation' && $contextId > 0) {
            return '/settings/database-maintenance?id=' . $contextId . '#resultado';
        }
        return null;
    }

    private function progressButtonLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Usar copia verificada',
            'failed' => 'Reintentar lote',
            'cancel_requested', 'deleting' => 'Continuar limpieza',
            default => 'Procesar siguiente lote local',
        };
    }

    /**
     * @return list<array<string,string>>
     */
    private function progressActivity(string $status, ?string $lastAdvanceAt, string $currentTable, int $chunksCompleted, int $rowsProcessed): array
    {
        $activity = [];
        if ($lastAdvanceAt !== null && $lastAdvanceAt !== '') {
            $activity[] = [
                'time' => $lastAdvanceAt,
                'label' => $chunksCompleted > 0 ? 'Fragmento aprobado' : 'Avance registrado',
                'detail' => $chunksCompleted > 0
                    ? number_format($chunksCompleted, 0, ',', '.') . ' fragmentos cifrados · '
                        . number_format($rowsProcessed, 0, ',', '.') . ' filas'
                    : 'La copia registró un checkpoint local.',
            ];
        }
        if ($currentTable !== '') {
            $activity[] = [
                'time' => '',
                'label' => 'Conjunto actual',
                'detail' => $currentTable,
            ];
        }
        $labels = $this->backupStatusLabels();
        $activity[] = [
            'time' => '',
            'label' => 'Estado',
            'detail' => $labels[$status] ?? ($status !== '' ? $status : 'Preparando'),
        ];
        return array_slice($activity, 0, 5);
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    private function secondsSince(?string $datetime, int $now): ?int
    {
        if ($datetime === null || trim($datetime) === '') {
            return null;
        }
        $timestamp = strtotime($datetime . (str_contains($datetime, '+') || str_ends_with($datetime, 'Z') ? '' : ' UTC'));
        if ($timestamp === false) {
            return null;
        }
        return max(0, $now - $timestamp);
    }

    /**
     * @param array<string,mixed>|null $active
     * @return array<string,mixed>
     */
    private function runtimeSummary(?array $active): array
    {
        $entryPath = AppPaths::storage('cache/cron-entry-state.json');
        $entry = $this->readSmallJson($entryPath);
        $inspection = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
            AppPaths::sharedRoot(),
            AppPaths::installationRoot()
        );
        $activeId = max(0, (int) ($active['id'] ?? 0));
        $signalExact = $activeId > 0
            && $inspection['valid']
            && (int) $inspection['backup_id'] === $activeId;
        $activeStatus = (string) ($active['status'] ?? '');
        $browserStates = [
            'prepared',
            'queued',
            'creating',
            'verifying',
            'ready_pending_release',
            'cancel_requested',
            'deleting',
        ];
        $reason = $activeId < 1
            ? 'No hay una copia local activa.'
            : ($signalExact
                ? 'La solicitud local también está disponible para mantenimiento local avanzado.'
                : (in_array($activeStatus, $browserStates, true)
                    ? 'Esta pestaña continuará la copia por micro-lotes locales. No se consultará Mercado Libre.'
                    : 'La copia perdió su señal local. Puede recuperarla sin repetir fragmentos.'));
        return [
            'signal_ready' => $signalExact,
            'browser_mode' => $activeId > 0 && in_array($activeStatus, $browserStates, true),
            'reason' => $reason,
            'launcher_stage' => preg_replace(
                '/[^a-z0-9_.-]/i',
                '',
                (string) ($entry['stage'] ?? '')
            ),
            'launcher_observed_at' => (string) ($entry['observed_at'] ?? ''),
            'remote' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function readSmallJson(string $path): array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || (int) @filesize($path) > 32768) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function maintenanceSessionBelongsTo(int $sessionId, int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*)
               FROM database_maintenance_sessions
              WHERE id=:id AND requested_by=:user_id AND status="analyzed"'
        );
        $statement->execute(['id' => $sessionId, 'user_id' => $userId]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function bindMaintenanceContext(int $backupId): void
    {
        try {
            $archive = $this->archive($backupId);
            if (
                (string) ($archive['context_type'] ?? '') !== 'database_sanitation'
                || (int) ($archive['context_id'] ?? 0) < 1
            ) {
                return;
            }
            (new DatabaseMaintenanceService())->bindVerifiedBackup(
                (int) $archive['context_id'],
                $backupId,
                (int) $archive['requested_by']
            );
            $this->audit(
                Database::connection(),
                $backupId,
                (int) $archive['requested_by'],
                'backup_bound_to_sanitation',
                'success',
                ['session_id' => (int) $archive['context_id']]
            );
        } catch (Throwable) {
            // La copia verificada continúa siendo válida. El administrador
            // podrá repetir el análisis si la sesión cambió durante el dump.
        }
    }
}
