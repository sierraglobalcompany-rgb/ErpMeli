<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\AsyncSectionService;
use App\Services\ShipmentQueryService;

final class ShipmentController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = $this->filters();
        $service = new ShipmentQueryService();
        $options = $service->options();
        $progressive = (new AppSettingsService())->bool('performance.async_sections_enabled', true)
            && (new AppSettingsService())->bool('performance.shipments_progressive', true)
            && (string) ($_GET['full'] ?? '') !== '1';
        $pageData = $progressive
            ? ['items' => [], 'total' => 0, 'page' => $filters['page'], 'pages' => 1, 'per_page' => $filters['per_page']]
            : $service->page($filters);
        View::render('sales/shipments/index', compact('filters', 'options', 'pageData', 'progressive'));
    }

    public function section(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        $filters = $this->filters();
        try {
            $pageData = (new ShipmentQueryService())->page($filters);
            $async->render('shipments-list', 'sales/shipments/_table', compact('pageData', 'filters'), [
                'total' => $pageData['total'],
                'page' => $pageData['page'],
                'pages' => $pageData['pages'],
                'cache' => 'miss',
            ], $startedAt);
        } catch (\Throwable $error) {
            $async->failure('shipments-list', $error, ['page' => $filters['page']]);
        }
    }

    public function export(): void
    {
        Auth::requireLogin();
        $filters = $this->filters();
        $filters['export'] = true;
        $shipments = (new ShipmentQueryService())->list($filters);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="envios.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['envio','cuenta','orden','estado','subestado','logistica','modo_envio','tracking','costo_vendedor','sync'], ',', '"', '', "\n");
        foreach ($shipments as $s) {
            fputcsv($out, [$s['external_shipment_id'], $s['account_name'], $s['external_order_id'], $s['status'], $s['substatus'], $s['logistic_type'], $s['shipping_mode'], $s['tracking_number'], $s['seller_cost'], $s['synced_at']], ',', '"', '', "\n");
        }
        exit;
    }

    private function filters(): array
    {
        return [
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'logistics_group' => trim((string) ($_GET['logistics_group'] ?? '')),
            'logistic_type' => trim((string) ($_GET['logistic_type'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'substatus' => trim((string) ($_GET['substatus'] ?? '')),
            'preset' => trim((string) ($_GET['preset'] ?? '')),
            'date_field' => trim((string) ($_GET['date_field'] ?? 'synced')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => $this->perPage((int) ($_GET['per_page'] ?? 50)),
        ];
    }

    private function perPage(int $requested): int
    {
        return in_array($requested, [25, 50, 100], true) ? $requested : 50;
    }
}
