<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;
    private static int $reconnectCount = 0;
    private static ?string $lastReconnectAt = null;
    private static string $profile = 'default';
    private static bool $connectionUnavailable = false;

    /**
     * Selecciona límites de conexión antes de abrir PDO.
     *
     * web/diagnostic fallan rápido para que una base lenta no bloquee la
     * navegación. migration conserva una conexión exclusiva apta para DDL.
     */
    public static function useProfile(string $profile): void
    {
        $allowed = ['default', 'web', 'diagnostic', 'cli', 'migration'];
        if (!in_array($profile, $allowed, true)) {
            throw new RuntimeException('Perfil de base de datos no válido.');
        }
        if (self::$profile === $profile) {
            return;
        }
        if (self::$connection instanceof PDO && self::$connection->inTransaction()) {
            throw new RuntimeException('No se puede cambiar el perfil durante una transacción.');
        }
        self::$connection = null;
        self::$profile = $profile;
        self::$connectionUnavailable = false;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', 'localhost'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', 'erp_meli')
        );
        $timeout = self::connectTimeout();
        try {
            self::$connection = new PDO($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => $timeout,
            ]);
            self::configureConnection(self::$connection);
            self::$connectionUnavailable = false;
            return self::$connection;
        } catch (Throwable $error) {
            self::$connection = null;
            self::$connectionUnavailable = true;
            throw $error;
        }
    }

    public static function connectionFresh(): PDO
    {
        // Compatibilidad histórica: "fresh" ya no implica SELECT 1. Una
        // petición comparte un único PDO y solo reconecta ante un error real
        // 2006/2013/4031 mediante executeWithReconnect().
        return self::connection();
    }

    public static function ping(): bool
    {
        if (!(self::$connection instanceof PDO)) {
            return false;
        }

        try {
            self::$connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            if (self::isLostConnection($e)) {
                if (self::$connection->inTransaction()) {
                    throw $e;
                }
                self::$connection = null;
                return false;
            }
            throw $e;
        }
    }

    public static function reconnect(): PDO
    {
        if (self::$connection instanceof PDO && self::$connection->inTransaction()) {
            throw new RuntimeException('No se puede reconectar MySQL durante una transaccion activa.');
        }

        self::$connection = null;
        self::$reconnectCount++;
        self::$lastReconnectAt = gmdate('Y-m-d H:i:s');
        return self::connection();
    }

    public static function profile(): string
    {
        return self::$profile;
    }

    public static function connectionUnavailable(): bool
    {
        return self::$connectionUnavailable;
    }

    /**
     * @template T
     * @param callable(PDO):T $operation
     * @return T
     */
    public static function executeWithReconnect(callable $operation): mixed
    {
        try {
            return $operation(self::connection());
        } catch (PDOException $e) {
            if (!self::isLostConnection($e)) {
                throw $e;
            }
            if (self::$connection instanceof PDO && self::$connection->inTransaction()) {
                throw $e;
            }
            return $operation(self::reconnect());
        }
    }

    public static function isLostConnection(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (
            str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'disconnected by the server because of inactivity')
            || str_contains($message, 'wait_timeout')
        ) {
            return true;
        }
        if (
            str_contains($message, 'error: 2006')
            || str_contains($message, 'error: 2013')
            || str_contains($message, 'error: 4031')
        ) {
            return true;
        }
        if ($e instanceof PDOException) {
            $info = $e->errorInfo ?? [];
            $driverCode = (string) ($info[1] ?? '');
            return in_array($driverCode, ['2006', '2013', '4031'], true);
        }
        return false;
    }

    public static function reconnectCount(): int
    {
        return self::$reconnectCount;
    }

    public static function lastReconnectAt(): ?string
    {
        return self::$lastReconnectAt;
    }

    /**
     * @return array{
     *   character_set_client:string,
     *   character_set_connection:string,
     *   character_set_results:string,
     *   collation_connection:string,
     *   expected_collation:string,
     *   matches_expected:bool
     * }
     */
    public static function sessionCharacterSet(): array
    {
        $row = self::connectionFresh()->query(
            'SELECT
               @@SESSION.character_set_client AS character_set_client,
               @@SESSION.character_set_connection AS character_set_connection,
               @@SESSION.character_set_results AS character_set_results,
               @@SESSION.collation_connection AS collation_connection'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $expected = 'utf8mb4_unicode_ci';
        return [
            'character_set_client' => (string) ($row['character_set_client'] ?? ''),
            'character_set_connection' => (string) ($row['character_set_connection'] ?? ''),
            'character_set_results' => (string) ($row['character_set_results'] ?? ''),
            'collation_connection' => (string) ($row['collation_connection'] ?? ''),
            'expected_collation' => $expected,
            'matches_expected' => (string) ($row['collation_connection'] ?? '') === $expected,
        ];
    }

    public static function setConnection(PDO $connection): void
    {
        self::$connection = $connection;
        self::$connectionUnavailable = false;
    }

    private static function configureConnection(PDO $connection): void
    {
        $connection->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $connection->exec("SET SESSION collation_connection='utf8mb4_unicode_ci'");
        // Todas las columnas DATETIME del ERP representan instantes UTC.
        // Esto también vuelve coherentes los DEFAULT CURRENT_TIMESTAMP con
        // las escrituras explícitas que usan UTC_TIMESTAMP().
        $connection->exec("SET SESSION time_zone='+00:00'");
        if (in_array(self::$profile, ['web', 'diagnostic'], true)) {
            $seconds = max(1, min(8, (int) Env::get('DB_WEB_QUERY_TIMEOUT_SECONDS', '5')));
            try {
                // MariaDB: límite por sentencia expresado en segundos.
                $connection->exec('SET SESSION max_statement_time=' . $seconds);
            } catch (Throwable) {
                try {
                    // MySQL: equivalente en milisegundos.
                    $connection->exec('SET SESSION max_execution_time=' . ($seconds * 1000));
                } catch (Throwable) {
                    // El timeout de conexión sigue siendo efectivo.
                }
            }
        }
    }

    private static function connectTimeout(): int
    {
        return match (self::$profile) {
            'web', 'diagnostic' => max(1, min(3, (int) Env::get('DB_WEB_CONNECT_TIMEOUT_SECONDS', '3'))),
            'cli' => max(1, min(10, (int) Env::get('DB_CLI_CONNECT_TIMEOUT_SECONDS', '5'))),
            'migration' => max(5, min(30, (int) Env::get('DB_MIGRATION_CONNECT_TIMEOUT_SECONDS', '15'))),
            default => max(1, min(10, (int) Env::get('DB_CONNECT_TIMEOUT_SECONDS', '5'))),
        };
    }
}
