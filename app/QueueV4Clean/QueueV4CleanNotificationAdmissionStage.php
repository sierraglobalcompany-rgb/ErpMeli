<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\CronAdmissionService;
use App\Services\CronDeadlineContext;
use PDO;
use Throwable;

/**
 * Forward-only bridge from durable Mercado Libre notifications to canonical work.
 *
 * K2 intentionally does not replay historical notification rows. On first run it
 * stores the current work-item frontier and only admits later rows. Physical
 * storage remains Queue V4, but notification code enters through
 * CronAdmissionService, the canonical admission boundary.
 */
final class QueueV4CleanNotificationAdmissionStage
{
    private const WATERMARK_ID_KEY = 'notifications.k2.forward_from_work_item_id';
    private const WATERMARK_AT_KEY = 'notifications.k2.forward_from_utc';
    private const MAX_LIMIT = 30;
    private const WALL_TIME_SECONDS = 2.0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function run(int $limit = 15): array
    {
        $startedAt = microtime(true);
        if (!$this->hasTable('meli_notification_work_items') || !$this->hasTable('meli_notification_events')) {
            return ['available' => false, 'activated' => false, 'admitted' => 0, 'unsupported' => 0, 'reconciled' => 0];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $summary = [
            'available' => true,
            'activated' => false,
            'watermark_id' => 0,
            'read' => 0,
            'admitted' => 0,
            'deduplicated' => 0,
            'unsupported' => 0,
            'quarantined' => 0,
            'reconciled' => 0,
            'completed' => 0,
            'errors' => 0,
            'deferred' => false,
        ];

        if (!$this->canContinue($startedAt)) {
            $summary['deferred'] = true;
            return $summary;
        }

        $watermark = $this->watermarkId();
        if ($watermark === null) {
            $summary['activated'] = true;
            $summary['watermark_id'] = $this->activateForwardWatermark();
            return $summary;
        }
        $summary['watermark_id'] = $watermark;
        $summary = $this->reconcileQueued($summary, $limit, $watermark, $startedAt);
        if (!$this->canContinue($startedAt)) {
            $summary['deferred'] = true;
            return $summary;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($this->candidatesForUpdate($watermark, $limit) as $row) {
                if (!$this->canContinue($startedAt)) {
                    $summary['deferred'] = true;
                    break;
                }
                $summary['read']++;
                $decision = $this->classify($row);
                if ($decision['action'] === 'quarantine') {
                    $this->markUnsupported((int) $row['id'], 'k2_notification_tenant_invalid');
                    $summary['quarantined']++;
                    continue;
                }
                if ($decision['action'] === 'unsupported') {
                    $this->markUnsupported((int) $row['id'], (string) $decision['reason']);
                    $summary['unsupported']++;
                    continue;
                }

                $receipt = (new CronAdmissionService($this->pdo))->submitOrderExact(
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    (string) $row['remote_resource_id'],
                    (int) $row['id'],
                    'work:' . (int) $row['id'],
                    [
                        'canonical_topic' => (string) $row['canonical_topic'],
                        'latest_event_id' => (int) $row['latest_event_id'],
                    ],
                );
                if (!$receipt['accepted']) {
                    if ($receipt['reason'] === 'ALREADY_COMPLETED' && $receipt['job_id'] !== null) {
                        $this->markComplete((int) $row['id'], (int) $row['latest_event_id']);
                        $summary['deduplicated']++;
                        $summary['reconciled']++;
                        $summary['completed']++;
                        continue;
                    }
                    $this->markAdmissionHeld((int) $row['id'], $receipt['reason']);
                    $summary['errors']++;
                    continue;
                }
                $this->markQueued((int) $row['id'], (int) $receipt['job_id'], (bool) $receipt['deduplicated']);
                $summary['admitted']++;
                if ($receipt['deduplicated']) {
                    $summary['deduplicated']++;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $summary;
    }

    private function watermarkId(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([self::WATERMARK_ID_KEY]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function activateForwardWatermark(): int
    {
        $this->pdo->beginTransaction();
        try {
            $max = (int) $this->pdo->query('SELECT COALESCE(MAX(id),0) FROM meli_notification_work_items')->fetchColumn();
            $stmt = $this->pdo->prepare(
                'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
                 VALUES (?, ?, 0, "notifications")
                 ON DUPLICATE KEY UPDATE setting_key=setting_key'
            );
            $stmt->execute([self::WATERMARK_ID_KEY, (string) $max]);
            $stmt->execute([self::WATERMARK_AT_KEY, gmdate('Y-m-d H:i:s')]);
            $authoritative = $this->watermarkId();
            $this->pdo->commit();
            return $authoritative ?? $max;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $authoritative = $this->watermarkId();
            if ($authoritative !== null) {
                return $authoritative;
            }
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function reconcileQueued(array $summary, int $limit, int $watermark, float $startedAt): array
    {
        if (!$this->canContinue($startedAt)) {
            $summary['deferred'] = true;
            return $summary;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT w.id,w.meli_account_id,w.latest_event_id,j.state,j.completed_at
                 FROM meli_notification_work_items w
                 JOIN queue_v4_clean_jobs j
                  ON j.idempotency_key=CONCAT("notification:order_exact:work:",w.id)
                  AND j.meli_account_id=w.meli_account_id
                  AND j.job_type="order_exact"
                 WHERE w.status="queued"
                   AND w.id>?
                 ORDER BY w.id ASC
                 LIMIT ' . max(1, min(self::MAX_LIMIT, $limit)) . '
                 FOR UPDATE'
            );
            $stmt->execute([$watermark]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!$this->canContinue($startedAt)) {
                    $summary['deferred'] = true;
                    break;
                }
                $state = (string) $row['state'];
                if ($state === 'completed') {
                    $this->markComplete((int) $row['id'], (int) $row['latest_event_id']);
                    $summary['reconciled']++;
                    $summary['completed']++;
                } elseif (in_array($state, ['review', 'dead'], true)) {
                    $this->markAdmissionHeld((int) $row['id'], 'canonical_pointer_' . $state);
                    $summary['reconciled']++;
                    $summary['errors']++;
                }
            }
            $this->pdo->commit();
            return $summary;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    private function candidatesForUpdate(int $watermark, int $limit): array
    {
        $reservationSql = $this->hasTable('manual_campaign_reservations')
            ? 'AND NOT EXISTS (
                   SELECT 1 FROM manual_campaign_reservations r
                   WHERE r.queue_key="notification_fallback"
                     AND r.source_id=CAST(w.id AS CHAR)
                     AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
               )'
            : '';
        $sql =
            'SELECT w.*,a.company_id,a.status account_status
             FROM meli_notification_work_items w
             LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
             WHERE w.id>?
               AND w.status IN ("pending","retry")
               AND w.next_run_at<=UTC_TIMESTAMP(3)
               AND (w.lock_expires_at IS NULL OR w.lock_expires_at<UTC_TIMESTAMP(3))
               ' . $reservationSql . '
             ORDER BY w.priority ASC,w.next_run_at ASC,w.id ASC
             LIMIT ' . max(1, min(self::MAX_LIMIT, $limit)) . '
             FOR UPDATE SKIP LOCKED';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$watermark]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function canContinue(float $startedAt, float $reserveSeconds = 0.2): bool
    {
        if ((microtime(true) - $startedAt) >= self::WALL_TIME_SECONDS) {
            return false;
        }

        return CronDeadlineContext::remainingSeconds() > $reserveSeconds;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{action:string,reason:string}
     */
    private function classify(array $row): array
    {
        $companyId = (int) ($row['company_id'] ?? 0);
        $accountId = (int) ($row['meli_account_id'] ?? 0);
        $status = strtolower(trim((string) ($row['account_status'] ?? '')));
        if ($companyId < 1 || $accountId < 1 || !in_array($status, ['connected', 'conectado'], true)) {
            return ['action' => 'quarantine', 'reason' => 'tenant_not_connected'];
        }
        $topic = (string) ($row['canonical_topic'] ?? '');
        $type = (string) ($row['resource_type'] ?? '');
        $remoteId = trim((string) ($row['remote_resource_id'] ?? ''));
        if ($topic === 'order' && $type === 'order' && $remoteId !== '' && ctype_digit($remoteId)) {
            return ['action' => 'admit_order_exact', 'reason' => 'canonical_order_exact'];
        }

        return ['action' => 'unsupported', 'reason' => 'k2_no_canonical_contract_' . substr($type !== '' ? $type : $topic, 0, 40)];
    }

    private function markQueued(int $workId, int $jobId, bool $deduplicated): void
    {
        $this->pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="queued",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL,
                 last_result=?,last_processed_at=UTC_TIMESTAMP(),processing_event_id=latest_event_id
             WHERE id=?'
        )->execute([$deduplicated ? 'canonical_order_exact_already_admitted' : 'canonical_order_exact_admitted:' . $jobId, $workId]);
        $this->pdo->prepare(
            'UPDATE meli_notification_events
             SET status="queued",disposition="canonical_order_exact_admitted",error_message=NULL,acknowledged_at=COALESCE(acknowledged_at,UTC_TIMESTAMP())
             WHERE work_item_id=? AND status NOT IN ("duplicate","ignored","processed")'
        )->execute([$workId]);
    }

    private function markUnsupported(int $workId, string $reason): void
    {
        $safe = substr(preg_replace('/[^a-z0-9_:-]/i', '_', $reason) ?: 'k2_no_canonical_contract', 0, 80);
        $this->pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="paused",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_result="unsupported_canonical_work",last_error_code=?,last_error_message=?,
                 last_error_diagnostic_id=?,last_error_stage="canonical_admission",last_processed_at=UTC_TIMESTAMP(),
                 processing_event_id=NULL
             WHERE id=?'
        )->execute([$safe, 'Notificación reconocida sin contrato canónico K2 certificado.', 'K2-' . gmdate('Ymd-His'), $workId]);
        $this->pdo->prepare(
            'UPDATE meli_notification_events
             SET disposition=?,error_message=NULL,acknowledged_at=COALESCE(acknowledged_at,UTC_TIMESTAMP())
             WHERE work_item_id=? AND status NOT IN ("duplicate","ignored","processed")'
        )->execute([$safe, $workId]);
    }

    private function markAdmissionHeld(int $workId, string $reason): void
    {
        $safe = substr(preg_replace('/[^a-z0-9_:-]/i', '_', $reason) ?: 'canonical_admission_held', 0, 80);
        $this->pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="error",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_error_code=?,last_error_message="El trabajo canónico asociado requiere revisión segura.",
                 last_error_diagnostic_id=?,last_error_stage="canonical_pointer",last_processed_at=UTC_TIMESTAMP(),
                 processing_event_id=NULL
             WHERE id=?'
        )->execute([$safe, 'K2-' . gmdate('Ymd-His'), $workId]);
    }

    private function markComplete(int $workId, int $latestEventId): void
    {
        $this->pdo->prepare(
            'UPDATE meli_notification_events
             SET status="processed",disposition="canonical_order_exact_completed",processed_at=COALESCE(processed_at,UTC_TIMESTAMP()),error_message=NULL
             WHERE work_item_id=? AND status NOT IN ("duplicate","ignored") AND (?=0 OR id<=?)'
        )->execute([$workId, $latestEventId, $latestEventId]);
        $this->pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="complete",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_result="canonical_order_exact_completed",last_error_code=NULL,last_error_message=NULL,
                 last_error_diagnostic_id=NULL,last_error_stage=NULL,last_processed_at=UTC_TIMESTAMP(),
                 last_success_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),processing_event_id=NULL,
                 rerun_requested=0,consecutive_failures=0
             WHERE id=?'
        )->execute([$workId]);
    }

    private function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
}
