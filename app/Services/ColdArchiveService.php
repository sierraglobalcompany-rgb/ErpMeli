<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ColdArchiveService
{
    private const INTERACTIVE_MAX_BATCH = 500;

    /** Closed technical receipts are immutable; incomplete/crashed runs stay hot. */
    public static function outerHttpRunScope(string $alias): string
    {
        if (preg_match('/^[a-z_]+$/D', $alias) !== 1) {
            throw new RuntimeException('Invalid technical retention alias.');
        }
        return $alias . '.component_key="queue_v4_outer_http"'
            . ' AND ' . $alias . '.manual_campaign_id IS NULL'
            . ' AND ' . $alias . '.status IN ("completed","failed","skipped")'
            . ' AND ' . $alias . '.finished_at IS NOT NULL'
            . ' AND JSON_VALID(' . $alias . '.http_receipt_json)=1'
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.http_receipt_json,"$.terminal_status"))<>"incomplete"'
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.http_receipt_json,"$.ended_at"))<>"null"';
    }

    /**
     * Canonical fingerprint shared by capture and purge validation.
     *
     * @param array<string,mixed> $row
     */
    public static function rowHash(array $row): string
    {
        return hash(
            'sha256',
            json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    /** @var array<string,array{table:string,date:string,predicate?:string}> */
    private const DATASETS = [
        'outer_http_attempts' => ['table' => 'system_execution_attempts', 'date' => 'reserved_at', 'outer_http' => true],
        'outer_http_runs' => ['table' => 'system_execution_runs', 'date' => 'finished_at', 'outer_http' => true],
        'notification_events' => ['table' => 'meli_notification_events', 'date' => 'erp_received_at'],
        'notification_success' => [
            'table' => 'meli_notification_events',
            'date' => 'erp_received_at',
            'predicate' => 'status IN ("processed","ignored","duplicate")',
        ],
        'notification_incidents' => [
            'table' => 'meli_notification_events',
            'date' => 'erp_received_at',
            'predicate' => 'status IN ("failed","unknown_topic")',
        ],
        'api_request_logs' => ['table' => 'api_request_logs', 'date' => 'created_at'],
        'cron_health_checks' => ['table' => 'cron_health_checks', 'date' => 'created_at'],
        'financial_job_items' => ['table' => 'order_financial_recalc_job_items', 'date' => 'created_at'],
        'cron_run_steps' => ['table' => 'system_cron_run_steps', 'date' => 'created_at'],
        'process_metrics' => ['table' => 'system_process_metrics', 'date' => 'measured_at'],
        'work_queue_items' => ['table' => 'system_work_queue_run_items', 'date' => 'created_at'],
        'work_queue_runs' => ['table' => 'system_work_queue_runs', 'date' => 'created_at'],
        'api_budget_windows' => ['table' => 'api_budget_windows', 'date' => 'window_started_at'],
        'manual_probe_runs' => ['table' => 'manual_engine_probe_runs', 'date' => 'started_at'],
        'performance_metrics' => ['table' => 'system_performance_metrics', 'date' => 'recorded_at'],
        'system_logs' => ['table' => 'system_logs', 'date' => 'created_at'],
        'api_operation_samples' => [
            'table' => 'api_operation_metric_samples',
            'date' => 'created_at',
        ],
        'webhook_events' => ['table' => 'meli_webhook_events', 'date' => 'received_at'],
        'cron_backlog_snapshots' => ['table' => 'system_cron_backlog_snapshots', 'date' => 'measured_at'],
        'cron_backlog_run_totals' => ['table' => 'system_cron_backlog_run_totals', 'date' => 'measured_at'],
        'manual_campaign_events' => ['table' => 'manual_campaign_events', 'date' => 'created_at'],
        'api_remote_permits' => [
            'table' => 'api_remote_permits',
            'date' => 'created_at',
            'predicate' => 'status IN ("completed","released","expired")',
        ],
        'operational_snapshots' => [
            'table' => 'system_operational_snapshots',
            'date' => 'measured_at',
        ],
    ];

    /**
     * Construye un archivo mensual con un único lote de base de datos por
     * invocación. El archivo temporal se recorta al último byte aprobado antes
     * de continuar, de modo que una respuesta perdida no duplica filas.
     *
     * @return array{stage:string,processed:int,complete:bool,row_count:int}
     */
    public function createStep(
        string $dataset,
        string $month,
        int $batchSize = 500,
        ?callable $leaseGuard = null
    ): array
    {
        $this->assertLease($leaseGuard);
        $definition = self::DATASETS[$dataset] ?? null;
        [$from, $to] = $this->period($month);
        if ($definition === null || $to > new DateTimeImmutable(gmdate('Y-m-01 00:00:00'))) {
            throw new RuntimeException('Solo se pueden archivar meses cerrados y conjuntos conocidos.');
        }
        $batchSize = max(1, min(self::INTERACTIVE_MAX_BATCH, $batchSize));
        $existing = $this->find($dataset, $month);
        if (is_array($existing) && (string) $existing['status'] === 'ready') {
            return [
                'stage' => 'ready',
                'processed' => 0,
                'complete' => true,
                'row_count' => (int) ($existing['row_count'] ?? 0),
            ];
        }
        if (is_array($existing) && (string) $existing['status'] === 'verifying') {
            $ready = $this->verifyIncremental($existing, $dataset, $month, $leaseGuard);
            return [
                'stage' => 'verified',
                'processed' => 0,
                'complete' => true,
                'row_count' => (int) ($ready['row_count'] ?? 0),
            ];
        }

        $this->assertLease($leaseGuard);
        $directory = AppPaths::coldArchives();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el archivo histórico privado.');
        }
        @chmod($directory, 0700);
        $lock = @fopen($directory . '/.archive.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Ya existe un archivo histórico en preparación.');
        }

        try {
            $this->assertLease($leaseGuard);
            $archive = $this->prepareIncremental($dataset, $month);
            $workingName = basename((string) ($archive['build_storage_name'] ?? ''));
            if (preg_match('/^\.build-[a-f0-9]{32}\.jsonl\.part$/', $workingName) !== 1) {
                throw new RuntimeException('El checkpoint del archivo histórico no es válido.');
            }
            $working = $directory . '/' . $workingName;
            $approvedBytes = max(0, (int) ($archive['build_plain_bytes'] ?? 0));
            $approvedCursor = max(0, (int) ($archive['build_cursor_id'] ?? 0));
            if (!is_file($working) && ($approvedBytes > 0 || $approvedCursor > 0)) {
                $this->assertLease($leaseGuard);
                $this->resetIncremental((int) $archive['id']);
                $this->assertLease($leaseGuard);
                $archive = $this->prepareIncremental($dataset, $month);
                $workingName = basename((string) $archive['build_storage_name']);
                $working = $directory . '/' . $workingName;
                $approvedBytes = 0;
                $approvedCursor = 0;
            }

            $handle = @fopen($working, 'c+b');
            if ($handle === false || !@flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new RuntimeException('No fue posible abrir el checkpoint del archivo histórico.');
            }
            try {
                $this->assertLease($leaseGuard);
                if (!@ftruncate($handle, $approvedBytes) || @fseek($handle, $approvedBytes) !== 0) {
                    throw new RuntimeException('No fue posible recuperar el último byte aprobado.');
                }
                if ($approvedBytes === 0) {
                    $manifest = json_encode([
                        'type' => 'manifest',
                        'format' => 2,
                        'dataset' => $dataset,
                        'period' => $month,
                        'created_at' => gmdate(DATE_ATOM),
                        'erp_version' => AppVersionService::fileVersion(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    if (@fwrite($handle, $manifest) !== strlen($manifest)) {
                        throw new RuntimeException('No fue posible iniciar el archivo histórico.');
                    }
                }

                $batch = $this->readIncrementalBatch(
                    $definition,
                    $from,
                    $to,
                    $approvedCursor,
                    $batchSize
                );
                if ($batch === []) {
                    @fflush($handle);
                    @flock($handle, LOCK_UN);
                    fclose($handle);
                    $handle = null;
                    $this->assertLease($leaseGuard);
                    $prepared = $this->finalizeIncremental(
                        $archive,
                        $dataset,
                        $month,
                        $working,
                        $leaseGuard
                    );
                    return [
                        'stage' => 'encrypted',
                        'processed' => 0,
                        'complete' => false,
                        'row_count' => (int) ($prepared['row_count'] ?? 0),
                    ];
                }

                $lastId = $approvedCursor;
                $first = null;
                $last = null;
                $memberships = [];
                $dateColumn = (string) $definition['date'];
                foreach ($batch as $index => $row) {
                    if ($index > 0 && $index % 100 === 0) {
                        $this->assertLease($leaseGuard);
                    }
                    $json = json_encode(
                        $row,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                    $line = $json . PHP_EOL;
                    if (@fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException('No fue posible guardar un lote del archivo histórico.');
                    }
                    $lastId = max($lastId, (int) ($row['id'] ?? 0));
                    $sourceId = (int) ($row['id'] ?? 0);
                    if ($sourceId <= 0) {
                        throw new RuntimeException('El archivo contiene una identidad de fila inválida.');
                    }
                    $memberships[] = [$sourceId, self::rowHash($row)];
                    $value = (string) ($row[$dateColumn] ?? '');
                    $first ??= $value !== '' ? $value : null;
                    $last = $value !== '' ? $value : $last;
                }
                @fflush($handle);
                $newBytes = @ftell($handle);
                if (!is_int($newBytes) || $newBytes <= $approvedBytes) {
                    throw new RuntimeException('El checkpoint del archivo no avanzó.');
                }

                $this->assertLease($leaseGuard);
                $pdo = Database::connection();
                $pdo->beginTransaction();
                try {
                    $member = $pdo->prepare(
                        'INSERT INTO system_cold_archive_memberships
                         (archive_id,source_table,source_id,row_sha256)
                         VALUES (:archive_id,:source_table,:source_id,:row_hash)
                         ON DUPLICATE KEY UPDATE
                            row_sha256=VALUES(row_sha256),
                            source_deleted_at=NULL,stale_at=NULL,verification_error=NULL'
                    );
                    foreach ($memberships as [$sourceId, $rowHash]) {
                        $member->execute([
                            'archive_id' => (int) $archive['id'],
                            'source_table' => (string) $definition['table'],
                            'source_id' => $sourceId,
                            'row_hash' => $rowHash,
                        ]);
                    }
                    $this->assertLease($leaseGuard);
                    $update = $pdo->prepare(
                        'UPDATE system_cold_archives
                         SET build_cursor_id=:cursor,build_plain_bytes=:bytes,
                             row_count=row_count+:rows,
                             first_row_at=COALESCE(first_row_at,:first_row),
                             last_row_at=COALESCE(:last_row,last_row_at),
                             build_heartbeat_at=UTC_TIMESTAMP(3),
                             safe_error_code=NULL,safe_error_message=NULL
                         WHERE id=:id AND status="creating"
                           AND build_cursor_id=:approved_cursor
                           AND build_plain_bytes=:approved_bytes'
                    );
                    $update->execute([
                        'cursor' => $lastId,
                        'bytes' => $newBytes,
                        'rows' => count($batch),
                        'first_row' => $first,
                        'last_row' => $last,
                        'id' => (int) $archive['id'],
                        'approved_cursor' => $approvedCursor,
                        'approved_bytes' => $approvedBytes,
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Otro proceso modificó el checkpoint del archivo.');
                    }
                    $this->assertLease($leaseGuard);
                    $pdo->commit();
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    @ftruncate($handle, $approvedBytes);
                    throw $error;
                }
                return [
                    'stage' => 'archive_batch',
                    'processed' => count($batch),
                    'complete' => false,
                    'row_count' => (int) ($archive['row_count'] ?? 0) + count($batch),
                ];
            } finally {
                if (is_resource($handle)) {
                    @flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    public function create(string $dataset, string $month): array
    {
        for ($step = 0; $step < 100000; $step++) {
            $result = $this->createStep($dataset, $month, self::INTERACTIVE_MAX_BATCH);
            if (!empty($result['complete'])) {
                return $this->find($dataset, $month) ?? [];
            }
        }
        throw new RuntimeException('El archivo histórico excedió el límite seguro de lotes.');
    }

    /** @return array{rows:int,content_sha256:string,key_id:string} */
    public function verifyFile(
        string $path,
        string $dataset,
        string $month,
        ?callable $leaseGuard = null
    ): array
    {
        $this->assertLease($leaseGuard);
        $temporary = $path . '.verify-' . bin2hex(random_bytes(4)) . '.jsonl.gz';
        try {
            $decrypted = (new PrivateArchiveCipher())->decryptTo($path, $temporary);
            $gzip = @gzopen($temporary, 'rb');
            if ($gzip === false) {
                throw new RuntimeException('El archivo histórico descifrado no se puede abrir.');
            }
            $rows = 0;
            $hash = hash_init('sha256');
            $manifest = null;
            $footer = null;
            try {
                while (($line = gzgets($gzip)) !== false) {
                    if ($rows > 0 && $rows % 250 === 0) {
                        $this->assertLease($leaseGuard);
                    }
                    $decoded = json_decode(trim($line), true);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('El archivo histórico contiene una fila inválida.');
                    }
                    if (($decoded['type'] ?? null) === 'manifest') {
                        $manifest = $decoded;
                        continue;
                    }
                    if (($decoded['type'] ?? null) === 'complete') {
                        $footer = $decoded;
                        continue;
                    }
                    hash_update($hash, rtrim($line, "\r\n") . PHP_EOL);
                    $rows++;
                }
            } finally {
                gzclose($gzip);
            }
            $contentHash = hash_final($hash);
            if (
                !is_array($manifest)
                || !is_array($footer)
                || ($manifest['dataset'] ?? '') !== $dataset
                || ($manifest['period'] ?? '') !== $month
                || (int) ($footer['rows'] ?? -1) !== $rows
                || !hash_equals((string) ($footer['content_sha256'] ?? ''), $contentHash)
            ) {
                throw new RuntimeException('El archivo histórico no superó su manifiesto.');
            }
            return ['rows' => $rows, 'content_sha256' => $contentHash, 'key_id' => $decrypted['key_id']];
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string $dataset, string $month): ?array
    {
        if (!isset(self::DATASETS[$dataset]) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM system_cold_archives
             WHERE dataset_key=:dataset AND period_month=:month LIMIT 1'
        );
        $stmt->execute(['dataset' => $dataset, 'month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{deleted:int,errors:int} */
    public function purgeExpired(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = Database::connection()->query(
            'SELECT id,storage_name
             FROM system_cold_archives
             WHERE status="ready" AND delete_after IS NOT NULL
               AND delete_after<=UTC_TIMESTAMP(3)
               AND NOT EXISTS (
                   SELECT 1 FROM system_cold_archive_memberships membership
                   WHERE membership.archive_id=system_cold_archives.id
                     AND membership.source_deleted_at IS NULL
                     AND membership.stale_at IS NULL
               )
             ORDER BY delete_after,id
             LIMIT ' . $limit
        );
        $result = ['deleted' => 0, 'errors' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $storage = (string) ($row['storage_name'] ?? '');
            if (
                preg_match('/^[a-z_]+-\d{4}-\d{2}-[a-f0-9]{12}\.erparchive$/', $storage) !== 1
            ) {
                $result['errors']++;
                continue;
            }
            $path = AppPaths::coldArchives() . '/' . $storage;
            if (is_file($path) && !@unlink($path)) {
                $result['errors']++;
                continue;
            }
            Database::connection()->prepare(
                'UPDATE system_cold_archives
                 SET status="deleted",storage_name=NULL,size_bytes=0
                 WHERE id=:id AND status="ready"'
            )->execute(['id' => (int) $row['id']]);
            $result['deleted']++;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function prepareIncremental(string $dataset, string $month): array
    {
        $existing = $this->find($dataset, $month);
        if (is_array($existing) && (string) $existing['status'] === 'creating') {
            return $existing;
        }
        $publicId = is_array($existing) && !empty($existing['public_id'])
            ? (string) $existing['public_id']
            : $this->uuid();
        $working = '.build-' . bin2hex(random_bytes(16)) . '.jsonl.part';
        $stmt = Database::connection()->prepare(
            'INSERT INTO system_cold_archives
             (public_id,dataset_key,period_month,status,build_cursor_id,
              build_plain_bytes,build_storage_name,build_started_at,build_heartbeat_at,
              row_count,size_bytes,first_row_at,last_row_at,safe_error_code,safe_error_message)
             VALUES (:public_id,:dataset,:month,"creating",0,0,:working,
                     UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),0,0,NULL,NULL,NULL,NULL)
             ON DUPLICATE KEY UPDATE
                id=LAST_INSERT_ID(id),status="creating",
                build_cursor_id=0,build_plain_bytes=0,build_storage_name=VALUES(build_storage_name),
                build_started_at=UTC_TIMESTAMP(3),build_heartbeat_at=UTC_TIMESTAMP(3),
                row_count=0,size_bytes=0,first_row_at=NULL,last_row_at=NULL,
                storage_name=NULL,key_id=NULL,content_sha256=NULL,archive_sha256=NULL,
                verified_at=NULL,membership_verified_at=NULL,
                rollup_verified_at=NULL,delete_after=NULL,
                safe_error_code=NULL,safe_error_message=NULL'
        );
        $stmt->execute([
            'public_id' => $publicId,
            'dataset' => $dataset,
            'month' => $month,
            'working' => $working,
        ]);
        $row = $this->find($dataset, $month);
        if (!is_array($row)) {
            throw new RuntimeException('No fue posible crear el checkpoint del archivo histórico.');
        }
        Database::connection()->prepare(
            'DELETE FROM system_cold_archive_memberships WHERE archive_id=:archive_id'
        )->execute(['archive_id' => (int) $row['id']]);
        return $row;
    }

    private function resetIncremental(int $archiveId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM system_cold_archive_memberships WHERE archive_id=:archive_id'
            )->execute(['archive_id' => $archiveId]);
            $pdo->prepare(
                'UPDATE system_cold_archives
                 SET status="failed",build_cursor_id=0,build_plain_bytes=0,
                     build_storage_name=NULL,build_started_at=NULL,build_heartbeat_at=NULL,
                     row_count=0,size_bytes=0,first_row_at=NULL,last_row_at=NULL,
                     membership_verified_at=NULL,
                     safe_error_code="checkpoint_missing",
                     safe_error_message="El archivo temporal dejó de estar disponible."
                 WHERE id=:id AND status="creating"'
            )->execute(['id' => $archiveId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array{table:string,date:string,predicate?:string} $definition
     * @return list<array<string,mixed>>
     */
    private function readIncrementalBatch(
        array $definition,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $lastId,
        int $limit
    ): array {
        $predicate = isset($definition['predicate'])
            ? ' AND (' . $definition['predicate'] . ')'
            : '';
        // Partition details by the parent's actual close month, not reservation
        // month: a late close can never be omitted from an already-ready archive.
        if (!empty($definition['outer_http'])) {
            $children = $definition['table'] === 'system_execution_attempts';
            $fromTable = $children
                ? 'system_execution_attempts INNER JOIN system_execution_runs parent_run'
                    . ' ON parent_run.id=system_execution_attempts.system_execution_run_id'
                : 'system_execution_runs parent_run';
            $selected = $children ? 'system_execution_attempts' : 'parent_run';
            $stmt = Database::connection()->prepare(
                'SELECT ' . $selected . '.* FROM ' . $fromTable
                . ' WHERE parent_run.finished_at>=:from AND parent_run.finished_at<:to'
                . ' AND ' . self::outerHttpRunScope('parent_run')
                . ($children ? ' AND system_execution_attempts.http_request_id IS NOT NULL' : '')
                . ' AND ' . $selected . '.id>:last_id ORDER BY ' . $selected . '.id'
                . ' LIMIT ' . max(1, min(self::INTERACTIVE_MAX_BATCH, $limit))
            );
            $stmt->execute(['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'last_id' => $lastId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM `' . $definition['table'] . '`
             WHERE `' . $definition['date'] . '`>=:from
               AND `' . $definition['date'] . '`<:to
               ' . $predicate . '
               AND id>:last_id
             ORDER BY id
             LIMIT ' . max(1, min(self::INTERACTIVE_MAX_BATCH, $limit))
        );
        $stmt->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'last_id' => $lastId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function finalizeIncremental(
        array $archive,
        string $dataset,
        string $month,
        string $working,
        ?callable $leaseGuard = null
    ): array {
        $this->assertLease($leaseGuard);
        $directory = AppPaths::coldArchives();
        $gzip = $working . '.gz';
        $publicId = (string) $archive['public_id'];
        $targetName = $dataset . '-' . $month . '-'
            . substr(str_replace('-', '', $publicId), 0, 12) . '.erparchive';
        $target = $directory . '/' . $targetName;
        $input = @fopen($working, 'rb');
        // El archivo ya quedará cifrado y su retención es temporal. Nivel 1
        // mantiene buena reducción sin convertir el cierre del archivo en una
        // petición larga que exceda el presupuesto interactivo.
        $output = @gzopen($gzip, 'wb1');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('No fue posible preparar la verificación final del archivo.');
        }

        $hash = hash_init('sha256');
        $rows = 0;
        $manifestFound = false;
        try {
            while (($line = fgets($input)) !== false) {
                if ($rows > 0 && $rows % 250 === 0) {
                    $this->assertLease($leaseGuard);
                }
                $decoded = json_decode(trim($line), true);
                if (!$manifestFound) {
                    if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'manifest') {
                        throw new RuntimeException('El checkpoint no contiene un manifiesto válido.');
                    }
                    $manifestFound = true;
                    gzwrite($output, rtrim($line, "\r\n") . PHP_EOL);
                    continue;
                }
                if (!is_array($decoded) || array_key_exists('type', $decoded)) {
                    throw new RuntimeException('El checkpoint contiene una fila inválida.');
                }
                $normalized = rtrim($line, "\r\n") . PHP_EOL;
                gzwrite($output, $normalized);
                hash_update($hash, $normalized);
                $rows++;
            }
            $contentHash = hash_final($hash);
            gzwrite($output, json_encode([
                'type' => 'complete',
                'rows' => $rows,
                'content_sha256' => $contentHash,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } finally {
            fclose($input);
            gzclose($output);
        }
        if (!$manifestFound || $rows !== (int) ($archive['row_count'] ?? -1)) {
            @unlink($gzip);
            throw new RuntimeException('El total del checkpoint no coincide con la base.');
        }

        try {
            $this->assertLease($leaseGuard);
            $key = (new BackupKeyringService())->active();
            (new PrivateArchiveCipher())->encrypt($gzip, $target, $key['id'], $key['key']);
            $this->assertLease($leaseGuard);
            Database::connection()->prepare(
                'UPDATE system_cold_archives
                 SET status="verifying",storage_name=:storage,key_id=:key_id,
                     size_bytes=:size,content_sha256=:content_hash,
                     archive_sha256=:archive_hash,verified_at=NULL,
                     membership_verified_at=NULL,delete_after=NULL,
                     build_cursor_id=0,build_plain_bytes=0,
                     build_storage_name=NULL,build_started_at=NULL,build_heartbeat_at=NULL,
                     safe_error_code=NULL,safe_error_message=NULL
                 WHERE id=:id AND status="creating"'
            )->execute([
                'storage' => $targetName,
                'key_id' => $key['id'],
                'size' => (int) @filesize($target),
                'content_hash' => $contentHash,
                'archive_hash' => (string) hash_file('sha256', $target),
                'id' => (int) $archive['id'],
            ]);
            @unlink($working);
            @unlink($gzip);
            return $this->find($dataset, $month) ?? [];
        } catch (Throwable $error) {
            @unlink($target);
            @unlink($gzip);
            throw $error;
        }
    }

    /**
     * Verifica en una petición separada el archivo ya cifrado. Así la lectura
     * del checkpoint y el descifrado de comprobación no comparten el mismo
     * límite web.
     *
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function verifyIncremental(
        array $archive,
        string $dataset,
        string $month,
        ?callable $leaseGuard = null
    ): array
    {
        $this->assertLease($leaseGuard);
        $storage = basename((string) ($archive['storage_name'] ?? ''));
        if (
            preg_match(
                '/^[a-z_]+-\d{4}-\d{2}-[a-f0-9]{12}\.erparchive$/',
                $storage
            ) !== 1
        ) {
            throw new RuntimeException('La referencia del archivo cifrado no es válida.');
        }
        $target = AppPaths::coldArchives() . '/' . $storage;
        if (
            !is_file($target)
            || !hash_equals(
                (string) ($archive['archive_sha256'] ?? ''),
                (string) hash_file('sha256', $target)
            )
        ) {
            throw new RuntimeException('El archivo cifrado cambió antes de su verificación.');
        }
        $verified = $this->verifyFile($target, $dataset, $month, $leaseGuard);
        if (
            $verified['rows'] !== (int) ($archive['row_count'] ?? -1)
            || !hash_equals(
                (string) ($archive['content_sha256'] ?? ''),
                $verified['content_sha256']
            )
        ) {
            throw new RuntimeException('El archivo histórico final no coincide con su captura.');
        }
        $membership = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM system_cold_archive_memberships
             WHERE archive_id=:archive_id'
        );
        $membership->execute(['archive_id' => (int) $archive['id']]);
        if ((int) $membership->fetchColumn() !== (int) $archive['row_count']) {
            throw new RuntimeException(
                'La lista exacta de filas archivadas no coincide con el archivo cifrado.'
            );
        }
        $retentionDays = max(1, min(3650, (new AppSettingsService())->int(
            'retention.cold_archive_server_days',
            90
        )));
        $deleteAfter = gmdate('Y-m-d H:i:s.v', time() + ($retentionDays * 86400));
        $this->assertLease($leaseGuard);
        $update = Database::connection()->prepare(
            'UPDATE system_cold_archives
             SET status="ready",verified_at=UTC_TIMESTAMP(3),
                 membership_verified_at=UTC_TIMESTAMP(3),delete_after=:delete_after,
                 safe_error_code=NULL,safe_error_message=NULL
             WHERE id=:id AND status="verifying"
               AND archive_sha256=:archive_hash'
        );
        $update->execute([
            'delete_after' => $deleteAfter,
            'id' => (int) $archive['id'],
            'archive_hash' => (string) $archive['archive_sha256'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('El archivo perdió su estado de verificación.');
        }
        $this->assertLease($leaseGuard);
        return $this->find($dataset, $month) ?? [];
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private function period(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new RuntimeException('El periodo mensual no es válido.');
        }
        $from = new DateTimeImmutable($month . '-01 00:00:00');
        return [$from, $from->modify('+1 month')];
    }

    private function assertLease(?callable $leaseGuard): void
    {
        if ($leaseGuard !== null) {
            $leaseGuard();
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
