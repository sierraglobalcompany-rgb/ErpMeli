<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Red de seguridad de Webhook-First.
 *
 * No consulta órdenes directamente. Programa una ventana incremental pequeña
 * en la cola existente cuando corresponde, de modo que los webhooks sigan
 * siendo la vía principal y el sondeo solo reconcilie entregas perdidas.
 */
final class NotificationReconciliationService
{
    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    /** @return array<string,mixed> */
    public function enqueueIfDue(): array
    {
        if (!$this->settings->bool('notifications.webhook_first_enabled', true)) {
            return ['enqueued' => 0, 'skipped' => true, 'reason' => 'feature_disabled'];
        }

        $health = (new WebhookService())->health();
        $healthy = !empty($health['automatic']) && (string) ($health['status'] ?? '') === 'green';
        $interval = $healthy
            ? max(15, $this->settings->int('notifications.reconcile_healthy_minutes', 60))
            : max(5, $this->settings->int('notifications.reconcile_degraded_minutes', 15));
        $last = (string) ($this->settings->get('notifications.last_reconcile_at', '') ?: '');
        $clock = new SystemDatabaseUtcClock();
        $lastTimestamp = $clock->timestamp($last) ?? 0;
        if ($lastTimestamp > time() - ($interval * 60)) {
            return [
                'enqueued' => 0,
                'skipped' => true,
                'reason' => 'not_due',
                'next_run_at' => gmdate('Y-m-d H:i:s', $lastTimestamp + ($interval * 60)),
                'mode' => $healthy ? 'healthy' : 'degraded',
            ];
        }

        $overlapHours = max(1, min(12, $this->settings->int('notifications.reconcile_overlap_hours', 2)));
        $timezone = new DateTimeZone(DateTimePresenter::timezone());
        $to = new DateTimeImmutable('now', $timezone);
        $from = $to->modify('-' . $overlapHours . ' hours');
        $accounts = Database::connection()->query(
            'SELECT id FROM meli_accounts
             WHERE status IN ("conectado","connected")
             ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);

        $enqueued = 0;
        $errors = 0;
        foreach ($accounts as $accountId) {
            try {
                (new SyncCenterService())->enqueueRange((int) $accountId, $from, $to);
                $enqueued++;
            } catch (Throwable $error) {
                $errors++;
                Logger::write('warning', 'No se pudo programar reconciliación Webhook-First.', [
                    'account_id' => (int) $accountId,
                    'error_class' => $error::class,
                ]);
            }
        }
        $this->settings->set('notifications.last_reconcile_at', gmdate('Y-m-d H:i:s'), 'notifications');
        $this->settings->set('notifications.last_reconcile_mode', $healthy ? 'healthy' : 'degraded', 'notifications');

        return [
            'enqueued' => $enqueued,
            'errors' => $errors,
            'skipped' => false,
            'mode' => $healthy ? 'healthy' : 'degraded',
            'interval_minutes' => $interval,
            'overlap_hours' => $overlapHours,
        ];
    }

    /** @return array<string,mixed> */
    public function recoverMissedFeedsIfDue(): array
    {
        if (!$this->settings->bool('notifications.missed_feeds_enabled', false)) {
            return ['fetched' => 0, 'skipped' => true, 'reason' => 'disabled'];
        }
        $health = (new WebhookService())->health();
        $degraded = empty($health['automatic']) || (string) ($health['status'] ?? '') === 'red';
        $configured = max(360, $this->settings->int('notifications.missed_feeds_interval_minutes', 360));
        $interval = $degraded ? min($configured, 60) : $configured;
        $last = (string) ($this->settings->get('notifications.last_missed_feeds_at', '') ?: '');
        $lastTimestamp = (new SystemDatabaseUtcClock())->timestamp($last) ?? 0;
        if ($lastTimestamp > time() - ($interval * 60)) {
            return ['fetched' => 0, 'skipped' => true, 'reason' => 'not_due'];
        }
        $stmt = Database::connection()->query(
            'SELECT id FROM meli_accounts
             WHERE status IN ("conectado","connected")
             ORDER BY token_expires_at DESC,id ASC LIMIT 1'
        );
        $accountId = (int) ($stmt->fetchColumn() ?: 0);
        if ($accountId <= 0) {
            return ['fetched' => 0, 'skipped' => true, 'reason' => 'no_connected_account'];
        }
        try {
            $result = (new MissedFeedService())->run($accountId, '', 50);
            $this->settings->set('notifications.last_missed_feeds_at', gmdate('Y-m-d H:i:s'), 'notifications');
            return $result + ['skipped' => false, 'coverage_days' => 2];
        } catch (Throwable $error) {
            Logger::write('warning', 'Recuperación missed_feeds aplazada.', [
                'account_id' => $accountId,
                'error_class' => $error::class,
            ]);
            return [
                'fetched' => 0,
                'errors' => 1,
                'skipped' => true,
                'reason' => 'temporarily_unavailable',
                'message' => 'La recuperación de los últimos dos días quedó aplazada por protección API.',
            ];
        }
    }
}
