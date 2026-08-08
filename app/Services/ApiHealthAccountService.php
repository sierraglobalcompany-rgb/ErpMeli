<?php

declare(strict_types=1);

namespace App\Services;

final class ApiHealthAccountService
{
    /**
     * @param list<array<string,mixed>> $accounts
     * @param list<array<string,mixed>> $stats
     * @param list<array<string,mixed>> $circuits
     * @param array<string,mixed> $manualPause
     * @return array{total:int,available:int,unverified:int,paused:int,disconnected:int,rows:list<array<string,mixed>>}
     */
    public function summarize(array $accounts, array $stats, array $circuits, array $manualPause): array
    {
        $settings = new AppSettingsService();
        $recentSeconds = max(60, min(3600, $settings->int('api.health.remote_evidence_recent_seconds', 900)));
        $staleSeconds = max($recentSeconds, min(604800, $settings->int('api.health.remote_evidence_stale_seconds', 3600)));
        $statsByAccount = [];
        foreach ($stats as $stat) {
            $statsByAccount[(int) ($stat['meli_account_id'] ?? 0)] = $stat;
        }
        $circuitsByAccount = [];
        $globalCircuits = [];
        foreach ($circuits as $circuit) {
            $circuitAccountId = (int) ($circuit['meli_account_id'] ?? 0);
            if (($circuit['scope'] ?? '') === 'app' || $circuitAccountId <= 0) {
                $globalCircuits[] = $circuit;
                continue;
            }
            $circuitsByAccount[$circuitAccountId][] = $circuit;
        }
        $manualByAccount = [];
        foreach (($manualPause['pauses'] ?? []) as $pause) {
            if (($pause['scope'] ?? '') === 'account') {
                $manualByAccount[(int) ($pause['meli_account_id'] ?? 0)][] = $pause;
            }
        }

        $rows = [];
        $clock = new SystemDatabaseUtcClock();
        $available = 0;
        $unverified = 0;
        $pausedCount = 0;
        $disconnected = 0;
        foreach ($accounts as $account) {
            $accountId = (int) ($account['id'] ?? 0);
            $stat = $statsByAccount[$accountId] ?? [];
            $accountCircuits = array_merge($globalCircuits, $circuitsByAccount[$accountId] ?? []);
            $accountManual = $manualByAccount[$accountId] ?? [];
            $connected = in_array(strtolower((string) ($account['status'] ?? '')), ['connected', 'conectado'], true);
            $paused = !$connected ? false : ($accountCircuits !== [] || !empty($manualPause['global']) || $accountManual !== []);
            $activeIncidents = (int) ($stat['active_remote_incident_count'] ?? 0);
            $activeLocalIncidents = (int) ($stat['active_local_incident_count'] ?? 0);
            $sent = (int) ($stat['sent'] ?? 0);
            $successful = (int) ($stat['successful'] ?? 0);
            $lastRemoteSuccessAt = (string) ($stat['last_remote_success_at'] ?? '');
            $lastRemoteSuccessTs = $clock->timestamp($lastRemoteSuccessAt);
            $evidenceAge = $lastRemoteSuccessTs > 0 ? max(0, time() - $lastRemoteSuccessTs) : null;
            $hasRecentRemoteEvidence = $evidenceAge !== null && $evidenceAge <= $recentSeconds;
            $hasAgingRemoteEvidence = $evidenceAge !== null && $evidenceAge <= $staleSeconds;
            $successRate = $sent > 0 ? round(($successful / $sent) * 100, 1) : null;
            $nextSafeAt = null;
            foreach (array_merge($accountCircuits, $accountManual) as $pause) {
                $candidate = $pause['blocked_until'] ?? $pause['paused_until'] ?? null;
                if ($candidate && ($nextSafeAt === null
                    || $clock->timestamp((string) $candidate) > $clock->timestamp($nextSafeAt))) {
                    $nextSafeAt = (string) $candidate;
                }
            }
            if (!empty($manualPause['global'])) {
                foreach (($manualPause['pauses'] ?? []) as $pause) {
                    if (($pause['scope'] ?? '') === 'app' && !empty($pause['paused_until'])) {
                        $candidate = (string) $pause['paused_until'];
                        if ($nextSafeAt === null
                            || $clock->timestamp($candidate) > $clock->timestamp($nextSafeAt)) {
                            $nextSafeAt = $candidate;
                        }
                    }
                }
            }

            $state = !$connected
                ? 'disconnected'
                : ($paused
                    ? 'paused'
                    : ($activeIncidents > 0
                        ? 'attention'
                        : ($hasRecentRemoteEvidence
                            ? 'available'
                            : ($hasAgingRemoteEvidence ? 'aging' : 'unverified'))));
            if ($state === 'available') {
                $available++;
            } elseif (in_array($state, ['aging', 'unverified'], true)) {
                $unverified++;
            } elseif ($state === 'paused') {
                $pausedCount++;
            } elseif ($state === 'disconnected') {
                $disconnected++;
            }
            $rows[] = [
                'id' => $accountId,
                'name' => (string) ($account['account_name'] ?? 'Cuenta Mercado Libre'),
                'state' => $state,
                'state_label' => match ($state) {
                    'disconnected' => 'Desconectada',
                    'paused' => 'Pausada',
                    'attention' => 'Revisar',
                    'aging' => 'Evidencia antigua',
                    'unverified' => 'No comprobada recientemente',
                    default => 'Disponible',
                },
                'sent' => $sent,
                'successful' => $successful,
                'success_rate' => $successRate,
                'evidence_age_seconds' => $evidenceAge,
                'remote_errors' => (int) ($stat['remote_errors'] ?? 0),
                'local_failures' => (int) ($stat['local_failures'] ?? 0),
                'active_incidents' => $activeIncidents,
                'active_local_incidents' => $activeLocalIncidents,
                'last_activity_at' => $stat['last_remote_success_at'] ?? null,
                'last_attempt_at' => $stat['last_remote_attempt_at'] ?? null,
                'last_wait_at' => $stat['last_policy_delay_at'] ?? null,
                'next_safe_at' => $nextSafeAt,
                'paused' => $paused,
                'connected' => $connected,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $priority = ['disconnected' => 0, 'attention' => 1, 'paused' => 2, 'unverified' => 3, 'aging' => 4, 'available' => 5];
            return $priority[$a['state']] <=> $priority[$b['state']]
                ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'total' => count($rows),
            'available' => $available,
            'unverified' => $unverified,
            'paused' => $pausedCount,
            'disconnected' => $disconnected,
            'rows' => $rows,
        ];
    }
}
