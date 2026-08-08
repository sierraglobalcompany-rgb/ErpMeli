<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class UpdateBackupService
{
    private const MAGIC = "ERP-MELI-BACKUP-1\n";
    private const CHUNK = 1048576;

    /** @return array{id:int,path:string,size:int,checksum:string,adapter:string,tables:int} */
    public function create(int $runId): array
    {
        return $this->createEncrypted($runId);
    }

    /** @return array{id:int,path:string,size:int,checksum:string,adapter:string,tables:int} */
    public function createDirect(): array
    {
        return $this->createEncrypted(null);
    }

    /** @return array{size:int,checksum:string} */
    public function verifyLegacy(string $path, int $expectedTables): array
    {
        return $this->verifyEncrypted($path, $expectedTables);
    }

    public function decryptLegacy(string $source, string $target): void
    {
        $this->decrypt($source, $target);
    }

    /** @return array{id:int,path:string,size:int,checksum:string,adapter:string,tables:int} */
    private function createEncrypted(?int $runId): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('El servidor necesita Sodium para crear un respaldo cifrado.');
        }
        (new UpdateFilesystemService())->ensureDirectories();
        $directory = AppPaths::updateBackups();
        $name = 'safe-' . ($runId ?? 'direct') . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $plain = $directory . '/' . $name . '.sql.gz.tmp';
        $path = $directory . '/' . $name . '.erpbackup';
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "INSERT INTO system_update_backups
             (run_id,backup_type,adapter,status,storage_path,expires_at)
             VALUES (:run_id,'database_config','php_sodium','running',:path,DATE_ADD(UTC_TIMESTAMP(),INTERVAL :days DAY))"
        );
        $stmt->bindValue('run_id', $runId, $runId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('path', $path);
        $stmt->bindValue('days', max(1, (int) (new AppSettingsService())->get('update.retention_backups_days', '30')), PDO::PARAM_INT);
        $stmt->execute();
        $id = (int) $pdo->lastInsertId();

        try {
            $tables = $this->phpDump($plain);
            $this->encrypt($plain, $path);
            @unlink($plain);
            $verification = $this->verifyEncrypted($path, $tables);
            $pdo->prepare(
                "UPDATE system_update_backups SET status='verified',size_bytes=:size,
                 checksum_sha256=:checksum,verified_at=UTC_TIMESTAMP() WHERE id=:id"
            )->execute(['size' => $verification['size'], 'checksum' => $verification['checksum'], 'id' => $id]);
            return [
                'id' => $id,
                'path' => $path,
                'size' => $verification['size'],
                'checksum' => $verification['checksum'],
                'adapter' => 'php_sodium',
                'tables' => $tables,
            ];
        } catch (Throwable $error) {
            try {
                $pdo->prepare(
                    "UPDATE system_update_backups
                     SET status='failed',safe_error_message=:error WHERE id=:id"
                )->execute([
                    'error' => 'El respaldo cifrado no superó su verificación.',
                    'id' => $id,
                ]);
            } catch (Throwable) {
                // El error original conserva prioridad.
            }
            @unlink($plain);
            @unlink($path);
            throw $error;
        }
    }

    private function phpDump(string $path): int
    {
        $pdo = Database::connection();
        $gzip = gzopen($path, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException('No fue posible crear el respaldo temporal.');
        }
        $tableCount = 0;
        try {
            $manifest = [
                'format' => 1,
                'created_at' => gmdate(DATE_ATOM),
                'version' => AppVersionService::fileVersion(),
                'config_b64' => base64_encode((string) @file_get_contents(AppPaths::configFile())),
            ];
            gzwrite($gzip, "-- ERP backup UTC " . gmdate('c') . "\n");
            gzwrite($gzip, '-- ERP manifest ' . base64_encode(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n");
            gzwrite($gzip, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
                if (!is_array($create) || !isset($create[1])) {
                    throw new RuntimeException('No se pudo leer la estructura completa del respaldo.');
                }
                $tableCount++;
                gzwrite($gzip, "DROP TABLE IF EXISTS {$quoted};\n" . $create[1] . ";\n");
                $query = $pdo->query('SELECT * FROM ' . $quoted, PDO::FETCH_ASSOC);
                $columns = null;
                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    $columns ??= array_keys($row);
                    $values = array_map(
                        static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                        array_values($row)
                    );
                    $columnSql = implode(',', array_map(
                        static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
                        $columns
                    ));
                    gzwrite($gzip, "INSERT INTO {$quoted} ({$columnSql}) VALUES (" . implode(',', $values) . ");\n");
                }
                gzwrite($gzip, "\n");
            }
            gzwrite($gzip, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzwrite($gzip, '-- ERP BACKUP COMPLETE tables=' . $tableCount . ' at=' . gmdate(DATE_ATOM) . "\n");
        } finally {
            gzclose($gzip);
        }
        return $tableCount;
    }

    private function encrypt(string $source, string $target): void
    {
        $key = $this->encryptionKey();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('No fue posible abrir el respaldo para cifrarlo.');
        }
        try {
            fwrite($output, self::MAGIC . $header);
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('No fue posible leer el respaldo temporal.');
                }
                $final = feof($input);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $final
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                fwrite($output, pack('N', strlen($cipher)) . $cipher);
            }
            fflush($output);
        } finally {
            fclose($input);
            fclose($output);
        }
        @chmod($target, 0600);
    }

    /** @return array{size:int,checksum:string} */
    private function verifyEncrypted(string $path, int $expectedTables): array
    {
        if (!is_file($path) || (int) filesize($path) < 100) {
            throw new RuntimeException('El respaldo cifrado quedó vacío o incompleto.');
        }
        $temporary = $path . '.verify-' . bin2hex(random_bytes(3)) . '.sql.gz';
        $this->decrypt($path, $temporary);
        $gzip = gzopen($temporary, 'rb');
        if ($gzip === false) {
            @unlink($temporary);
            throw new RuntimeException('El respaldo descifrado no se puede abrir.');
        }
        $head = '';
        $tail = '';
        try {
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, self::CHUNK);
                if (!is_string($chunk)) {
                    throw new RuntimeException('No se pudo verificar el contenido del respaldo.');
                }
                if (strlen($head) < 4096) {
                    $head .= $chunk;
                }
                $tail = substr($tail . $chunk, -16384);
            }
        } finally {
            gzclose($gzip);
            @unlink($temporary);
        }
        if (!str_contains($head, 'ERP backup') || !str_contains($head, 'ERP manifest')) {
            throw new RuntimeException('El respaldo no contiene su cabecera verificable.');
        }
        if (!str_contains($tail, 'ERP BACKUP COMPLETE tables=' . $expectedTables)) {
            throw new RuntimeException('El respaldo no contiene su cierre verificable.');
        }
        return ['size' => (int) filesize($path), 'checksum' => (string) hash_file('sha256', $path)];
    }

    private function decrypt(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('No fue posible preparar la verificación del respaldo.');
        }
        try {
            $magic = fread($input, strlen(self::MAGIC));
            $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || !is_string($header) || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('El formato del respaldo no es válido.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->encryptionKey());
            $finalSeen = false;
            while (!feof($input)) {
                $lengthRaw = fread($input, 4);
                if ($lengthRaw === '') {
                    break;
                }
                if (!is_string($lengthRaw) || strlen($lengthRaw) !== 4) {
                    throw new RuntimeException('El respaldo cifrado está truncado.');
                }
                $length = unpack('Nlength', $lengthRaw)['length'] ?? 0;
                if ($length < 17 || $length > self::CHUNK + 1024) {
                    throw new RuntimeException('El bloque cifrado no es válido.');
                }
                $cipher = '';
                while (strlen($cipher) < $length && !feof($input)) {
                    $piece = fread($input, $length - strlen($cipher));
                    if (!is_string($piece) || $piece === '') {
                        break;
                    }
                    $cipher .= $piece;
                }
                $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($plain === false) {
                    throw new RuntimeException('No fue posible autenticar el respaldo cifrado.');
                }
                [$message, $tag] = $plain;
                fwrite($output, $message);
                $finalSeen = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!$finalSeen) {
                throw new RuntimeException('El respaldo cifrado no contiene su cierre autenticado.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function encryptionKey(): string
    {
        $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
        $directory = $home !== '' && is_dir($home) && is_writable($home)
            ? rtrim($home, '/\\') . '/.erp-meli/update-backup-keys'
            : dirname(AppPaths::updateBackups()) . '/private-update-keys';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar la clave privada del respaldo.');
        }
        $path = $directory . '/backup.key';
        if (!is_file($path)) {
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(3));
            $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
            if (@file_put_contents($temporary, base64_encode($key), LOCK_EX) === false || !@rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('No fue posible guardar la clave privada del respaldo.');
            }
            @chmod($path, 0600);
        }
        $key = base64_decode(trim((string) @file_get_contents($path)), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('La clave privada del respaldo no es válida.');
        }
        return $key;
    }
}
