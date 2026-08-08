<?php

declare(strict_types=1);

namespace App\Modules\MeliGrowth\Services;

use App\Core\Database;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Shared\Gateways\CoreReadGateway;
use App\Services\BusinessScopeContext;
use PDO;
use Throwable;

final class GrowthDashboardService
{
    /** @return array<string,mixed> */
    public function page(string $page, array $filters): array
    {
        $page = in_array($page, ['promotions', 'performance', 'trends'], true) ? $page : 'promotions';
        $accountId = max(0, (int) ($filters['account_id'] ?? 0));
        $status = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($filters['status'] ?? '')) ?? '';
        $scope = new BusinessScopeContext();
        $authorizedAccountIds = $scope->accountIds();
        if ($accountId > 0) {
            $scope->account($accountId);
        }
        $accounts = [];
        $core = new CoreReadGateway();
        foreach ($authorizedAccountIds as $authorizedAccountId) {
            foreach ($core->activeAccounts($authorizedAccountId) as $account) {
                $accounts[] = $account;
            }
        }
        $siteIds = array_values(array_unique(array_filter(array_map(
            static fn (array $account): string => strtoupper(trim((string) ($account['site_id'] ?? ''))),
            $accounts
        ))));
        $names = [];
        foreach ($accounts as $account) {
            $names[(int) $account['id']] = (string) ($account['account_name'] ?: $account['nickname'] ?: ('Cuenta ' . $account['id']));
        }
        $rows = $this->rows($page, $accountId, $status, $authorizedAccountIds, $siteIds);
        foreach ($rows as &$row) {
            if (isset($row['meli_account_id'])) {
                $row['account_name'] = $names[(int) $row['meli_account_id']] ?? 'Cuenta no disponible';
            }
        }
        unset($row);

        $registry = new ModuleRegistry();
        return [
            'module' => $registry->provider('meli-growth'),
            'state' => $registry->states()['meli-growth'] ?? [],
            'page' => $page,
            'accounts' => $accounts,
            'filters' => ['account_id' => $accountId, 'status' => $status],
            'rows' => $rows,
            'metrics' => $this->metrics($page, $rows, $accountId),
            'capabilities' => $this->capabilities($accountId, $authorizedAccountIds),
            'jobs' => $this->jobs($authorizedAccountIds),
            'lastSnapshotAt' => $this->lastSnapshotAt($page, $accountId, $authorizedAccountIds, $siteIds),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $page, int $accountId, string $status, array $accountIds, array $siteIds): array
    {
        try {
            if ($accountIds === []) {
                return [];
            }
            if ($page === 'performance') {
                $selected = $accountId > 0 ? [$accountId] : $accountIds;
                $where = 'WHERE c.meli_account_id IN (' . implode(',', array_fill(0, count($selected), '?')) . ')';
                $stmt = Database::connection()->prepare(
                    "SELECT c.meli_account_id,c.observed_on,c.visits,c.orders_count,c.conversion_rate,c.source_status
                     FROM ml_growth_conversion_daily c {$where}
                     ORDER BY c.observed_on DESC,c.meli_account_id LIMIT 100"
                );
                $stmt->execute($selected);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($page === 'trends') {
                if ($siteIds === []) {
                    return [];
                }
                $stmt = Database::connection()->prepare(
                    "SELECT * FROM (
                     SELECT 'trend' AS row_type,site_id,category_id,keyword AS label,NULL AS external_item_id,
                            rank_position,observed_at
                     FROM ml_growth_trends
                     UNION ALL
                     SELECT 'highlight' AS row_type,site_id,category_id,
                            COALESCE(external_product_id,external_item_id,'Producto destacado') AS label,
                            external_item_id,rank_position,observed_at
                     FROM ml_growth_highlights
                     ) public_growth_cache
                     WHERE site_id IN (" . implode(',', array_fill(0, count($siteIds), '?')) . ")
                     ORDER BY observed_at DESC,rank_position ASC LIMIT 100"
                );
                $stmt->execute($siteIds);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $conditions = [];
            $params = [];
            if ($accountId > 0) {
                $conditions[] = 'p.meli_account_id=?';
                $params[] = $accountId;
            } else {
                $conditions[] = 'p.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
                $params = array_merge($params, $accountIds);
            }
            if ($status !== '') {
                $conditions[] = 'p.status=?';
                $params[] = $status;
            }
            $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
            $stmt = Database::connection()->prepare(
                "SELECT p.meli_account_id,p.external_promotion_id,p.name,p.promotion_type,p.status,p.start_at,p.end_at,p.observed_at,
                        (SELECT COUNT(*) FROM ml_growth_candidates c
                         WHERE c.meli_account_id=p.meli_account_id AND c.external_promotion_id=p.external_promotion_id) AS candidates
                 FROM ml_growth_promotions p {$where}
                 ORDER BY p.observed_at DESC,p.id DESC LIMIT 100"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array{label:string,value:string,tone:string,help:string}> */
    private function metrics(string $page, array $rows, int $accountId): array
    {
        if ($rows === []) {
            return [
                ['label' => 'Información comprobada', 'value' => 'Sin comprobar', 'tone' => 'neutral', 'help' => 'El cero no significa ausencia: todavía no existe un snapshot confirmado.'],
                ['label' => 'Cuenta', 'value' => $accountId > 0 ? 'Seleccionada' : 'Todas', 'tone' => 'neutral', 'help' => 'El filtro no inicia consultas remotas.'],
                ['label' => 'Actualización', 'value' => 'Pendiente', 'tone' => 'warning', 'help' => 'Programe una actualización y deje que cron procese las etapas.'],
            ];
        }
        if ($page === 'performance') {
            $visits = array_sum(array_map(static fn (array $row): int => (int) $row['visits'], $rows));
            $orders = array_sum(array_map(static fn (array $row): int => (int) $row['orders_count'], $rows));
            $rate = $visits > 0 ? ($orders / $visits) * 100 : null;
            return [
                ['label' => 'Visitas observadas', 'value' => number_format($visits, 0, ',', '.'), 'tone' => 'neutral', 'help' => 'Visitas agregadas informadas por Mercado Libre.'],
                ['label' => 'Órdenes locales', 'value' => number_format($orders, 0, ',', '.'), 'tone' => 'neutral', 'help' => 'Órdenes ya incorporadas al ERP en el mismo periodo.'],
                ['label' => 'Conversión estimada', 'value' => $rate === null ? 'Sin base' : number_format($rate, 2, ',', '.') . ' %', 'tone' => 'success', 'help' => 'Órdenes locales divididas entre visitas remotas; es una estimación operativa.'],
            ];
        }
        if ($page === 'trends') {
            $trends = count(array_filter($rows, static fn (array $row): bool => $row['row_type'] === 'trend'));
            $highlights = count($rows) - $trends;
            return [
                ['label' => 'Tendencias observadas', 'value' => (string) $trends, 'tone' => 'neutral', 'help' => 'Búsquedas generales del sitio, no visitas propias.'],
                ['label' => 'Destacados', 'value' => (string) $highlights, 'tone' => 'neutral', 'help' => 'Productos o ítems destacados en categorías consultadas.'],
                ['label' => 'Frecuencia', 'value' => 'Semanal', 'tone' => 'success', 'help' => 'La caché evita consultar información general en cada ciclo.'],
            ];
        }
        $active = count(array_filter($rows, static fn (array $row): bool => in_array(strtolower((string) $row['status']), ['started', 'active'], true)));
        $candidates = array_sum(array_map(static fn (array $row): int => (int) $row['candidates'], $rows));
        return [
            ['label' => 'Promociones visibles', 'value' => (string) count($rows), 'tone' => 'neutral', 'help' => 'Promociones ofrecidas o vigentes para las cuentas filtradas.'],
            ['label' => 'Activas', 'value' => (string) $active, 'tone' => $active > 0 ? 'success' : 'neutral', 'help' => 'Promociones que Mercado Libre informó como activas o iniciadas.'],
            ['label' => 'Candidatos recibidos', 'value' => (string) $candidates, 'tone' => 'neutral', 'help' => 'Oportunidades notificadas; el ERP no incorpora productos automáticamente.'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function capabilities(int $accountId, array $accountIds): array
    {
        try {
            if ($accountIds === []) {
                return [];
            }
            $sql = 'SELECT meli_account_id,capability,status,safe_message,checked_at,cooldown_until
                    FROM ml_growth_capabilities';
            $params = $accountId > 0 ? [$accountId] : $accountIds;
            if ($accountId > 0) {
                $sql .= ' WHERE meli_account_id=?';
            } else {
                $sql .= ' WHERE meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            }
            $sql .= ' ORDER BY meli_account_id,capability LIMIT 30';
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
                "SELECT id,job_type,meli_account_id,status,stage,progress_current,progress_total,next_run_at,safe_error_message,updated_at
                 FROM system_module_jobs WHERE module_id='meli-growth'
                   AND meli_account_id IN (" . implode(',', array_fill(0, count($accountIds), '?')) . ")
                 ORDER BY id DESC LIMIT 10"
            );
            $stmt->execute($accountIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function lastSnapshotAt(string $page, int $accountId, array $accountIds, array $siteIds): ?string
    {
        $table = match ($page) {
            'performance' => 'ml_growth_conversion_daily',
            'trends' => 'ml_growth_trends',
            default => 'ml_growth_promotions',
        };
        $column = $page === 'performance' ? 'observed_on' : 'observed_at';
        $supportsAccount = $page !== 'trends';
        try {
            $sql = "SELECT MAX({$column}) FROM {$table}";
            $params = [];
            if ($supportsAccount) {
                $selected = $accountId > 0 ? [$accountId] : $accountIds;
                if ($selected === []) {
                    return null;
                }
                $sql .= ' WHERE meli_account_id IN (' . implode(',', array_fill(0, count($selected), '?')) . ')';
                $params = $selected;
            } else {
                if ($siteIds === []) {
                    return null;
                }
                $sql .= ' WHERE site_id IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')';
                $params = $siteIds;
            }
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
