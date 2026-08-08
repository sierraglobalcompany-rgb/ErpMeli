<?php

declare(strict_types=1);

namespace App\Services;

final class ApiRequestOutcomeClassifier
{
    /**
     * @param array<string,mixed> $classification
     * @return array{outcome_class:string,reached_remote:int,actionable:int,risk_signal:int,incident_key:?string}
     */
    public static function classify(
        string $method,
        string $path,
        ?int $status,
        bool $blocked,
        ?string $message,
        array $classification = [],
        ?string $errorCode = null
    ): array {
        $type = strtolower(trim((string) ($classification['type'] ?? '')));
        $message = trim((string) $message);
        $lowerMessage = mb_strtolower($message);
        $normalizedPath = self::normalizePath($path);
        $reachedRemote = array_key_exists('reached_remote', $classification)
            ? !empty($classification['reached_remote'])
            : $status !== null;

        if (isset($classification['outcome_class'])) {
            $outcome = (string) $classification['outcome_class'];
        } elseif ($status !== null && $status >= 200 && $status < 400) {
            $outcome = 'success';
        } elseif ($status === 404 && str_contains($normalizedPath, '/description')) {
            $outcome = 'expected_absence';
        } elseif (!empty($classification['is_app_blocked_signal'])
            || $type === 'app_blocked'
            || str_contains($lowerMessage, 'unauthorized_scopes')
            || str_contains($lowerMessage, 'excessive_api_call')) {
            $outcome = 'blocked_signal';
        } elseif ($status !== null && $status >= 400) {
            $outcome = 'remote_error';
        } elseif (in_array($type, ['api_budget_exhausted', 'api_circuit_open', 'api_manual_pause'], true)) {
            $outcome = 'policy_delay';
        } else {
            $outcome = 'local_failure';
        }

        $riskSignal = $outcome === 'blocked_signal'
            || ($outcome === 'remote_error' && in_array($status, [401, 403, 429], true));
        $actionable = match ($outcome) {
            'success', 'expected_absence', 'policy_delay' => false,
            default => true,
        };
        $incidentKey = in_array($outcome, ['success', 'expected_absence'], true) ? null : hash('sha256', implode('|', [
            strtoupper($method),
            $normalizedPath,
            $outcome,
            $type,
            strtolower((string) $errorCode),
            (string) ($status ?? 0),
            self::stableCause($message),
        ]));

        return [
            'outcome_class' => $outcome,
            'reached_remote' => $reachedRemote ? 1 : 0,
            'actionable' => $actionable ? 1 : 0,
            'risk_signal' => $riskSignal ? 1 : 0,
            'incident_key' => $incidentKey,
        ];
    }

    public static function normalizePath(string $path): string
    {
        $parsed = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        $parsed = '/' . ltrim($parsed, '/');
        $parsed = preg_replace('~/items/(M[A-Z]{2}\d+)(?=/|$)~i', '/items/{id}', $parsed) ?? $parsed;
        $parsed = preg_replace('~/(orders|shipments|packs|payments|questions)/\d+(?=/|$)~i', '/$1/{id}', $parsed) ?? $parsed;
        $parsed = preg_replace('~/post-purchase/v\d+/claims/\d+(?=/|$)~i', '/post-purchase/v1/claims/{id}', $parsed) ?? $parsed;
        $parsed = preg_replace('~/user-products/[^/]+(?=/|$)~i', '/user-products/{id}', $parsed) ?? $parsed;
        return $parsed;
    }

    private static function stableCause(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $known = match (true) {
            str_contains($message, 'sqlstate'), str_contains($message, 'pdoexception') => 'database_error',
            str_contains($message, 'already an active transaction') => 'transaction_conflict',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'timeout',
            str_contains($message, 'unauthorized') => 'authorization',
            str_contains($message, 'rate limit'), str_contains($message, 'too many requests') => 'rate_limit',
            str_contains($message, 'presupuesto') => 'budget',
            str_contains($message, 'ritmo'), str_contains($message, 'espaciada') => 'rhythm',
            default => null,
        };
        if ($known !== null) {
            return $known;
        }
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{uuid}', $message) ?? $message;
        $message = preg_replace('/\b\d+\b/', '{n}', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        return 'message:' . substr(hash('sha256', mb_substr($message, 0, 240)), 0, 16);
    }
}
