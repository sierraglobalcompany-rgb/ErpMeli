<?php

declare(strict_types=1);

namespace App\Modules\MeliInsights\Services;

use App\Core\Database;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Shared\Gateways\CoreReadGateway;
use PDO;
use Throwable;
use App\Services\BusinessScopeContext;

final class InsightsDashboardService
{
    /** @return array<string,mixed> */
    public function page(string $page, array $filters): array
    {
        $allowedPages = ['performance', 'pricing', 'moderations', 'capabilities'];
        if (!in_array($page, $allowedPages, true)) {
            $page = 'performance';
        }
        $accountId = max(0, (int) ($filters['account_id'] ?? 0));
        $scope = new BusinessScopeContext();
        if ($accountId > 0) {
            $scope->account($accountId);
        }
        $accountIds = $accountId > 0 ? [$accountId] : $scope->accountIds();
        $status = trim((string) ($filters['status'] ?? ''));
        $from = $this->date((string) ($filters['from'] ?? ''));
        $to = $this->date((string) ($filters['to'] ?? ''));
        $accounts = $this->accounts($accountIds);
        $accountNames = [];
        foreach ($accounts as $account) {
            $accountNames[(int) $account['id']] = (string) ($account['account_name'] ?: $account['nickname'] ?: ('Cuenta ' . $account['id']));
        }

        $rows = $this->rows($page, $accountIds, $status, $from, $to);
        $rows = $this->attachItems($rows);
        foreach ($rows as &$row) {
            $row['account_name'] = $accountNames[(int) ($row['meli_account_id'] ?? 0)] ?? 'Cuenta no disponible';
        }
        unset($row);

        return [
            'module' => (new ModuleRegistry())->provider('meli-insights'),
            'state' => (new ModuleRegistry())->states()['meli-insights'] ?? [],
            'page' => $page,
            'filters' => ['account_id' => $accountId, 'status' => $status, 'from' => $from, 'to' => $to],
            'accounts' => $accounts,
            'rows' => $rows,
            'metrics' => $this->metrics($page, $rows, $accountId),
            'jobs' => $this->jobs($accountIds),
            'reputation' => $page === 'performance' ? $this->reputation($accountIds) : [],
            'itemCapabilities' => $page === 'capabilities' ? $this->itemCapabilities($accountIds) : [],
            'lastSnapshotAt' => $this->lastSnapshotAt($page, $accountIds),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $page, array $accountIds, string $status, ?string $from, ?string $to): array
    {
        [$where, $params] = $this->where($accountIds, $status, $from, $to, $page);
        $sql = match ($page) {
            'pricing' => "SELECT p.meli_account_id,p.external_item_id,p.standard_amount,p.effective_amount,p.currency_id,
                          p.context,p.status,p.observed_at,c.status AS competition_status,c.eligibility,c.suggested_price,c.reason
                          FROM ml_insights_item_prices p
                          LEFT JOIN ml_insights_catalog_competition c
                            ON c.meli_account_id=p.meli_account_id AND c.external_item_id=p.external_item_id
                          {$where} ORDER BY p.observed_at DESC,p.id DESC LIMIT 100",
            'moderations' => "SELECT meli_account_id,external_item_id,moderation_id,status,reason,affected_fields_json,observed_at
                              FROM ml_insights_moderations {$where} ORDER BY observed_at DESC,id DESC LIMIT 100",
            'capabilities' => "SELECT meli_account_id,capability,status,last_http_status,safe_message,cooldown_until,last_checked_at AS observed_at
                               FROM ml_insights_account_capabilities {$where} ORDER BY meli_account_id,capability LIMIT 100",
            default => "SELECT meli_account_id,external_item_id,status,level,score,pending_json,recommendations_json,observed_at
                        FROM ml_insights_item_performance {$where} ORDER BY observed_at DESC,id DESC LIMIT 100",
        };
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(array $accountIds, string $status, ?string $from, ?string $to, string $page): array
    {
        $alias = $page === 'pricing' ? 'p.' : '';
        $conditions = [$accountIds === [] ? '1=0' : $alias . 'meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')'];
        $params = $accountIds;
        if ($status !== '') {
            $allowed = $page === 'capabilities'
                ? ['pending', 'supported', 'permission_required', 'unsupported', 'temporarily_unavailable']
                : ['confirmed', 'active', 'resolved', 'not_eligible', 'unknown'];
            if (in_array($status, $allowed, true)) {
                $conditions[] = $alias . 'status=?';
                $params[] = $status;
            }
        }
        $dateColumn = $page === 'capabilities' ? 'last_checked_at' : 'observed_at';
        if ($from !== null) {
            $conditions[] = $alias . $dateColumn . '>=?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $conditions[] = $alias . $dateColumn . '<?';
            $params[] = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
        }
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function attachItems(array $rows): array
    {
        $byAccount = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['external_item_id'] ?? ''));
            if ($id !== '') {
                $byAccount[(int) $row['meli_account_id']][] = $id;
            }
        }
        $items = [];
        $gateway = new CoreReadGateway();
        foreach ($byAccount as $accountId => $ids) {
            $items[$accountId] = $gateway->itemsByIds($accountId, $ids);
        }
        foreach ($rows as &$row) {
            $accountId = (int) ($row['meli_account_id'] ?? 0);
            $externalId = (string) ($row['external_item_id'] ?? '');
            $row['item'] = $items[$accountId][$externalId] ?? null;
        }
        unset($row);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return list<array{label:string,value:int|string,tone:string}> */
    private function metrics(string $page, array $rows, int $accountId): array
    {
        $count = count($rows);
        if ($rows === []) {
            return [
                ['label' => 'Información comprobada', 'value' => 'Sin comprobar', 'tone' => 'neutral'],
                ['label' => 'Datos disponibles', 'value' => '—', 'tone' => 'neutral'],
                ['label' => 'Requieren atención', 'value' => '—', 'tone' => 'neutral'],
            ];
        }
        if ($page === 'capabilities') {
            $supported = count(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'supported'));
            $attention = count(array_filter($rows, static fn (array $row): bool => in_array($row['status'] ?? '', ['permission_required', 'temporarily_unavailable'], true)));
            return [
                ['label' => 'Operaciones comprobadas', 'value' => $count, 'tone' => 'neutral'],
                ['label' => 'Disponibles', 'value' => $supported, 'tone' => 'success'],
                ['label' => 'Requieren atención', 'value' => $attention, 'tone' => $attention > 0 ? 'warning' : 'success'],
            ];
        }
        if ($page === 'moderations') {
            $active = count(array_filter($rows, static fn (array $row): bool => in_array($row['status'] ?? '', ['active', 'pending', 'warning'], true)));
            return [
                ['label' => 'Moderaciones observadas', 'value' => $count, 'tone' => 'neutral'],
                ['label' => 'Activas', 'value' => $active, 'tone' => $active > 0 ? 'warning' : 'success'],
                ['label' => 'Resueltas o históricas', 'value' => max(0, $count - $active), 'tone' => 'success'],
            ];
        }
        if ($page === 'pricing') {
            $competing = count(array_filter($rows, static fn (array $row): bool => !empty($row['competition_status']) && $row['competition_status'] !== 'not_eligible'));
            return [
                ['label' => 'Precios confirmados', 'value' => $count, 'tone' => 'neutral'],
                ['label' => 'Con información competitiva', 'value' => $competing, 'tone' => 'success'],
                ['label' => 'No elegibles', 'value' => count(array_filter($rows, static fn (array $row): bool => ($row['competition_status'] ?? '') === 'not_eligible')), 'tone' => 'neutral'],
            ];
        }
        $pending = count(array_filter($rows, static fn (array $row): bool => !empty(json_decode((string) ($row['pending_json'] ?? '[]'), true))));
        return [
            ['label' => 'Publicaciones evaluadas', 'value' => $count, 'tone' => 'neutral'],
            ['label' => 'Con mejoras pendientes', 'value' => $pending, 'tone' => $pending > 0 ? 'warning' : 'success'],
            ['label' => 'Cuenta seleccionada', 'value' => $accountId > 0 ? '1' : 'Todas', 'tone' => 'neutral'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function itemCapabilities(array $accountIds): array
    {
        try {
            $sql = "SELECT capability,status,COUNT(*) AS total,MAX(observed_at) AS observed_at
                    FROM ml_insights_item_capabilities";
            if ($accountIds === []) {
                return [];
            }
            $params = $accountIds;
            $sql .= ' WHERE meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $sql .= ' GROUP BY capability,status ORDER BY capability,status';
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function jobs(array $accountIds): array
    {
        try {
            if ($accountIds === []) {
                return [];
            }
            $stmt = Database::connection()->prepare(
                "SELECT id,job_type,meli_account_id,status,stage,progress_current,progress_total,next_run_at,
                        safe_error_message,updated_at
                 FROM system_module_jobs WHERE module_id='meli-insights' AND meli_account_id IN ("
                 . implode(',', array_fill(0, count($accountIds), '?')) . ") ORDER BY id DESC LIMIT 12"
            );
            $stmt->execute($accountIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function reputation(array $accountIds): array
    {
        try {
            $sql = 'SELECT meli_account_id,level_id,power_seller_status,transactions_json,metrics_json,observed_on
                    FROM ml_insights_reputation_snapshots';
            if ($accountIds === []) {
                return [];
            }
            $params = $accountIds;
            $sql .= ' WHERE meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $sql .= ' ORDER BY observed_on DESC,id DESC LIMIT 10';
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function lastSnapshotAt(string $page, array $accountIds): ?string
    {
        $table = match ($page) {
            'pricing' => 'ml_insights_item_prices',
            'moderations' => 'ml_insights_moderations',
            'capabilities' => 'ml_insights_account_capabilities',
            default => 'ml_insights_item_performance',
        };
        $column = $page === 'capabilities' ? 'last_checked_at' : 'observed_at';
        try {
            $sql = "SELECT MAX({$column}) FROM {$table}";
            if ($accountIds === []) {
                return null;
            }
            $params = $accountIds;
            $sql .= ' WHERE meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<int> $accountIds @return list<array<string,mixed>> */
    private function accounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            "SELECT id,company_id,account_name,meli_user_id,nickname,site_id,country_id,status,last_sync_at
             FROM meli_accounts WHERE status='conectado' AND id IN ("
            . implode(',', array_fill(0, count($accountIds), '?')) . ') ORDER BY account_name,id'
        );
        $stmt->execute($accountIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
