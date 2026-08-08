<?php

declare(strict_types=1);

namespace App\Services;

final class ApiHealthTechnicalService
{
    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function technical(array $filters = []): array
    {
        $perPageAllowed = [25, 50, 100];
        $perPage = (int) ($filters['per_page'] ?? $this->settings->int('api.health.technical_page_size', 50));
        $perPage = in_array($perPage, $perPageAllowed, true) ? $perPage : 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $accountId = max(0, (int) ($filters['account_id'] ?? 0));
        $scope = new BusinessScopeContext();
        if ($accountId > 0) {
            $scope->account($accountId);
        }
        $accountIds = $accountId > 0 ? [$accountId] : $scope->accountIds();
        $operation = mb_strtolower(trim((string) ($filters['operation'] ?? '')));
        $state = in_array((string) ($filters['state'] ?? ''), ['available', 'cooldown'], true) ? (string) $filters['state'] : '';

        $healthScope = (new ApiHealthAccessScope())->snapshot($accountId ?: null);
        $budget = (new ApiBudgetService())->summary(500, $accountIds);
        $clock = new SystemDatabaseUtcClock();
        $rows = array_values(array_filter($budget['windows'], static function (array $row) use ($accountId, $operation, $state, $clock): bool {
            if ($accountId > 0 && (int) ($row['meli_account_id'] ?? 0) !== $accountId) {
                return false;
            }
            if ($operation !== '' && !str_contains(mb_strtolower((string) ($row['endpoint_path'] ?? '')), $operation)
                && !str_contains(mb_strtolower((string) ($row['job_type'] ?? '')), $operation)) {
                return false;
            }
            $cooldownAt = $clock->timestamp((string) ($row['cooldown_until'] ?? ''));
            $cooldown = $cooldownAt !== null && $cooldownAt > time();
            return $state === '' || ($state === 'cooldown' && $cooldown) || ($state === 'available' && !$cooldown);
        }));
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'available' => $budget['available'],
            'protocol' => $budget['protocol'],
            'budget' => $budget,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'filters' => ['account_id' => $accountId, 'operation' => $operation, 'state' => $state],
            'circuits' => (new ApiGuardService())->openCircuits(100, $accountIds, $healthScope['application']),
            'manual_pause' => (new ApiManualPauseService())->summary($accountIds, $healthScope['application']),
            'emergency_stop' => (new MeliEmergencyStopService())->status(),
        ];
    }
}
