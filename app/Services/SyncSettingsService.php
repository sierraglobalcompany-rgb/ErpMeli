<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

final class SyncSettingsService
{
    /**
     * Safety ceilings are code contracts, not merely editable settings. A
     * corrupted/legacy app_settings row must never turn one Cron cycle into an
     * unbounded Mercado Libre scan.
     */
    public const MAX_ORDERS_PER_RUN_HARD_LIMIT = 500;
    public const MAX_API_PAGES_PER_RUN_HARD_LIMIT = 10;

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    public function maxManualRangeDays(): int { return max(1, $this->settings->int('sync.max_manual_range_days', 7)); }
    public function pageLimit(): int { return min(50, max(1, $this->settings->int('sync.page_limit', 50))); }
    public function pauseMs(): int { return max(0, $this->settings->int('sync.pause_between_pages_ms', 400)); }
    public function maxOrdersPerRun(): int
    {
        return self::clampOrdersPerRun($this->settings->int('sync.max_orders_per_run', 500));
    }

    public function maxApiPagesPerRun(?int $requested = null): int
    {
        $configured = $this->settings->int('sync.max_api_pages_per_run', 5);
        return self::clampApiPages($requested ?? $configured);
    }

    public static function clampOrdersPerRun(int $value): int
    {
        return max(1, min(self::MAX_ORDERS_PER_RUN_HARD_LIMIT, $value));
    }

    public static function clampApiPages(int $value): int
    {
        return max(1, min(self::MAX_API_PAGES_PER_RUN_HARD_LIMIT, $value));
    }
    public function backoff429Seconds(): int { return max(1, $this->settings->int('sync.backoff_429_seconds', 5)); }
    public function backoff5xxSeconds(): int { return max(1, $this->settings->int('sync.backoff_5xx_seconds', 3)); }
    public function chunkMode(): string
    {
        $mode = (string) $this->settings->get('sync.chunk_mode', $this->settings->get('sync.default_chunk_mode', 'daily'));
        return in_array($mode, ['daily', 'weekly', 'parts'], true) ? $mode : 'daily';
    }

    public function chunkParts(): int { return max(1, min(31, $this->settings->int('sync.chunk_parts', 4))); }
    public function continuationDelayMinutes(): int { return max(1, min(1440, $this->settings->int('sync.continuation_delay_minutes', 5))); }
    public function queueMaxChunksPerRun(): int { return max(1, min(20, $this->settings->int('sync.queue_max_chunks_per_run', 3))); }
    public function diagnosticSampleDays(): int { return max(1, min(14, $this->settings->int('sync.diagnostic_sample_days', 7))); }
    public function monitorRefreshSeconds(): int { return max(5, min(120, $this->settings->int('sync.monitor_refresh_seconds', 12))); }
    public function manualProcessEnabled(): bool { return $this->settings->bool('sync.manual_process_enabled', true); }
    public function defaultEnqueueDelayMinutes(): int
    {
        $minutes = $this->settings->int('sync.default_enqueue_delay_minutes', 5);
        return in_array($minutes, [0, 5, 30, 60], true) ? $minutes : 5;
    }

    public function overdueRescheduleDefaultMinutes(): int
    {
        $minutes = $this->settings->int('sync.overdue_reschedule_default_minutes', 5);
        return in_array($minutes, [0, 5, 10, 20, 30], true) ? $minutes : 5;
    }

    public function assistedDetailsEnabled(): bool { return $this->settings->bool('sync.assisted_details_enabled', true); }
    public function dailyEnabled(): bool { return $this->settings->bool('sync.daily_enabled', false); }
    public function allowCustomSchedule(): bool { return $this->settings->bool('sync.allow_custom_schedule', true); }
    public function manualOverlayEnabled(): bool { return $this->settings->bool('sync.manual_overlay_enabled', true); }

    public function validateManualRange(DateTimeImmutable $from, DateTimeImmutable $to, bool $confirmed, bool $isAdmin): void
    {
        if ($to < $from) {
            throw new RuntimeException('El rango de sincronización es inválido.');
        }
        $days = (int) $from->diff($to)->format('%a') + 1;
        if ($days > $this->maxManualRangeDays() && (!$confirmed || !$isAdmin)) {
            throw new RuntimeException('Para evitar sobrecargar la API de Mercado Libre, sincroniza por rangos pequeños o confirma el rango como administrador.');
        }
    }
}
