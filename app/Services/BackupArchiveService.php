<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class BackupArchiveService
{
    private const MAGIC = "ERP-MELI-BACKUP-2\n";
    private const CHUNK = 1048576;
    private const V3_ROWS_PER_CHUNK = 500;

    public function __construct(private readonly ?BackupKeyringService $keyring = null)
    {
    }

    /**
     * @return array{path:string,storage_name:string,size:int,checksum:string,key_id:string,
     *   manifest_checksum:string,tables:int,rows:int,table_checks:array<int,array<string,mixed>>}
     */
    public function create(string $publicId, string $purpose): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('El servidor necesita Sodium para crear copias cifradas.');
        }
        $directory = AppPaths::backups();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento privado de copias.');
        }
        @chmod($directory, 0700);
        foreach (glob($directory . '/*.part') ?: [] as $incomplete) {
            if ((int) @filemtime($incomplete) < time() - 3600) {
                @unlink($incomplete);
            }
        }
        $this->assertSpace($directory);

        $name = 'erp-' . gmdate('Ymd-His') . '-' . substr(str_replace('-', '', $publicId), 0, 12);
        $plain = $directory . '/' . $name . '.sql.gz.part';
        $target = $directory . '/' . $name . '.erpbackup';
        $lockPath = $directory . '/.snapshot.lock';
        $snapshotMarker = AppPaths::storage('cache/database-snapshot-active.json');
        $lock = fopen($lockPath, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Ya existe una copia o restauración en curso.');
        }

        try {
            $markerDirectory = dirname($snapshotMarker);
            if (!is_dir($markerDirectory)) {
                @mkdir($markerDirectory, 0750, true);
            }
            @file_put_contents($snapshotMarker, json_encode([
                'public_id' => $publicId,
                'started_at' => gmdate(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES), LOCK_EX);
            $dump = $this->dump($plain, $purpose, $snapshotMarker);
            $activeKey = ($this->keyring ?? new BackupKeyringService())->active();
            $this->encrypt($plain, $target, $activeKey['id'], $activeKey['key']);
            @unlink($plain);
            $verified = $this->verify($target);
            if (!hash_equals($dump['manifest_checksum'], $verified['manifest_checksum'])) {
                throw new RuntimeException('El manifiesto verificado no coincide con el manifiesto creado.');
            }
            return [
                'path' => $target,
                'storage_name' => basename($target),
                'size' => (int) filesize($target),
                'checksum' => (string) hash_file('sha256', $target),
                'key_id' => $activeKey['id'],
                'manifest_checksum' => $dump['manifest_checksum'],
                'tables' => count($dump['table_checks']),
                'rows' => $dump['rows'],
                'table_checks' => $dump['table_checks'],
            ];
        } catch (Throwable $error) {
            @unlink($plain);
            @unlink($target);
            throw $error;
        } finally {
            @unlink($snapshotMarker);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Construye una copia v3 en micro-lotes reanudables. El tamaño confirmado
     * del archivo plano forma parte del checkpoint: si el proceso muere después
     * de escribir pero antes de aprobar, el siguiente ciclo trunca exactamente
     * hasta el último byte aprobado y no duplica filas.
     *
     * @param array<string,mixed> $checkpoint
     * @return array{complete:bool,checkpoint:array<string,mixed>,archive?:array<string,mixed>}
     */
    public function processChunk(
        string $publicId,
        string $purpose,
        array $checkpoint = [],
        int $rowLimit = self::V3_ROWS_PER_CHUNK,
        int $maxSeconds = 12
    ): array {
        return (new BackupArchiveV3Service($this->keyring))->process(
            $publicId,
            $purpose,
            $checkpoint,
            $rowLimit,
            $maxSeconds
        );
    }

    /**
     * @return array{format:int,key_id:string,manifest:array<string,mixed>,manifest_checksum:string}
     */
    public function verify(string $path): array
    {
        if (BackupArchiveV3Service::matches($path)) {
            return (new BackupArchiveV3Service($this->keyring))->verify($path);
        }
        if (!is_file($path) || (int) filesize($path) < 128) {
            throw new RuntimeException('La copia está vacía o incompleta.');
        }
        $temporary = $path . '.verify-' . bin2hex(random_bytes(4)) . '.sql.gz';
        try {
            $keyId = $this->decrypt($path, $temporary);
            $manifest = $this->readManifest($temporary);
            $expected = (string) ($manifest['manifest_sha256'] ?? '');
            $copy = $manifest;
            unset($copy['manifest_sha256']);
            $actual = hash('sha256', $this->canonicalJson($copy));
            if ($expected === '' || !hash_equals($expected, $actual)) {
                throw new RuntimeException('El manifiesto de la copia no superó su verificación.');
            }
            return [
                'format' => (int) ($manifest['format'] ?? 2),
                'key_id' => $keyId,
                'manifest' => $manifest,
                'manifest_checksum' => $actual,
            ];
        } finally {
            @unlink($temporary);
        }
    }

    public function decryptTo(string $source, string $target): string
    {
        if (BackupArchiveV3Service::matches($source)) {
            return (new BackupArchiveV3Service($this->keyring))->decryptTo($source, $target);
        }
        return $this->decrypt($source, $target);
    }

    /**
     * @return array{manifest_checksum:string,rows:int,table_checks:array<int,array<string,mixed>>}
     */
    private function dump(string $path, string $purpose, string $snapshotMarker): array
    {
        $pdo = Database::connection();
        $gzip = gzopen($path, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal.');
        }
        $checks = [];
        $totalRows = 0;
        try {
            gzwrite($gzip, "-- ERP-MELI-BACKUP-2\n");
            // NO_AUTO_VALUE_ON_ZERO intentionally mirrors the safe compatibility
            // mode used by MariaDB/phpMyAdmin dumps. Some long-lived installations
            // can contain the valid ENUM index 0 (displayed as an empty string)
            // after a historical schema change. Strict restore mode would reject
            // that already-existing value and make an otherwise sound backup
            // impossible to recover.
            gzwrite(
                $gzip,
                "SET NAMES utf8mb4;\n"
                . "SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET FOREIGN_KEY_CHECKS=0;\n\n"
            );
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            sort($tables, SORT_STRING);
            foreach ($tables as $tableName) {
                @touch($snapshotMarker);
                $table = (string) $tableName;
                $quoted = $this->quoteIdentifier($table);
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
                if (!is_array($create) || !isset($create[1])) {
                    throw new RuntimeException('No fue posible leer la estructura de una tabla.');
                }
                $structure = (string) $create[1];
                $dataHash = hash_init('sha256');
                $rows = 0;
                gzwrite($gzip, "DROP TABLE IF EXISTS {$quoted};\n{$structure};\n");
                $query = $pdo->query(
                    'SELECT * FROM ' . $quoted . $this->primaryKeyOrder($pdo, $table)
                );
                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_keys($row);
                    $values = array_map(
                        static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                        array_values($row)
                    );
                    $sql = 'INSERT INTO ' . $quoted . ' (' . implode(',', array_map(
                        fn (string $column): string => $this->quoteIdentifier($column),
                        $columns
                    )) . ') VALUES (' . implode(',', $values) . ");\n";
                    gzwrite($gzip, $sql);
                    hash_update($dataHash, $sql);
                    $rows++;
                }
                gzwrite($gzip, "\n");
                $totalRows += $rows;
                $checks[] = [
                    'table' => $table,
                    'rows' => $rows,
                    'structure_sha256' => hash('sha256', $structure),
                    'data_sha256' => hash_final($dataHash),
                ];
            }
            $manifest = [
                'format' => 2,
                'created_at' => gmdate(DATE_ATOM),
                'timezone' => date_default_timezone_get(),
                'erp_version' => AppVersionService::fileVersion(),
                'purpose' => $purpose,
                'database' => $this->databaseMetadata($pdo),
                'migrations' => $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
                'tables' => $checks,
                'table_count' => count($checks),
                'row_count' => $totalRows,
                'config_b64' => base64_encode((string) @file_get_contents(AppPaths::configFile())),
            ];
            $manifest['manifest_sha256'] = hash('sha256', $this->canonicalJson($manifest));
            gzwrite($gzip, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzwrite(
                $gzip,
                '-- ERP MANIFEST V2 ' . base64_encode($this->canonicalJson($manifest)) . "\n"
                . '-- ERP BACKUP COMPLETE format=2 tables=' . count($checks) . ' rows=' . $totalRows . "\n"
            );
        } finally {
            gzclose($gzip);
        }
        return [
            'manifest_checksum' => (string) $manifest['manifest_sha256'],
            'rows' => $totalRows,
            'table_checks' => $checks,
        ];
    }

    private function encrypt(string $source, string $target, string $keyId, string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('No fue posible abrir la copia para cifrarla.');
        }
        try {
            fwrite($output, self::MAGIC . pack('n', strlen($keyId)) . $keyId . $header);
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if (!is_string($chunk)) {
                    throw new RuntimeException('No fue posible leer la copia temporal.');
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    feof($input)
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

    private function decrypt(string $source, string $target): string
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('No fue posible preparar la copia para verificarla.');
        }
        try {
            if (fread($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('El formato de la copia no es compatible.');
            }
            $lengthRaw = fread($input, 2);
            $length = is_string($lengthRaw) && strlen($lengthRaw) === 2 ? (int) (unpack('nlength', $lengthRaw)['length'] ?? 0) : 0;
            if ($length < 4 || $length > 120) {
                throw new RuntimeException('El identificador de clave de la copia no es válido.');
            }
            $keyId = (string) fread($input, $length);
            $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key = ($this->keyring ?? new BackupKeyringService())->key($keyId);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (!feof($input)) {
                $sizeRaw = fread($input, 4);
                if ($sizeRaw === '') {
                    break;
                }
                if (!is_string($sizeRaw) || strlen($sizeRaw) !== 4) {
                    throw new RuntimeException('La copia cifrada está truncada.');
                }
                $size = (int) (unpack('Nsize', $sizeRaw)['size'] ?? 0);
                if ($size < 17 || $size > self::CHUNK + 1024) {
                    throw new RuntimeException('La copia contiene un bloque inválido.');
                }
                $cipher = '';
                while (strlen($cipher) < $size) {
                    $piece = fread($input, $size - strlen($cipher));
                    if (!is_string($piece) || $piece === '') {
                        throw new RuntimeException('La copia cifrada está truncada.');
                    }
                    $cipher .= $piece;
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($decoded === false) {
                    throw new RuntimeException('La copia no superó la autenticación criptográfica.');
                }
                [$plain, $tag] = $decoded;
                fwrite($output, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!$final) {
                throw new RuntimeException('La copia no contiene un cierre autenticado.');
            }
            return $keyId;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** @return array<string,mixed> */
    private function readManifest(string $gzipPath): array
    {
        $gzip = gzopen($gzipPath, 'rb');
        if ($gzip === false) {
            throw new RuntimeException('La copia descifrada no se puede abrir.');
        }
        $tail = '';
        try {
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, self::CHUNK);
                if (!is_string($chunk)) {
                    throw new RuntimeException('No se pudo leer la copia descifrada.');
                }
                $tail = substr($tail . $chunk, -1048576);
            }
        } finally {
            gzclose($gzip);
        }
        if (!preg_match('/^-- ERP MANIFEST V([23]) ([A-Za-z0-9+\\/=]+)$/m', $tail, $match)) {
            throw new RuntimeException('La copia no contiene un manifiesto verificable.');
        }
        $format = (int) $match[1];
        if (!str_contains($tail, '-- ERP BACKUP COMPLETE format=' . $format)) {
            throw new RuntimeException('La copia no contiene su cierre verificable.');
        }
        $json = base64_decode($match[2], true);
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($manifest) || (int) ($manifest['format'] ?? 0) !== $format) {
            throw new RuntimeException('El manifiesto de la copia no es válido.');
        }
        return $manifest;
    }

    /** @return array<string,mixed> */
    private function databaseMetadata(PDO $pdo): array
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $collation = (string) $pdo->query('SELECT @@collation_database')->fetchColumn();
        return ['engine_version' => $version, 'collation' => $collation];
    }

    private function assertSpace(string $directory): void
    {
        $free = @disk_free_space($directory);
        if (!is_float($free)) {
            throw new RuntimeException('No fue posible comprobar el espacio disponible.');
        }
        $pdo = Database::connection();
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0)
             FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY :schema'
        );
        $stmt->execute(['schema' => $schema]);
        $estimated = max(1, (int) $stmt->fetchColumn());
        $factor = max(2.5, (float) (new AppSettingsService())->get('backup.minimum_free_space_factor', '2.5'));
        if ((float) $free < $estimated * $factor) {
            throw new RuntimeException('No hay espacio suficiente para crear y verificar la copia.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function primaryKeyOrder(PDO $pdo, string $table): string
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
               AND BINARY TABLE_NAME=BINARY :table
               AND INDEX_NAME='PRIMARY'
             ORDER BY SEQ_IN_INDEX"
        );
        $stmt->execute(['table' => $table]);
        $columns = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $columns === []
            ? ''
            : ' ORDER BY ' . implode(',', array_map($this->quoteIdentifier(...), $columns));
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
