<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Exclusión corta para coordinar el mantenimiento CLI heredado con el centro
 * interactivo. El lock es de conexión y nunca se conserva entre peticiones.
 */
final class MaintenanceExecutionLock
{
    private const NAME = 'erp_meli_storage_maintenance';
    private bool $held = false;

    public function acquire(): void
    {
        $stmt = Database::connection()->prepare('SELECT GET_LOCK(:lock_name,0)');
        $stmt->execute(['lock_name' => self::NAME]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Otro proceso de mantenimiento está terminando un lote.');
        }
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        try {
            $stmt = Database::connection()->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute(['lock_name' => self::NAME]);
        } catch (Throwable) {
            // Al perder la conexión MariaDB libera también sus advisory locks.
        } finally {
            $this->held = false;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
