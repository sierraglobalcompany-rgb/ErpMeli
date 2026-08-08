<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\SaleFinancialService;
use App\Services\SaleReadService;
use App\Services\SyncSettingsService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class OrderController
{
    public function index(): void
    {
        Auth::requireLogin();
        $query = $_GET;
        if (isset($query['search_type'])) {
            unset($query['search_type']);
        }
        $this->redirect('/sales' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    public function section(): void
    {
        Auth::requireLogin();
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode([
            'status' => 'retired',
            'message' => 'El listado de órdenes fue reemplazado por Ventas de Mercado Libre.',
            'location' => rtrim(Env::get('APP_URL', ''), '/') . '/sales',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function show(): void
    {
        Auth::requireLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $identity = (new \App\Services\SaleReadService())->identityForOrder($id);
        $this->redirect('/sales/show?' . http_build_query([
            'account_id' => $identity['account_id'],
            'sale_id' => $identity['sale_id'],
            'highlight_order' => $this->externalOrderId($id),
        ]));
    }

    public function refresh(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $order = $this->orderRow($id);
            Session::flash(
                'info',
                'La actualización se realizará mediante el lanzador seguro. Revise y prepare el trabajo de ventas; esta página no consultó Mercado Libre.'
            );
            $this->redirect('/settings/manual-processing?scope=sales&account_id=' . (int) $order['meli_account_id'] . '&origin=order_detail');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible preparar la actualización de la orden.',
                ['module' => 'orders', 'order_id' => $id]
            ));
        }
        $this->redirect('/orders/show?id=' . $id);
    }

    public function queueFinancial(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $identity = (new SaleReadService())->identityForOrder($id);
            (new SaleFinancialService())->queue(
                $identity['account_id'],
                $identity['sale_id'],
                Auth::id()
            );
            Session::flash('success', 'La conciliación de toda la venta quedó en espera del lanzador CLI.');
            $this->redirect('/sales/show?' . http_build_query($identity));
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible preparar la conciliación de la venta.'
            ));
        }
        $this->redirect('/orders/show?id=' . $id);
    }

    public function recalculateFinancial(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $identity = (new SaleReadService())->identityForOrder($id);
            (new SaleFinancialService())->queue(
                $identity['account_id'],
                $identity['sale_id'],
                Auth::id()
            );
            Session::flash('success', 'Se recalculará la venta completa, no una orden hija aislada.');
            $this->redirect('/sales/show?' . http_build_query($identity));
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible preparar el recálculo de la venta.'
            ));
        }
        $this->redirect('/orders/show?id=' . $id);
    }

    public function manualReviewFinancial(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $identity = (new SaleReadService())->identityForOrder($id);
            Session::flash(
                'info',
                'La revisión financiera ahora se realiza sobre la venta completa. No se modificó una orden hija.'
            );
            $this->redirect('/sales/show?' . http_build_query($identity));
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible abrir la venta agrupada.'
            ));
        }
        $this->redirect('/orders/show?id=' . $id);
    }

    public function sync(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $tz = new DateTimeZone(DateTimePresenter::timezone());
            $from = new DateTimeImmutable((string) ($_POST['from'] ?? 'today'), $tz);
            $to = (new DateTimeImmutable((string) ($_POST['to'] ?? 'now') . ' 00:00:00', $tz))->modify('+1 day');
            (new SyncSettingsService())->validateManualRange($from, $to, isset($_POST['confirm_large_range']), Auth::role() === 'admin');
            $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
            Session::flash('info', 'La sincronización se preparará como trabajo CLI. Esta página no consultó Mercado Libre.');
            $query = http_build_query([
                'scope' => 'sales',
                'account_id' => $accountId,
                'origin' => 'orders',
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->modify('-1 day')->format('Y-m-d'),
            ]);
            $this->redirect('/settings/manual-processing?' . $query);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible preparar la sincronización de órdenes.'));
        }
        $this->redirect('/orders');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }

    private function orderRow(int $id): array
    {
        $scope = (new \App\Services\BusinessScopeContext())->accountPredicate('meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT * FROM meli_orders WHERE id=? AND ' . $scope['sql'] . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$id], $scope['params']));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new \RuntimeException('Orden no encontrada.');
        }
        return $order;
    }

    private function externalOrderId(int $id): string
    {
        $order = $this->orderRow($id);
        return (string) $order['external_order_id'];
    }
}
