<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Crypto;
use PDO;
use RuntimeException;

final class RestoreConfigSwitchService
{
    public function switch(int $restoreId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Explique el motivo del cambio de base.');
        }
        $restore = (new RestoreService())->plan($restoreId);
        if ((string) $restore['mode'] !== 'production_recovery' || (string) $restore['status'] !== 'ready_to_switch') {
            throw new RuntimeException('La restauración todavía no está lista para cambiar el ERP.');
        }
        $store = new RestoreSecretStoreService();
        $target = $store->get((string) $restore['target_secret_name']);
        $this->test($target);
        $configPath = AppPaths::configFile();
        $current = @file_get_contents($configPath);
        if (!is_string($current) || $current === '') {
            throw new RuntimeException('No fue posible leer la configuración activa.');
        }
        $directory = $store->directory();
        $previousName = 'config-before-restore-' . (int) $restoreId . '.secret';
        $previousPath = $directory . '/' . $previousName;
        if (@file_put_contents($previousPath, Crypto::encrypt($current), LOCK_EX) === false) {
            throw new RuntimeException('No fue posible cifrar la configuración anterior.');
        }
        @chmod($previousPath, 0600);
        $next = $this->replaceDatabase($current, $target);
        $temporary = $configPath . '.next';
        if (@file_put_contents($temporary, $next, LOCK_EX) === false) {
            throw new RuntimeException('No fue posible preparar la configuración nueva.');
        }
        @chmod($temporary, 0600);
        $pdo = \App\Core\Database::connection();
        $configurationChanged = false;
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO system_restore_switches
                 (restore_id,switched_by,reason,previous_config_name,next_config_sha256,status)
                 VALUES (:restore_id,:user_id,:reason,:previous,:checksum,'prepared')"
            )->execute([
                'restore_id' => $restoreId,
                'user_id' => $userId,
                'reason' => mb_substr(trim($reason), 0, 500),
                'previous' => $previousName,
                'checksum' => hash('sha256', $next),
            ]);
            if (!@rename($temporary, $configPath)) {
                throw new RuntimeException('No fue posible activar la configuración nueva de forma atómica.');
            }
            $configurationChanged = true;
            $pdo->prepare(
                "UPDATE system_restore_switches SET status='switched',switched_at=UTC_TIMESTAMP(3)
                 WHERE restore_id=:id"
            )->execute(['id' => $restoreId]);
            $pdo->prepare(
                "UPDATE system_restore_plans SET status='switched',completed_at=UTC_TIMESTAMP(3)
                 WHERE id=:id"
            )->execute(['id' => $restoreId]);
            $pdo->commit();
            $store->delete((string) $restore['target_secret_name']);
            (new SessionGenerationService())->rotate();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($temporary);
            if ($configurationChanged) {
                $rollback = $configPath . '.failed-switch-rollback';
                if (@file_put_contents($rollback, $current, LOCK_EX) !== false) {
                    @chmod($rollback, 0600);
                    @rename($rollback, $configPath);
                }
            }
            throw $error;
        }
    }

    public function rollbackLatest(): void
    {
        $directory = (new RestoreSecretStoreService())->directory();
        $candidates = glob($directory . '/config-before-restore-*.secret') ?: [];
        usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $path = $candidates[0] ?? null;
        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException('No existe una configuración anterior disponible.');
        }
        $encoded = (string) @file_get_contents($path);
        $previous = Crypto::decrypt($encoded);
        $configPath = AppPaths::configFile();
        $temporary = $configPath . '.rollback';
        if (@file_put_contents($temporary, $previous, LOCK_EX) === false || !@rename($temporary, $configPath)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible restaurar la configuración anterior.');
        }
        @chmod($configPath, 0600);
    }

    /** @param array<string,string> $target */
    private function test(array $target): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $target['host'], $target['port'], $target['database']),
            $target['user'],
            $target['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $version = (string) $pdo->query(
            "SELECT version FROM app_versions ORDER BY installed_at DESC,id DESC LIMIT 1"
        )->fetchColumn();
        if ($version === '') {
            throw new RuntimeException('La base restaurada no contiene una versión verificable.');
        }
    }

    /** @param array<string,string> $target */
    private function replaceDatabase(string $config, array $target): string
    {
        $values = [
            'DB_HOST' => $target['host'],
            'DB_PORT' => $target['port'],
            'DB_NAME' => $target['database'],
            'DB_USER' => $target['user'],
            'DB_PASS' => $target['password'],
        ];
        foreach ($values as $key => $value) {
            $line = $key . '=' . $this->encodeEnv($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            $config = preg_match($pattern, $config) === 1
                ? (string) preg_replace($pattern, $line, $config)
                : rtrim($config) . "\n" . $line . "\n";
        }
        return $config;
    }

    private function encodeEnv(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
    }
}
