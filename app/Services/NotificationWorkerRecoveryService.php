<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class NotificationWorkerRecoveryService
{
    private const RECOVERY_VERSION = '2.11.8-active-transaction';
    private const ERROR_SIGNATURE = 'There is already an active transaction';

    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    /** @return array{enabled:bool,recovered:int,examined:int,remaining:int,complete:bool} */
    /** @param list<int>|null $accountIds */
    public function recoverKnownErrors(?int $limit = null, bool $force = false, ?array $accountIds = null): array
    {
        $enabled = $this->settings->bool('notifications.transaction_error_recovery_enabled', true);
        if (!$enabled) {
            return ['enabled' => false, 'recovered' => 0, 'examined' => 0, 'remaining' => 0, 'complete' => false];
        }
        if (
            !$force
            && $this->settings->get('notifications.transaction_error_recovery_completed_version') === self::RECOVERY_VERSION
        ) {
            return ['enabled' => true, 'recovered' => 0, 'examined' => 0, 'remaining' => 0, 'complete' => true];
        }

        $limit ??= $this->settings->int('notifications.transaction_error_recovery_batch', 25);
        $limit = max(1, min(100, $limit));
        if ($accountIds !== null && $accountIds === []) {
            return ['enabled' => true, 'recovered' => 0, 'examined' => 0, 'remaining' => 0, 'complete' => true];
        }
        $candidates = $this->candidates(max($limit * 4, 100), $accountIds);
        $matched = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (count($matched) >= $limit) {
                break;
            }
            $id = (int) $candidate['id'];
            if (!isset($seen[$id]) && $this->matchesKnownFailure($candidate)) {
                $matched[] = $id;
                $seen[$id] = true;
            }
        }

        if ($matched !== []) {
            $pdo = Database::connectionFresh();
            $pdo->beginTransaction();
            try {
                $placeholders = implode(',', array_fill(0, count($matched), '?'));
                $stmt = $pdo->prepare(
                    'UPDATE meli_notification_work_items
                     SET status="pending",next_run_at=UTC_TIMESTAMP(),
                         locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                         last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL,
                         consecutive_failures=0,processing_event_id=NULL,completed_at=NULL
                     WHERE id IN (' . $placeholders . ') AND status IN ("error","retry")'
                );
                $stmt->execute($matched);
                $sources = $pdo->prepare(
                    'SELECT id,meli_account_id FROM meli_notification_work_items
                     WHERE id IN (' . $placeholders . ') AND status="pending" ORDER BY id'
                );
                $sources->execute($matched);
                $work = new NotificationWorkItemService();
                foreach ($sources->fetchAll(PDO::FETCH_ASSOC) as $source) {
                    $receipt = $work->admitCanonicalWork(
                        (int) $source['id'],
                        (int) ($source['meli_account_id'] ?? 0),
                        $pdo
                    );
                    if (!$receipt['accepted']) {
                        throw new \RuntimeException('notification_worker_recovery_admission_denied:' . $receipt['reason']);
                    }
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            Logger::write('info', 'Recuperación selectiva del worker aplicada.', [
                'recovery_version' => self::RECOVERY_VERSION,
                'recovered_count' => count($matched),
            ]);
        }

        $remaining = $this->countKnownFailures($accountIds);
        $complete = $remaining === 0;
        if ($complete && $accountIds === null) {
            $this->settings->set(
                'notifications.transaction_error_recovery_completed_version',
                self::RECOVERY_VERSION,
                'notifications'
            );
        }
        if ($accountIds === null) {
            $this->settings->set(
                'notifications.transaction_error_recovery_last_result',
                json_encode([
                    'recovered' => count($matched),
                    'examined' => count($candidates),
                    'remaining' => $remaining,
                    'complete' => $complete,
                    'at' => gmdate('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'notifications'
            );
        }

        return [
            'enabled' => true,
            'recovered' => count($matched),
            'examined' => count($candidates),
            'remaining' => $remaining,
            'complete' => $complete,
        ];
    }

    /** @return list<array<string,mixed>> */
    /** @param list<int>|null $accountIds */
    private function candidates(int $limit, ?array $accountIds = null): array
    {
        $scopeSql = '';
        $params = [];
        if ($accountIds !== null) {
            $names = [];
            foreach ($accountIds as $index => $accountId) {
                $name = ':scope_' . $index;
                $names[] = $name;
                $params[$name] = $accountId;
            }
            $scopeSql = ' AND w.meli_account_id IN (' . implode(',', $names) . ')';
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT w.id,w.last_error_diagnostic_id,l.context_json
             FROM meli_notification_work_items w
             JOIN system_logs l
               ON l.context_json LIKE CONCAT("%",w.last_error_diagnostic_id,"%")
             WHERE w.status IN ("error","retry")
               AND w.last_error_code="processing_error"
               AND w.last_error_stage="processing"
               AND w.last_error_diagnostic_id IS NOT NULL
               ' . $scopeSql . '
             ORDER BY w.last_processed_at ASC,w.id ASC
             LIMIT :limit'
        );
        foreach ($params as $name => $accountId) {
            $stmt->bindValue($name, $accountId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $candidate */
    private function matchesKnownFailure(array $candidate): bool
    {
        $context = json_decode((string) ($candidate['context_json'] ?? ''), true);
        return is_array($context)
            && hash_equals((string) ($candidate['last_error_diagnostic_id'] ?? ''), (string) ($context['reference'] ?? ''))
            && (string) ($context['module'] ?? '') === 'notifications'
            && (string) ($context['stage'] ?? '') === 'processing'
            && (string) ($context['error'] ?? '') === self::ERROR_SIGNATURE;
    }

    /** @param list<int>|null $accountIds */
    private function countKnownFailures(?array $accountIds = null): int
    {
        $matched = [];
        foreach ($this->candidates(5000, $accountIds) as $candidate) {
            if ($this->matchesKnownFailure($candidate)) {
                $matched[(int) $candidate['id']] = true;
            }
        }
        return count($matched);
    }
}
