<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class PerformanceMonitorService
{
    /** @param array<string,mixed> $metric */
    public function recordClient(array $metric): void
    {
        $allowed = ['ttfb', 'shell_visible', 'section_useful', 'lcp', 'cls', 'inp', 'section_timeout', 'section_cancelled'];
        $name = strtolower(trim((string) ($metric['name'] ?? '')));
        if (!in_array($name, $allowed, true)) {
            return;
        }
        $value = (float) ($metric['value'] ?? 0);
        if (!is_finite($value) || $value < 0 || $value > 3600000) {
            return;
        }
        $this->insert('client', $name, $value, [
            'route' => $this->safeRoute((string) ($metric['route'] ?? '')),
            'section' => mb_substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($metric['section'] ?? '')) ?: '', 0, 80),
            'cache' => mb_substr((string) ($metric['cache'] ?? ''), 0, 30),
        ]);
    }

    /** @param array<string,mixed> $context */
    public function recordServer(string $name, float $value, array $context = []): void
    {
        $this->insert('server', mb_substr($name, 0, 80), $value, $context);
    }

    /** @param array<string,mixed> $context */
    private function insert(string $source, string $name, float $value, array $context): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO system_performance_metrics
                 (source,metric_name,metric_value,route_path,section_name,cache_status,user_id,user_role,context_json,recorded_at)
                 VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $source,
                $name,
                round($value, 3),
                $this->safeRoute((string) ($context['route'] ?? ($_SERVER['REQUEST_URI'] ?? ''))),
                mb_substr((string) ($context['section'] ?? ''), 0, 80) ?: null,
                mb_substr((string) ($context['cache'] ?? ''), 0, 30) ?: null,
                Auth::id(),
                mb_substr((string) (Auth::role() ?? ''), 0, 30) ?: null,
                json_encode($this->sanitize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function safeRoute(string $route): string
    {
        $path = (string) (parse_url($route, PHP_URL_PATH) ?: '/');
        return mb_substr(preg_replace('/[^a-zA-Z0-9_\/.{}-]/', '', $path) ?: '/', 0, 190);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitize(array $context): array
    {
        unset($context['query'], $context['sql'], $context['token'], $context['cookie'], $context['authorization']);
        return array_slice($context, 0, 12, true);
    }
}
