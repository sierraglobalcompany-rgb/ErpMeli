<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class RecurringSyncService
{
    /** @param list<int> $accountIds */
    public function rules(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT a.id account_id,a.account_name,a.status,r.*
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             LEFT JOIN sync_recurring_rules r ON r.meli_account_id=a.id AND r.rule_type="orders"
             WHERE a.id IN (' . $placeholders . ')
             ORDER BY a.account_name'
        );
        $stmt->execute($accountIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int|string,mixed> $rows @param list<int> $accountIds */
    public function save(array $rows, array $accountIds): void
    {
        $allowed = array_fill_keys($accountIds, true);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO sync_recurring_rules
             (meli_account_id,rule_type,enabled,frequency_minutes,start_time,end_time,active_weekdays,overlap_hours,allow_outside_hours,next_due_at,last_result)
             VALUES (:account,"orders",:enabled,:frequency,:start_time,:end_time,:weekdays,:overlap,:outside,UTC_TIMESTAMP(),NULL)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),frequency_minutes=VALUES(frequency_minutes),start_time=VALUES(start_time),end_time=VALUES(end_time),active_weekdays=VALUES(active_weekdays),overlap_hours=VALUES(overlap_hours),allow_outside_hours=VALUES(allow_outside_hours),updated_at=NOW()'
        );
        foreach ($rows as $accountId => $data) {
            $accountId = (int) $accountId;
            if ($accountId <= 0 || !isset($allowed[$accountId]) || !is_array($data)) {
                continue;
            }
            $weekdays = array_values(array_filter((array) ($data['weekdays'] ?? []), static fn($day) => preg_match('/^[1-7]$/', (string) $day)));
            $stmt->execute([
                'account' => $accountId,
                'enabled' => !empty($data['enabled']) ? 1 : 0,
                'frequency' => in_array((int) ($data['frequency'] ?? 60), [30, 60, 120], true) ? (int) $data['frequency'] : 60,
                'start_time' => $this->time((string) ($data['start_time'] ?? '09:00')),
                'end_time' => $this->time((string) ($data['end_time'] ?? '19:00')),
                'weekdays' => $weekdays ? implode(',', $weekdays) : '1,2,3,4,5,6,7',
                'overlap' => max(1, min(12, (int) ($data['overlap_hours'] ?? 2))),
                'outside' => !empty($data['allow_outside_hours']) ? 1 : 0,
            ]);
        }
    }

    public function processDue(): array
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('sync.daily_enabled', false)) {
            return ['enabled' => false, 'enqueued' => 0, 'skipped' => true];
        }
        $stmt = Database::connection()->query(
            'SELECT r.*,a.account_name
             FROM sync_recurring_rules r
             JOIN meli_accounts a ON a.id=r.meli_account_id
             WHERE r.rule_type="orders" AND r.enabled=1 AND a.status IN ("conectado","connected")
             ORDER BY r.next_due_at IS NULL DESC, r.next_due_at ASC, r.id ASC'
        );
        $summary = ['enabled' => true, 'enqueued' => 0, 'skipped' => false, 'rules' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $summary['rules']++;
            try {
                if (!$this->isDue($rule)) {
                    continue;
                }
                $now = new DateTimeImmutable('now', new DateTimeZone(DateTimePresenter::timezone()));
                $from = $now->modify('-' . max(1, (int) $rule['overlap_hours']) . ' hours');
                if ($from->format('Y-m-d') !== $now->format('Y-m-d')) {
                    $from = $now->setTime(0, 0, 0);
                }
                (new SyncCenterService())->enqueueRange((int) $rule['meli_account_id'], $from, $now);
                $this->mark($rule, 'Bloque diario encolado: ' . $from->format('Y-m-d H:i') . ' a ' . $now->format('Y-m-d H:i'));
                $summary['enqueued']++;
            } catch (Throwable $e) {
                $this->mark($rule, mb_substr($e->getMessage(), 0, 500), false);
            }
        }
        return $summary;
    }

    public function dailyEnabled(): bool
    {
        return (new AppSettingsService())->bool('sync.daily_enabled', false);
    }

    /** @return array{known:bool,work_count:int,oldest_due_at:?string} */
    public function dueSnapshot(): array
    {
        if (!$this->dailyEnabled()) {
            return ['known' => true, 'work_count' => 0, 'oldest_due_at' => null];
        }
        if (!(new SchemaInspectorService())->hasTable('sync_recurring_rules')) {
            return ['known' => false, 'work_count' => 0, 'oldest_due_at' => null];
        }
        $stmt = Database::connection()->query(
            'SELECT r.*
             FROM sync_recurring_rules r
             JOIN meli_accounts a ON a.id=r.meli_account_id
             WHERE r.rule_type="orders" AND r.enabled=1
               AND a.status IN ("conectado","connected")
               AND (r.next_due_at IS NULL OR r.next_due_at<=UTC_TIMESTAMP())
             ORDER BY COALESCE(r.next_due_at,"1970-01-01 00:00:00"),r.id'
        );
        $count = 0;
        $oldest = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            if (!$this->isDue($rule)) {
                continue;
            }
            $count++;
            $candidate = !empty($rule['next_due_at']) ? (string) $rule['next_due_at'] : null;
            if ($candidate !== null && ($oldest === null || $candidate < $oldest)) {
                $oldest = $candidate;
            }
        }
        return ['known' => true, 'work_count' => $count, 'oldest_due_at' => $oldest];
    }

    private function isDue(array $rule): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(DateTimePresenter::timezone()));
        $nextDue = !empty($rule['next_due_at'])
            ? (new DateTimeImmutable((string) $rule['next_due_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(DateTimePresenter::timezone()))
            : $now;
        if ($nextDue > $now) {
            return false;
        }
        if ((int) ($rule['allow_outside_hours'] ?? 0) !== 1) {
            $weekday = $now->format('N');
            $allowed = array_map('trim', explode(',', (string) ($rule['active_weekdays'] ?? '1,2,3,4,5,6,7')));
            if (!in_array($weekday, $allowed, true)) {
                return false;
            }
            $time = $now->format('H:i:s');
            if ($time < (string) $rule['start_time'] || $time > (string) $rule['end_time']) {
                return false;
            }
        }
        return true;
    }

    private function mark(array $rule, string $message, bool $success = true): void
    {
        $minutes = max(30, min(120, (int) ($rule['frequency_minutes'] ?? 60)));
        Database::connection()->prepare(
            'UPDATE sync_recurring_rules
             SET last_enqueued_at=IF(:success=1,UTC_TIMESTAMP(),last_enqueued_at),
                 next_due_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE),
                 last_result=:message
             WHERE id=:id'
        )->execute(['success' => $success ? 1 : 0, 'minutes' => $minutes, 'message' => $message, 'id' => (int) $rule['id']]);
    }

    private function time(string $value): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value . ':00' : '09:00:00';
    }
}
