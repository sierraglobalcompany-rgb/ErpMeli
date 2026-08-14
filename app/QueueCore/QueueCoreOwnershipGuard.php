<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\MeliTransportSourcePolicy;
use PDO;
use PDOException;
use Throwable;

final class QueueCoreOwnershipGuard
{
    public static function v4OwnsWebhook(?PDO $pdo = null): bool
    {
        try {
            $snapshot = (new QueueEngineControlService($pdo ?? Database::connectionFresh()))->snapshot();
            return $snapshot['active_engine'] === 'v4';
        } catch (Throwable $error) {
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1146) {
                // Antes de aplicar B1.4 no existe un motor V4 que pueda ser dueño.
                return false;
            }
            // La ausencia o indisponibilidad de la autoridad nunca habilita
            // por accidente un consumidor legacy con transporte remoto.
            return true;
        }
    }

    /** @return array<string,mixed> */
    public static function skippedResult(): array
    {
        return [
            'status' => 'skipped',
            'processed' => 0,
            'ignored' => 0,
            'errors' => 0,
            'skipped' => true,
            'stop_reason' => 'SKIPPED_V4_OWNER',
            'http' => 0,
        ];
    }

    /** @param array<string,mixed> $metadata */
    public static function assertLegacyTransportAllowed(
        string $method,
        string $path,
        array $metadata,
        ?PDO $pdo = null,
    ): void {
        $source = (string) ($metadata['source'] ?? '');
        if ($source === 'queue_v4_clean_readiness'
            || $source === MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR
            || MeliTransportSourcePolicy::requiresQueueV4ReadFence($source)
            || MeliTransportSourcePolicy::requiresCurrentOAuthFence($source)) {
            return;
        }
        if (!self::v4OwnsWebhook($pdo)) {
            return;
        }
        if ($source === 'queue_core') {
            return;
        }
        $normalized = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');
        // Initial OAuth authorization and the audited emergency flows are
        // technical control-plane operations, not competing business owners.
        if ($normalized === '/oauth/token'
            && in_array($source, ['web', 'manual_emergency_oauth_refresh'], true)) {
            return;
        }
        if ($source === 'manual_emergency_canary' && $normalized === '/users/me'
            && strtoupper($method) === 'GET') {
            return;
        }
        throw new QueueCorePreRemoteBlockedException('SKIPPED_V4_OWNER');
    }
}
