<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class SecureUpdateEngineService
{
    private const TERMINAL = ['completed', 'failed', 'rolled_back', 'manual_intervention'];

    /** @return array<string,mixed> */
    public function dashboard(bool $refreshRemote = false): array
    {
        $ready = $this->engineReady();
        $localSources = $this->discoverLocalSources();
        $remote = [];
        $remoteError = null;
        if ($ready) {
            try {
                $remote = (new UpdateRemoteService())->releases($refreshRemote);
            } catch (Throwable $e) {
                $remoteError = $e->getMessage();
            }
        }
        return [
            'engine_ready' => $ready,
            'capabilities' => (new UpdateCapabilityService())->detect(),
            'local_sources' => $localSources,
            'remote_releases' => $remote,
            'remote_error' => $remoteError,
            'runs' => $ready ? $this->runs(20) : [],
            'active_run' => $ready ? $this->activeRun() : null,
            'pointer' => (new UpdateReleaseService())->pointer(),
            'backups' => $ready ? $this->backups(10) : [],
            'trusted_keys' => $ready ? $this->trustedKeys() : [],
            'schema' => $ready ? (new UpdateSchemaService())->inspect() : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function discoverLocalSources(): array
    {
        $filesystem = new UpdateFilesystemService();
        $filesystem->ensureDirectories();
        $sources = [];
        foreach (glob(AppPaths::updateInbox() . '/*') ?: [] as $path) {
            if (is_file($path) && str_ends_with(strtolower($path), '.erpupd')) {
                $sources[] = [
                    'key' => hash('sha256', realpath($path) ?: $path),
                    'type' => 'package',
                    'name' => basename($path),
                    'path' => $path,
                    'size' => (int) filesize($path),
                    'version' => null,
                ];
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            try {
                $manifest = (new UpdateManifestService())->fromDirectory($path);
                $sources[] = [
                    'key' => hash('sha256', realpath($path) ?: $path),
                    'type' => 'folder',
                    'name' => basename($path),
                    'path' => $path,
                    'size' => $filesystem->directorySize($path),
                    'version' => $manifest['version'] ?? null,
                    'release_id' => $manifest['release_id'] ?? null,
                ];
            } catch (Throwable $e) {
                $sources[] = [
                    'key' => hash('sha256', realpath($path) ?: $path),
                    'type' => 'invalid_folder',
                    'name' => basename($path),
                    'path' => $path,
                    'size' => 0,
                    'version' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $sources;
    }

    public function upload(array $file): string
    {
        return (new UpdatePackageService())->acceptUpload($file);
    }

    /**
     * @param array<string,mixed>|null $remote
     * @return array<string,mixed>
     */
    public function createRun(
        string $sourceKey,
        string $mode,
        bool $backupRequested,
        ?string $waiver,
        ?int $userId,
        ?array $remote = null
    ): array {
        if (!$this->engineReady()) {
            throw new RuntimeException('Primero debe ejecutar la migración 059 del motor de actualizaciones.');
        }
        if ($this->activeRun() !== null) {
            throw new RuntimeException('Ya existe una actualización activa. Continúela o cancélela antes de iniciar otra.');
        }
        if ($mode !== 'atomic') {
            throw new RuntimeException(
                'La sobrescritura clásica fue retirada. Use una actualización atómica con manifiesto.'
            );
        }
        if (!$backupRequested && trim((string) $waiver) !== 'ACTUALIZAR SIN RESPALDO') {
            throw new RuntimeException('Debe escribir ACTUALIZAR SIN RESPALDO para omitir el backup.');
        }

        $uuid = $this->uuid();
        $sourceType = 'folder';
        $sourcePath = '';
        $manifest = [];

        $remotePathSteps = [];
        if ($remote !== null) {
            if ((bool) ($remote['withdrawn'] ?? false) || (bool) ($remote['paused'] ?? false)) {
                throw new RuntimeException('La release remota fue pausada o retirada y ya no admite instalaciones nuevas.');
            }
            $sourceType = 'remote';
            $packageUrl = (string) ($remote['package_url'] ?? '');
            $packageSha = strtolower((string) ($remote['package_sha256'] ?? ''));
            $sourcePath = (new UpdateRemoteService())->download($packageUrl, $packageSha);
            $sourceKey = hash('sha256', realpath($sourcePath) ?: $sourcePath);
            $remotePathSteps = (new UpdateRemoteService())->upgradePath(
                (new AppVersionService())->installedVersion(),
                (string) ($remote['version'] ?? '')
            );
        } else {
            $source = null;
            foreach ($this->discoverLocalSources() as $candidate) {
                if (hash_equals((string) $candidate['key'], $sourceKey)) {
                    $source = $candidate;
                    break;
                }
            }
            if ($source === null || ($source['type'] ?? '') === 'invalid_folder') {
                throw new RuntimeException('La fuente de actualización no está disponible o no es válida.');
            }
            $sourcePath = (string) $source['path'];
            $sourceType = (string) $source['type'];
        }

        if (in_array($sourceType, ['package', 'remote'], true)) {
            $extracted = (new UpdatePackageService())->extract($sourcePath, $uuid);
            $sourceDirectory = $extracted['directory'];
            $manifest = $extracted['manifest'];
        } else {
            $sourceDirectory = (new UpdateFilesystemService())->assertInside($sourcePath, AppPaths::updateInbox());
            $manifest = (new UpdateManifestService())->fromDirectory($sourceDirectory);
            $manifest['signature_status'] = (new UpdateManifestService())->verifySignature($manifest);
            (new UpdateManifestService())->verifyFiles($manifest, $sourceDirectory);
        }

        if ($remotePathSteps !== []) {
            $resolvedBridges = [];
            $index = 0;
            foreach ($remotePathSteps as $bridge) {
                if (($bridge['type'] ?? '') !== 'bridge') {
                    continue;
                }
                $bridgePackage = (new UpdateRemoteService())->download(
                    (string) ($bridge['package_url'] ?? ''),
                    (string) ($bridge['package_sha256'] ?? '')
                );
                $bridgeExtracted = (new UpdatePackageService())->extract($bridgePackage, $uuid . '-bridge-' . $index++);
                $resolvedBridges[] = [
                    'release_id' => (string) ($bridgeExtracted['manifest']['release_id'] ?? ''),
                    'version' => (string) ($bridgeExtracted['manifest']['version'] ?? ''),
                    'directory' => (string) $bridgeExtracted['directory'],
                ];
            }
            $manifest['_resolved_bridges'] = $resolvedBridges;
        }

        $sourceBytes = (new UpdateFilesystemService())->directorySize($sourceDirectory);
        $freeBytes = (int) (@disk_free_space(AppPaths::sharedRoot()) ?: 0);
        $minimumFree = ($sourceBytes * 2) + (64 * 1024 * 1024);
        if ($freeBytes > 0 && $freeBytes < $minimumFree) {
            throw new RuntimeException(
                'No hay espacio suficiente para staging, activación y recuperación. Libere al menos '
                . number_format(($minimumFree - $freeBytes) / 1048576, 0, ',', '.')
                . ' MB adicionales.'
            );
        }

        $this->assertUpgradePath($manifest);
        $releaseDatabaseId = $this->registerRelease($manifest, $sourceType, $sourcePath);
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            "INSERT INTO system_update_runs
             (run_uuid,release_id,version_from,version_to,mode,state,backup_requested,backup_waived,
              backup_waiver_text,current_step,next_action,source_path,staging_path,started_by,started_at)
             VALUES (:uuid,:release_id,:from_version,:to_version,:mode,'discovered',:backup,:waived,:waiver,
                     'Verificar paquete','Continuar verificación',:source,:staging,:user,UTC_TIMESTAMP())"
        );
        $stmt->execute([
            'uuid' => $uuid,
            'release_id' => $releaseDatabaseId,
            'from_version' => (new AppVersionService())->installedVersion(),
            'to_version' => (string) $manifest['version'],
            'mode' => $mode,
            'backup' => $backupRequested ? 1 : 0,
            'waived' => $backupRequested ? 0 : 1,
            'waiver' => $backupRequested ? null : 'ACTUALIZAR SIN RESPALDO',
            'source' => $sourcePath,
            'staging' => $sourceDirectory,
            'user' => $userId,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $this->seedSteps($runId);
        $this->event($runId, 'info', 'run_created', 'Actualización creada y lista para verificación.', [
            'mode' => $mode,
            'source_type' => $sourceType,
            'version_to' => $manifest['version'],
            'backup_requested' => $backupRequested,
        ]);
        return $this->run($runId) ?? throw new RuntimeException('No fue posible volver a leer la actualización.');
    }

    /** @return array<string,mixed> */
    public function process(int $runId): array
    {
        $run = $this->run($runId);
        if ($run === null) {
            throw new RuntimeException('La ejecución de actualización no existe.');
        }
        if (in_array((string) $run['state'], self::TERMINAL, true)) {
            return $run;
        }
        $owner = $this->acquireLock($runId);
        try {
            $this->heartbeat($runId, $owner);
            $state = (string) $run['state'];
            match ($state) {
                'discovered' => $this->stepVerify($run),
                'verified' => $this->stepDiagnose($run),
                'diagnosing' => $this->stepResolvePath($run),
                'path_resolved' => $this->stepBackupChoice($run),
                'awaiting_backup_choice', 'backing_up' => $this->stepBackup($run),
                'backup_skipped' => $this->transition($runId, 'staging', 45, 'Preparar release', 'Continuar staging'),
                'staging' => $this->stepStage($run),
                'bridging' => $this->stepBridge($run),
                'migrating' => $this->stepMigrate($run),
                'activating' => $this->stepActivate($run),
                'health_check' => $this->stepHealth($run),
                'paused' => throw new RuntimeException('La actualización está pausada. Use Reanudar antes de continuar.'),
                default => throw new RuntimeException('El estado de actualización no es procesable: ' . $state),
            };
            return $this->run($runId) ?? $run;
        } catch (Throwable $e) {
            $this->failRun($runId, $e, (string) $run['state']);
            throw $e;
        } finally {
            $this->releaseLock($runId, $owner);
        }
    }

    /** @return array<string,mixed>|null */
    public function processDue(): ?array
    {
        if (!$this->engineReady()) {
            return null;
        }
        $stmt = Database::connectionFresh()->query(
            "SELECT id FROM system_update_runs
             WHERE state NOT IN ('completed','failed','rolled_back','manual_intervention','paused')
             ORDER BY created_at ASC LIMIT 1"
        );
        $id = $stmt->fetchColumn();
        return $id ? $this->process((int) $id) : null;
    }

    public function pause(int $runId): void
    {
        $run = $this->run($runId);
        if ($run === null || in_array((string) $run['state'], self::TERMINAL, true)) {
            throw new RuntimeException('La actualización no se puede pausar en su estado actual.');
        }
        $this->transition($runId, 'paused', null, 'Actualización pausada', 'Reanudar');
        $stmt = Database::connectionFresh()->prepare("UPDATE system_update_runs SET safe_error_code=:code WHERE id=:id");
        $stmt->execute(['code' => 'paused_from:' . (string) $run['state'], 'id' => $runId]);
        $this->event($runId, 'warning', 'run_paused', 'La actualización fue pausada por un administrador.');
    }

    public function resume(int $runId): void
    {
        $run = $this->run($runId);
        if ($run === null || !in_array((string) $run['state'], ['paused', 'failed'], true)) {
            throw new RuntimeException('La actualización no está pausada ni fallida.');
        }
        $storedCode = (string) ($run['safe_error_code'] ?? '');
        $state = str_contains($storedCode, ':') ? substr($storedCode, strpos($storedCode, ':') + 1) : '';
        $allowed = ['verified', 'diagnosing', 'path_resolved', 'awaiting_backup_choice', 'backing_up', 'backup_skipped', 'staging', 'bridging', 'migrating', 'activating', 'health_check'];
        if (!in_array($state, $allowed, true)) {
            $step = $this->currentPendingStep($runId);
            $pending = (string) ($step['step_key'] ?? 'verified');
            $state = $pending === 'diagnosing' ? 'verified' : ($pending === 'path_resolved' ? 'diagnosing' : $pending);
        }
        $this->transition($runId, $state, null, 'Actualización reanudada', 'Continuar');
        $stmt = Database::connectionFresh()->prepare('UPDATE system_update_runs SET safe_error_code=NULL,safe_error_message=NULL WHERE id=:id');
        $stmt->execute(['id' => $runId]);
        $this->event($runId, 'info', 'run_resumed', 'La actualización fue reanudada.');
    }

    /** @return array<string,mixed> */
    public function rollback(int $runId): array
    {
        $run = $this->run($runId);
        if ($run === null) {
            throw new RuntimeException('La ejecución no existe.');
        }
        $manifest = $this->manifestForRun($run);
        if (($manifest['rollback']['code_compatible'] ?? true) !== true) {
            throw new RuntimeException('El manifiesto bloquea el rollback de código sin restaurar la base de datos.');
        }
        $owner = $this->acquireLock($runId);
        try {
        $releaseService = new UpdateReleaseService();
        $releaseService->startMaintenance((string) $run['version_from']);
        $this->transition($runId, 'rollback_pending', 92, 'Revertir código y metadata', 'Completar rollback');
        try {
            $currentPointer = $releaseService->pointer();
            if (($currentPointer['version'] ?? '') === (string) $run['version_to']
                && ($currentPointer['release_id'] ?? '') === (string) $run['release_code']) {
                if (empty($run['previous_release_id'])
                    || ($currentPointer['previous_release_id'] ?? '') !== (string) $run['previous_release_id']) {
                    throw new RuntimeException('update_rollback_pointer_version_mismatch');
                }
                $pointer = $releaseService->rollback();
            } elseif (($currentPointer['version'] ?? '') === (string) $run['version_from']
                && !empty($run['previous_release_id'])
                && ($currentPointer['release_id'] ?? '') === (string) $run['previous_release_id']) {
                // A previous rollback attempt switched code but could not finish metadata.
                $pointer = $currentPointer;
            } else {
                throw new RuntimeException('update_rollback_pointer_version_mismatch');
            }
            $pdo = Database::connectionFresh();
            $currentVersion = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1")->fetchColumn();
            if ($currentVersion !== (string) $pointer['version']) {
                (new DirectUpdateMetadataPromotionService())->restoreForRollback(
                    $pdo,
                    (string) $run['version_to'],
                    (string) $pointer['version'],
                    $this->lastAppliedMigration($pdo),
                    'Rollback seguro de código; esquema conservado.'
                );
            }
            $path = AppPaths::installationRoot() . '/' . $pointer['path'];
            $marker = (new InstalledVersionMarkerService())->read();
            if (!$releaseService->healthCheck($path)['ok']
                || !(new ReleaseIntegrityService())->inspectDirectory($path, true, false)['ok']
                || !$marker['valid']
                || $marker['version'] !== (string) $pointer['version']
                || $marker['last_migration'] !== $this->lastAppliedMigration($pdo)) {
                throw new RuntimeException('update_rollback_integrity_failed');
            }
        } catch (Throwable $failure) {
            $this->event($runId, 'error', 'rollback_incomplete', 'Rollback sin cierre: se conserva mantenimiento.');
            throw $failure;
        }
        $this->transition($runId, 'rolled_back', 100, 'Rollback completado', null);
        $this->event($runId, 'warning', 'rollback_completed', 'Se activó la release anterior.', ['pointer' => $pointer]);
        $releaseService->stopMaintenance();
        return $pointer;
        } finally {
            $this->releaseLock($runId, $owner);
        }
    }

    /** @return array<string,mixed>|null */
    public function run(int $id): ?array
    {
        if (!$this->engineReady()) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT r.*,rel.release_id AS release_code,rel.manifest_json,rel.signature_status,rel.source_type
             FROM system_update_runs r
             LEFT JOIN system_update_releases rel ON rel.id=r.release_id
             WHERE r.id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['steps'] = $this->steps($id);
        $row['events'] = $this->events($id, 30);
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function activeRun(): ?array
    {
        if (!$this->engineReady()) {
            return null;
        }
        $id = Database::connectionFresh()->query(
            "SELECT id FROM system_update_runs
             WHERE state NOT IN ('completed','failed','rolled_back','manual_intervention')
             ORDER BY created_at DESC LIMIT 1"
        )->fetchColumn();
        return $id ? $this->run((int) $id) : null;
    }

    /** @return list<array<string,mixed>> */
    public function runs(int $limit): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT r.id,r.run_uuid,r.version_from,r.version_to,r.mode,r.state,r.progress_percent,
                    r.current_step,r.next_action,r.safe_error_code,r.safe_error_message,r.created_at,r.completed_at,
                    rel.release_id AS release_code,rel.signature_status
             FROM system_update_runs r LEFT JOIN system_update_releases rel ON rel.id=r.release_id
             ORDER BY r.created_at DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function engineReady(): bool
    {
        try {
            $stmt = Database::connectionFresh()->query("SHOW TABLES LIKE 'system_update_runs'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function stepVerify(array $run): void
    {
        $manifest = $this->manifestForRun($run);
        $source = (string) $run['staging_path'];
        (new UpdateManifestService())->verifyFiles($manifest, $source);
        $health = (new UpdateReleaseService())->healthCheck($source);
        if (!$health['ok']) {
            throw new RuntimeException('La release no supera la verificación estructural.');
        }
        $this->completeStep((int) $run['id'], 'verified', 'verified', 15, 'Paquete verificado', 'Diagnosticar instalación');
    }

    private function stepDiagnose(array $run): void
    {
        $manifest = $this->manifestForRun($run);
        $expected = is_array($manifest['schema'] ?? null) ? $manifest['schema'] : null;
        $snapshot = (new UpdateSchemaService())->inspect($expected);
        (new UpdateSchemaService())->persist((int) $run['id'], $snapshot, (string) $run['version_from']);
        $stmt = Database::connectionFresh()->prepare(
            "UPDATE system_update_runs SET schema_classification=:classification,state='diagnosing',
             progress_percent=25,current_step='Resolver ruta de actualización',
             next_action='Continuar diagnóstico' WHERE id=:id"
        );
        $stmt->execute(['classification' => $snapshot['classification'], 'id' => (int) $run['id']]);
        $this->finishStep((int) $run['id'], 'diagnosing', 'Diagnóstico de esquema: ' . $snapshot['classification']);
        $this->event((int) $run['id'], 'info', 'schema_diagnosed', 'El esquema fue clasificado.', ['classification' => $snapshot['classification']]);
    }

    private function stepResolvePath(array $run): void
    {
        $classification = (string) ($run['schema_classification'] ?? '');
        if (in_array($classification, ['known_drifted', 'unknown_schema', 'future_version', 'damaged_installation'], true)) {
            $this->transition((int) $run['id'], 'manual_intervention', 25, 'Esquema requiere reparación', 'Revisar diferencias');
            $this->event((int) $run['id'], 'error', 'schema_blocked', 'La actualización fue bloqueada por diferencias de esquema.', ['classification' => $classification]);
            return;
        }
        $manifest = $this->manifestForRun($run);
        $bridges = array_values(array_filter((array) ($manifest['required_bridges'] ?? []), 'is_string'));
        $resolved = array_values(array_filter((array) ($manifest['_resolved_bridges'] ?? []), 'is_array'));
        if ($bridges !== [] && count($resolved) < count($bridges)) {
            $this->transition((int) $run['id'], 'manual_intervention', 30, 'Faltan puentes de actualización', 'Cargar puentes requeridos');
            $this->event((int) $run['id'], 'error', 'bridges_missing', 'La release requiere puentes que todavía no están cargados.', ['bridges' => $bridges]);
            return;
        }
        $this->completeStep((int) $run['id'], 'path_resolved', 'path_resolved', 35, 'Ruta compatible', 'Confirmar respaldo');
    }

    private function stepBackupChoice(array $run): void
    {
        if ((int) $run['backup_requested'] === 1) {
            $this->transition((int) $run['id'], 'backing_up', 38, 'Crear respaldo verificado', 'Continuar respaldo');
        } else {
            $this->transition((int) $run['id'], 'backup_skipped', 42, 'Respaldo omitido por administrador', 'Preparar release');
            $this->finishStep((int) $run['id'], 'awaiting_backup_choice', 'Backup omitido con aceptación reforzada.');
            $this->event((int) $run['id'], 'warning', 'backup_waived', 'El administrador decidió continuar sin respaldo.');
        }
    }

    private function stepBackup(array $run): void
    {
        if ((int) $run['backup_requested'] !== 1) {
            $this->transition((int) $run['id'], 'backup_skipped', 42, 'Respaldo omitido', 'Preparar release');
            return;
        }
        $backup = (new UpdateBackupService())->create((int) $run['id']);
        $this->completeStep((int) $run['id'], 'backing_up', 'staging', 45, 'Backup verificado', 'Preparar release');
        $this->event((int) $run['id'], 'info', 'backup_verified', 'El respaldo fue creado y verificado.', ['backup_id' => $backup['id'], 'size' => $backup['size'], 'adapter' => $backup['adapter']]);
    }

    private function stepStage(array $run): void
    {
        $manifest = $this->manifestForRun($run);
        if ((string) $run['mode'] !== 'atomic') {
            throw new RuntimeException('La ejecución heredada no puede sobrescribir el runtime activo.');
        }
        $releasePath = (new UpdateReleaseService())->stage((string) $run['staging_path'], $manifest);
        $stmt = Database::connectionFresh()->prepare("UPDATE system_update_runs SET staging_path=:path WHERE id=:id");
        $stmt->execute(['path' => $releasePath, 'id' => (int) $run['id']]);
        $manifest = $this->manifestForRun($run);
        $next = !empty($manifest['_resolved_bridges']) ? 'bridging' : 'migrating';
        $this->completeStep((int) $run['id'], 'staging', $next, 60, 'Release preparada', $next === 'bridging' ? 'Aplicar puentes' : 'Ejecutar migraciones');
    }

    private function stepBridge(array $run): void
    {
        $manifest = $this->manifestForRun($run);
        $bridges = array_values(array_filter((array) ($manifest['_resolved_bridges'] ?? []), 'is_array'));
        foreach ($bridges as $bridge) {
            $directory = (string) ($bridge['directory'] ?? '');
            if ($directory === '' || !is_dir($directory . '/database/migrations')) {
                throw new RuntimeException('Un puente resuelto no contiene migraciones válidas.');
            }
            (new Migrator(Database::connectionFresh(), $directory . '/database/migrations'))->run();
            $this->event((int) $run['id'], 'info', 'bridge_applied', 'Puente de compatibilidad aplicado.', [
                'release_id' => (string) ($bridge['release_id'] ?? ''),
                'version' => (string) ($bridge['version'] ?? ''),
            ]);
        }
        $this->completeStep((int) $run['id'], 'bridging', 'migrating', 68, 'Puentes aplicados', 'Ejecutar migraciones finales');
    }

    private function stepMigrate(array $run): void
    {
        if ((string) $run['schema_classification'] === 'known_drifted') {
            throw new RuntimeException('No se pueden ejecutar migraciones sobre un esquema con drift.');
        }
        (new UpdateReleaseService())->startMaintenance((string) $run['version_to']);
        $migrationPath = rtrim((string) $run['staging_path'], '/\\') . '/database/migrations';
        $migrator = new Migrator(Database::connectionFresh(), $migrationPath);
        $results = $migrator->run(1);
        $applied = count(array_filter($results, static fn(array $row): bool => in_array($row['status'], ['applied', 'adopted'], true)));
        $remaining = $migrator->pendingCount();
        if ($remaining > 0) {
            $progress = max(68, min(77, 78 - $remaining));
            $this->transition((int) $run['id'], 'migrating', $progress, 'Migraciones por etapas', 'Continuar siguiente migración');
            $this->event((int) $run['id'], 'info', 'migration_checkpoint', 'Se guardó un checkpoint entre migraciones.', [
                'applied_or_adopted' => $applied,
                'remaining' => $remaining,
            ]);
            return;
        }
        $this->completeStep((int) $run['id'], 'migrating', 'activating', 78, 'Migraciones aplicadas', 'Activar release');
        $this->event((int) $run['id'], 'info', 'migrations_completed', 'Las migraciones terminaron.', ['applied_or_adopted' => $applied]);
    }

    private function stepActivate(array $run): void
    {
        $manifest = $this->manifestForRun($run);
        if ((string) $run['mode'] !== 'atomic') {
            throw new RuntimeException('La ejecución heredada requiere iniciar una actualización atómica nueva.');
        }
        $pointer = (new UpdateReleaseService())->activate((string) $run['staging_path'], $manifest);
        $previous = $pointer['previous_release_id'] ?? null;
        $stmt = Database::connectionFresh()->prepare(
            "UPDATE system_update_runs SET state='health_check',progress_percent=90,current_step='Comprobar sistema',
             next_action='Ejecutar health checks',previous_release_id=:previous WHERE id=:id"
        );
        $stmt->execute(['previous' => $previous, 'id' => (int) $run['id']]);
        $this->finishStep((int) $run['id'], 'activating', 'Release activada.');
        $this->event((int) $run['id'], 'info', 'release_activated', 'La release fue activada.', ['mode' => $run['mode']]);
    }

    private function stepHealth(array $run): void
    {
        $releaseService = new UpdateReleaseService();
        $pointer = $releaseService->pointer();
        $expectedPath = 'releases/' . (string) $run['release_code'];
        $activePath = realpath(AppPaths::installationRoot() . '/' . (string) ($pointer['path'] ?? ''));
        $stagingPath = realpath((string) $run['staging_path']);
        if (($pointer['release_id'] ?? '') !== (string) $run['release_code']
            || ($pointer['version'] ?? '') !== (string) $run['version_to']
            || ($pointer['path'] ?? '') !== $expectedPath
            || $stagingPath === false || $activePath !== $stagingPath) {
            $this->transition((int) $run['id'], 'rollback_pending', 92, 'Identidad activa no concordante', 'Ejecutar rollback');
            $this->event((int) $run['id'], 'error', 'active_release_identity_mismatch', 'La release activa no corresponde a la ejecución.');
            return;
        }
        $health = $releaseService->healthCheck((string) $run['staging_path']);
        if (!$health['ok']) {
            $this->transition((int) $run['id'], 'rollback_pending', 92, 'Health check fallido', 'Ejecutar rollback');
            $this->event((int) $run['id'], 'error', 'health_check_failed', 'La release activa no superó los health checks.', ['checks' => $health['checks']]);
            return;
        }
        $pdo = Database::connectionFresh();
        (new DirectUpdateMetadataPromotionService())->promote(
            $pdo,
            (string) $run['version_to'],
            $this->lastAppliedMigration($pdo),
            'Actualización segura completada.'
        );
        if (!(new ReleaseIntegrityService())->inspectDirectory((string) $run['staging_path'], true, false)['ok']) {
            $this->transition((int) $run['id'], 'rollback_pending', 92, 'Integridad canónica fallida', 'Ejecutar rollback');
            $this->event((int) $run['id'], 'error', 'canonical_integrity_failed', 'La release activa no supera la integridad con DB.');
            return;
        }
        $stmt = $pdo->prepare(
            "UPDATE system_update_runs SET state='completed',progress_percent=100,current_step='Actualización completa',
             next_action=NULL,completed_at=UTC_TIMESTAMP(),safe_error_code=NULL,safe_error_message=NULL WHERE id=:id"
        );
        $stmt->execute(['id' => (int) $run['id']]);
        $release = $pdo->prepare(
            "UPDATE system_update_releases SET status='active',installed_at=COALESCE(installed_at,UTC_TIMESTAMP()),
             activated_at=UTC_TIMESTAMP() WHERE id=:id"
        );
        $release->execute(['id' => (int) $run['release_id']]);
        $this->finishStep((int) $run['id'], 'health_check', 'Health checks aprobados.');
        $this->event((int) $run['id'], 'info', 'update_completed', 'Actualización completada correctamente.');
        try {
            (new UpdateRemoteService())->report([
                'version_from' => $run['version_from'],
                'version_to' => $run['version_to'],
                'result' => 'completed',
                'duration_seconds' => max(0, time() - strtotime((string) $run['started_at'])),
            ]);
        } catch (Throwable) {
        }
        $releaseService->cleanup();
        $releaseService->stopMaintenance();
    }

    private function lastAppliedMigration(PDO $pdo): string
    {
        $migration = $pdo->query(
            'SELECT version FROM schema_migrations ORDER BY CAST(SUBSTRING_INDEX(version, "_", 1) AS UNSIGNED) DESC LIMIT 1'
        )->fetchColumn();
        if (!is_string($migration) || $migration === '') {
            throw new RuntimeException('update_last_migration_missing');
        }
        return $migration;
    }

    /** @param array<string,mixed> $manifest */
    private function assertUpgradePath(array $manifest): void
    {
        $installed = (new AppVersionService())->installedVersion();
        if (preg_match('/^\d+\.\d+\.\d+/', $installed) === 1 && version_compare($installed, (string) $manifest['version'], '>')) {
            throw new RuntimeException("La instalación {$installed} es más nueva que el paquete {$manifest['version']}. Se bloqueó el downgrade.");
        }
        $allowed = (array) ($manifest['upgrade_from'] ?? ['*']);
        $resolvedBridges = array_values(array_filter((array) ($manifest['_resolved_bridges'] ?? []), 'is_array'));
        $effectiveFrom = $installed;
        if ($resolvedBridges !== []) {
            $lastBridge = $resolvedBridges[count($resolvedBridges) - 1];
            $effectiveFrom = (string) ($lastBridge['version'] ?? $installed);
        }
        if (
            !in_array('*', $allowed, true)
            && !in_array($installed, $allowed, true)
            && !in_array($effectiveFrom, $allowed, true)
        ) {
            throw new RuntimeException("La release no admite actualización directa desde {$installed}. Se requiere un puente.");
        }
        $sequence = (int) ($manifest['sequence'] ?? 0);
        if ($sequence > 0 && $this->engineReady()) {
            $max = (int) Database::connectionFresh()->query("SELECT COALESCE(MAX(sequence_no),0) FROM system_update_releases WHERE status IN ('installed','active')")->fetchColumn();
            if ($sequence < $max) {
                throw new RuntimeException('La secuencia del paquete intenta degradar la instalación.');
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function registerRelease(array $manifest, string $sourceType, string $source): int
    {
        $service = new UpdateManifestService();
        $stmt = Database::connectionFresh()->prepare(
            "INSERT INTO system_update_releases
             (release_id,product_id,version,sequence_no,channel,source_type,source_reference,manifest_json,
              manifest_sha256,signature_status,status,is_withdrawn)
             VALUES (:release_id,:product,:version,:sequence,:channel,:source_type,:source,:manifest,:hash,:signature,'verified',:withdrawn)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),source_reference=VALUES(source_reference),
               manifest_json=VALUES(manifest_json),manifest_sha256=VALUES(manifest_sha256),
               signature_status=VALUES(signature_status),is_withdrawn=VALUES(is_withdrawn)"
        );
        $stmt->execute([
            'release_id' => $manifest['release_id'],
            'product' => $manifest['product_id'],
            'version' => $manifest['version'],
            'sequence' => (int) ($manifest['sequence'] ?? 0),
            'channel' => $manifest['channel'],
            'source_type' => $sourceType,
            'source' => $source,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'hash' => $service->manifestHash($manifest),
            'signature' => $manifest['signature_status'] ?? 'not_checked',
            'withdrawn' => (int) ($manifest['withdrawn'] ?? 0),
        ]);
        return (int) Database::connectionFresh()->lastInsertId();
    }

    private function seedSteps(int $runId): void
    {
        $steps = [
            'verified',
            'diagnosing',
            'path_resolved',
            'awaiting_backup_choice',
            'backing_up',
            'staging',
            'bridging',
            'migrating',
            'activating',
            'health_check',
        ];
        $stmt = Database::connectionFresh()->prepare(
            'INSERT IGNORE INTO system_update_run_steps (run_id,step_key,sequence_no) VALUES (:run_id,:step,:sequence)'
        );
        foreach ($steps as $index => $step) {
            $stmt->execute(['run_id' => $runId, 'step' => $step, 'sequence' => $index + 1]);
        }
    }

    private function completeStep(int $runId, string $step, string $nextState, float $progress, string $currentStep, ?string $nextAction): void
    {
        $this->finishStep($runId, $step, $currentStep);
        $this->transition($runId, $nextState, $progress, $currentStep, $nextAction);
    }

    private function finishStep(int $runId, string $step, string $message): void
    {
        $stmt = Database::connectionFresh()->prepare(
            "UPDATE system_update_run_steps SET status='completed',progress_percent=100,
             attempts=attempts+1,started_at=COALESCE(started_at,UTC_TIMESTAMP()),finished_at=UTC_TIMESTAMP(),
             safe_message=:message WHERE run_id=:run_id AND step_key=:step"
        );
        $stmt->execute(['message' => mb_substr($message, 0, 700), 'run_id' => $runId, 'step' => $step]);
    }

    private function transition(int $runId, string $state, ?float $progress, string $currentStep, ?string $nextAction): void
    {
        $sql = 'UPDATE system_update_runs SET state=:state,current_step=:step,next_action=:next,heartbeat_at=UTC_TIMESTAMP()';
        $params = ['state' => $state, 'step' => $currentStep, 'next' => $nextAction, 'id' => $runId];
        if ($progress !== null) {
            $sql .= ',progress_percent=:progress';
            $params['progress'] = $progress;
        }
        $sql .= ' WHERE id=:id';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
    }

    private function failRun(int $runId, Throwable $error, string $failedFrom): void
    {
        $code = $this->safeErrorCode($error);
        $message = mb_substr($error->getMessage(), 0, 700);
        try {
            $stmt = Database::connectionFresh()->prepare(
                "UPDATE system_update_runs SET state='failed',safe_error_code=:code,safe_error_message=:message,
                 current_step='Actualización detenida',next_action='Revisar y reintentar' WHERE id=:id"
            );
            $stmt->execute(['code' => 'failed_from:' . $failedFrom, 'message' => '[' . $code . '] ' . $message, 'id' => $runId]);
            $this->event($runId, 'error', $code, $message);
            $run = $this->run($runId);
            if ($run !== null) {
                try {
                    (new UpdateRemoteService())->report([
                        'version_from' => $run['version_from'],
                        'version_to' => $run['version_to'],
                        'result' => 'failed',
                        'duration_seconds' => max(0, time() - strtotime((string) $run['started_at'])),
                        'error_code' => $code,
                        'failed_stage' => $failedFrom,
                    ]);
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
            Logger::write('error', 'Falló una actualización y no pudo persistirse el estado.', ['run_id' => $runId, 'error' => $message]);
        }
    }

    private function acquireLock(int $runId): string
    {
        $pdo = Database::connectionFresh();
        if ((int) $pdo->query("SELECT GET_LOCK('erp_meli_secure_update',3)")->fetchColumn() !== 1) {
            throw new RuntimeException('Otro proceso está avanzando la actualización.');
        }
        $owner = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare(
            "INSERT INTO system_update_locks (lock_name,owner_token,run_id,heartbeat_at,expires_at)
             VALUES ('secure_update',:owner,:run_id,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))
             ON DUPLICATE KEY UPDATE
               owner_token=IF(expires_at<UTC_TIMESTAMP(),VALUES(owner_token),owner_token),
               run_id=IF(expires_at<UTC_TIMESTAMP(),VALUES(run_id),run_id),
               heartbeat_at=IF(expires_at<UTC_TIMESTAMP(),VALUES(heartbeat_at),heartbeat_at),
               expires_at=IF(expires_at<UTC_TIMESTAMP(),VALUES(expires_at),expires_at)"
        );
        $stmt->execute(['owner' => $owner, 'run_id' => $runId]);
        $check = $pdo->query("SELECT owner_token FROM system_update_locks WHERE lock_name='secure_update'")->fetchColumn();
        if (!is_string($check) || !hash_equals($owner, $check)) {
            $pdo->query("SELECT RELEASE_LOCK('erp_meli_secure_update')");
            throw new RuntimeException('El lock persistente de actualización sigue vigente.');
        }
        return $owner;
    }

    private function heartbeat(int $runId, string $owner): void
    {
        $stmt = Database::connectionFresh()->prepare(
            "UPDATE system_update_locks SET heartbeat_at=UTC_TIMESTAMP(),
             expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)
             WHERE lock_name='secure_update' AND owner_token=:owner AND run_id=:run_id"
        );
        $stmt->execute(['owner' => $owner, 'run_id' => $runId]);
    }

    private function releaseLock(int $runId, string $owner): void
    {
        try {
            $stmt = Database::connectionFresh()->prepare(
                "DELETE FROM system_update_locks WHERE lock_name='secure_update' AND owner_token=:owner AND run_id=:run_id"
            );
            $stmt->execute(['owner' => $owner, 'run_id' => $runId]);
            Database::connectionFresh()->query("SELECT RELEASE_LOCK('erp_meli_secure_update')");
        } catch (Throwable) {
        }
    }

    /** @return array<string,mixed> */
    private function manifestForRun(array $run): array
    {
        try {
            $manifest = json_decode((string) ($run['manifest_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('El manifiesto guardado para la ejecución no es válido.', 0, $e);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('La ejecución no tiene manifiesto.');
        }
        return $manifest;
    }

    /** @return list<array<string,mixed>> */
    private function steps(int $runId): array
    {
        $stmt = Database::connectionFresh()->prepare('SELECT * FROM system_update_run_steps WHERE run_id=:run_id ORDER BY sequence_no');
        $stmt->execute(['run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function currentPendingStep(int $runId): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            "SELECT * FROM system_update_run_steps WHERE run_id=:run_id AND status IN ('pending','running')
             ORDER BY sequence_no LIMIT 1"
        );
        $stmt->execute(['run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function events(int $runId, int $limit): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT level,event_code,message,created_at FROM system_update_events
             WHERE run_id=:run_id ORDER BY created_at DESC,id DESC LIMIT :limit'
        );
        $stmt->bindValue('run_id', $runId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function backups(int $limit): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT id,run_id,backup_type,adapter,status,size_bytes,checksum_sha256,verified_at,expires_at,created_at
             FROM system_update_backups ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function trustedKeys(): array
    {
        return Database::connectionFresh()->query(
            'SELECT key_id,algorithm,channels_json,status,valid_from,valid_until,revoked_at,created_at
             FROM system_update_trusted_keys ORDER BY created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function event(int $runId, string $level, string $code, string $message, array $context = []): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'INSERT INTO system_update_events (run_id,level,event_code,message,context_json)
             VALUES (:run_id,:level,:code,:message,:context)'
        );
        $stmt->execute([
            'run_id' => $runId,
            'level' => $level,
            'code' => $code,
            'message' => mb_substr($message, 0, 700),
            'context' => $context === [] ? null : json_encode(Logger::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function safeErrorCode(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'firma') || str_contains($message, 'hash') => 'package_integrity',
            str_contains($message, 'espacio') || str_contains($message, 'disk') => 'disk_space',
            str_contains($message, 'schema') || str_contains($message, 'esquema') => 'schema_drift',
            str_contains($message, 'lock') || str_contains($message, 'otro proceso') => 'update_locked',
            str_contains($message, 'mysql') || str_contains($message, 'sqlstate') => 'database_error',
            str_contains($message, 'backup') || str_contains($message, 'respaldo') => 'backup_error',
            default => 'update_error',
        };
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
