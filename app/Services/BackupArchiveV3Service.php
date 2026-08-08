<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Respaldo reanudable sin persistir SQL en claro.
 *
 * Cada micro-lote se cifra y autentica antes de devolver su checkpoint. El
 * cursor es la clave primaria completa; una tabla sin identidad estable se
 * rechaza en lugar de recurrir a OFFSET.
 */
final class BackupArchiveV3Service
{
    public const MAGIC = "ERP-MELI-BACKUP-3\n";
    private const CHUNK_AD = 'erp-meli-backup-v3-chunk:';
    private const MANIFEST_AD = 'erp-meli-backup-v3-manifest:';

    public function __construct(private readonly ?BackupKeyringService $keyring = null)
    {
    }

    /** @param array<string,mixed> $checkpoint @return array<string,mixed> */
    public function process(
        string $publicId,
        string $purpose,
        array $checkpoint,
        int $rowLimit,
        int $maxSeconds
    ): array {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('El servidor necesita Sodium para crear copias cifradas.');
        }
        $directory = AppPaths::backups();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento privado de copias.');
        }
        @chmod($directory, 0700);
        $safeId = substr(preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup', 0, 24);
        $executionTag = strtolower((string) ($checkpoint['_execution_tag'] ?? ''));
        if (preg_match('/^g[0-9]+-[a-f0-9]{12}$/', $executionTag) !== 1) {
            throw new RuntimeException('La generación del worker de respaldo no es válida.');
        }
        $rowLimit = max(1, min(5000, $rowLimit));
        $byteLimit = max(
            65536,
            min(
                8388608,
                (new AppSettingsService())->int('backup.chunk_byte_limit', 8388608)
            )
        );
        $targetName = 'erp-v3-' . $safeId . '.erpbackup';
        // Congelar nuevas mutaciones antes incluso de descubrir el catálogo.
        // El marcador es la autoridad persistente durante todos los ciclos.
        $this->touchSnapshotMarker($publicId, $executionTag);
        $pdo = Database::connection();
        if (!isset($checkpoint['format'])) {
            $this->assertSpace($directory);
            $tables = array_values(array_map(
                'strval',
                $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")
                    ->fetchAll(PDO::FETCH_COLUMN)
            ));
            sort($tables, SORT_STRING);
            $active = ($this->keyring ?? new BackupKeyringService())->active();
            $checkpoint = [
                'format' => 3,
                'snapshot_id' => $safeId,
                'stage' => 'dump',
                'key_id' => $active['id'],
                'storage_name' => $targetName,
                'tables' => $tables,
                'table_index' => 0,
                'last_key' => null,
                'rows' => 0,
                'checks' => [],
                'chunks' => [],
                'started_at' => gmdate(DATE_ATOM),
            ];
        }
        $this->assertCheckpoint($checkpoint, $targetName);
        if ((string) $checkpoint['stage'] === 'finalize') {
            return [
                'complete' => true,
                'checkpoint' => $checkpoint,
                'archive' => $this->finalize($pdo, $publicId, $purpose, $checkpoint),
            ];
        }

        $tables = array_values($checkpoint['tables']);
        $tableIndex = (int) $checkpoint['table_index'];
        if ($tableIndex >= count($tables)) {
            $checkpoint['stage'] = 'finalize';
            return ['complete' => false, 'checkpoint' => $checkpoint];
        }
        $table = (string) $tables[$tableIndex];
        $quoted = $this->quoteIdentifier($table);
        $checks = is_array($checkpoint['checks']) ? $checkpoint['checks'] : [];
        $newTable = !isset($checks[$table]);
        $plain = '';
        if ($newTable) {
            $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || !isset($create[1])) {
                throw new RuntimeException('No fue posible leer la estructura de una tabla.');
            }
            $structure = (string) $create[1];
            $plain .= "DROP TABLE IF EXISTS {$quoted};\n{$structure};\n";
            $checks[$table] = [
                'table' => $table,
                'rows' => 0,
                'structure_sha256' => hash('sha256', $structure),
                'data_sha256' => hash('sha256', ''),
                'hash_algorithm' => 'sha256_chain_v1',
            ];
            $checkpoint['checks'] = $checks;
        }
        /*
         * El catálogo de copias/restauraciones cambia mientras este mismo
         * trabajo guarda heartbeat y checkpoints. Excluirlo por completo
         * dejaba una base restaurada con schema_migrations=153, pero sin las
         * tablas que esas migraciones ya no volverían a crear. Conservamos su
         * estructura autenticada y comenzamos el catálogo vacío: las copias
         * antiguas dependen de archivos externos que no pertenecen a este
         * contenedor y no deben reaparecer como descargables.
         */
        if ($this->isVolatileCatalog($table)) {
            if ($plain !== '') {
                $checkpoint['chunks'][] = $this->writeEncryptedChunk(
                    $publicId,
                    count($checkpoint['chunks']) + 1,
                    $plain,
                    (string) $checkpoint['key_id'],
                    $executionTag
                );
            }
            $checkpoint['table_index'] = $tableIndex + 1;
            $checkpoint['last_key'] = null;
            if ((int) $checkpoint['table_index'] >= count($tables)) {
                $checkpoint['stage'] = 'finalize';
            }
            return ['complete' => false, 'checkpoint' => $checkpoint];
        }
        $keys = $this->primaryKeys($pdo, $table);
        if ($keys === []) {
            throw new RuntimeException(
                'La tabla ' . $table . ' no tiene clave primaria estable; no se creó una copia ambigua.'
            );
        }

        [$where, $parameters] = $this->keysetPredicate(
            $keys,
            is_array($checkpoint['last_key'] ?? null) ? $checkpoint['last_key'] : null
        );
        $sql = 'SELECT * FROM ' . $quoted
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' ORDER BY ' . implode(',', array_map($this->quoteIdentifier(...), $keys))
            . ' LIMIT ' . $rowLimit;
        $started = microtime(true);
        $query = $pdo->prepare($sql, $this->unbufferedPrepareOptions());
        $query->execute($parameters);
        $lastKey = null;
        $fetched = 0;
        while (true) {
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            if (microtime(true) - $started > max(2, min(20, $maxSeconds)) && $plain !== '') {
                break;
            }
            $insert = $this->insertSql($pdo, $table, $row);
            if (strlen($insert) > $byteLimit) {
                throw new RuntimeException(
                    'Una fila de ' . $table . ' supera el límite seguro de 8 MB por fragmento.'
                );
            }
            if ($plain !== '' && strlen($plain) + strlen($insert) > $byteLimit) {
                break;
            }
            $fetched++;
            $plain .= $insert;
            $previous = (string) $checkpoint['checks'][$table]['data_sha256'];
            $checkpoint['checks'][$table]['data_sha256'] =
                hash('sha256', hex2bin($previous) . $insert);
            $checkpoint['checks'][$table]['rows'] =
                (int) $checkpoint['checks'][$table]['rows'] + 1;
            $checkpoint['rows'] = (int) $checkpoint['rows'] + 1;
            $lastKey = [];
            foreach ($keys as $key) {
                $lastKey[$key] = $row[$key];
            }
            if (strlen($plain) >= $byteLimit) {
                break;
            }
        }
        $query->closeCursor();
        $processed = $lastKey === null ? 0 : (int) $checkpoint['checks'][$table]['rows']
            - (int) ($checks[$table]['rows'] ?? 0);
        if ($plain !== '') {
            $checkpoint['chunks'][] = $this->writeEncryptedChunk(
                $publicId,
                count($checkpoint['chunks']) + 1,
                $plain,
                (string) $checkpoint['key_id'],
                $executionTag
            );
        }
        if ($processed > 0) {
            $checkpoint['last_key'] = $lastKey;
        }
        /*
         * Un cursor limitado siempre devuelve false después de LIMIT filas,
         * aunque todavía existan filas en la tabla. Interpretar ese false
         * como fin de tabla truncaba silenciosamente tablas grandes en 5.000
         * registros. La única autoridad es una sonda keyset posterior al
         * último registro que sí quedó cifrado y aprobado.
         */
        $probeKey = $processed > 0
            ? $lastKey
            : (is_array($checkpoint['last_key'] ?? null) ? $checkpoint['last_key'] : null);
        if (!$this->hasRowsAfterKey($pdo, $table, $keys, $probeKey)) {
            $checkpoint['table_index'] = $tableIndex + 1;
            $checkpoint['last_key'] = null;
        }
        if ((int) $checkpoint['table_index'] >= count($tables)) {
            $checkpoint['stage'] = 'finalize';
        }
        return ['complete' => false, 'checkpoint' => $checkpoint];
    }

    /** @return array<string,mixed> */
    public function verify(string $path): array
    {
        $key = null;
        $stream = $this->streamContainer(
            $path,
            function (string $keyId, string $snapshotId, int $sequence, string $cipher) use (&$key): void {
                $key ??= ($this->keyring ?? new BackupKeyringService())->key($keyId);
                $plain = $this->decryptBlob(
                    $cipher,
                    $key,
                    self::CHUNK_AD . $snapshotId . ':' . $sequence
                );
                sodium_memzero($plain);
            }
        );
        $keyId = $stream['key_id'];
        $key ??= ($this->keyring ?? new BackupKeyringService())->key($keyId);
        $manifestJson = $this->decryptBlob(
            $stream['manifest'],
            $key,
            self::MANIFEST_AD . $stream['manifest_ad_id']
        );
        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest) || (int) ($manifest['format'] ?? 0) !== 3) {
            throw new RuntimeException('El manifiesto v3 no es válido.');
        }
        $copy = $manifest;
        $expected = (string) ($copy['manifest_sha256'] ?? '');
        unset($copy['manifest_sha256']);
        $actual = hash('sha256', $this->canonicalJson($copy));
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('El manifiesto v3 no superó su verificación.');
        }
        $listed = is_array($manifest['chunks'] ?? null) ? $manifest['chunks'] : [];
        if ((int) $stream['chunk_count'] !== count($listed)) {
            throw new RuntimeException('La copia v3 no contiene todos sus micro-lotes.');
        }
        foreach ($stream['chunk_hashes'] as $index => $cipherHash) {
            if (!hash_equals((string) ($listed[$index]['sha256'] ?? ''), $cipherHash)) {
                throw new RuntimeException('Un micro-lote cifrado cambió después de aprobarse.');
            }
        }
        if (
            isset($manifest['snapshot_id'])
            && !hash_equals((string) $manifest['snapshot_id'], $stream['snapshot_id'])
        ) {
            throw new RuntimeException('La identidad inmutable de la copia no coincide.');
        }
        return [
            'format' => 3,
            'key_id' => $keyId,
            'manifest' => $manifest,
            'manifest_checksum' => $actual,
        ];
    }

    public function decryptTo(string $source, string $gzipTarget): string
    {
        // gzopen() does not support fopen's exclusive "x" mode consistently
        // across the PHP builds used by Hostinger and Windows QA. Callers use
        // a unique private staging name, so "wb6" is deterministic and portable.
        $gzip = gzopen($gzipTarget, 'wb6');
        if ($gzip === false) {
            throw new RuntimeException('No fue posible preparar el contenido restaurable.');
        }
        $key = null;
        try {
            if (gzwrite($gzip, "-- ERP-MELI-BACKUP-3\n") === false) {
                throw new RuntimeException('No fue posible iniciar el contenido restaurable.');
            }
            $stream = $this->streamContainer(
                $source,
                function (string $keyId, string $snapshotId, int $sequence, string $cipher) use (&$key, $gzip): void {
                    $key ??= ($this->keyring ?? new BackupKeyringService())->key($keyId);
                    $plain = $this->decryptBlob(
                        $cipher,
                        $key,
                        self::CHUNK_AD . $snapshotId . ':' . $sequence
                    );
                    if (gzwrite($gzip, $plain) === false) {
                        throw new RuntimeException('No fue posible reconstruir el contenido restaurable.');
                    }
                    sodium_memzero($plain);
                }
            );
            $key ??= ($this->keyring ?? new BackupKeyringService())->key($stream['key_id']);
            $manifestJson = $this->decryptBlob(
                $stream['manifest'],
                $key,
            self::MANIFEST_AD . $stream['manifest_ad_id']
            );
            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest) || (int) ($manifest['format'] ?? 0) !== 3) {
                throw new RuntimeException('El manifiesto v3 no es válido.');
            }
        } finally {
            gzclose($gzip);
        }
        return (string) $stream['key_id'];
    }

    public function cleanup(array $checkpoint): void
    {
        foreach (is_array($checkpoint['chunks'] ?? null) ? $checkpoint['chunks'] : [] as $chunk) {
            $name = basename((string) ($chunk['name'] ?? ''));
            if ($name !== '') {
                @unlink(AppPaths::backups() . '/' . $name);
            }
        }
        $markerPath = AppPaths::storage('cache/database-snapshot-active.json');
        $marker = json_decode((string) @file_get_contents($markerPath), true);
        if (
            is_array($marker)
            && hash_equals((string) ($checkpoint['snapshot_id'] ?? ''), (string) ($marker['snapshot_id'] ?? ''))
            && hash_equals((string) ($checkpoint['_execution_tag'] ?? ''), (string) ($marker['execution_tag'] ?? ''))
        ) {
            @unlink($markerPath);
        }
    }

    /**
     * Retira únicamente artefactos creados por una generación que perdió su
     * lease. Nunca toca fragmentos aprobados por generaciones anteriores.
     */
    public function cleanupExecutionArtifacts(string $publicId, string $executionTag): void
    {
        $safeId = substr(
            preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup',
            0,
            24
        );
        $executionTag = strtolower($executionTag);
        if (preg_match('/^g[0-9]+-[a-f0-9]{12}$/', $executionTag) !== 1) {
            return;
        }
        $pattern = AppPaths::backups()
            . '/erp-v3-' . $safeId . '-' . $executionTag . '-*';
        foreach (glob($pattern) ?: [] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
        foreach ([
            AppPaths::backups() . '/erp-v3-' . $safeId . '-' . $executionTag . '.erpbackup',
            AppPaths::backups() . '/erp-v3-' . $safeId . '-' . $executionTag . '.erpbackup.part',
        ] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
        $markerPath = AppPaths::storage('cache/database-snapshot-active.json');
        $marker = json_decode((string) @file_get_contents($markerPath), true);
        if (
            is_array($marker)
            && hash_equals($safeId, (string) ($marker['snapshot_id'] ?? ''))
            && hash_equals($executionTag, (string) ($marker['execution_tag'] ?? ''))
        ) {
            @unlink($markerPath);
        }
    }

    public function activateFreeze(string $publicId): void
    {
        $this->touchSnapshotMarker($publicId, 'queued');
    }

    public static function matches(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        try {
            return fread($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $checkpoint @return array<string,mixed> */
    private function finalize(PDO $pdo, string $publicId, string $purpose, array $checkpoint): array
    {
        $directory = AppPaths::backups();
        $baseName = basename((string) $checkpoint['storage_name']);
        $executionTag = strtolower((string) ($checkpoint['_execution_tag'] ?? ''));
        if (preg_match('/^g[0-9]+-[a-f0-9]{12}$/', $executionTag) !== 1) {
            throw new RuntimeException('La generación final del respaldo no es válida.');
        }
        $target = $directory . '/'
            . preg_replace('/\\.erpbackup$/', '', $baseName)
            . '-' . $executionTag . '.erpbackup';
        $temporary = $target . '.part';
        @unlink($temporary);
        @unlink($target);
        $checks = [];
        foreach ($checkpoint['tables'] as $table) {
            if (!isset($checkpoint['checks'][$table])) {
                throw new RuntimeException('El respaldo no terminó todas sus tablas.');
            }
            $checks[] = $checkpoint['checks'][$table];
        }
        $manifest = [
            'format' => 3,
            'snapshot_id' => substr(preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup', 0, 24),
            'created_at' => gmdate(DATE_ATOM),
            'timezone' => date_default_timezone_get(),
            'erp_version' => AppVersionService::fileVersion(),
            'purpose' => $purpose,
            'database' => [
                'engine_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
                'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
            ],
            'migrations' => $pdo->query(
                'SELECT version FROM schema_migrations ORDER BY version'
            )->fetchAll(PDO::FETCH_COLUMN),
            'tables' => $checks,
            'table_count' => count($checks),
            'row_count' => (int) $checkpoint['rows'],
            'data_hash_algorithm' => 'sha256_chain_v1',
            'chunks' => array_map(
                static fn (array $chunk): array => [
                    'sequence' => (int) $chunk['sequence'],
                    'bytes' => (int) $chunk['bytes'],
                    'sha256' => (string) $chunk['sha256'],
                ],
                $checkpoint['chunks']
            ),
            'config_b64' => base64_encode((string) @file_get_contents(AppPaths::configFile())),
        ];
        $manifest['manifest_sha256'] = hash('sha256', $this->canonicalJson($manifest));
        $keyId = (string) $checkpoint['key_id'];
        $key = ($this->keyring ?? new BackupKeyringService())->key($keyId);
        $manifestCipher = $this->encryptBlob(
            $this->canonicalJson($manifest),
            $key,
            self::MANIFEST_AD . (string) $manifest['snapshot_id']
        );
        $output = fopen($temporary, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('No fue posible cerrar el contenedor v3.');
        }
        try {
            $snapshotId = (string) $manifest['snapshot_id'];
            fwrite(
                $output,
                self::MAGIC . pack('n', strlen($keyId)) . $keyId
                . 'ID' . pack('n', strlen($snapshotId)) . $snapshotId
            );
            fwrite($output, pack('N', count($checkpoint['chunks'])));
            foreach ($checkpoint['chunks'] as $chunk) {
                $path = $directory . '/' . basename((string) $chunk['name']);
                $cipher = @file_get_contents($path);
                if (!is_string($cipher) || !hash_equals((string) $chunk['sha256'], hash('sha256', $cipher))) {
                    throw new RuntimeException('Un micro-lote cifrado no está disponible o cambió.');
                }
                $authenticated = $this->decryptBlob(
                    $cipher,
                    $key,
                    self::CHUNK_AD . $snapshotId . ':' . (int) $chunk['sequence']
                );
                sodium_memzero($authenticated);
                fwrite($output, pack('N', strlen($cipher)) . $cipher);
            }
            fwrite($output, pack('N', strlen($manifestCipher)) . $manifestCipher);
            fflush($output);
        } finally {
            fclose($output);
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible aprobar el contenedor v3.');
        }
        $verified = $this->verify($target);
        return [
            'path' => $target,
            'storage_name' => basename($target),
            'size' => (int) filesize($target),
            'checksum' => (string) hash_file('sha256', $target),
            'key_id' => $keyId,
            'manifest_checksum' => (string) $verified['manifest_checksum'],
            'tables' => count($checks),
            'rows' => (int) $checkpoint['rows'],
            'table_checks' => $checks,
            'format' => 3,
        ];
    }

    /**
     * @param callable(string,string,int,string):void $visitChunk
     * @return array{key_id:string,snapshot_id:string,manifest_ad_id:string,chunk_count:int,chunk_hashes:list<string>,manifest:string}
     */
    private function streamContainer(string $path, callable $visitChunk): array
    {
        $input = fopen($path, 'rb');
        if (!is_resource($input) || fread($input, strlen(self::MAGIC)) !== self::MAGIC) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('El formato v3 no es compatible.');
        }
        try {
            $length = unpack('nlength', $this->readExact($input, 2))['length'] ?? 0;
            if ($length < 4 || $length > 120) {
                throw new RuntimeException('El identificador de clave v3 no es válido.');
            }
            $keyId = $this->readExact($input, (int) $length);
            $prefix = $this->readExact($input, 2);
            $newHeader = $prefix === 'ID';
            if ($newHeader) {
                $snapshotLength = unpack('nlength', $this->readExact($input, 2))['length'] ?? 0;
                if ($snapshotLength < 4 || $snapshotLength > 64) {
                    throw new RuntimeException('La identidad v3 no es válida.');
                }
                $snapshotId = strtolower($this->readExact($input, (int) $snapshotLength));
                if (preg_match('/^[a-f0-9]+$/', $snapshotId) !== 1) {
                    throw new RuntimeException('La identidad v3 contiene caracteres inválidos.');
                }
                $countBytes = $this->readExact($input, 4);
            } else {
                $snapshotId = $this->publicTokenFromTarget($path);
                $countBytes = $prefix . $this->readExact($input, 2);
            }
            $count = unpack('Ncount', $countBytes)['count'] ?? 0;
            if ($count < 0 || $count > 1000000) {
                throw new RuntimeException('La cantidad de micro-lotes v3 no es válida.');
            }
            $chunkHashes = [];
            for ($index = 0; $index < $count; $index++) {
                $size = unpack('Nsize', $this->readExact($input, 4))['size'] ?? 0;
                if ($size < 41 || $size > 16777216) {
                    throw new RuntimeException('Un micro-lote v3 declara un tamaño inválido.');
                }
                $cipher = $this->readExact($input, (int) $size);
                $chunkHashes[] = hash('sha256', $cipher);
                $visitChunk($keyId, $snapshotId, $index + 1, $cipher);
                sodium_memzero($cipher);
            }
            $manifestSize = unpack('Nsize', $this->readExact($input, 4))['size'] ?? 0;
            $manifest = $this->readExact($input, (int) $manifestSize);
            if (fread($input, 1) !== '') {
                throw new RuntimeException('El contenedor v3 contiene datos posteriores al cierre.');
            }
            return [
                'key_id' => $keyId,
                'snapshot_id' => $snapshotId,
                'manifest_ad_id' => $newHeader ? $snapshotId : basename($path),
                'chunk_count' => (int) $count,
                'chunk_hashes' => $chunkHashes,
                'manifest' => $manifest,
            ];
        } finally {
            fclose($input);
        }
    }

    /** @return array{name:string,sequence:int,bytes:int,sha256:string} */
    private function writeEncryptedChunk(
        string $publicId,
        int $sequence,
        string $plain,
        string $keyId,
        string $executionTag
    ): array {
        $safeId = substr(preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup', 0, 24);
        $name = sprintf(
            'erp-v3-%s-%s-chunk-%08d.bin',
            $safeId,
            $executionTag,
            $sequence
        );
        $path = AppPaths::backups() . '/' . $name;
        $temporary = $path . '.part';
        @unlink($temporary);
        $key = ($this->keyring ?? new BackupKeyringService())->key($keyId);
        $cipher = $this->encryptBlob(
            gzencode($plain, 6),
            $key,
            self::CHUNK_AD . $safeId . ':' . $sequence
        );
        if (@file_put_contents($temporary, $cipher, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible aprobar un micro-lote cifrado.');
        }
        @chmod($path, 0600);
        return [
            'name' => $name,
            'sequence' => $sequence,
            'bytes' => strlen($cipher),
            'sha256' => hash('sha256', $cipher),
        ];
    }

    private function encryptBlob(string $plain, string $key, string $ad): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $ad, $nonce, $key);
    }

    private function decryptBlob(string $blob, string $key, string $ad): string
    {
        $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($blob) <= $nonceSize) {
            throw new RuntimeException('Un micro-lote cifrado está truncado.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($blob, $nonceSize),
            $ad,
            substr($blob, 0, $nonceSize),
            $key
        );
        if (!is_string($plain)) {
            throw new RuntimeException('Un micro-lote no superó la autenticación.');
        }
        if (str_starts_with($ad, self::CHUNK_AD)) {
            $decoded = gzdecode($plain);
            if (!is_string($decoded)) {
                throw new RuntimeException('Un micro-lote cifrado no se puede descomprimir.');
            }
            return $decoded;
        }
        return $plain;
    }

    /** @return list<string> */
    private function primaryKeys(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
               AND BINARY TABLE_NAME=BINARY :table AND INDEX_NAME='PRIMARY'
             ORDER BY SEQ_IN_INDEX"
        );
        $stmt->execute(['table' => $table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<string> $keys @param array<string,mixed>|null $last @return array{0:string,1:list<mixed>} */
    private function keysetPredicate(array $keys, ?array $last): array
    {
        if ($last === null) {
            return ['', []];
        }
        $parts = [];
        $parameters = [];
        foreach ($keys as $index => $key) {
            $and = [];
            for ($prior = 0; $prior < $index; $prior++) {
                $and[] = $this->quoteIdentifier($keys[$prior]) . '=?';
                $parameters[] = $last[$keys[$prior]] ?? null;
            }
            $and[] = $this->quoteIdentifier($key) . '>?';
            $parameters[] = $last[$key] ?? null;
            $parts[] = '(' . implode(' AND ', $and) . ')';
        }
        return ['(' . implode(' OR ', $parts) . ')', $parameters];
    }

    /**
     * Confirma el final real de la tabla sin OFFSET.
     *
     * @param list<string> $keys
     * @param array<string,mixed>|null $last
     */
    private function hasRowsAfterKey(PDO $pdo, string $table, array $keys, ?array $last): bool
    {
        [$where, $parameters] = $this->keysetPredicate($keys, $last);
        $sql = 'SELECT 1 FROM ' . $this->quoteIdentifier($table)
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' LIMIT 1';
        $probe = $pdo->prepare($sql);
        $probe->execute($parameters);
        return $probe->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $checkpoint */
    private function assertCheckpoint(array $checkpoint, string $targetName): void
    {
        if (
            (int) ($checkpoint['format'] ?? 0) !== 3
            || !in_array((string) ($checkpoint['stage'] ?? ''), ['dump', 'finalize'], true)
            || !hash_equals($targetName, (string) ($checkpoint['storage_name'] ?? ''))
            || !is_array($checkpoint['tables'] ?? null)
            || !is_array($checkpoint['checks'] ?? null)
            || !is_array($checkpoint['chunks'] ?? null)
            || preg_match('/^[a-f0-9]+$/', (string) ($checkpoint['snapshot_id'] ?? '')) !== 1
        ) {
            throw new RuntimeException('El checkpoint v3 no es válido.');
        }
        $tables = array_values($checkpoint['tables']);
        $tableIndex = (int) ($checkpoint['table_index'] ?? -1);
        if ($tableIndex < 0 || $tableIndex > count($tables) || count($tables) !== count(array_unique($tables))) {
            throw new RuntimeException('El checkpoint v3 contiene un catálogo de tablas inválido.');
        }
        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                throw new RuntimeException('El checkpoint v3 contiene una tabla no restaurable.');
            }
        }
        foreach (array_values($checkpoint['chunks']) as $index => $chunk) {
            if (
                !is_array($chunk)
                || (int) ($chunk['sequence'] ?? 0) !== $index + 1
                || preg_match('/^erp-v3-[a-f0-9]+-g[0-9]+-[a-f0-9]{12}-chunk-[0-9]{8}\.bin$/', (string) ($chunk['name'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/', (string) ($chunk['sha256'] ?? '')) !== 1
                || (int) ($chunk['bytes'] ?? 0) < 41
            ) {
                throw new RuntimeException('El checkpoint v3 contiene un micro-lote inválido.');
            }
        }
    }

    private function touchSnapshotMarker(string $publicId, string $executionTag): void
    {
        $path = AppPaths::storage('cache/database-snapshot-active.json');
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        $payload = json_encode([
            'snapshot_id' => substr(preg_replace('/[^a-f0-9]/i', '', $publicId) ?: 'backup', 0, 24),
            'execution_tag' => $executionTag,
            'format' => 3,
            'heartbeat_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible mantener el bloqueo de snapshot.');
        }
        $renamed = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temporary, $path)) {
                $renamed = true;
                break;
            }
            if (is_file($path)) {
                @unlink($path);
            }
            if (@rename($temporary, $path)) {
                $renamed = true;
                break;
            }
            usleep(50000);
        }
        if (!$renamed) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible mantener el bloqueo de snapshot.');
        }
        @chmod($path, 0640);
    }

    /** @param array<string,mixed> $row */
    private function insertSql(PDO $pdo, string $table, array $row): string
    {
        $values = array_map(
            static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
            array_values($row)
        );
        return 'INSERT INTO ' . $this->quoteIdentifier($table) . ' ('
            . implode(',', array_map($this->quoteIdentifier(...), array_keys($row)))
            . ') VALUES (' . implode(',', $values) . ");\n";
    }

    private function readExact($handle, int $bytes): string
    {
        if ($bytes < 1 || $bytes > 1073741824) {
            throw new RuntimeException('El contenedor v3 declara un tamaño inválido.');
        }
        $value = '';
        while (strlen($value) < $bytes) {
            $piece = fread($handle, $bytes - strlen($value));
            if (!is_string($piece) || $piece === '') {
                throw new RuntimeException('El contenedor v3 está truncado.');
            }
            $value .= $piece;
        }
        return $value;
    }

    private function publicTokenFromTarget(string $path): string
    {
        if (preg_match('/erp-v3-([a-f0-9]+)(?:-g[0-9]+-[a-f0-9]{12})?\\.erpbackup$/i', basename($path), $match)) {
            return strtolower($match[1]);
        }
        throw new RuntimeException('El nombre del respaldo v3 no es válido.');
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @return array<int,bool> */
    private function unbufferedPrepareOptions(): array
    {
        $modern = 'Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY';
        $legacy = 'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY';
        $attribute = defined($modern) ? constant($modern) : constant($legacy);
        return [$attribute => false];
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
        $factor = max(
            2.5,
            (float) (new AppSettingsService())->get(
                'backup.minimum_free_space_factor',
                '2.5'
            )
        );
        if ((float) $free < $estimated * $factor) {
            throw new RuntimeException('No hay espacio suficiente para crear y verificar la copia.');
        }
    }

    private function isVolatileCatalog(string $table): bool
    {
        return str_starts_with($table, 'system_backup_')
            || str_starts_with($table, 'system_restore_');
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
