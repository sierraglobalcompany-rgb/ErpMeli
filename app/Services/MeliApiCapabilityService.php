<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliApiCapabilityService
{
    public function status(int $accountId, string $endpoint, string $capability): ?array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('meli_api_capabilities')) {
                return null;
            }
            $stmt = Database::connection()->prepare(
                'SELECT * FROM meli_api_capabilities
                 WHERE meli_account_id=:account AND endpoint_path=:endpoint AND capability=:capability
                 LIMIT 1'
            );
            $stmt->execute(['account' => $accountId, 'endpoint' => $endpoint, 'capability' => $capability]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function isCoolingDown(int $accountId, string $endpoint, string $capability): bool
    {
        $row = $this->status($accountId, $endpoint, $capability);
        if (!$row || empty($row['cooldown_until'])) {
            return false;
        }
        return strtotime((string) $row['cooldown_until']) > time();
    }

    public function mark(
        int $accountId,
        string $endpoint,
        string $capability,
        string $status,
        ?string $safeError = null,
        ?int $cooldownMinutes = null
    ): void {
        try {
            if (!(new SchemaInspectorService())->hasTable('meli_api_capabilities')) {
                return;
            }
            $cooldown = $cooldownMinutes !== null && $cooldownMinutes > 0 ? gmdate('Y-m-d H:i:s', time() + ($cooldownMinutes * 60)) : null;
            $stmt = Database::connection()->prepare(
                'INSERT INTO meli_api_capabilities
                 (meli_account_id,endpoint_path,capability,status,last_checked_at,last_error_message,cooldown_until)
                 VALUES (:account,:endpoint,:capability,:status,UTC_TIMESTAMP(),:error,:cooldown)
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    last_checked_at=VALUES(last_checked_at),
                    last_error_message=VALUES(last_error_message),
                    cooldown_until=VALUES(cooldown_until),
                    updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'account' => $accountId,
                'endpoint' => $endpoint,
                'capability' => $capability,
                'status' => $status,
                'error' => $safeError !== null ? mb_substr($safeError, 0, 500) : null,
                'cooldown' => $cooldown,
            ]);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo guardar capacidad API Mercado Libre.', [
                'account_id' => $accountId,
                'endpoint' => $endpoint,
                'capability' => $capability,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
