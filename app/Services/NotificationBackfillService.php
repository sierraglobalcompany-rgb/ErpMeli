<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class NotificationBackfillService
{
    private ?bool $uniqueResourceTableAvailable = null;
    /** @var array<string,bool> */
    private array $tableExistsCache = [];
    /** @var array<string,array<string,mixed>> */
    private array $columnsCache = [];
    /** @var array<int,int> */
    private array $accountByUserCache = [];

    public function createAnalysis(int $accountId = 0, int $companyId = 0): int
    {
        $this->assertAvailable();
        $scope = $this->requestedScope($accountId, $companyId);
        $accountId = $scope['meli_account_id'];
        $companyId = $scope['company_id'];
        $activeStmt = Database::connection()->prepare(
            'SELECT r.id
             FROM meli_notification_backfill_runs r
             JOIN meli_accounts a
               ON a.id=r.meli_account_id AND a.company_id=r.company_id
             WHERE r.company_id=:company AND r.meli_account_id=:account
               AND r.status IN ("analyzing","ready","running","paused")
             ORDER BY r.id DESC LIMIT 1'
        );
        $activeStmt->execute(['company' => $companyId, 'account' => $accountId]);
        $active = $activeStmt->fetchColumn();
        if ($active !== false) {
            return (int) $active;
        }
        $totalStmt = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM meli_notification_events e
             JOIN meli_accounts a
               ON a.id=e.meli_account_id AND a.company_id=:company
             WHERE e.meli_account_id=:account'
        );
        $totalStmt->execute(['company' => $companyId, 'account' => $accountId]);
        $total = (int) $totalStmt->fetchColumn();
        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_notification_backfill_runs
             (company_id,meli_account_id,mode,status,source_total,priority_since,next_run_at,created_by,started_at)
             VALUES (?,? ,"analyze","analyzing",?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY),UTC_TIMESTAMP(),?,UTC_TIMESTAMP())'
        );
        $stmt->execute([$companyId, $accountId, $total, Auth::id()]);
        return (int) Database::connection()->lastInsertId();
    }

    public function start(int $runId, int $companyId = 0, int $accountId = 0): void
    {
        $run = $this->scopedRun($runId, $companyId, $accountId, true);
        $companyId = (int) $run['company_id'];
        $accountId = (int) $run['meli_account_id'];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE meli_notification_backfill_runs
                 SET mode="enqueue",status="running",checkpoint_event_id=0,analyzed_count=0,normalized_count=0,
                     phase="recent",
                     unique_resource_count=0,satisfied_local_count=0,queued_count=0,ignored_count=0,error_count=0,
                     next_run_at=UTC_TIMESTAMP(),last_error_message=NULL,started_at=UTC_TIMESTAMP(),completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("ready","paused","complete")'
            );
            $stmt->execute([$runId, $companyId, $accountId]);
            if ($stmt->rowCount() === 1 && $this->uniqueResourceTableAvailable()) {
                $pdo->prepare(
                    'DELETE FROM meli_notification_backfill_unique_resources
                     WHERE run_id=? AND company_id=? AND meli_account_id=?'
                )->execute([$runId, $companyId, $accountId]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function pause(int $runId, int $companyId = 0, int $accountId = 0): void
    {
        $run = $this->scopedRun($runId, $companyId, $accountId, true);
        Database::connection()->prepare(
            'UPDATE meli_notification_backfill_runs
             SET status="paused",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND status IN ("analyzing","running")'
        )->execute([$runId, (int) $run['company_id'], (int) $run['meli_account_id']]);
    }

    /** @return array<string,mixed> */
    public function processDue(?int $limit = null): array
    {
        if (!$this->hasTable('meli_notification_backfill_runs')) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => true];
        }
        $limit ??= max(50, min(1000, (new AppSettingsService())->int('notifications.backfill_batch_limit', 500)));
        $reservationFilter = $this->hasTable('manual_campaign_reservations')
            ? ' AND NOT EXISTS (
                 SELECT 1 FROM manual_campaign_reservations mr
                 WHERE mr.queue_key="notification_backfill"
                   AND mr.source_id=CAST(r.id AS CHAR)
                   AND mr.company_id=r.company_id AND mr.meli_account_id=r.meli_account_id
                   AND mr.status="active" AND mr.expires_at>UTC_TIMESTAMP(3)
               )'
            : '';
        $run = Database::connection()->query(
            'SELECT r.*
             FROM meli_notification_backfill_runs r
             JOIN meli_accounts a
               ON a.id=r.meli_account_id AND a.company_id=r.company_id
             WHERE r.company_id IS NOT NULL AND r.meli_account_id IS NOT NULL
               AND r.status IN ("analyzing","running")
               AND (r.next_run_at IS NULL OR r.next_run_at<=UTC_TIMESTAMP())
               AND (r.lock_expires_at IS NULL OR r.lock_expires_at<UTC_TIMESTAMP())'
             . $reservationFilter . '
             ORDER BY r.id ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => true, 'stop_reason' => 'no_pending_jobs'];
        }
        return $this->processRun(
            (int) $run['id'],
            $limit,
            (int) $run['company_id'],
            (int) $run['meli_account_id']
        );
    }

    /** @return array<string,mixed> */
    public function processRun(int $runId, int $limit = 500, int $companyId = 0, int $accountId = 0): array
    {
        $scope = $this->scopedRun($runId, $companyId, $accountId, false);
        $companyId = (int) $scope['company_id'];
        $accountId = (int) $scope['meli_account_id'];
        $owner = 'backfill-' . bin2hex(random_bytes(10));
        $manualExecution = (string) (ApiExecutionMetadataContext::current()['source'] ?? '') === 'manual_campaign';
        $reservationReady = $this->hasTable('manual_campaign_reservations');
        $lease = Database::connection()->prepare(
            'UPDATE meli_notification_backfill_runs
             SET locked_by=?,locked_at=UTC_TIMESTAMP(),lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND)
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND status IN ("analyzing","running")
               AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())'
               . ($manualExecution || !$reservationReady ? '' : '
               AND NOT EXISTS (
                 SELECT 1 FROM manual_campaign_reservations mr
                 WHERE mr.queue_key="notification_backfill"
                   AND mr.source_id=CAST(meli_notification_backfill_runs.id AS CHAR)
                   AND mr.company_id=meli_notification_backfill_runs.company_id
                   AND mr.meli_account_id=meli_notification_backfill_runs.meli_account_id
                   AND mr.status="active" AND mr.expires_at>UTC_TIMESTAMP(3)
               )')
        );
        $lease->execute([$owner, $runId, $companyId, $accountId]);
        if ($lease->rowCount() !== 1) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => true, 'stop_reason' => 'locked'];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM meli_notification_backfill_runs
             WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=? LIMIT 1'
        );
        $stmt->execute([$runId, $companyId, $accountId, $owner]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            return ['processed' => 0, 'errors' => 1, 'skipped' => true];
        }
        $limit = max(1, min(1000, $limit));
        $this->importLegacy($companyId, $accountId, min(250, $limit));
        $phase = (string) ($run['phase'] ?? 'recent');
        $dateFilter = $phase === 'recent'
            ? ' AND e.erp_received_at>=COALESCE(' . Database::connection()->quote((string) $run['priority_since']) . ',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY))'
            : ' AND e.erp_received_at<COALESCE(' . Database::connection()->quote((string) $run['priority_since']) . ',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY))';
        $eventsStmt = Database::connection()->prepare(
            'SELECT e.id,e.meli_account_id,e.meli_user_id,e.topic,e.actions_json,e.resource,
                    e.sent_at,e.erp_received_at,e.status
             FROM meli_notification_events e
             JOIN meli_accounts a
               ON a.id=e.meli_account_id AND a.company_id=:company
             WHERE e.meli_account_id=:account AND e.id>:checkpoint' . $dateFilter . '
             ORDER BY e.id ASC LIMIT ' . $limit
        );
        $eventsStmt->execute([
            'company' => $companyId,
            'account' => $accountId,
            'checkpoint' => (int) $run['checkpoint_event_id'],
        ]);
        $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = [
            'processed' => 0,
            'normalized' => 0,
            'queued' => 0,
            'satisfied' => 0,
            'ignored' => 0,
            'errors' => 0,
        ];
        $lastId = (int) $run['checkpoint_event_id'];
        $registry = new MeliNotificationTopicRegistry();
        $workService = new NotificationWorkItemService();
        $classified = [];
        foreach ($events as $event) {
            $lastId = (int) $event['id'];
            $counts['processed']++;
            try {
                $classification = $registry->classify(
                    (string) $event['topic'],
                    isset($event['resource']) ? (string) $event['resource'] : null,
                    json_decode((string) ($event['actions_json'] ?? ''), true)
                );
                $counts['normalized']++;
                $classified[] = [
                    'event' => $event,
                    'classification' => $classification,
                    'account_id' => $this->accountIdForEvent($event),
                ];
            } catch (Throwable) {
                $counts['errors']++;
            }
        }

        $localFreshness = $this->prefetchLocalFreshness($companyId, $classified);
        $rememberStmt = $this->uniqueResourceTableAvailable()
            ? Database::connection()->prepare(
                'INSERT IGNORE INTO meli_notification_backfill_unique_resources
                 (run_id,company_id,meli_account_id,canonical_topic,resource_type,remote_resource_id,first_event_id)
                 VALUES (?,?,?,?,?,?,?)'
            )
            : null;
        foreach ($classified as $row) {
            if (!$this->ownsRunLease($runId, $companyId, $accountId, $owner)) {
                return $counts + [
                    'run_id' => $runId,
                    'status' => 'lease_lost',
                    'done' => false,
                    'stop_reason' => 'lease_lost',
                ];
            }
            $event = $row['event'];
            $classification = $row['classification'];
            $accountId = (int) $row['account_id'];
            try {
                $this->rememberRunResource(
                    $rememberStmt,
                    $runId,
                    (int) $event['id'],
                    $companyId,
                    $accountId,
                    $classification
                );
                if (empty($classification['valid']) || empty($classification['actionable'])) {
                    Database::connection()->prepare(
                        'UPDATE meli_notification_events
                         SET canonical_topic=?,resource_type=?,remote_resource_id=?,validation_status=?,
                             disposition="ignored",status="ignored",processed_at=COALESCE(processed_at,UTC_TIMESTAMP())
                         WHERE id=? AND meli_account_id=?
                           AND EXISTS (
                             SELECT 1 FROM meli_accounts a
                             WHERE a.id=meli_notification_events.meli_account_id AND a.company_id=?
                           )
                           AND EXISTS (
                             SELECT 1 FROM meli_notification_backfill_runs br
                             WHERE br.id=? AND br.company_id=? AND br.meli_account_id=?
                               AND br.locked_by=? AND br.lock_expires_at>=UTC_TIMESTAMP()
                           )'
                    )->execute([
                        $classification['canonical_topic'],
                        $classification['resource_type'],
                        $classification['resource_id'],
                        empty($classification['valid']) ? 'unknown_topic' : 'recognized',
                        $event['id'],
                        $accountId,
                        $companyId,
                        $runId,
                        $companyId,
                        $accountId,
                        $owner,
                    ]);
                    $counts['ignored']++;
                    continue;
                }
                if ($this->satisfiedLocallyFromPrefetch(
                    $accountId,
                    $classification,
                    $event['sent_at'] ?? $event['erp_received_at'] ?? null,
                    $localFreshness
                )) {
                    Database::connection()->prepare(
                        'UPDATE meli_notification_events
                         SET meli_account_id=?,canonical_topic=?,resource_type=?,remote_resource_id=?,validation_status="valid",
                             disposition="satisfied_local",status="processed",processed_at=COALESCE(processed_at,UTC_TIMESTAMP())
                         WHERE id=? AND meli_account_id=?
                           AND EXISTS (
                             SELECT 1 FROM meli_accounts a
                             WHERE a.id=meli_notification_events.meli_account_id AND a.company_id=?
                           )
                           AND EXISTS (
                             SELECT 1 FROM meli_notification_backfill_runs br
                             WHERE br.id=? AND br.company_id=? AND br.meli_account_id=?
                               AND br.locked_by=? AND br.lock_expires_at>=UTC_TIMESTAMP()
                           )'
                    )->execute([
                        $accountId ?: null,
                        $classification['canonical_topic'],
                        $classification['resource_type'],
                        $classification['resource_id'],
                        $event['id'],
                        $accountId,
                        $companyId,
                        $runId,
                        $companyId,
                        $accountId,
                        $owner,
                    ]);
                    $counts['satisfied']++;
                    continue;
                }
                if ((string) $run['mode'] === 'enqueue') {
                    $workService->enqueue(
                        (int) $event['id'],
                        $accountId ?: null,
                        isset($event['meli_user_id']) ? (int) $event['meli_user_id'] : null,
                        $classification,
                        isset($event['sent_at']) ? (string) $event['sent_at'] : null
                    );
                    $counts['queued']++;
                } else {
                    Database::connection()->prepare(
                        'UPDATE meli_notification_events
                         SET meli_account_id=?,canonical_topic=?,resource_type=?,remote_resource_id=?,
                             validation_status="valid",disposition="backfill_candidate"
                         WHERE id=? AND meli_account_id=?
                           AND EXISTS (
                             SELECT 1 FROM meli_accounts a
                             WHERE a.id=meli_notification_events.meli_account_id AND a.company_id=?
                           )
                           AND EXISTS (
                             SELECT 1 FROM meli_notification_backfill_runs br
                             WHERE br.id=? AND br.company_id=? AND br.meli_account_id=?
                               AND br.locked_by=? AND br.lock_expires_at>=UTC_TIMESTAMP()
                           )'
                    )->execute([
                        $accountId ?: null,
                        $classification['canonical_topic'],
                        $classification['resource_type'],
                        $classification['resource_id'],
                        $event['id'],
                        $accountId,
                        $companyId,
                        $runId,
                        $companyId,
                        $accountId,
                        $owner,
                    ]);
                }
            } catch (Throwable) {
                $counts['errors']++;
            }
        }
        $phaseDone = count($events) < $limit;
        $done = $phaseDone && $phase === 'historical';
        $nextPhase = $phaseDone && $phase === 'recent' ? 'historical' : $phase;
        if ($phaseDone && $phase === 'recent') {
            $lastId = 0;
        }
        $nextStatus = $done ? ((string) $run['mode'] === 'analyze' ? 'ready' : 'complete') : (string) $run['status'];
        $uniqueResourceCount = $this->uniqueResourceCount($runId, $companyId, $accountId);
        $finish = Database::connection()->prepare(
            'UPDATE meli_notification_backfill_runs
             SET checkpoint_event_id=?,phase=?,analyzed_count=?,normalized_count=?,
                 unique_resource_count=?,
                 satisfied_local_count=?,queued_count=?,
                 ignored_count=?,error_count=?,estimated_api_calls=?,
                 status=?,next_run_at=?,locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 completed_at=IF(? IN ("ready","complete"),UTC_TIMESTAMP(),NULL)
             WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=?'
        );
        $finish->execute([
            $lastId,
            $nextPhase,
            (int) $run['analyzed_count'] + $counts['processed'],
            (int) $run['normalized_count'] + $counts['normalized'],
            $uniqueResourceCount,
            (int) $run['satisfied_local_count'] + $counts['satisfied'],
            (int) $run['queued_count'] + $counts['queued'],
            (int) $run['ignored_count'] + $counts['ignored'],
            (int) $run['error_count'] + $counts['errors'],
            (int) $run['queued_count'] + $counts['queued'],
            $nextStatus,
            $done ? null : gmdate('Y-m-d H:i:s', time() + 2),
            $nextStatus,
            $runId,
            $companyId,
            $accountId,
            $owner,
        ]);
        if ($finish->rowCount() !== 1) {
            return $counts + [
                'run_id' => $runId,
                'status' => 'lease_lost',
                'done' => false,
                'stop_reason' => 'lease_lost',
            ];
        }
        return $counts + ['run_id' => $runId, 'status' => $nextStatus, 'done' => $done];
    }

    /** @return list<array<string,mixed>> */
    public function runs(int $limit = 20): array
    {
        if (!$this->hasTable('meli_notification_backfill_runs')) {
            return [];
        }
        $scope = (new BusinessScopeContext())->accountIds();
        if ($scope === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT r.*
             FROM meli_notification_backfill_runs r
             JOIN meli_accounts a
               ON a.id=r.meli_account_id AND a.company_id=r.company_id
             WHERE r.meli_account_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')
             ORDER BY r.id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute($scope);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $classification */
    private function satisfiedLocallyFromPrefetch(
        int $accountId,
        array $classification,
        mixed $eventAt,
        array $localFreshness
    ): bool
    {
        if ($accountId <= 0) {
            return false;
        }
        $clock = new SystemDatabaseUtcClock();
        $eventTime = $clock->timestamp(is_scalar($eventAt) ? (string) $eventAt : null) ?? 0;
        $type = (string) $classification['resource_type'];
        $remoteId = (string) $classification['resource_id'];
        $localAt = $localFreshness[$type][$accountId][$remoteId] ?? null;
        if ($localAt === null || $localAt === '') {
            return false;
        }
        return $eventTime <= 0 || ($clock->timestamp((string) $localAt) ?? 0) >= $eventTime;
    }

    /**
     * @param list<array{event:array<string,mixed>,classification:array<string,mixed>,account_id:int}> $classified
     * @return array<string,array<int,array<string,string>>>
     */
    private function prefetchLocalFreshness(int $companyId, array $classified): array
    {
        $keys = [];
        foreach ($classified as $row) {
            $classification = $row['classification'];
            if (empty($classification['valid']) || empty($classification['actionable'])) {
                continue;
            }
            $accountId = (int) $row['account_id'];
            $type = (string) ($classification['resource_type'] ?? '');
            $remoteId = (string) ($classification['resource_id'] ?? '');
            if ($accountId <= 0 || $remoteId === '' || $this->localMap($type) === null) {
                continue;
            }
            $keys[$type][$accountId][$remoteId] = true;
        }

        $freshness = [];
        foreach ($keys as $type => $accounts) {
            $map = $this->localMap((string) $type);
            if ($map === null) {
                continue;
            }
            [$table, $externalColumn, $freshColumn, $fallbackColumn] = $map;
            if (!$this->hasTable($table)) {
                continue;
            }
            $columns = $this->columns($table);
            $dateColumn = isset($columns[$freshColumn]) ? $freshColumn : $fallbackColumn;
            if (!isset($columns[$externalColumn], $columns['meli_account_id'], $columns[$dateColumn])) {
                continue;
            }
            foreach ($accounts as $accountId => $resourceIds) {
                foreach (array_chunk(array_keys($resourceIds), 1000) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = Database::connection()->prepare(
                        'SELECT `' . $externalColumn . '` resource_id,`' . $dateColumn . '` local_at
                         FROM `' . $table . '`
                         WHERE meli_account_id=?
                           AND EXISTS (
                             SELECT 1 FROM meli_accounts a
                             WHERE a.id=`' . $table . '`.meli_account_id AND a.company_id=?
                           )
                           AND `' . $externalColumn . '` IN (' . $placeholders . ')'
                    );
                    $stmt->execute([(int) $accountId, $companyId, ...$chunk]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $local) {
                        $freshness[(string) $type][(int) $accountId][(string) $local['resource_id']]
                            = (string) ($local['local_at'] ?? '');
                    }
                }
            }
        }
        return $freshness;
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function localMap(string $type): ?array
    {
        $map = match ($type) {
            'order' => ['meli_orders', 'external_order_id', 'last_updated_utc', 'synced_at'],
            'shipment' => ['meli_shipments', 'external_shipment_id', 'synced_at', 'synced_at'],
            'claim' => ['meli_claims', 'external_claim_id', 'synced_at', 'synced_at'],
            'question' => ['meli_questions', 'external_question_id', 'synced_at', 'synced_at'],
            'item' => ['meli_items', 'external_item_id', 'updated_at', 'synced_at'],
            default => null,
        };
        return $map;
    }

    private function importLegacy(int $companyId, int $accountId, int $limit): int
    {
        if (!$this->hasTable('meli_webhook_events')) {
            return 0;
        }
        $rowsStmt = Database::connection()->prepare(
            'SELECT l.id,l.event_hash,l.meli_account_id,l.topic,l.resource,l.external_user_id,l.raw_json,l.received_at
             FROM meli_webhook_events l
             JOIN meli_accounts a
               ON a.id=l.meli_account_id AND a.company_id=:company
             LEFT JOIN meli_notification_events n
               ON n.payload_hash=l.event_hash AND n.meli_account_id=l.meli_account_id
             WHERE l.meli_account_id=:account AND n.id IS NULL
             ORDER BY l.id ASC LIMIT ' . max(1, min(500, $limit))
        );
        $rowsStmt->execute(['company' => $companyId, 'account' => $accountId]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        $inserted = 0;
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO meli_notification_events
             (notification_id,source_type,meli_account_id,meli_user_id,topic,resource,erp_received_at,source_ip,user_agent,
              payload_json,payload_hash,status,priority,process_after)
             VALUES (NULL,"legacy",?,?,?,?,?,NULL,NULL,?,?,"queued",50,UTC_TIMESTAMP())'
        );
        foreach ($rows as $row) {
            $stmt->execute([
                $row['meli_account_id'] ?: null,
                $row['external_user_id'] ?: null,
                $row['topic'] ?: 'unknown',
                $row['resource'],
                $row['received_at'],
                $row['raw_json'],
                $row['event_hash'],
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * @param \PDOStatement|null $stmt
     * @param array<string,mixed> $classification
     */
    private function rememberRunResource(
        ?\PDOStatement $stmt,
        int $runId,
        int $eventId,
        int $companyId,
        int $accountId,
        array $classification
    ): void
    {
        if (!$stmt instanceof \PDOStatement) {
            return;
        }
        $canonicalTopic = trim((string) ($classification['canonical_topic'] ?? ''));
        $resourceType = trim((string) ($classification['resource_type'] ?? ''));
        $resourceId = trim((string) ($classification['resource_id'] ?? ''));
        if ($canonicalTopic === '' || $resourceType === '' || $resourceId === '') {
            return;
        }
        $stmt->execute([
            $runId,
            $companyId,
            max(0, $accountId),
            mb_substr($canonicalTopic, 0, 40),
            mb_substr($resourceType, 0, 40),
            mb_substr($resourceId, 0, 120),
            $eventId,
        ]);
    }

    /** @param array<string,mixed> $event */
    private function accountIdForEvent(array $event): int
    {
        $accountId = (int) ($event['meli_account_id'] ?? 0);
        if ($accountId > 0 || empty($event['meli_user_id'])) {
            return $accountId;
        }
        $userId = (int) $event['meli_user_id'];
        if (!array_key_exists($userId, $this->accountByUserCache)) {
            $this->accountByUserCache[$userId] = (int) ((new WebhookService())->accountIdFromUser($userId) ?? 0);
        }
        return $this->accountByUserCache[$userId];
    }

    private function uniqueResourceCount(int $runId, int $companyId = 0, int $accountId = 0): int
    {
        if (!$this->uniqueResourceTableAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM meli_notification_backfill_unique_resources
             WHERE run_id=? AND company_id=? AND meli_account_id=?'
        );
        $stmt->execute([$runId, $companyId, $accountId]);
        return (int) $stmt->fetchColumn();
    }

    /** @phpstan-impure Lease ownership depends on database time and concurrent workers. */
    private function ownsRunLease(int $runId, int $companyId, int $accountId, string $owner): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM meli_notification_backfill_runs
             WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=?
               AND status IN ("analyzing","running") AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([$runId, $companyId, $accountId, $owner]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array{company_id:int,meli_account_id:int} */
    private function requestedScope(int $accountId, int $companyId): array
    {
        if ($accountId <= 0) {
            $accountIds = (new BusinessScopeContext())->accountIds(null, $companyId);
            if (count($accountIds) !== 1) {
                throw new \RuntimeException('Seleccione una cuenta exacta para analizar notificaciones históricas.');
            }
            $accountId = $accountIds[0];
        }
        $account = (new BusinessScopeContext())->account($accountId, $companyId);
        return [
            'company_id' => (int) $account['company_id'],
            'meli_account_id' => (int) $account['id'],
        ];
    }

    /** @return array<string,mixed> */
    private function scopedRun(int $runId, int $companyId, int $accountId, bool $requireUserAccess): array
    {
        if ($runId <= 0) {
            throw new \RuntimeException('La ejecución histórica solicitada no existe.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT r.*
             FROM meli_notification_backfill_runs r
             JOIN meli_accounts a
               ON a.id=r.meli_account_id AND a.company_id=r.company_id
             WHERE r.id=:id
               AND (:company=0 OR r.company_id=:company_exact)
               AND (:account=0 OR r.meli_account_id=:account_exact)
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $runId,
            'company' => $companyId,
            'company_exact' => $companyId,
            'account' => $accountId,
            'account_exact' => $accountId,
        ]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            throw new \RuntimeException('La ejecución histórica solicitada no existe.');
        }
        if ($requireUserAccess) {
            (new BusinessScopeContext())->account((int) $run['meli_account_id'], (int) $run['company_id']);
        }
        return $run;
    }

    private function assertAvailable(): void
    {
        if (!$this->hasTable('meli_notification_backfill_runs')) {
            throw new \RuntimeException('Ejecute la migración 068 antes de analizar el historial.');
        }
    }

    private function uniqueResourceTableAvailable(): bool
    {
        if ($this->uniqueResourceTableAvailable === null) {
            $this->uniqueResourceTableAvailable = $this->hasTable('meli_notification_backfill_unique_resources');
        }
        return $this->uniqueResourceTableAvailable;
    }

    private function hasTable(string $table): bool
    {
        return $this->tableExistsCache[$table]
            ??= (new SchemaInspectorService())->hasTable($table);
    }

    /** @return array<string,mixed> */
    private function columns(string $table): array
    {
        return $this->columnsCache[$table]
            ??= (new SchemaInspectorService())->columns($table);
    }
}
