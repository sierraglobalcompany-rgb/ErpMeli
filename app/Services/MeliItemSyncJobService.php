<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Separa el descubrimiento de IDs del detalle de publicaciones.
 * Así un scroll_id nunca queda esperando mientras se descargan detalles.
 */
final class MeliItemSyncJobService
{
    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    public function isAvailable(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('meli_item_sync_jobs')
            && $schema->hasTable('meli_item_sync_job_items');
    }

    public function createOrResume(int $accountId, bool $allowRemoteProbe = true): int
    {
        if ($accountId < 1) {
            throw new \RuntimeException('Seleccione una cuenta Mercado Libre.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id
             FROM meli_item_sync_jobs
             WHERE meli_account_id=:account AND phase IN ("discovering","details","partial")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['account' => $accountId]);
        $existing = (int) ($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }
        $mode = $this->resolveMode($accountId, $allowRemoteProbe);
        Database::connection()->prepare(
            'INSERT INTO meli_item_sync_jobs
             (meli_account_id,search_mode,phase,next_run_at)
             VALUES (:account,:mode,"discovering",UTC_TIMESTAMP())'
        )->execute(['account' => $accountId, 'mode' => $mode]);
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @return array{processed:int,discovered:int,errors:int,job_id:int,phase:string,stop_reason:string}
     */
    public function processAccount(int $accountId, ?int $limit = null, ?float $deadline = null): array
    {
        $jobId = $this->createOrResume($accountId);
        return $this->processJob($jobId, $limit, $deadline);
    }

    /**
     * @return array{processed:int,discovered:int,errors:int,job_id:int,phase:string,stop_reason:string}
     */
    public function processDue(?int $limit = null, ?float $deadline = null): array
    {
        if (!$this->isAvailable()) {
            return ['processed' => 0, 'discovered' => 0, 'errors' => 0, 'job_id' => 0, 'phase' => 'unavailable', 'stop_reason' => 'schema_unavailable'];
        }
        $reservationFilter = (new SchemaInspectorService())->hasTable('manual_campaign_reservations')
            ? ' AND NOT EXISTS (
                 SELECT 1 FROM manual_campaign_reservations r
                 WHERE r.queue_key="items_sync" AND r.source_id=CAST(meli_item_sync_jobs.id AS CHAR)
                   AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
               )'
            : '';
        $stmt = Database::connection()->query(
            'SELECT id
             FROM meli_item_sync_jobs
             WHERE phase IN ("discovering","details","partial")
               AND next_run_at<=UTC_TIMESTAMP()
               AND (locked_at IS NULL OR locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))'
             . $reservationFilter . '
             ORDER BY next_run_at ASC,id ASC
             LIMIT 1'
        );
        $jobId = (int) ($stmt->fetchColumn() ?: 0);
        return $jobId > 0
            ? $this->processJob($jobId, $limit, $deadline)
            : ['processed' => 0, 'discovered' => 0, 'errors' => 0, 'job_id' => 0, 'phase' => 'empty', 'stop_reason' => 'empty'];
    }

    /**
     * @return array{processed:int,discovered:int,errors:int,job_id:int,phase:string,stop_reason:string}
     */
    public function processJob(int $jobId, ?int $limit = null, ?float $deadline = null): array
    {
        $job = $this->claim($jobId);
        if ($job === null) {
            return ['processed' => 0, 'discovered' => 0, 'errors' => 0, 'job_id' => $jobId, 'phase' => 'locked', 'stop_reason' => 'locked'];
        }
        $deadline ??= microtime(true) + 20;
        $summary = ['processed' => 0, 'discovered' => 0, 'errors' => 0, 'job_id' => $jobId, 'phase' => (string) $job['phase'], 'stop_reason' => 'batch_limit'];
        try {
            if ($job['phase'] === 'discovering') {
                $summary['discovered'] = $this->discoverPage($job);
                $job = $this->job($jobId);
                $summary['phase'] = (string) ($job['phase'] ?? 'discovering');
                $summary['stop_reason'] = $summary['phase'] === 'details' ? 'discovery_complete' : 'discovery_checkpoint';
                return $summary;
            }

            $limit ??= max(1, min(100, $this->settings->int('items.sync_detail_batch_limit', 25)));
            $items = $this->pendingItems($jobId, $limit);
            $sync = new MeliItemSyncService((int) $job['meli_account_id']);
            foreach ($items as $item) {
                if (microtime(true) >= $deadline) {
                    $summary['stop_reason'] = 'time_budget';
                    break;
                }
                try {
                    $detail = $sync->fetchRemoteItem((string) $item['external_item_id']);
                    if ($this->settings->bool('items.hybrid_bulk_updates_enabled', true)) {
                        (new MeliProductUpdateReviewService())->ingestDiscoveredSnapshot(
                            (int) $job['meli_account_id'],
                            (string) $item['external_item_id'],
                            $detail
                        );
                    } else {
                        $sync->persistApprovedItem($detail, null);
                    }
                    $this->finishItem((int) $item['id'], 'complete', null);
                    $summary['processed']++;
                } catch (Throwable $error) {
                    $maxAttempts = max(1, $this->settings->int('items.sync_job_max_attempts', 3));
                    $status = ((int) $item['attempts'] + 1) >= $maxAttempts ? 'error' : 'pending';
                    $this->finishItem((int) $item['id'], $status, $error);
                    $summary['errors']++;
                }
            }
            $this->refresh($jobId);
            $job = $this->job($jobId);
            $summary['phase'] = (string) ($job['phase'] ?? 'details');
            if ($summary['phase'] === 'complete') {
                $summary['stop_reason'] = 'complete';
            }
            return $summary;
        } catch (Throwable $error) {
            $discoveryRetry = (string) ($job['phase'] ?? '') === 'discovering';
            $cursorFailure = $discoveryRetry
                && (string) ($job['search_mode'] ?? '') === 'scan'
                && trim((string) ($job['cursor_value'] ?? '')) !== '';
            $restartCount = (int) ($job['cursor_restart_count'] ?? 0);
            $phase = $discoveryRetry ? 'discovering' : 'partial';
            if ($cursorFailure && $restartCount >= 10) {
                $phase = 'error';
            }
            Database::connectionFresh()->prepare(
                'UPDATE meli_item_sync_jobs
                 SET phase=:phase,
                     cursor_value=IF(:restart_cursor=1,NULL,cursor_value),
                     cursor_expires_at=IF(:restart_expiry=1,NULL,cursor_expires_at),
                     cursor_restart_count=cursor_restart_count+IF(:restart_count=1,1,0),
                     next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),
                     lock_token=NULL,locked_at=NULL,last_error_message=:error
                 WHERE id=:id'
            )->execute([
                'phase' => $phase,
                'restart_cursor' => $cursorFailure ? 1 : 0,
                'restart_expiry' => $cursorFailure ? 1 : 0,
                'restart_count' => $cursorFailure ? 1 : 0,
                'error' => mb_substr(Logger::redactString($error->getMessage()), 0, 500),
                'id' => $jobId,
            ]);
            throw $error;
        } finally {
            Database::connectionFresh()->prepare(
                'UPDATE meli_item_sync_jobs
                 SET lock_token=NULL,locked_at=NULL
                 WHERE id=:id'
            )->execute(['id' => $jobId]);
        }
    }

    private function discoverPage(array $job): int
    {
        $accountId = (int) $job['meli_account_id'];
        $sync = new MeliItemSyncService($accountId);
        $sellerId = $sync->sellerId();
        $api = new MeliApiClient($accountId);
        $mode = (string) $job['search_mode'];
        $pageLimit = max(20, min(100, $this->settings->int('items.sync_discovery_page_limit', 100)));
        if ($mode === 'scan') {
            $query = ['search_type' => 'scan'];
            $cursor = trim((string) ($job['cursor_value'] ?? ''));
            $expiresAt = trim((string) ($job['cursor_expires_at'] ?? ''));
            if ($cursor !== '' && $expiresAt !== '' && (strtotime($expiresAt) ?: 0) <= time()) {
                Database::connectionFresh()->prepare(
                    'UPDATE meli_item_sync_jobs
                     SET cursor_value=NULL,cursor_expires_at=NULL,
                         cursor_restart_count=cursor_restart_count+1
                     WHERE id=?'
                )->execute([(int) $job['id']]);
                $cursor = '';
            }
            if ($cursor !== '') {
                $query['scroll_id'] = $cursor;
            }
        } else {
            $query = ['offset' => (int) $job['offset_value'], 'limit' => $pageLimit];
        }
        $page = $api->get('/users/' . $sellerId . '/items/search', $query, ['job_type' => 'items_sync', 'bulk' => true]);
        $ids = array_values(array_filter(array_map('strval', is_array($page['results'] ?? null) ? $page['results'] : [])));
        $insert = Database::connection()->prepare(
            'INSERT IGNORE INTO meli_item_sync_job_items
             (meli_item_sync_job_id,external_item_id,status)
             VALUES (:job_id,:external_id,"pending")'
        );
        foreach ($ids as $externalId) {
            $insert->execute(['job_id' => (int) $job['id'], 'external_id' => $externalId]);
        }
        $nextCursor = $mode === 'scan' ? trim((string) ($page['scroll_id'] ?? '')) : '';
        $cursorTtl = max(60, min(300, $this->settings->int('items.scroll_cursor_ttl_seconds', 300)));
        $offset = (int) $job['offset_value'] + count($ids);
        $finished = $ids === []
            || ($mode === 'offset' && count($ids) < $pageLimit)
            || ($mode === 'scan' && $nextCursor === '');
        Database::connection()->prepare(
            'UPDATE meli_item_sync_jobs
             SET phase=:phase,cursor_value=:cursor,offset_value=:offset,
                 cursor_expires_at=CASE
                    WHEN :cursor_expires=1 THEN DATE_ADD(UTC_TIMESTAMP(),INTERVAL :cursor_ttl SECOND)
                    ELSE NULL END,
                 discovered_count=(SELECT COUNT(*) FROM meli_item_sync_job_items WHERE meli_item_sync_job_id=:count_job),
                 next_run_at=UTC_TIMESTAMP(),last_error_message=NULL
             WHERE id=:id'
        )->execute([
            'phase' => $finished ? 'details' : 'discovering',
            'cursor' => $nextCursor !== '' ? $nextCursor : null,
            'cursor_expires' => $mode === 'scan' && $nextCursor !== '' ? 1 : 0,
            'cursor_ttl' => $cursorTtl,
            'offset' => $offset,
            'count_job' => (int) $job['id'],
            'id' => (int) $job['id'],
        ]);
        return count($ids);
    }

    private function resolveMode(int $accountId, bool $allowRemoteProbe = true): string
    {
        $configured = (string) ($this->settings->get('items.search_mode', 'auto') ?: 'auto');
        if ($configured === 'offset' || !$this->settings->bool('items.scan_enabled', true)) {
            return 'offset';
        }
        if ($configured === 'scan') {
            return 'scan';
        }
        if (!$allowRemoteProbe) {
            return 'scan';
        }
        try {
            $sync = new MeliItemSyncService($accountId);
            $probe = (new MeliApiClient($accountId))->get(
                '/users/' . $sync->sellerId() . '/items/search',
                ['offset' => 0, 'limit' => 1],
                ['job_type' => 'items_sync']
            );
            return (int) ($probe['paging']['total'] ?? 0) >= max(100, $this->settings->int('items.scan_threshold', 1000))
                ? 'scan'
                : 'offset';
        } catch (Throwable) {
            return 'offset';
        }
    }

    private function claim(int $jobId): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $manualExecution = (string) (ApiExecutionMetadataContext::current()['source'] ?? '') === 'manual_campaign';
            $reservationReady = (new SchemaInspectorService())->hasTable('manual_campaign_reservations');
            $stmt = $pdo->prepare(
                'SELECT * FROM meli_item_sync_jobs
                 WHERE id=:id AND phase IN ("discovering","details","partial")
                   AND (locked_at IS NULL OR locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
                   ' . ($manualExecution || !$reservationReady ? '' : 'AND NOT EXISTS (
                     SELECT 1 FROM manual_campaign_reservations r
                     WHERE r.queue_key="items_sync" AND r.source_id=CAST(meli_item_sync_jobs.id AS CHAR)
                       AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
                   )') . '
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $pdo->prepare(
                'UPDATE meli_item_sync_jobs
                 SET lock_token=:token,locked_at=UTC_TIMESTAMP(),
                     phase=IF(phase="partial","details",phase)
                 WHERE id=:id'
            )->execute(['token' => $token, 'id' => $jobId]);
            $pdo->commit();
            if ($job['phase'] === 'partial') {
                $job['phase'] = 'details';
            }
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function pendingItems(int $jobId, int $limit): array
    {
        $maxAttempts = max(1, $this->settings->int('items.sync_job_max_attempts', 3));
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM meli_item_sync_job_items
             WHERE meli_item_sync_job_id=:job_id
               AND (status="pending" OR (status="error" AND attempts<' . $maxAttempts . '))
             ORDER BY status="pending" DESC,id ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function finishItem(int $itemId, string $status, ?Throwable $error): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE meli_item_sync_job_items
             SET status=:status,attempts=attempts+1,processed_at=IF(:done=1,UTC_TIMESTAMP(),processed_at),
                 last_error_message=:error
             WHERE id=:id'
        )->execute([
            'status' => $status,
            'done' => $status === 'complete' ? 1 : 0,
            'error' => $error ? mb_substr(Logger::redactString($error->getMessage()), 0, 500) : null,
            'id' => $itemId,
        ]);
    }

    private function refresh(int $jobId): void
    {
        $maxAttempts = max(1, $this->settings->int('items.sync_job_max_attempts', 3));
        $stmt = Database::connection()->prepare(
            'SELECT
                SUM(status="complete") complete_count,
                SUM(status="error") error_count,
                SUM(
                    status IN ("pending","running")
                    OR (status="error" AND attempts<' . $maxAttempts . ')
                ) retryable_count
             FROM meli_item_sync_job_items
             WHERE meli_item_sync_job_id=:job_id'
        );
        $stmt->execute(['job_id' => $jobId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $retryable = (int) ($counts['retryable_count'] ?? 0);
        $errors = (int) ($counts['error_count'] ?? 0);
        // "partial" solo significa que todavía queda trabajo recuperable.
        // Cuando todos los fallos agotaron sus intentos, el job debe quedar
        // terminal y salir del selector de Cron; de lo contrario se reclama y
        // actualiza cada minuto sin realizar ningún trabajo.
        $phase = $retryable > 0 ? 'details' : ($errors > 0 ? 'error' : 'complete');
        $terminal = in_array($phase, ['complete', 'error'], true);
        Database::connection()->prepare(
            'UPDATE meli_item_sync_jobs
             SET phase=:phase,processed_count=:processed,error_count=:errors,
                 completed_at=IF(:finished_complete=1,UTC_TIMESTAMP(),completed_at),
                 next_run_at=IF(:terminal=1,next_run_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))
             WHERE id=:id'
        )->execute([
            'phase' => $phase,
            'processed' => (int) ($counts['complete_count'] ?? 0),
            'errors' => $errors,
            'finished_complete' => $phase === 'complete' ? 1 : 0,
            'terminal' => $terminal ? 1 : 0,
            'id' => $jobId,
        ]);
    }

    private function job(int $jobId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM meli_item_sync_jobs WHERE id=:id');
        $stmt->execute(['id' => $jobId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
