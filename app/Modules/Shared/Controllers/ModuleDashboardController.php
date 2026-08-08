<?php

declare(strict_types=1);

namespace App\Modules\Shared\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Modules\ModuleJobRunner;
use App\Core\Modules\ModuleView;
use App\Core\Session;
use App\Modules\Shared\Services\ModuleDashboardService;
use App\Services\BusinessScopeContext;

abstract class ModuleDashboardController
{
    abstract protected function moduleId(): string;

    public function index(): void
    {
        Auth::requireRole('admin', 'operator', 'consulta');
        ModuleView::render($this->moduleId(), 'index', (new ModuleDashboardService())->summary($this->moduleId()));
    }

    public function refresh(): void
    {
        Auth::requireRole('admin', 'operator');
        Csrf::validate($_POST['_token'] ?? null);
        $account = (new BusinessScopeContext())->account($this->accountId());
        (new ModuleJobRunner())->enqueueScoped(
            $this->moduleId(),
            'snapshot_sync',
            (int) $account['company_id'],
            (int) $account['id'],
            ['requested_by' => Auth::id()],
            80
        );
        Session::flash('success', 'Actualización programada. El módulo procesará los datos sin bloquear el ERP.');
        header('Location: ' . rtrim(\App\Core\Env::get('APP_URL', ''), '/') . '/' . $this->moduleId());
        exit;
    }

    private function accountId(): int
    {
        $value = filter_input(INPUT_POST, 'meli_account_id', FILTER_VALIDATE_INT);
        if (!is_int($value) || $value <= 0) {
            throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        return $value;
    }
}
