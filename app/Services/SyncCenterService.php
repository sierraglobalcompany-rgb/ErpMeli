<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class SyncCenterService
{
    /** @var list<int>|null null representa exclusivamente el proceso CLI sin sesión. */
    private ?array $authorizedAccountIds = null;
    private bool $scopeResolved = false;

    public function accounts(): array
    {
        $where = [];
        $params = [];
        $this->appendAccountScope($where, $params, 'a.id');
        $stmt = Database::connection()->prepare(
            'SELECT a.id,a.account_name,a.nickname,a.status,a.last_sync_at,c.name company_name
             FROM meli_accounts a LEFT JOIN companies c ON c.id=a.company_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name,a.account_name'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function yearOverview(int $accountId, int $year): array
    {
        $this->assertAccountAccess($accountId);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $from = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
            $to = $from->modify('first day of next month');
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) total_chunks,
                        SUM(status="complete") complete_chunks,
                        SUM(status IN ("queued","running","partial")) active_chunks,
                        SUM(status="error") error_chunks,
                        COALESCE(SUM(processed_count),0) processed_count,
                        MAX(updated_at) updated_at
                 FROM sync_batch_chunks
                 WHERE meli_account_id=:account AND sync_type="orders" AND date_from>=:from AND date_from<:to'
            );
            $stmt->execute([
                'account' => $accountId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int) ($stats['total_chunks'] ?? 0);
            $complete = (int) ($stats['complete_chunks'] ?? 0);
            $status = $total === 0 ? 'pending' : ($complete === $total ? 'complete' : ((int) ($stats['error_chunks'] ?? 0) > 0 ? 'error' : 'partial'));
            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $this->monthLabel($month),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'status' => $status,
                'total_chunks' => $total,
                'complete_chunks' => $complete,
                'active_chunks' => (int) ($stats['active_chunks'] ?? 0),
                'error_chunks' => (int) ($stats['error_chunks'] ?? 0),
                'processed_count' => (int) ($stats['processed_count'] ?? 0),
                'updated_at' => $stats['updated_at'] ?? null,
            ];
        }
        return $months;
    }

    public function chunks(int $accountId, int $year, int $month): array
    {
        $this->assertAccountAccess($accountId);
        $stmt = Database::connection()->prepare(
            'SELECT ch.*, b.chunk_mode,b.chunk_parts
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             WHERE ch.meli_account_id=:account AND b.period_year=:year AND b.period_month=:month AND ch.sync_type="orders"
             ORDER BY ch.sequence_no'
        );
        $stmt->execute(['account' => $accountId, 'year' => $year, 'month' => $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function chunk(int $chunkId): ?array
    {
        $where = ['ch.id=:id'];
        $params = ['id' => $chunkId];
        $this->appendAccountScope($where, $params, 'ch.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT ch.* FROM sync_batch_chunks ch WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function monthStatus(int $accountId, int $year, int $month): array
    {
        $this->assertAccountAccess($accountId);
        $chunks = $this->chunks($accountId, $year, $month);
        $processed = 0;
        $estimated = 0;
        $complete = 0;
        $running = 0;
        $errors = 0;
        foreach ($chunks as $chunk) {
            $processed += (int) $chunk['processed_count'];
            $estimated += max((int) $chunk['estimated_total'], (int) $chunk['processed_count']);
            $complete += $chunk['status'] === 'complete' ? 1 : 0;
            $running += $chunk['status'] === 'running' ? 1 : 0;
            $errors += $chunk['status'] === 'error' ? 1 : 0;
        }
        $percent = $estimated > 0 ? min(100, round(($processed / $estimated) * 100, 1)) : ($chunks === [] ? 0 : round(($complete / count($chunks)) * 100, 1));
        $lastRun = Database::connection()->prepare(
            'SELECT started_at,finished_at,processed_count FROM sync_chunk_runs WHERE meli_account_id=:account ORDER BY started_at DESC LIMIT 1'
        );
        $lastRun->execute(['account' => $accountId]);
        $latest = $lastRun->fetch(PDO::FETCH_ASSOC) ?: null;
        return [
            'account_id' => $accountId,
            'year' => $year,
            'month' => $month,
            'chunks_total' => count($chunks),
            'chunks_complete' => $complete,
            'chunks_running' => $running,
            'chunks_error' => $errors,
            'processed' => $processed,
            'estimated' => $estimated,
            'percent' => $percent,
            'latest_run' => $latest,
            'chunks' => $chunks,
        ];
    }

    public function globalStatus(int $accountId = 0): array
    {
        $where = ['ch.sync_type="orders"'];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'ch.meli_account_id');
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) chunks_total,
                    SUM(ch.status="complete") chunks_complete,
                    SUM(ch.status="running") chunks_running,
                    SUM(ch.status="error") chunks_error,
                    SUM(ch.status IN ("queued","partial") AND (ch.next_run_at IS NULL OR ch.next_run_at<=UTC_TIMESTAMP())) chunks_overdue,
                    COALESCE(SUM(ch.processed_count),0) processed,
                    COALESCE(SUM(GREATEST(COALESCE(ch.estimated_total,0), ch.processed_count)),0) estimated
             FROM sync_batch_chunks ch
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $estimated = (int) ($row['estimated'] ?? 0);
        $processed = (int) ($row['processed'] ?? 0);
        $total = (int) ($row['chunks_total'] ?? 0);
        $complete = (int) ($row['chunks_complete'] ?? 0);
        $latestError = $this->latestError($accountId);
        return [
            'chunks_total' => $total,
            'chunks_complete' => $complete,
            'chunks_running' => (int) ($row['chunks_running'] ?? 0),
            'chunks_error' => (int) ($row['chunks_error'] ?? 0),
            'chunks_overdue' => (int) ($row['chunks_overdue'] ?? 0),
            'processed' => $processed,
            'estimated' => $estimated,
            'percent' => $estimated > 0 ? min(100, round(($processed / $estimated) * 100, 1)) : ($total > 0 ? round(($complete / $total) * 100, 1) : 0),
            'upcoming' => $this->upcomingSchedule($accountId, 3),
            'latest_error' => $latestError,
        ];
    }

    public function upcomingSchedule(int $accountId = 0, int $limit = 50): array
    {
        $where = ['ch.status IN ("queued","partial")'];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'ch.meli_account_id');
        }
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ch.next_run_at IS NULL ASC, ch.next_run_at ASC, ch.priority ASC, ch.id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function overdueSchedule(int $accountId = 0, int $limit = 50): array
    {
        $where = ['ch.status IN ("queued","partial")', '(ch.next_run_at IS NULL OR ch.next_run_at<=UTC_TIMESTAMP())'];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'ch.meli_account_id');
        }
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ch.next_run_at IS NULL DESC, ch.next_run_at ASC, ch.priority ASC, ch.id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reprogramOverdue(int $accountId = 0, ?DateTimeImmutable $runAt = null, int $limit = 500): int
    {
        $this->assertCanManage();
        $where = ['status IN ("queued","partial")', '(next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())'];
        $params = ['next_run' => $this->dbQueueDate($runAt)];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'meli_account_id');
        }
        $sql = 'UPDATE sync_batch_chunks
                SET status="queued",queued_at=COALESCE(queued_at,UTC_TIMESTAMP()),next_run_at=:next_run
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY next_run_at IS NULL DESC, next_run_at ASC, id ASC
                LIMIT ' . max(1, min(1000, $limit));
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function latestError(int $accountId = 0): ?array
    {
        try {
            $where = ['ch.last_error IS NOT NULL', 'ch.last_error<>""'];
            $params = [];
            if ($accountId > 0) {
                $this->assertAccountAccess($accountId);
                $where[] = 'ch.meli_account_id=:account';
                $params['account'] = $accountId;
            } else {
                $this->appendAccountScope($where, $params, 'ch.meli_account_id');
            }
            $stmt = Database::connection()->prepare(
                'SELECT ch.id,ch.sequence_no,ch.date_from,ch.date_to,ch.status,ch.last_error,ch.blocked_reason,ch.error_type,ch.error_http_status,ch.error_endpoint,ch.error_recommendation,a.account_name
                 FROM sync_batch_chunks ch
                 JOIN meli_accounts a ON a.id=ch.meli_account_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ch.updated_at DESC LIMIT 1'
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function planMonth(int $accountId, int $year, int $month, ?string $mode = null, ?int $parts = null): int
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden planificar sincronizaciones.');
        }
        if ($accountId <= 0 || $year < 2020 || $month < 1 || $month > 12) {
            throw new RuntimeException('Seleccione cuenta, año y mes válidos.');
        }
        $this->assertAccountAccess($accountId);
        $settings = new SyncSettingsService();
        $mode = in_array($mode, ['daily', 'weekly', 'parts'], true) ? $mode : $settings->chunkMode();
        $parts = $mode === 'parts' ? max(1, min(31, (int) ($parts ?: $settings->chunkParts()))) : null;
        $ranges = $this->rangesForMonth($year, $month, $mode, $parts ?: 0);
        $from = $ranges[0][0];
        $to = $ranges[count($ranges) - 1][1];
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO sync_batches (meli_account_id,sync_type,period_year,period_month,chunk_mode,chunk_parts,date_from,date_to,status,created_by)
             VALUES (:account,"orders",:year,:month,:mode,:parts,:from,:to,"draft",:user)
             ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),status=IF(status="complete","complete","draft"),updated_at=NOW(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $accountId,
            'year' => $year,
            'month' => $month,
            'mode' => $mode,
            'parts' => $parts,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'user' => Auth::id(),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $schema = new SchemaInspectorService();
        $hasRangeMeta = $schema->hasColumn('sync_batch_chunks', 'local_from') && $schema->hasColumn('sync_batch_chunks', 'utc_from');
        $insertSql = $hasRangeMeta
            ? 'INSERT INTO sync_batch_chunks (sync_batch_id,meli_account_id,sync_type,sequence_no,date_from,date_to,local_from,local_to,utc_from,utc_to,timezone,date_field_used,status)
               VALUES (:batch,:account,"orders",:seq,:from,:to,:local_from,:local_to,:utc_from,:utc_to,:timezone,"date_created","pending")
               ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),local_from=VALUES(local_from),local_to=VALUES(local_to),utc_from=VALUES(utc_from),utc_to=VALUES(utc_to),timezone=VALUES(timezone),date_field_used=VALUES(date_field_used),status=IF(status="complete","complete",status)'
            : 'INSERT INTO sync_batch_chunks (sync_batch_id,meli_account_id,sync_type,sequence_no,date_from,date_to,status)
               VALUES (:batch,:account,"orders",:seq,:from,:to,"pending")
               ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),status=IF(status="complete","complete",status)';
        $insert = $pdo->prepare($insertSql);
        $rangeService = new MeliDateRangeService();
        foreach ($ranges as $idx => [$rangeFrom, $rangeTo]) {
            $params = [
                'batch' => $batchId,
                'account' => $accountId,
                'seq' => $idx + 1,
                'from' => $rangeFrom->format('Y-m-d H:i:s'),
                'to' => $rangeTo->format('Y-m-d H:i:s'),
            ];
            if ($hasRangeMeta) {
                $normalized = $rangeService->fromLocal($rangeFrom, $rangeTo);
                $params += [
                    'local_from' => $normalized['local_from']->format('Y-m-d H:i:s'),
                    'local_to' => $normalized['local_to']->format('Y-m-d H:i:s'),
                    'utc_from' => $normalized['utc_from']->format('Y-m-d H:i:s'),
                    'utc_to' => $normalized['utc_to']->format('Y-m-d H:i:s'),
                    'timezone' => $normalized['timezone'],
                ];
            }
            $insert->execute($params);
        }
        AuditService::record('plan_sync_month', 'sync_batches', 'sync', $batchId, $accountId, null, ['year' => $year, 'month' => $month, 'mode' => $mode, 'parts' => $parts]);
        return $batchId;
    }

    public function enqueueChunk(int $chunkId, ?DateTimeImmutable $runAt = null): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden encolar sincronizaciones.');
        }
        $this->assertChunkAccess($chunkId);
        $nextRun = $this->dbQueueDate($runAt);
        $stmt = Database::connection()->prepare(
            'UPDATE sync_batch_chunks SET status="queued",queued_at=UTC_TIMESTAMP(),next_run_at=:next_run,last_error=NULL,error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL
             WHERE id=:id AND status IN ("pending","partial","error","queued")'
        );
        $stmt->execute(['id' => $chunkId, 'next_run' => $nextRun]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('El bloque no se puede encolar en su estado actual.');
        }
        $this->refreshBatchStatusByChunk($chunkId);
    }

    public function enqueueMonth(int $batchId, ?DateTimeImmutable $runAt = null): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden encolar sincronizaciones.');
        }
        $this->assertBatchAccess($batchId);
        $nextRun = $this->dbQueueDate($runAt);
        Database::connection()->prepare(
            'UPDATE sync_batch_chunks SET status="queued",queued_at=UTC_TIMESTAMP(),next_run_at=:next_run,last_error=NULL,error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL
             WHERE sync_batch_id=:batch AND status IN ("pending","partial","error","queued")'
        )->execute(['batch' => $batchId, 'next_run' => $nextRun]);
        Database::connection()->prepare('UPDATE sync_batches SET status="queued" WHERE id=:batch AND status<>"complete"')->execute(['batch' => $batchId]);
    }

    public function enqueueRange(int $accountId, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $runAt = null): int
    {
        if (Auth::check()) {
            Auth::requireRole('admin', 'operador');
            if (Auth::isTemporary()) {
                throw new RuntimeException('Los usuarios temporales no pueden encolar sincronizaciones.');
            }
        }
        if ($accountId <= 0 || $to < $from) {
            throw new RuntimeException('Rango inválido para encolar sincronización.');
        }
        $this->assertAccountAccess($accountId);
        $pdo = Database::connection();
        $year = (int) $from->format('Y');
        $month = (int) $from->format('n');
        $partsKey = 1000 + (int) $from->format('z');
        $stmt = $pdo->prepare(
            'INSERT INTO sync_batches (meli_account_id,sync_type,period_year,period_month,chunk_mode,chunk_parts,date_from,date_to,status,created_by)
             VALUES (:account,"orders",:year,:month,"parts",:parts,:from,:to,"queued",:user)
             ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),status="queued",updated_at=NOW(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $accountId,
            'year' => $year,
            'month' => $month,
            'parts' => $partsKey,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'user' => Auth::id(),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $schema = new SchemaInspectorService();
        $rangeService = new MeliDateRangeService();
        $normalized = $rangeService->fromLocal($from, $to);
        $hasRangeMeta = $schema->hasColumn('sync_batch_chunks', 'local_from') && $schema->hasColumn('sync_batch_chunks', 'utc_from');
        $sql = $hasRangeMeta
            ? 'INSERT INTO sync_batch_chunks (sync_batch_id,meli_account_id,sync_type,sequence_no,date_from,date_to,local_from,local_to,utc_from,utc_to,timezone,date_field_used,status,next_run_at,queued_at)
               VALUES (:batch,:account,"orders",1,:from,:to,:local_from,:local_to,:utc_from,:utc_to,:timezone,"date_created","queued",:next_run,UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),local_from=VALUES(local_from),local_to=VALUES(local_to),utc_from=VALUES(utc_from),utc_to=VALUES(utc_to),timezone=VALUES(timezone),date_field_used=VALUES(date_field_used),status="queued",next_run_at=VALUES(next_run_at),queued_at=UTC_TIMESTAMP(),id=LAST_INSERT_ID(id)'
            : 'INSERT INTO sync_batch_chunks (sync_batch_id,meli_account_id,sync_type,sequence_no,date_from,date_to,status,next_run_at,queued_at)
               VALUES (:batch,:account,"orders",1,:from,:to,"queued",:next_run,UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),status="queued",next_run_at=VALUES(next_run_at),queued_at=UTC_TIMESTAMP(),id=LAST_INSERT_ID(id)';
        $params = [
            'batch' => $batchId,
            'account' => $accountId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'next_run' => $this->dbQueueDate($runAt),
        ];
        if ($hasRangeMeta) {
            $params += [
                'local_from' => $normalized['local_from']->format('Y-m-d H:i:s'),
                'local_to' => $normalized['local_to']->format('Y-m-d H:i:s'),
                'utc_from' => $normalized['utc_from']->format('Y-m-d H:i:s'),
                'utc_to' => $normalized['utc_to']->format('Y-m-d H:i:s'),
                'timezone' => $normalized['timezone'],
            ];
        }
        $pdo->prepare($sql)->execute($params);
        return (int) $pdo->lastInsertId();
    }

    public function rescheduleChunk(int $chunkId, DateTimeImmutable $runAt): void
    {
        $this->assertCanManage();
        $this->assertChunkAccess($chunkId);
        $stmt = Database::connection()->prepare(
            'UPDATE sync_batch_chunks SET status="queued",queued_at=COALESCE(queued_at,UTC_TIMESTAMP()),next_run_at=:next_run,last_error=NULL,error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL
             WHERE id=:id AND status IN ("pending","queued","partial","error")'
        );
        $stmt->execute(['id' => $chunkId, 'next_run' => $this->dbQueueDate($runAt)]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No se puede reprogramar un bloque en ejecución, completo o cancelado.');
        }
        $this->refreshBatchStatusByChunk($chunkId);
    }

    public function cancelChunk(int $chunkId): void
    {
        $this->assertCanManage();
        $this->assertChunkAccess($chunkId);
        $stmt = Database::connection()->prepare('UPDATE sync_batch_chunks SET status="cancelled",next_run_at=NULL,last_error=NULL,error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL WHERE id=:id AND status<>"running"');
        $stmt->execute(['id' => $chunkId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No se puede cancelar un bloque en ejecución.');
        }
        $this->refreshBatchStatusByChunk($chunkId);
    }

    public function reactivateChunk(int $chunkId): void
    {
        $this->assertCanManage();
        $this->assertChunkAccess($chunkId);
        Database::connection()->prepare('UPDATE sync_batch_chunks SET status="pending",next_run_at=NULL,last_error=NULL,error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL WHERE id=:id AND status IN ("cancelled","error")')
            ->execute(['id' => $chunkId]);
        $this->refreshBatchStatusByChunk($chunkId);
    }

    public function deleteChunk(int $chunkId): void
    {
        $this->assertCanManage();
        $this->assertChunkAccess($chunkId);
        $pdo = Database::connection();
        $chunk = $this->chunk($chunkId);
        if (!$chunk) {
            throw new RuntimeException('Bloque no encontrado.');
        }
        if ($chunk['status'] === 'running') {
            throw new RuntimeException('No se puede eliminar un bloque en ejecución.');
        }
        $batchId = (int) $chunk['sync_batch_id'];
        $pdo->prepare('DELETE FROM sync_batch_chunks WHERE id=:id AND status<>"running"')->execute(['id' => $chunkId]);
        $this->refreshBatchStatus($batchId);
    }

    public function deleteBatch(int $batchId): void
    {
        $this->assertCanManage();
        $this->assertBatchAccess($batchId);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM sync_batch_chunks WHERE sync_batch_id=:batch AND status="running"');
        $stmt->execute(['batch' => $batchId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('No se puede eliminar un mes con bloques en ejecución.');
        }
        Database::connection()->prepare('DELETE FROM sync_batches WHERE id=:batch')->execute(['batch' => $batchId]);
    }

    public function batchForMonth(int $accountId, int $year, int $month): ?array
    {
        $this->assertAccountAccess($accountId);
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sync_batches WHERE meli_account_id=:account AND period_year=:year AND period_month=:month AND sync_type="orders" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'year' => $year, 'month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function recentRuns(int $accountId = 0): array
    {
        $conditions = [];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $conditions[] = 'r.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($conditions, $params, 'r.meli_account_id');
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $stmt = Database::connection()->prepare(
            'SELECT r.*, ch.date_from,ch.date_to,a.account_name
             FROM sync_chunk_runs r
             JOIN sync_batch_chunks ch ON ch.id=r.sync_batch_chunk_id
             JOIN meli_accounts a ON a.id=r.meli_account_id
             ' . $where . '
             ORDER BY r.started_at DESC LIMIT 20'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function refreshBatchStatusByChunk(int $chunkId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT sync_batch_id FROM sync_batch_chunks WHERE id=:id');
        $stmt->execute(['id' => $chunkId]);
        $batchId = (int) $stmt->fetchColumn();
        if ($batchId <= 0) {
            return;
        }
        $this->refreshBatchStatus($batchId);
    }

    public function refreshBatchStatus(int $batchId): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) total,
                    SUM(status="complete") complete_count,
                    SUM(status="error") error_count,
                    SUM(status IN ("queued","running","partial")) active_count
             FROM sync_batch_chunks WHERE sync_batch_id=:batch'
        );
        $stmt->execute(['batch' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $status = 'draft';
        if ($total > 0 && (int) ($row['complete_count'] ?? 0) === $total) {
            $status = 'complete';
        } elseif ((int) ($row['error_count'] ?? 0) > 0) {
            $status = 'error';
        } elseif ((int) ($row['active_count'] ?? 0) > 0) {
            $status = 'queued';
        }
        Database::connection()->prepare('UPDATE sync_batches SET status=:status WHERE id=:id')->execute(['status' => $status, 'id' => $batchId]);
    }

    public function rangesForMonth(int $year, int $month, string $mode, int $parts = 0): array
    {
        $tz = new DateTimeZone(DateTimePresenter::timezone());
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $end = $start->modify('first day of next month');
        if ($mode === 'daily') {
            $ranges = [];
            for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
                $ranges[] = [$cursor, $cursor->modify('+1 day')];
            }
            return $ranges;
        }
        if ($mode === 'parts') {
            return $this->partRanges($start, $end, max(1, $parts));
        }
        $ranges = [];
        $cursor = $start;
        while ($cursor < $end) {
            $rangeTo = $cursor->modify('monday next week');
            if ($rangeTo > $end) {
                $rangeTo = $end;
            }
            $ranges[] = [$cursor, $rangeTo];
            $cursor = $rangeTo;
        }
        return $ranges;
    }

    private function partRanges(DateTimeImmutable $start, DateTimeImmutable $end, int $parts): array
    {
        $days = (int) $start->diff($end)->format('%a');
        $parts = min($parts, $days);
        $ranges = [];
        $cursor = $start;
        for ($i = 0; $i < $parts; $i++) {
            $remainingDays = $days - (int) $start->diff($cursor)->format('%a');
            $remainingParts = $parts - $i;
            $chunkDays = (int) ceil($remainingDays / $remainingParts);
            $rangeTo = $cursor->modify('+' . $chunkDays . ' days');
            if ($rangeTo > $end) {
                $rangeTo = $end;
            }
            $ranges[] = [$cursor, $rangeTo];
            $cursor = $rangeTo;
        }
        return $ranges;
    }

    private function monthLabel(int $month): string
    {
        return ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'][$month - 1] ?? (string) $month;
    }

    private function assertCanManage(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden gestionar sincronizaciones.');
        }
    }

    /**
     * @return list<int>|null null solo se permite al proceso CLI sin sesión.
     */
    private function webAccountScope(): ?array
    {
        if ($this->scopeResolved) {
            return $this->authorizedAccountIds;
        }
        $this->scopeResolved = true;
        if (!Auth::check()) {
            $this->authorizedAccountIds = null;
            return null;
        }
        $this->authorizedAccountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        return $this->authorizedAccountIds;
    }

    /** @param array<int|string,mixed> $params */
    private function appendAccountScope(array &$where, array &$params, string $column): void
    {
        $scope = $this->webAccountScope();
        if ($scope === null) {
            $where[] = '1=1';
            return;
        }
        if ($scope === []) {
            $where[] = '1=0';
            return;
        }
        $placeholders = [];
        foreach ($scope as $index => $accountId) {
            $key = 'authorized_account_' . count($params) . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $accountId;
        }
        $where[] = $column . ' IN (' . implode(',', $placeholders) . ')';
    }

    private function assertAccountAccess(int $accountId): void
    {
        $scope = $this->webAccountScope();
        if ($accountId <= 0 || ($scope !== null && !in_array($accountId, $scope, true))) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
    }

    private function assertChunkAccess(int $chunkId): void
    {
        if ($chunkId <= 0 || $this->chunk($chunkId) === null) {
            throw new HttpException(404, 'No se encontró el bloque solicitado.');
        }
    }

    private function assertBatchAccess(int $batchId): void
    {
        $where = ['b.id=:batch'];
        $params = ['batch' => $batchId];
        $this->appendAccountScope($where, $params, 'b.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT b.id FROM sync_batches b WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
        );
        $stmt->execute($params);
        if ($batchId <= 0 || !$stmt->fetchColumn()) {
            throw new HttpException(404, 'No se encontró el plan solicitado.');
        }
    }

    public function dbQueueDate(?DateTimeImmutable $runAt = null): string
    {
        $runAt ??= new DateTimeImmutable('now', new DateTimeZone(DateTimePresenter::timezone()));
        return $runAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
