<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use PDO;
use RuntimeException;
use Throwable;

final class DirectUpdateMetadataPromotionService
{
    private const LOCK_NAME = 'erp_meli_direct_update_metadata';

    /** @return array{previous_version:string,target_version:string,marker_version:string} */
    public function promote(PDO $pdo, string $targetVersion, string $lastMigration, ?string $notes = null): array
    {
        $targetVersion = trim($targetVersion);
        if (preg_match('/^\d+\.\d+\.\d+$/D', $targetVersion) !== 1 || trim($lastMigration) === '') {
            throw new RuntimeException('direct_update_target_invalid');
        }
        if ((bool) $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('direct_update_persistent_connection_refused');
        }

        $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
        $lock->execute(['lock_name' => self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('direct_update_lock_busy');
        }

        $markerSnapshot = $this->markerSnapshot();
        $markerWritten = false;
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $setting = $pdo->query(
                "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1 FOR UPDATE"
            )->fetchColumn();
            $previousVersion = is_string($setting) ? trim($setting) : '';
            if (preg_match('/^\d+\.\d+\.\d+$/D', $previousVersion) !== 1) {
                throw new RuntimeException('direct_update_installed_version_invalid');
            }
            if (version_compare($previousVersion, $targetVersion, '>')) {
                throw new RuntimeException('direct_update_downgrade_refused');
            }

            $history = $pdo->prepare('SELECT id FROM app_versions WHERE version=? LIMIT 1 FOR UPDATE');
            $history->execute([$targetVersion]);
            $targetHistoryId = $history->fetchColumn();

            if (!hash_equals($previousVersion, $targetVersion)) {
                $update = $pdo->prepare(
                    "UPDATE app_settings SET setting_value=:target WHERE setting_key='app.version' AND setting_value=:previous"
                );
                $update->execute(['target' => $targetVersion, 'previous' => $previousVersion]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('direct_update_version_cas_miss');
                }
            }
            if ($targetHistoryId === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO app_versions(version,notes,installed_at) VALUES(:version,:notes,CURRENT_TIMESTAMP)'
                );
                $insert->execute(['version' => $targetVersion, 'notes' => $notes]);
            }

            $marker = new InstalledVersionMarkerService();
            if (!$marker->write($targetVersion, $lastMigration)) {
                throw new RuntimeException('installed_release_marker_write_failed');
            }
            $markerWritten = true;
            $observed = $marker->read();
            if (!(bool) ($observed['valid'] ?? false)
                || !hash_equals($targetVersion, (string) ($observed['version'] ?? ''))
                || !hash_equals($lastMigration, (string) ($observed['last_migration'] ?? ''))
            ) {
                throw new RuntimeException('installed_release_marker_postcheck_failed');
            }

            $pdo->commit();
            return [
                'previous_version' => $previousVersion,
                'target_version' => $targetVersion,
                'marker_version' => (string) $observed['version'],
            ];
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($markerWritten) {
                try {
                    $this->restoreMarker($markerSnapshot);
                } catch (Throwable $restoreFailure) {
                    throw new RuntimeException(
                        $failure->getMessage() . ';direct_update_marker_rollback_failed:' . $restoreFailure->getMessage(),
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => self::LOCK_NAME]);
            } catch (Throwable) {
                // The connection closing also releases the advisory lock.
            }
        }
    }

    /** @return array{exists:bool,bytes:string,mode:int|null} */
    private function markerSnapshot(): array
    {
        $path = AppPaths::storage('installed-release.json');
        if (is_link($path)) {
            throw new RuntimeException('installed_release_marker_symlink_refused');
        }
        if (!file_exists($path)) {
            return ['exists' => false, 'bytes' => '', 'mode' => null];
        }
        if (!is_file($path)) {
            throw new RuntimeException('installed_release_marker_not_regular');
        }
        $bytes = file_get_contents($path);
        $permissions = fileperms($path);
        if (!is_string($bytes) || !is_int($permissions)) {
            throw new RuntimeException('installed_release_marker_snapshot_failed');
        }
        return ['exists' => true, 'bytes' => $bytes, 'mode' => $permissions & 0777];
    }

    /** @param array{exists:bool,bytes:string,mode:int|null} $snapshot */
    private function restoreMarker(array $snapshot): void
    {
        $path = AppPaths::storage('installed-release.json');
        if (is_link($path)) {
            throw new RuntimeException('installed_release_marker_rollback_symlink_refused');
        }
        if (!$snapshot['exists']) {
            if (file_exists($path) && (!is_file($path) || !@unlink($path))) {
                throw new RuntimeException('installed_release_marker_rollback_remove_failed');
            }
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('installed_release_marker_rollback_directory_missing');
        }
        $temporary = $path . '.rollback-' . bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('installed_release_marker_rollback_temp_failed');
        }
        try {
            $written = fwrite($handle, $snapshot['bytes']);
            if ($written !== strlen($snapshot['bytes']) || !fflush($handle)) {
                throw new RuntimeException('installed_release_marker_rollback_write_failed');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('installed_release_marker_rollback_fsync_failed');
            }
        } finally {
            fclose($handle);
        }
        if (is_int($snapshot['mode']) && !@chmod($temporary, $snapshot['mode'])) {
            @unlink($temporary);
            throw new RuntimeException('installed_release_marker_rollback_mode_failed');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('installed_release_marker_rollback_publish_failed');
        }
    }
}
