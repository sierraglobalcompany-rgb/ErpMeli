<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AppVersionService
{
    private static ?string $fileVersion = null;

    public static function fileVersion(): string
    {
        if (self::$fileVersion !== null) {
            return self::$fileVersion;
        }
        $file = dirname(__DIR__, 2) . '/VERSION';
        return self::$fileVersion = is_file($file) ? trim((string) file_get_contents($file)) : 'desconocida';
    }

    public function installedVersion(): string
    {
        try {
            $stmt = Database::connection()->query(
                "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
            );
            $version = $stmt->fetchColumn();
            if (is_string($version) && trim($version) !== '') {
                return trim($version);
            }

            // Compatibilidad con instalaciones anteriores a app.version.
            // app_versions es historial y no debe ser la autoridad cuando la
            // clave canónica ya existe.
            $stmt = Database::connection()->query(
                'SELECT version FROM app_versions ORDER BY installed_at DESC,id DESC LIMIT 1'
            );
            $version = $stmt->fetchColumn();
            return is_string($version) && trim($version) !== '' ? trim($version) : 'sin registrar';
        } catch (Throwable) {
            return 'sin registrar';
        }
    }

    public function registerCurrent(?string $notes = null): void
    {
        $this->registerVersion(self::fileVersion(), $notes);
    }

    public function registerVersion(string $version, ?string $notes = null): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO app_versions (version,notes,installed_at)
                 VALUES (:version,:notes,CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    notes=VALUES(notes),
                    installed_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute(['version' => $version, 'notes' => $notes]);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo registrar la versión instalada.', ['error' => $e->getMessage()]);
        }
    }
}
