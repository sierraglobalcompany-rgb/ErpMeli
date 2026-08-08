<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class ModuleMigrationRunner
{
    public function __construct(private readonly ?ModuleRegistry $registry = null)
    {
    }

    /** @return list<string> */
    public function pending(string $moduleId): array
    {
        return $this->pendingAll([$moduleId])[$moduleId] ?? [];
    }

    /**
     * Lee la metadata de migraciones una sola vez para todos los módulos.
     *
     * @param list<string> $moduleIds
     * @return array<string,list<string>>
     */
    public function pendingAll(array $moduleIds): array
    {
        $registry = $this->registry ?? new ModuleRegistry();
        $moduleIds = array_values(array_unique(array_map('strval', $moduleIds)));
        $filesByModule = [];
        $result = [];
        foreach ($moduleIds as $moduleId) {
            $provider = $registry->provider($moduleId);
            if ($provider === null) {
                $result[$moduleId] = [];
                continue;
            }
            $files = glob(rtrim($provider->migrationPath(), '/\\') . '/*.sql') ?: [];
            sort($files);
            $filesByModule[$moduleId] = array_map('basename', $files);
            $result[$moduleId] = $filesByModule[$moduleId];
        }
        if ($filesByModule === []) {
            return $result;
        }
        $applied = [];
        foreach (array_keys($filesByModule) as $moduleId) {
            $applied[$moduleId] = [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($filesByModule), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT module_id,migration_key FROM system_module_migrations
                 WHERE module_id IN ({$placeholders}) AND state IN ('applied','adopted')"
            );
            $stmt->execute(array_keys($filesByModule));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $moduleId = (string) ($row['module_id'] ?? '');
                if (isset($applied[$moduleId])) {
                    $applied[$moduleId][(string) ($row['migration_key'] ?? '')] = true;
                }
            }
        } catch (Throwable) {
            return $result;
        }
        foreach ($filesByModule as $moduleId => $files) {
            $result[$moduleId] = array_values(array_filter(
                $files,
                static fn(string $file): bool => !isset($applied[$moduleId][$file])
            ));
        }
        return $result;
    }

    /** @return list<array{migration:string,status:string}> */
    public function run(string $moduleId): array
    {
        if (!(new ModuleRuntimeReadinessService())->ready(true)) {
            throw new RuntimeException(
                'Complete primero las migraciones centrales 087 y 088 antes de instalar este módulo.'
            );
        }
        $registry = $this->registry ?? new ModuleRegistry();
        $provider = $registry->provider($moduleId);
        if ($provider === null) {
            throw new RuntimeException('Módulo no reconocido.');
        }
        $pdo = Database::connectionFresh();
        $lockName = 'erp_module_' . preg_replace('/[^a-z0-9_-]/', '_', $moduleId);
        $quotedLock = $pdo->quote($lockName);
        if ((int) $pdo->query("SELECT GET_LOCK({$quotedLock},5)")->fetchColumn() !== 1) {
            throw new RuntimeException('Otro proceso está actualizando este módulo.');
        }
        $results = [];
        try {
            $files = glob(rtrim($provider->migrationPath(), '/\\') . '/*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $key = basename($file);
                $checksum = hash_file('sha256', $file);
                if ($checksum === false) {
                    throw new RuntimeException('No fue posible verificar una migración del módulo.');
                }
                $existing = $pdo->prepare('SELECT state,checksum_sha256 FROM system_module_migrations WHERE module_id=? AND migration_key=? LIMIT 1');
                $existing->execute([$moduleId, $key]);
                $row = $existing->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && in_array((string) $row['state'], ['applied', 'adopted'], true)) {
                    if (!hash_equals((string) $row['checksum_sha256'], $checksum)) {
                        throw new RuntimeException('Una migración aplicada del módulo cambió de checksum.');
                    }
                    $results[] = ['migration' => $key, 'status' => 'skip'];
                    continue;
                }
                $this->mark($moduleId, $key, $checksum, 'running', null);
                try {
                    $sql = file_get_contents($file);
                    if ($sql === false) {
                        throw new RuntimeException('No fue posible leer una migración del módulo.');
                    }
                    $pdo->exec($sql);
                    $this->mark($moduleId, $key, $checksum, 'applied', null);
                    $results[] = ['migration' => $key, 'status' => 'applied'];
                } catch (Throwable $error) {
                    $this->mark($moduleId, $key, $checksum, 'failed', mb_substr($error->getMessage(), 0, 500));
                    $pdo->prepare("UPDATE system_modules SET status='degraded',last_error_message=?,updated_at=UTC_TIMESTAMP() WHERE module_id=?")
                        ->execute(['Falló una migración aislada del módulo.', $moduleId]);
                    throw $error;
                }
            }
            $pdo->prepare(
                "UPDATE system_modules SET installed_version=?,migration_version=?,status='disabled',
                 last_error_message=NULL,updated_at=UTC_TIMESTAMP() WHERE module_id=?"
            )->execute([$provider->version(), count($files), $moduleId]);
            $registry->clear();
            return $results;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK({$quotedLock})");
            } catch (Throwable) {
            }
        }
    }

    private function mark(string $moduleId, string $key, string $checksum, string $state, ?string $error): void
    {
        $pdo = Database::connection();
        $lookup = $pdo->prepare('SELECT id FROM system_module_migrations WHERE module_id=? AND migration_key=? LIMIT 1');
        $lookup->execute([$moduleId, $key]);
        $id = $lookup->fetchColumn();
        if ($id === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO system_module_migrations
                 (module_id,migration_key,checksum_sha256,state,attempts,started_at,safe_error_message)
                 VALUES (?,?,?,?,1,UTC_TIMESTAMP(),?)'
            );
            $stmt->execute([$moduleId, $key, $checksum, $state, $error]);
            return;
        }
        $finished = in_array($state, ['applied', 'failed', 'adopted'], true) ? ',finished_at=UTC_TIMESTAMP()' : '';
        $stmt = $pdo->prepare(
            "UPDATE system_module_migrations SET checksum_sha256=?,state=?,safe_error_message=?,
             attempts=attempts+" . ($state === 'running' ? '1' : '0') . "{$finished} WHERE id=?"
        );
        $stmt->execute([$checksum, $state, $error, (int) $id]);
    }
}
