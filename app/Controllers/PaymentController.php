<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\Logger;
use App\Services\PaymentSummaryService;

final class PaymentController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'payment_method_id' => trim((string) ($_GET['payment_method_id'] ?? '')),
            'payment_type' => trim((string) ($_GET['payment_type'] ?? '')),
            'detail_status' => trim((string) ($_GET['detail_status'] ?? '')),
            'date_field' => trim((string) ($_GET['date_field'] ?? 'approved')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'sort' => trim((string) ($_GET['sort'] ?? 'date')),
            'dir' => trim((string) ($_GET['dir'] ?? 'DESC')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50,
        ];

        $service = new PaymentSummaryService();
        $diagnostics = $service->diagnostics();

        try {
            $payments = $service->list($filters);
            $totals = $service->totals($filters);
            $options = $service->options();
            $error = null;
        } catch (\Throwable $e) {
            Logger::write('error', 'No se pudo cargar el módulo de pagos.', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'diagnostics' => $diagnostics,
            ]);
            $payments = [];
            $totals = ['count_rows' => 0, 'amount' => 0, 'fees' => 0, 'not_approved' => 0];
            $options = $service->options();
            $diagnostics = $service->diagnostics($e);
            $error = 'No se pudo cargar Pagos por completo. Revise el diagnóstico de esta página y ejecute migraciones pendientes.';
        }

        View::render('sales/payments/index', compact('payments', 'filters', 'totals', 'options', 'error', 'diagnostics'));
    }
}
