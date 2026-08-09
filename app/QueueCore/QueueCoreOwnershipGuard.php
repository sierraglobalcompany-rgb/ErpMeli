<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
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
}
