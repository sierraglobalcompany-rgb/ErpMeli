<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

final class RestoreService
{
    /**
     * @param array{host:string,port:string,database:string,user:string,password:string} $target
     * @return array{id:int,public_id:string}
     */
    public function prepare(int $backupId, int $userId, string $mode, array $target): array
    {
        if (!in_array($mode, ['production_recovery', 'diagnostic_clone'], true)) {
            throw new RuntimeException('El modo de restauración no es válido.');
        }
        $this->assertPhysicalStops();
        $archive = (new BackupCenterService())->archive($backupId);
        if ((string) $archive['status'] !== 'ready') {
            throw new RuntimeException('La copia debe estar verificada antes de restaurarla.');
        }
        if (version_compare((string) $archive['erp_version'], AppVersionService::fileVersion(), '>')) {
            throw new RuntimeException('La copia fue creada con una versión más reciente del ERP.');
        }
        $pdo = Database::connection();
        if ((int) $pdo->query(
            "SELECT COUNT(*) FROM system_restore_plans
             WHERE status IN ('prepared','validated','queued','restoring','verifying','ready_to_switch')"
        )->fetchColumn() > 0) {
            throw new RuntimeException('Ya existe una recuperación activa. Termínela o reviértala antes de preparar otra.');
        }
        $target = $this->normalizeTarget($target);
        $targetPdo = $this->targetPdo($target);
        $tables = (int) $targetPdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()"
        )->fetchColumn();
        if ($tables !== 0) {
            throw new RuntimeException('La base de destino no está vacía. No se modificó ningún dato.');
        }
        $publicId = $this->uuid();
        if ($mode === 'diagnostic_clone') {
            $target['app_key'] = 'base64:' . base64_encode(random_bytes(32));
            $target['ml_write_enabled'] = 'false';
        }
        $secretName = (new RestoreSecretStoreService())->put($publicId, $target);
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO system_restore_plans
                 (public_id,backup_id,requested_by,mode,status,target_secret_name,target_database_hash,tables_total)
                 VALUES (:public_id,:backup_id,:user_id,:mode,'queued',:secret_name,:db_hash,:tables)"
            );
            $stmt->execute([
                'public_id' => $publicId,
                'backup_id' => $backupId,
                'user_id' => $userId,
                'mode' => $mode,
                'secret_name' => $secretName,
                'db_hash' => hash('sha256', strtolower($target['database'])),
                'tables' => (int) $archive['table_count'],
            ]);
        } catch (Throwable $error) {
            (new RestoreSecretStoreService())->delete($secretName);
            throw $error;
        }
        $id = (int) $pdo->lastInsertId();
        (new RestoreMaintenanceRequestService())->publish($id, $publicId);
        return ['id' => $id, 'public_id' => $publicId];
    }

    /** @return array<string,mixed> */
    public function processRequested(): array
    {
        $this->assertPhysicalStops();
        $requestService = new RestoreMaintenanceRequestService();
        $request = $requestService->valid();
        $pdo = Database::connection();
        if ($request !== null) {
            $stmt = $pdo->prepare(
                "SELECT p.*,b.storage_name,b.erp_version,b.status AS backup_status
                 FROM system_restore_plans p
                 INNER JOIN system_backup_archives b ON b.id=p.backup_id
                 WHERE p.id=:id AND p.public_id=:public_id LIMIT 1"
            );
            $stmt->execute(['id' => $request['restore_id'], 'public_id' => $request['public_id']]);
        } else {
            // The signed file only wakes the maintenance lane. MariaDB remains
            // the source of truth so a crash between checkpoint and republish
            // cannot strand a resumable restoration.
            $stmt = $pdo->query(
                "SELECT p.*,b.storage_name,b.erp_version,b.status AS backup_status
                 FROM system_restore_plans p
                 INNER JOIN system_backup_archives b ON b.id=p.backup_id
                 WHERE p.status IN ('queued','restoring','verifying')
                   AND (p.lease_owner IS NULL OR p.lease_expires_at IS NULL
                        OR p.lease_expires_at<UTC_TIMESTAMP(3))
                 ORDER BY p.requested_at,p.id LIMIT 1"
            );
        }
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plan)) {
            if ($request !== null) {
                $requestService->clearIfMatches($request['restore_id'], $request['public_id']);
            }
            return ['result' => 'empty'];
        }
        if (in_array((string) $plan['status'], ['ready_to_switch', 'completed', 'switched', 'rolled_back', 'failed', 'cancelled'], true)) {
            $requestService->clearIfMatches((int) $plan['id'], (string) $plan['public_id']);
            return ['result' => 'completed'];
        }
        $owner = 'restore-' . bin2hex(random_bytes(8));
        $generation = (int) $plan['lease_generation'] + 1;
        $claim = $pdo->prepare(
            "UPDATE system_restore_plans
             SET status='restoring',lease_owner=:owner,lease_generation=:generation,
                 lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE),
                 started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),heartbeat_at=UTC_TIMESTAMP(3)
             WHERE id=:id AND (
                 lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3)
             )"
        );
        $claim->execute(['owner' => $owner, 'generation' => $generation, 'id' => $plan['id']]);
        if ($claim->rowCount() !== 1) {
            return ['result' => 'locked'];
        }

        $target = null;
        $restoreLock = null;
        try {
            $secret = (new RestoreSecretStoreService())->get((string) $plan['target_secret_name']);
            $target = $this->targetPdo($secret);
            $restoreLock = 'erp_restore_' . substr((string) $plan['target_database_hash'], 0, 48);
            $lock = $target->prepare('SELECT GET_LOCK(:lock_name,0)');
            $lock->execute(['lock_name' => $restoreLock]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('La base de destino ya está siendo restaurada por otro worker.');
            }
            $archive = (new BackupCenterService())->archive((int) $plan['backup_id']);
            $archivePath = (new BackupCenterService())->pathForArchive($archive);
            $verifiedArchive = (new BackupArchiveService())->verify($archivePath);
            $this->assertTargetCompatibility($target, $verifiedArchive['manifest']);
            $plainPath = $this->plainPath((string) $plan['public_id']);
            if (!is_file($plainPath)) {
                $temporary = $plainPath . '.part';
                @unlink($temporary);
                (new BackupArchiveService())->decryptTo($archivePath, $temporary);
                if (!@rename($temporary, $plainPath)) {
                    @unlink($temporary);
                    throw new RuntimeException('No fue posible preparar el archivo privado de restauración.');
                }
                @chmod($plainPath, 0600);
            }
            $checkpoint = json_decode((string) ($plan['checkpoint_json'] ?? '{}'), true);
            $checkpoint = is_array($checkpoint) ? $checkpoint : [];
            $offset = max(0, (int) ($checkpoint['gzip_offset'] ?? 0));
            $archiveVerified = (bool) ($checkpoint['archive_verified'] ?? false);
            $dumpComplete = (bool) ($checkpoint['dump_complete'] ?? false);
            $result = ($archiveVerified || $dumpComplete)
                ? ['complete' => true, 'offset' => $offset, 'statements' => 0]
                : $this->applyBatch($target, $plainPath, $offset);
            if (!$result['complete']) {
                $guard = $pdo->prepare(
                    "UPDATE system_restore_plans
                     SET checkpoint_json=:checkpoint,rows_restored=rows_restored+:statements,
                         heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
                );
                $guard->execute([
                    'checkpoint' => json_encode([
                        'gzip_offset' => $result['offset'],
                        'dump_complete' => false,
                        'archive_verified' => false,
                        'stage' => 'restoring_archive',
                    ], JSON_THROW_ON_ERROR),
                    'statements' => $result['statements'],
                    'id' => $plan['id'],
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                if ($guard->rowCount() !== 1) {
                    throw new RuntimeException('El worker perdió el lease durante la restauración.');
                }
                (new RestoreMaintenanceRequestService())->publish((int) $plan['id'], (string) $plan['public_id']);
                $this->releaseRestoreLock($target, $restoreLock);
                return ['result' => 'partial', 'statements' => $result['statements']];
            }

            if (!$archiveVerified && !$dumpComplete) {
                // Persist the end of the import first. Verification can be
                // expensive on a large production clone and therefore runs in
                // the next bounded CLI invocation instead of overrunning the
                // hosting window after the final insert batch.
                $checkpoint = [
                    'gzip_offset' => $result['offset'],
                    'dump_complete' => true,
                    'archive_verified' => false,
                    'stage' => 'verifying_archive',
                ];
                $guard = $pdo->prepare(
                    "UPDATE system_restore_plans
                     SET checkpoint_json=:checkpoint,rows_restored=rows_restored+:statements,
                         heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
                );
                $guard->execute([
                    'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                    'statements' => $result['statements'],
                    'id' => $plan['id'],
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                if ($guard->rowCount() !== 1) {
                    throw new RuntimeException('El worker perdió el lease al cerrar la importación.');
                }
                (new RestoreMaintenanceRequestService())->publish((int) $plan['id'], (string) $plan['public_id']);
                $this->releaseRestoreLock($target, $restoreLock);
                return ['result' => 'partial', 'stage' => 'verifying_archive'];
            }

            if (!$archiveVerified) {
                // Verify the exact source snapshot before any current-code
                // migration or diagnostic sanitization can legitimately change
                // its rows or structure.
                $this->verifyTarget(
                    $target,
                    (array) $verifiedArchive['manifest'],
                    $pdo,
                    (int) $plan['id'],
                    $owner,
                    $generation
                );
                $checkpoint = [
                    'gzip_offset' => $result['offset'],
                    'dump_complete' => true,
                    'archive_verified' => true,
                    'stage' => 'migrating_target',
                ];
            }

            $this->renewRestoreLease($pdo, (int) $plan['id'], $owner, $generation);
            $migrator = new Migrator($target, AppPaths::releaseRoot() . '/database/migrations');
            if ($migrator->pendingCount() > 0) {
                $migrator->run(1);
                $this->renewRestoreLease($pdo, (int) $plan['id'], $owner, $generation);
                $remaining = $migrator->pendingCount();
                $checkpoint['migrations_remaining'] = $remaining;
                $guard = $pdo->prepare(
                    "UPDATE system_restore_plans
                     SET checkpoint_json=:checkpoint,rows_restored=rows_restored+:statements,
                         heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
                );
                $guard->execute([
                    'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                    'statements' => $result['statements'],
                    'id' => $plan['id'],
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                if ($guard->rowCount() !== 1) {
                    throw new RuntimeException('El worker perdió el lease durante la actualización de la base nueva.');
                }
                // Even when this was the last pending migration, close the
                // current bounded invocation and perform final sanitization
                // and approval in a fresh cycle.
                (new RestoreMaintenanceRequestService())->publish((int) $plan['id'], (string) $plan['public_id']);
                $this->releaseRestoreLock($target, $restoreLock);
                return ['result' => 'partial', 'migrations_remaining' => $remaining];
            }

            $this->renewRestoreLease($pdo, (int) $plan['id'], $owner, $generation);
            $this->reconcileRestoredMaintenanceState($target);
            if ((string) $plan['mode'] === 'diagnostic_clone') {
                $this->sanitizeDiagnosticClone($target);
                $this->verifyDiagnosticSafety($target);
            }
            $this->renewRestoreLease($pdo, (int) $plan['id'], $owner, $generation);
            $checkpoint['migrations_remaining'] = 0;
            $checkpoint['stage'] = 'verified';
            $checkpoint['complete'] = true;
            $finalStatus = (string) $plan['mode'] === 'diagnostic_clone'
                ? 'completed'
                : 'ready_to_switch';
            $guard = $pdo->prepare(
                "UPDATE system_restore_plans
                 SET status=:final_status,verified_at=UTC_TIMESTAMP(3),completed_at=UTC_TIMESTAMP(3),
                     heartbeat_at=UTC_TIMESTAMP(3),checkpoint_json=:checkpoint,
                     tables_completed=COALESCE(tables_total,tables_completed),
                     rows_restored=rows_restored+:statements,
                     lease_owner=NULL,lease_expires_at=NULL
                 WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
            );
            $guard->execute([
                'final_status' => $finalStatus,
                'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                'statements' => $result['statements'],
                'id' => $plan['id'],
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($guard->rowCount() !== 1) {
                throw new RuntimeException('El worker perdió el lease antes de aprobar la restauración.');
            }
            @unlink($plainPath);
            if ((string) $plan['mode'] === 'diagnostic_clone') {
                (new RestoreSecretStoreService())->delete((string) $plan['target_secret_name']);
            }
            (new RestoreMaintenanceRequestService())->clearIfMatches(
                (int) $plan['id'],
                (string) $plan['public_id']
            );
            $this->releaseRestoreLock($target, $restoreLock);
            return ['result' => 'completed'];
        } catch (Throwable $error) {
            $owned = false;
            $retryScheduled = false;
            try {
                $checkpoint = json_decode((string) ($plan['checkpoint_json'] ?? '{}'), true);
                $checkpoint = is_array($checkpoint) ? $checkpoint : [];
                $recoveryAttempts = max(0, (int) ($checkpoint['recovery_attempts'] ?? 0)) + 1;
                $checkpoint['recovery_attempts'] = $recoveryAttempts;
                $checkpoint['last_recovery_at'] = gmdate('c');
                $checkpoint['stage'] = (string) ($checkpoint['stage'] ?? 'restoring_archive');
                $retryScheduled = $recoveryAttempts <= 3;
                $guard = $pdo->prepare(
                    "UPDATE system_restore_plans
                     SET status=:status,safe_error_code=:error_code,
                         safe_error_message=:error_message,checkpoint_json=:checkpoint,
                         heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,
                         completed_at=IF(:terminal=1,UTC_TIMESTAMP(3),NULL)
                     WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
                );
                $guard->execute([
                    'status' => $retryScheduled ? 'queued' : 'failed',
                    'error_code' => $retryScheduled ? 'restore_retry_scheduled' : 'restore_failed',
                    'error_message' => $retryScheduled
                        ? 'La restauración se reanudará desde el último punto confirmado.'
                        : 'La restauración se detuvo después de tres recuperaciones automáticas. La base activa no fue modificada.',
                    'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                    'terminal' => $retryScheduled ? 0 : 1,
                    'id' => $plan['id'],
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                $owned = $guard->rowCount() === 1;
            } catch (Throwable) {
            }

            // A stale worker must never clear the request, plaintext staging file
            // or encrypted target credential owned by a newer lease generation.
            if ($owned && $retryScheduled) {
                (new RestoreMaintenanceRequestService())->publish((int) $plan['id'], (string) $plan['public_id']);
            } elseif ($owned) {
                @unlink($this->plainPath((string) ($plan['public_id'] ?? 'invalid')));
                (new RestoreSecretStoreService())->delete((string) ($plan['target_secret_name'] ?? ''));
                (new RestoreMaintenanceRequestService())->clearIfMatches(
                    (int) $plan['id'],
                    (string) $plan['public_id']
                );
            }
            $this->releaseRestoreLock($target, $restoreLock);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $plans = Database::connection()->query(
            "SELECT p.id,p.public_id,p.backup_id,p.mode,p.status,p.tables_total,p.tables_completed,
                    p.rows_restored,p.safe_error_message,p.requested_at,p.started_at,p.heartbeat_at,
                    p.verified_at,p.completed_at,b.public_id AS backup_public_id
             FROM system_restore_plans p
             INNER JOIN system_backup_archives b ON b.id=p.backup_id
             ORDER BY p.id DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['plans' => $plans];
    }

    /** @return array<string,mixed> */
    public function plan(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM system_restore_plans WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \App\Core\HttpException(404, 'La restauración solicitada no existe.');
        }
        return $row;
    }

    /** @return array{complete:bool,offset:int,statements:int} */
    private function applyBatch(PDO $target, string $gzipPath, int $offset): array
    {
        $gzip = gzopen($gzipPath, 'rb');
        if ($gzip === false) {
            throw new RuntimeException('No fue posible abrir el contenido privado de restauración.');
        }
        if ($offset > 0 && gzseek($gzip, $offset) !== 0) {
            gzclose($gzip);
            throw new RuntimeException('No fue posible retomar el checkpoint de restauración.');
        }
        $started = microtime(true);
        $statements = 0;
        $pendingStatements = [];
        $pendingBytes = 0;
        $buffer = '';
        $quote = null;
        try {
            // Cada ciclo abre una conexión nueva; la protección debe
            // desactivarse nuevamente antes de retomar el dump.
            $target->exec("SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
            $target->exec('SET FOREIGN_KEY_CHECKS=0');
            while (!gzeof($gzip) && $statements + count($pendingStatements) < 20000 && microtime(true) - $started < 28) {
                $line = gzgets($gzip);
                if (!is_string($line)) {
                    break;
                }
                if ($buffer === '' && (trim($line) === '' || str_starts_with(ltrim($line), '-- '))) {
                    continue;
                }
                $buffer .= $line;
                $this->scanQuotes($line, $quote);
                if ($quote !== null || !str_ends_with(rtrim($line), ';')) {
                    continue;
                }
                $sql = trim($buffer);
                $buffer = '';
                if ($sql !== '') {
                    $pendingStatements[] = $sql;
                    $pendingBytes += strlen($sql);
                    if (count($pendingStatements) >= 200 || $pendingBytes >= 2097152) {
                        $this->executeRestoreStatements($target, $pendingStatements);
                        $statements += count($pendingStatements);
                        $pendingStatements = [];
                        $pendingBytes = 0;
                    }
                }
            }
            if ($buffer !== '') {
                throw new RuntimeException('El checkpoint terminó dentro de una sentencia SQL.');
            }
            if ($pendingStatements !== []) {
                $this->executeRestoreStatements($target, $pendingStatements);
                $statements += count($pendingStatements);
            }
            return ['complete' => gzeof($gzip), 'offset' => (int) gztell($gzip), 'statements' => $statements];
        } finally {
            gzclose($gzip);
        }
    }

    /**
     * Ejecuta DML dentro de una transacción y usa INSERT IGNORE únicamente
     * durante la restauración. Si el proceso muere después del commit pero
     * antes de persistir el offset, repetir el lote no duplica ni bloquea el
     * avance. La verificación criptográfica final detecta cualquier conflicto
     * que no sea una repetición exacta.
     *
     * @param list<string> $statements
     */
    private function executeRestoreStatements(PDO $target, array $statements): void
    {
        /** @var list<string> $dml */
        $dml = [];
        foreach ($statements as $sql) {
            if (preg_match('/^INSERT\\s+INTO\\s+/i', ltrim($sql)) === 1) {
                $dml[] = $sql;
                continue;
            }
            $this->flushRestoreDml($target, $dml);
            $dml = [];
            $target->exec($sql);
        }
        $this->flushRestoreDml($target, $dml);
    }

    /** @param list<string> $dml */
    private function flushRestoreDml(PDO $target, array $dml): void
    {
        if ($dml === []) {
            return;
        }
        $target->beginTransaction();
        try {
            foreach ($dml as $sql) {
                $idempotent = preg_replace(
                    '/^INSERT\\s+INTO\\s+/i',
                    'INSERT IGNORE INTO ',
                    $sql,
                    1
                );
                $target->exec(is_string($idempotent) ? $idempotent : $sql);
            }
            $target->commit();
        } catch (Throwable $error) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            throw $error;
        }
    }

    private function scanQuotes(string $line, ?string &$quote): void
    {
        $escaped = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote === null && ($char === "'" || $char === '"' || $char === '`')) {
                $quote = $char;
                continue;
            }
            if ($quote !== null && $char === $quote) {
                if ($i + 1 < $length && $line[$i + 1] === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function verifyTarget(
        PDO $target,
        array $manifest,
        PDO $control,
        int $restoreId,
        string $owner,
        int $generation
    ): void
    {
        $format = max(2, (int) ($manifest['format'] ?? 0));
        $checks = is_array($manifest['tables'] ?? null) ? array_values($manifest['tables']) : [];
        $expectedTables = (int) ($manifest['table_count'] ?? count($checks));
        if ($expectedTables < 1 || count($checks) !== $expectedTables) {
            throw new RuntimeException('El manifiesto autenticado no contiene todas las tablas de la copia.');
        }
        foreach ($checks as $check) {
            if (!is_array($check)) {
                throw new RuntimeException('El manifiesto autenticado contiene una tabla inválida.');
            }
            $this->renewRestoreLease($control, $restoreId, $owner, $generation);
            $table = (string) ($check['table_name'] ?? '');
            if ($table === '') {
                $table = (string) ($check['table'] ?? '');
            }
            $expected = (int) ($check['row_count'] ?? $check['rows'] ?? -1);
            if ($table === '' || $expected < 0) {
                throw new RuntimeException('El manifiesto autenticado contiene metadatos incompletos.');
            }
            $actual = (int) $target->query('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table))->fetchColumn();
            if ($actual !== $expected) {
                throw new RuntimeException('La base restaurada no coincide con los conteos verificados.');
            }
            $create = $target->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))->fetch(PDO::FETCH_NUM);
            $structure = is_array($create) && isset($create[1]) ? (string) $create[1] : '';
            if (
                $structure === ''
                || !hash_equals((string) $check['structure_sha256'], hash('sha256', $structure))
            ) {
                throw new RuntimeException('La estructura restaurada no coincide con la copia verificada.');
            }
            $dataHash = $this->targetDataHash(
                $target,
                $table,
                $format,
                function () use ($control, $restoreId, $owner, $generation): void {
                    $this->renewRestoreLease($control, $restoreId, $owner, $generation);
                }
            );
            if (!hash_equals((string) $check['data_sha256'], $dataHash)) {
                throw new RuntimeException('Los datos restaurados no coinciden con la copia verificada.');
            }
            $this->renewRestoreLease($control, $restoreId, $owner, $generation);
        }
    }

    private function verifyDiagnosticSafety(PDO $target): void
    {
        foreach (['meli_tokens', 'meli_oauth_states', 'system_backup_download_grants', 'login_attempts'] as $table) {
            if (
                $this->targetTableExists($target, $table)
                && (int) $target->query('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table))->fetchColumn() !== 0
            ) {
                throw new RuntimeException('El clon diagnóstico todavía contiene credenciales o sesiones utilizables.');
            }
        }
        if (
            $this->targetTableExists($target, 'meli_accounts')
            && (int) $target->query(
                "SELECT COUNT(*) FROM meli_accounts WHERE status='conectado'"
            )->fetchColumn() !== 0
        ) {
            throw new RuntimeException('El clon diagnóstico todavía contiene cuentas conectadas.');
        }
    }

    private function targetDataHash(
        PDO $target,
        string $table,
        int $format = 2,
        ?callable $heartbeat = null
    ): string
    {
        $hash = hash_init('sha256');
        $chain = hash('sha256', '');
        $query = $target->query(
            'SELECT * FROM ' . $this->quoteIdentifier($table) . $this->primaryKeyOrder($target, $table)
        );
        $rows = 0;
        $lastHeartbeat = microtime(true);
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $rows++;
            $columns = array_keys($row);
            $values = array_map(
                static fn (mixed $value): string => $value === null ? 'NULL' : $target->quote((string) $value),
                array_values($row)
            );
            $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . implode(',', array_map(
                fn (string $column): string => $this->quoteIdentifier($column),
                $columns
            )) . ') VALUES (' . implode(',', $values) . ");\n";
            if ($format >= 3) {
                $chain = hash('sha256', hex2bin($chain) . $sql);
            } else {
                hash_update($hash, $sql);
            }
            if (
                $heartbeat !== null
                && ($rows % 1000 === 0 || microtime(true) - $lastHeartbeat >= 30.0)
            ) {
                $heartbeat();
                $lastHeartbeat = microtime(true);
            }
        }
        return $format >= 3 ? $chain : hash_final($hash);
    }

    private function primaryKeyOrder(PDO $pdo, string $table): string
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
               AND BINARY TABLE_NAME=BINARY :table
               AND INDEX_NAME='PRIMARY'
             ORDER BY SEQ_IN_INDEX"
        );
        $stmt->execute(['table' => $table]);
        $columns = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $columns === []
            ? ''
            : ' ORDER BY ' . implode(',', array_map($this->quoteIdentifier(...), $columns));
    }

    private function sanitizeDiagnosticClone(PDO $target): void
    {
        foreach (['meli_tokens', 'meli_oauth_states'] as $table) {
            if ($this->targetTableExists($target, $table)) {
                $target->exec('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
            }
        }
        if ($this->targetTableExists($target, 'meli_accounts')) {
            $target->exec("UPDATE meli_accounts SET status='desconectado',last_error=NULL");
        }
        foreach (['system_backup_download_grants', 'login_attempts'] as $table) {
            if ($this->targetTableExists($target, $table)) {
                $target->exec('TRUNCATE TABLE ' . $this->quoteIdentifier($table));
            }
        }
    }

    private function reconcileRestoredMaintenanceState(PDO $target): void
    {
        if ($this->targetTableExists($target, 'system_backup_jobs')) {
            $target->exec(
                "UPDATE system_backup_jobs
                 SET status='failed',lease_owner=NULL,lease_expires_at=NULL,
                     safe_error_code='restored_snapshot',
                     safe_error_message='La solicitud pertenecía al momento de la copia y no se reanudó automáticamente.',
                     completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3))
                 WHERE status IN ('pending','running','cancel_requested')"
            );
        }
        if ($this->targetTableExists($target, 'system_backup_archives')) {
            $target->exec(
                "UPDATE system_backup_archives
                 SET status='failed',safe_error_code='restored_snapshot',
                     safe_error_message='Esta copia estaba en curso dentro del estado restaurado.',
                     completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3))
                 WHERE status IN (
                    'prepared','queued','creating','verifying',
                    'ready_pending_release','cancel_requested','deleting'
                 )"
            );
        }
        if ($this->targetTableExists($target, 'system_restore_plans')) {
            $target->exec(
                "UPDATE system_restore_plans
                 SET status='failed',lease_owner=NULL,lease_expires_at=NULL,
                     safe_error_code='restored_snapshot',
                     safe_error_message='La restauración histórica no se reanudó automáticamente.',
                     completed_at=COALESCE(completed_at,UTC_TIMESTAMP(3))
                 WHERE status IN ('prepared','validated','queued','restoring','verifying')"
            );
        }
        if ($this->targetTableExists($target, 'system_backup_download_grants')) {
            $target->exec('DELETE FROM system_backup_download_grants');
        }
    }

    private function targetTableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function renewRestoreLease(
        PDO $control,
        int $restoreId,
        string $owner,
        int $generation
    ): void {
        $guard = $control->prepare(
            "UPDATE system_restore_plans
             SET heartbeat_at=UTC_TIMESTAMP(3),
                 lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)
             WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation"
        );
        $guard->execute([
            'id' => $restoreId,
            'owner' => $owner,
            'generation' => $generation,
        ]);
        if ($guard->rowCount() !== 1) {
            throw new RuntimeException('El worker perdió el lease de restauración.');
        }
    }

    private function releaseRestoreLock(?PDO $target, ?string $lockName): void
    {
        if (!$target instanceof PDO || $lockName === null || $lockName === '') {
            return;
        }
        try {
            $release = $target->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertTargetCompatibility(PDO $target, array $manifest): void
    {
        $sourceVersion = (string) (($manifest['database']['engine_version'] ?? '') ?: '');
        $targetVersion = (string) $target->query('SELECT VERSION()')->fetchColumn();
        $sourceMaria = stripos($sourceVersion, 'MariaDB') !== false;
        $targetMaria = stripos($targetVersion, 'MariaDB') !== false;
        if ($sourceVersion === '' || $targetVersion === '' || $sourceMaria !== $targetMaria) {
            throw new RuntimeException(
                'La base nueva debe usar la misma familia de motor que la copia (MariaDB o MySQL).'
            );
        }
        $numeric = static function (string $version): string {
            return preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/', $version, $match) === 1
                ? (string) $match[1]
                : '0.0.0';
        };
        if (
            ($targetMaria && version_compare($numeric($targetVersion), '11.8.0', '<'))
            || (!$targetMaria && version_compare($numeric($targetVersion), '8.0.0', '<'))
        ) {
            throw new RuntimeException('La base nueva no cumple la versión mínima compatible.');
        }
    }

    /** @param array<string,string> $target */
    private function targetPdo(array $target): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $target['host'],
            $target['port'],
            $target['database']
        );
        $multiStatementsAttribute = class_exists(\Pdo\Mysql::class)
            ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
        return new PDO($dsn, $target['user'], $target['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
            $multiStatementsAttribute => true,
        ]);
    }

    /** @param array<string,string> $target @return array<string,string> */
    private function normalizeTarget(array $target): array
    {
        $normalized = [
            'host' => trim((string) ($target['host'] ?? '')),
            'port' => trim((string) ($target['port'] ?? '3306')),
            'database' => trim((string) ($target['database'] ?? '')),
            'user' => trim((string) ($target['user'] ?? '')),
            'password' => (string) ($target['password'] ?? ''),
        ];
        if (
            $normalized['host'] === ''
            || !ctype_digit($normalized['port'])
            || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $normalized['database'])
            || $normalized['user'] === ''
        ) {
            throw new RuntimeException('Los datos de la base nueva no son válidos.');
        }
        if (
            hash_equals(strtolower((string) Env::get('DB_NAME', '')), strtolower($normalized['database']))
            && hash_equals(strtolower((string) Env::get('DB_HOST', '')), strtolower($normalized['host']))
        ) {
            throw new RuntimeException('La restauración nunca puede usar la base activa como destino.');
        }
        return $normalized;
    }

    private function assertPhysicalStops(): void
    {
        $root = AppPaths::installationRoot();
        if (!is_file($root . '/PAUSE_MELI_API') || !is_file($root . '/PAUSE_ERP_AUTOMATION')) {
            throw new RuntimeException('Active las dos paradas físicas antes de preparar una restauración.');
        }
    }

    private function plainPath(string $publicId): string
    {
        return (new RestoreSecretStoreService())->directory() . '/restore-' . preg_replace('/[^A-Za-z0-9-]/', '', $publicId) . '.sql.gz';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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
}
