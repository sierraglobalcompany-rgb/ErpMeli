<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Core\Database;
use App\Core\Modules\ModuleRegistry;
use PDO;
use Throwable;
use App\Services\BusinessScopeContext;

final class ModuleDashboardService
{
    /** @var array<string,list<string>> */
    private const TABLES = [
        'meli-insights' => ['ml_insights_item_prices', 'ml_insights_item_performance', 'ml_insights_moderations', 'ml_insights_catalog_competition'],
        'meli-growth' => ['ml_growth_promotions', 'ml_growth_item_visits_daily', 'ml_growth_trends', 'ml_growth_highlights'],
        'meli-ads' => ['ml_ads_advertisers', 'ml_ads_campaigns', 'ml_ads_ads', 'ml_ads_metrics_daily'],
        'meli-postsale' => ['ml_postsale_conversations', 'ml_postsale_returns', 'ml_postsale_product_reviews', 'ml_postsale_sales_feedback'],
        'meli-logistics' => ['ml_logistics_shipment_snapshots', 'ml_logistics_tracking_events', 'ml_logistics_inventory_snapshots', 'ml_logistics_inventory_differences'],
    ];

    /** @return array<string,mixed> */
    public function summary(string $moduleId): array
    {
        $provider = (new ModuleRegistry())->provider($moduleId);
        if ($provider === null) {
            throw new \RuntimeException('Módulo no reconocido.');
        }
        $scope = new BusinessScopeContext();
        $accountIds = $scope->accountIds();
        $siteIds = $this->authorizedSiteIds($accountIds);
        $metrics = [];
        foreach (self::TABLES[$moduleId] ?? [] as $table) {
            try {
                $publicSiteCache = in_array($table, ['ml_growth_trends', 'ml_growth_highlights'], true);
                $ids = $publicSiteCache ? $siteIds : $accountIds;
                if ($ids === []) {
                    $count = 0;
                } else {
                    $column = $publicSiteCache ? 'site_id' : 'meli_account_id';
                    $stmt = Database::connection()->prepare(
                        'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $column . ' IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ')'
                    );
                    $stmt->execute($ids);
                    $count = (int) $stmt->fetchColumn();
                }
            } catch (Throwable) {
                $count = 0;
            }
            $metrics[] = ['label' => $this->humanize($table), 'value' => $count];
        }
        $jobs = [];
        try {
            if ($accountIds === []) {
                throw new \RuntimeException('Sin cuentas autorizadas.');
            }
            $stmt = Database::connection()->prepare(
                'SELECT id,job_type,status,progress_current,progress_total,next_run_at,safe_error_message,updated_at
                 FROM system_module_jobs WHERE module_id=? AND meli_account_id IN ('
                 . implode(',', array_fill(0, count($accountIds), '?')) . ') ORDER BY id DESC LIMIT 10'
            );
            $stmt->execute(array_merge([$moduleId], $accountIds));
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
        }
        return [
            'module' => $provider,
            'metrics' => $metrics,
            'jobs' => $jobs,
            'state' => (new ModuleRegistry())->states()[$moduleId] ?? [],
        ];
    }

    /** @param list<int> $accountIds @return list<string> */
    private function authorizedSiteIds(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT site_id FROM meli_accounts WHERE id IN ('
            . implode(',', array_fill(0, count($accountIds), '?')) . ') AND site_id IS NOT NULL AND site_id<>""'
        );
        $stmt->execute($accountIds);
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function humanize(string $table): string
    {
        $labels = [
            'ml_insights_item_prices' => 'Precios observados',
            'ml_insights_item_performance' => 'Publicaciones evaluadas',
            'ml_insights_moderations' => 'Moderaciones',
            'ml_insights_catalog_competition' => 'Comparaciones',
            'ml_growth_promotions' => 'Promociones',
            'ml_growth_item_visits_daily' => 'Visitas diarias',
            'ml_growth_trends' => 'Tendencias',
            'ml_growth_highlights' => 'Oportunidades',
            'ml_ads_advertisers' => 'Anunciantes',
            'ml_ads_campaigns' => 'Campañas',
            'ml_ads_ads' => 'Anuncios',
            'ml_ads_metrics_daily' => 'Métricas diarias',
            'ml_postsale_conversations' => 'Conversaciones',
            'ml_postsale_returns' => 'Devoluciones',
            'ml_postsale_product_reviews' => 'Opiniones',
            'ml_postsale_sales_feedback' => 'Calificaciones',
            'ml_logistics_shipment_snapshots' => 'Envíos observados',
            'ml_logistics_tracking_events' => 'Eventos de seguimiento',
            'ml_logistics_inventory_snapshots' => 'Inventarios',
            'ml_logistics_inventory_differences' => 'Diferencias',
        ];
        return $labels[$table] ?? $table;
    }
}
