<?php

declare(strict_types=1);

namespace App\Services;

/** Distribuye la ventana real sin permitir que una urgencia consuma el turno dirigido. */
final class CronLaneBudgetService
{
    private float $acceptUntil;
    private ?CronExecutionWindow $window = null;
    private CampaignExecutionWindowPolicyService $campaignWindow;

    public function __construct(float $startedAt, int $runtimeSeconds, private readonly AppSettingsService $settings)
    {
        $safeClose = max(5, min(15, $settings->int('cron.safe_close_seconds', 10)));
        $this->acceptUntil = $startedAt + max(5, $runtimeSeconds - $safeClose);
        $this->campaignWindow = new CampaignExecutionWindowPolicyService($settings);
    }

    public static function fromWindow(CronExecutionWindow $window, AppSettingsService $settings): self
    {
        $service = new self($window->startedAt(), (int) ceil($window->deadline() - $window->startedAt()), $settings);
        $service->window = $window;
        $service->acceptUntil = $window->acceptUntil();
        return $service;
    }

    public function deadline(string $lane): float
    {
        $seconds = match ($lane) {
            'urgent' => max(3, min(10, $this->settings->int('cron.urgent_lane_seconds', 10))),
            'directed' => $this->campaignWindow->directedLaneSeconds(),
            'spool' => max(1, min(5, $this->settings->int('cron.spool_lane_seconds', 3))),
            default => max(1, (int) floor($this->acceptUntil - microtime(true))),
        };
        return min($this->acceptUntil, microtime(true) + $seconds);
    }

    public function canStart(string $lane): bool
    {
        return $this->remainingSeconds() >= $this->requiredSeconds($lane);
    }

    public function requiredSeconds(string $lane): float
    {
        if ($lane !== 'directed') {
            return 1.0;
        }

        // La campaña dirigida decide con candidatos exactos. Exigir aquí la
        // ventana máxima histórica hacía que un minuto con 10-14 segundos
        // útiles descartara la campaña completa antes de inspeccionar si había
        // un ítem corto que sí cabía. Solo pedimos margen mínimo de entrada; el
        // worker conserva el guard rail real antes de abrir HTTP.
        return max(3.0, min(6.0, (float) $this->settings->int('cron.directed_min_start_seconds', 4)));
    }

    public function remainingSeconds(): float
    {
        $acceptUntil = $this->window?->acceptUntil() ?? $this->acceptUntil;
        return max(0.0, $acceptUntil - microtime(true));
    }

    public function acceptUntil(): float
    {
        return $this->window?->acceptUntil() ?? $this->acceptUntil;
    }
}
