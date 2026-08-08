<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Coordina el carril local de copias sin convertir un marcador aislado en una
 * autorización indefinida.
 *
 * La base conserva la autoridad del trabajo. Este marcador firmado solo
 * permite que el lanzador detenido llegue hasta MariaDB para reconciliar o
 * continuar exactamente una copia. Las señales heredadas permanecen
 * compatibles durante la transición.
 */
final class LocalMaintenanceCoordinator
{
    private const VERSION = 2;
    private const LEGACY_VERSION = 1;
    private const TASK_BACKUP = 'backup';
    private const MAX_MARKER_BYTES = 16384;
    private const MAX_LIFETIME_SECONDS = 604800;

    /**
     * Lectura utilizable antes del autoload y antes de abrir MariaDB.
     *
     * @return array{
     *   exists:bool,valid:bool,task:string,backup_id:int,public_id:string,
     *   state:string,owner:string,generation:int,phase:string,freeze_active:bool,
     *   expires_at:int,reason:string
     * }
     */
    public static function inspectBeforeBootstrap(
        string $sharedRoot,
        string $installationRoot
    ): array {
        $path = self::markerPathFromRoot($sharedRoot);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return self::emptyInspection();
        }

        $payload = self::readJsonFile($path);
        if ($payload === []) {
            return self::invalidInspection('coordinator_marker_unreadable');
        }
        $appKey = self::prebootstrapAppKey($sharedRoot, $installationRoot);
        if ($appKey === '') {
            return self::invalidInspection('coordinator_key_unavailable');
        }
        $invalidReason = self::invalidPayloadReason($payload, $appKey);
        if ($invalidReason !== '') {
            return self::invalidInspection($invalidReason, $payload);
        }

        return [
            'exists' => true,
            'valid' => true,
            'task' => (string) $payload['task'],
            'backup_id' => (int) $payload['backup_id'],
            'public_id' => (string) $payload['public_id'],
            'state' => (string) $payload['state'],
            'owner' => (string) ($payload['owner'] ?? ''),
            'generation' => max(0, (int) ($payload['generation'] ?? 0)),
            'phase' => (string) ($payload['phase'] ?? $payload['state']),
            'freeze_active' => (bool) ($payload['freeze_active'] ?? true),
            'expires_at' => max(0, (int) ($payload['expires_at'] ?? 0)),
            'reason' => '',
        ];
    }

    /**
     * Repara señales interrumpidas y decide si existe una copia exacta que el
     * lanzador puede continuar. No ejecuta el dump y no realiza transporte.
     *
     * @return array{
     *   state:'runnable'|'terminal_reconciled'|'idle'|'blocked',
     *   reason:string,backup_id:int,public_id:string,remote:false
     * }
     */
    public function reconcileBackup(PDO $pdo): array
    {
        $coordinatorInspection = self::inspectBeforeBootstrap(
            AppPaths::sharedRoot(),
            AppPaths::installationRoot()
        );
        $legacyService = new BackupMaintenanceRequestService();
        $legacy = $legacyService->consumeValid();
        $snapshot = $this->snapshotMarker();
        $fallbackBackupId = $coordinatorInspection['backup_id'];
        $fallbackPublicId = $coordinatorInspection['public_id'];
        if (
            $coordinatorInspection['exists']
            && !$coordinatorInspection['valid']
            && $coordinatorInspection['reason'] !== 'coordinator_expired'
        ) {
            /*
             * Una firma vencida sigue demostrando qué copia publicó el
             * marcador y puede renovarse contra MariaDB. Una firma dañada,
             * un JSON truncado o una clave ausente no demuestran identidad:
             * el lanzador queda en remote=false y espera que un administrador
             * elija explícitamente Recuperar, Cancelar o Limpiar restos.
             */
            return $this->result(
                'blocked',
                'coordinator_guided_repair_required',
                $fallbackBackupId,
                $fallbackPublicId
            );
        }
        if ($fallbackBackupId < 1 && $legacy !== null) {
            $fallbackBackupId = $legacy['backup_id'];
            $fallbackPublicId = $legacy['public_id'];
        }

        try {
            $archive = $this->resolveArchive(
                $pdo,
                $coordinatorInspection,
                $legacy,
                $snapshot
            );
        } catch (Throwable) {
            return $this->result(
                'blocked',
                'backup_state_unavailable',
                $fallbackBackupId,
                $fallbackPublicId
            );
        }

        if ($archive === null) {
            if (
                $coordinatorInspection['exists']
                || $legacy !== null
                || $snapshot !== []
            ) {
                return $this->result(
                    'blocked',
                    'backup_reference_not_reconciled',
                    $fallbackBackupId,
                    $fallbackPublicId
                );
            }
            return $this->result('idle', 'no_local_backup', 0, '');
        }

        $backupId = (int) $archive['id'];
        $publicId = (string) $archive['public_id'];
        $archiveStatus = (string) $archive['status'];
        $job = $this->jobForArchive($pdo, $backupId);

        if (in_array($archiveStatus, ['ready', 'failed', 'deleted'], true)) {
            try {
                $this->removeOwnedSnapshot($publicId);
                if (
                    $legacy !== null
                    && $legacy['backup_id'] === $backupId
                    && hash_equals($legacy['public_id'], $publicId)
                ) {
                    $legacyService->clearIfMatches($backupId, $publicId);
                }
                $this->clearCoordinatorIfMatches($backupId, $publicId);
            } catch (Throwable) {
                return $this->result(
                    'blocked',
                    'terminal_backup_marker_release_failed',
                    $backupId,
                    $publicId
                );
            }
            return $this->result(
                'terminal_reconciled',
                'terminal_backup_released',
                $backupId,
                $publicId
            );
        }

        if (!in_array(
            $archiveStatus,
            [
                'prepared', 'queued', 'creating', 'verifying',
                'ready_pending_release', 'cancel_requested', 'deleting',
            ],
            true
        )) {
            return $this->result(
                'blocked',
                'backup_archive_state_not_supported',
                $backupId,
                $publicId
            );
        }
        if ($job === null || !in_array((string) $job['status'], ['pending', 'running'], true)) {
            return $this->result(
                'blocked',
                'backup_job_not_runnable',
                $backupId,
                $publicId
            );
        }
        if (
            $legacy !== null
            && (
                $legacy['backup_id'] !== $backupId
                || !hash_equals($legacy['public_id'], $publicId)
            )
        ) {
            return $this->result(
                'blocked',
                'backup_request_conflict',
                $backupId,
                $publicId
            );
        }

        try {
            // El marcador firmado se publica primero. Si el proceso termina
            // antes de la señal legacy, el ciclo siguiente puede regenerarla.
            $this->publishBackup(
                $backupId,
                $publicId,
                (string) $job['status'],
                (string) ($job['lease_owner'] ?? ''),
                max(0, (int) ($job['lease_generation'] ?? 0)),
                (string) ($job['job_type'] ?? 'create'),
                true
            );
            if (
                $legacy !== null
                && $legacy['backup_id'] === $backupId
                && hash_equals($legacy['public_id'], $publicId)
            ) {
                $legacyService->clearIfMatches($backupId, $publicId);
            }
        } catch (Throwable) {
            return $this->result(
                'blocked',
                'backup_request_publish_failed',
                $backupId,
                $publicId
            );
        }

        return $this->result(
            'runnable',
            in_array($archiveStatus, ['deleting', 'ready_pending_release'], true)
                ? 'backup_cleanup_pending'
                : ((string) $job['status'] === 'running'
                    ? 'backup_job_resumable'
                    : 'backup_job_pending'),
            $backupId,
            $publicId
        );
    }

    public function publishBackup(
        int $backupId,
        string $publicId,
        string $state,
        string $owner = '',
        int $generation = 0,
        string $phase = 'create',
        bool $freezeActive = true
    ): void
    {
        if ($backupId < 1 || preg_match('/^[a-f0-9-]{16,64}$/i', $publicId) !== 1) {
            throw new RuntimeException('La referencia local de la copia no es válida.');
        }
        $now = time();
        $payload = [
            'version' => self::VERSION,
            'task' => self::TASK_BACKUP,
            'backup_id' => $backupId,
            'public_id' => strtolower($publicId),
            'state' => preg_replace('/[^a-z0-9_-]/', '_', strtolower($state)) ?: 'pending',
            'owner' => substr(
                preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $owner) ?: '',
                0,
                96
            ),
            'generation' => max(0, $generation),
            'phase' => substr(
                preg_replace('/[^a-z0-9_-]/', '_', strtolower($phase)) ?: 'create',
                0,
                40
            ),
            'freeze_active' => $freezeActive,
            'issued_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + self::MAX_LIFETIME_SECONDS,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $payload['signature'] = hash_hmac(
            'sha256',
            self::canonicalWithoutSignature($payload),
            $this->signingKey()
        );
        $this->atomicWrite($this->markerPath(), $payload);
    }

    /**
     * @param array<string,mixed> $coordinator
     * @param array{backup_id:int,public_id:string}|null $legacy
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    private function resolveArchive(
        PDO $pdo,
        array $coordinator,
        ?array $legacy,
        array $snapshot
    ): ?array {
        /*
         * Una firma vencida o dañada nunca autoriza trabajo, pero su identidad
         * saneada puede usarse como pista para consultar la fila exacta. Solo
         * MariaDB y un job ejecutable vuelven a publicar una autorización.
         */
        $backupId = (int) ($coordinator['backup_id'] ?? 0);
        $publicId = (string) ($coordinator['public_id'] ?? '');
        if ($legacy !== null) {
            if (
                $backupId > 0
                && (
                    $backupId !== $legacy['backup_id']
                    || !hash_equals($publicId, strtolower($legacy['public_id']))
                )
            ) {
                return null;
            }
            $backupId = $legacy['backup_id'];
            $publicId = strtolower($legacy['public_id']);
        }

        if ($backupId > 0) {
            $statement = $pdo->prepare(
                'SELECT id,public_id,status,deleted_at
                   FROM system_backup_archives
                  WHERE id=:id
                  LIMIT 1'
            );
            $statement->execute(['id' => $backupId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($row)
                || ($publicId !== '' && !hash_equals(strtolower((string) $row['public_id']), $publicId))
            ) {
                return null;
            }
            return $row;
        }

        $snapshotId = strtolower((string) ($snapshot['snapshot_id'] ?? ''));
        if (preg_match('/^[a-f0-9]{4,24}$/', $snapshotId) !== 1) {
            return null;
        }
        $rows = $pdo->query(
            'SELECT id,public_id,status,deleted_at
               FROM system_backup_archives
              ORDER BY id DESC
              LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (
                is_array($row)
                && hash_equals($snapshotId, self::snapshotId((string) $row['public_id']))
            ) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function jobForArchive(PDO $pdo, int $backupId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT j.id,j.job_type,j.status,j.lease_owner,j.lease_generation,j.lease_expires_at,
                    j.checkpoint_json
               FROM system_backup_jobs j
               JOIN system_backup_archives b ON b.id=j.backup_id
              WHERE j.backup_id=:backup_id
                AND j.status IN ("pending","running","cancel_requested")
              ORDER BY
                    CASE
                      WHEN b.status IN ("cancel_requested","deleting","ready_pending_release") AND j.job_type="cleanup" THEN 0
                      WHEN b.status NOT IN ("cancel_requested","deleting","ready_pending_release") AND j.job_type IN ("create","verify") THEN 0
                      ELSE 1
                    END,
                    CASE j.status WHEN "running" THEN 0 ELSE 1 END,
                    j.id DESC
              LIMIT 1'
        );
        $statement->execute(['backup_id' => $backupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function snapshotMarker(): array
    {
        return self::readJsonFile(
            AppPaths::storage('cache/database-snapshot-active.json')
        );
    }

    private function removeOwnedSnapshot(string $publicId): void
    {
        $path = AppPaths::storage('cache/database-snapshot-active.json');
        $lock = $this->exclusiveLock($path . '.lock');
        try {
            $marker = self::readJsonFile($path);
            if ($marker === []) {
                return;
            }
            if (!hash_equals(self::snapshotId($publicId), strtolower((string) ($marker['snapshot_id'] ?? '')))) {
                throw new RuntimeException('El snapshot pertenece a otra copia.');
            }
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('No fue posible liberar el snapshot terminado.');
            }
            clearstatcache(true, $path);
            if (is_file($path)) {
                throw new RuntimeException('El snapshot terminado continúa activo.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function clearCoordinatorIfMatches(int $backupId, string $publicId): void
    {
        $path = $this->markerPath();
        $lock = $this->exclusiveLock($path . '.lock');
        try {
            $inspection = self::inspectBeforeBootstrap(
                AppPaths::sharedRoot(),
                AppPaths::installationRoot()
            );
            $raw = [];
            if ($inspection['exists'] && !$inspection['valid']) {
                $raw = self::readJsonFile($path);
            }
            if (
                !$inspection['exists']
                || (
                    $inspection['valid']
                    && (
                        $inspection['backup_id'] !== $backupId
                        || !hash_equals($inspection['public_id'], strtolower($publicId))
                    )
                )
                || (
                    !$inspection['valid']
                    && (
                        (int) ($raw['backup_id'] ?? 0) !== $backupId
                        || !hash_equals(
                            strtolower((string) ($raw['public_id'] ?? '')),
                            strtolower($publicId)
                        )
                    )
                )
            ) {
                return;
            }
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('No fue posible liberar la coordinación terminada.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $payload */
    private function atomicWrite(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar la coordinación local.');
        }
        $lock = $this->exclusiveLock($path . '.lock');
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new RuntimeException('No fue posible publicar la coordinación local.');
            }
            @chmod($temporary, 0640);
            $renamed = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if (@rename($temporary, $path)) {
                    $renamed = true;
                    break;
                }
                /*
                 * En Windows rename() no reemplaza siempre el destino aunque
                 * tengamos el lock de coordinación. El lock impide lectores
                 * administrativos concurrentes de esta clase; retiramos el
                 * marcador anterior únicamente después de haber escrito el
                 * temporal completo y firmado.
                 */
                if (is_file($path)) {
                    @unlink($path);
                }
                if (@rename($temporary, $path)) {
                    $renamed = true;
                    break;
                }
                usleep(50000);
            }
            if (!$renamed) {
                throw new RuntimeException('No fue posible publicar la coordinación local.');
            }
        } finally {
            @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function exclusiveLock(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el lock local.');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('No fue posible adquirir el lock local.');
        }
        return $handle;
    }

    private function markerPath(): string
    {
        return self::markerPathFromRoot(AppPaths::sharedRoot());
    }

    private function signingKey(): string
    {
        $appKey = trim((string) Env::get('APP_KEY', ''));
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY no está configurada.');
        }
        return self::keyFromAppKey($appKey);
    }

    /** @return array<string,mixed> */
    private static function readJsonFile(string $path): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_MARKER_BYTES) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true, 12);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload */
    private static function invalidPayloadReason(array $payload, string $appKey): string
    {
        $signature = strtolower((string) ($payload['signature'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return 'coordinator_signature_invalid';
        }
        $unsigned = $payload;
        unset($unsigned['signature']);
        $version = (int) ($unsigned['version'] ?? 0);
        $issuedAt = (int) ($unsigned['issued_at'] ?? 0);
        $updatedAt = (int) ($unsigned['updated_at'] ?? 0);
        $expiresAt = (int) ($unsigned['expires_at'] ?? 0);
        $now = time();
        if (
            !in_array($version, [self::LEGACY_VERSION, self::VERSION], true)
            || (string) ($unsigned['task'] ?? '') !== self::TASK_BACKUP
            || (int) ($unsigned['backup_id'] ?? 0) < 1
            || preg_match('/^[a-f0-9-]{16,64}$/', (string) ($unsigned['public_id'] ?? '')) !== 1
            || !in_array(
                (string) ($unsigned['state'] ?? ''),
                [
                    'prepared', 'queued', 'pending', 'running', 'creating',
                    'verifying', 'ready_pending_release', 'cancel_requested',
                    'deleting',
                ],
                true
            )
            || $issuedAt < $now - self::MAX_LIFETIME_SECONDS
            || $issuedAt > $now + 300
            || $updatedAt < $issuedAt
            || $updatedAt > $now + 300
            || $expiresAt > $issuedAt + self::MAX_LIFETIME_SECONDS
        ) {
            return $expiresAt > 0 && $expiresAt < $now
                ? 'coordinator_expired'
                : 'coordinator_payload_invalid';
        }
        $expected = hash_hmac(
            'sha256',
            self::canonicalWithoutSignature($unsigned),
            self::keyFromAppKey($appKey)
        );
        if (!hash_equals($expected, $signature)) {
            return 'coordinator_signature_invalid';
        }
        if ($expiresAt < $now) {
            return 'coordinator_expired';
        }
        return '';
    }

    /** @param array<string,mixed> $payload */
    private static function canonicalWithoutSignature(array $payload): string
    {
        unset($payload['signature']);
        ksort($payload);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function prebootstrapAppKey(
        string $sharedRoot,
        string $installationRoot
    ): string {
        $environment = getenv('APP_KEY');
        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }
        foreach (array_unique([
            rtrim($sharedRoot, '/\\') . '/config.env',
            rtrim($installationRoot, '/\\') . '/config.env',
            rtrim($installationRoot, '/\\') . '/.env',
        ]) as $path) {
            $handle = @fopen($path, 'rb');
            if (!is_resource($handle)) {
                continue;
            }
            try {
                while (($line = fgets($handle, 8192)) !== false) {
                    if (preg_match('/^\s*(?:export\s+)?APP_KEY\s*=\s*(.*)\s*$/', $line, $matches) !== 1) {
                        continue;
                    }
                    $value = trim((string) $matches[1]);
                    if (
                        strlen($value) >= 2
                        && (
                            ($value[0] === '"' && str_ends_with($value, '"'))
                            || ($value[0] === "'" && str_ends_with($value, "'"))
                        )
                    ) {
                        $value = substr($value, 1, -1);
                    }
                    return trim(str_replace(['\\"', "\\'"], ['"', "'"], $value));
                }
            } finally {
                fclose($handle);
            }
        }
        return '';
    }

    private static function keyFromAppKey(string $appKey): string
    {
        return hash('sha256', 'local-maintenance-coordinator|' . $appKey, true);
    }

    private static function markerPathFromRoot(string $sharedRoot): string
    {
        return rtrim($sharedRoot, '/\\')
            . '/storage/cache/local-maintenance-state.json';
    }

    private static function snapshotId(string $publicId): string
    {
        return substr(
            preg_replace('/[^a-f0-9]/i', '', strtolower($publicId)) ?: 'backup',
            0,
            24
        );
    }

    /**
     * @return array{
     *   exists:bool,valid:bool,task:string,backup_id:int,public_id:string,
     *   state:string,owner:string,generation:int,phase:string,
     *   freeze_active:bool,expires_at:int,reason:string
     * }
     */
    private static function emptyInspection(): array
    {
        return [
            'exists' => false,
            'valid' => false,
            'task' => '',
            'backup_id' => 0,
            'public_id' => '',
            'state' => '',
            'owner' => '',
            'generation' => 0,
            'phase' => '',
            'freeze_active' => false,
            'expires_at' => 0,
            'reason' => '',
        ];
    }

    /**
     * @return array{
     *   exists:bool,valid:bool,task:string,backup_id:int,public_id:string,
     *   state:string,owner:string,generation:int,phase:string,
     *   freeze_active:bool,expires_at:int,reason:string
     * }
     */
    private static function invalidInspection(string $reason, array $payload = []): array
    {
        $inspection = self::emptyInspection();
        $inspection['exists'] = true;
        $backupId = max(0, (int) ($payload['backup_id'] ?? 0));
        $publicId = strtolower((string) ($payload['public_id'] ?? ''));
        if (
            $backupId > 0
            && preg_match('/^[a-f0-9-]{16,64}$/', $publicId) === 1
        ) {
            $inspection['backup_id'] = $backupId;
            $inspection['public_id'] = $publicId;
        }
        $inspection['reason'] = $reason;
        return $inspection;
    }

    /**
     * @return array{
     *   state:'runnable'|'terminal_reconciled'|'idle'|'blocked',
     *   reason:string,backup_id:int,public_id:string,remote:false
     * }
     */
    private function result(
        string $state,
        string $reason,
        int $backupId,
        string $publicId
    ): array {
        /** @var 'runnable'|'terminal_reconciled'|'idle'|'blocked' $state */
        return [
            'state' => $state,
            'reason' => $reason,
            'backup_id' => max(0, $backupId),
            'public_id' => strtolower($publicId),
            'remote' => false,
        ];
    }
}
