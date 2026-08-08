<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SettingsDefinitionRepository;
use App\Services\SettingsSectionService;
use App\Services\ApiHealthService;
use App\Services\ApiManualPauseService;
use App\Services\BusinessScopeContext;
use Throwable;

final class SettingsSectionController
{
    public function show(array $params): void
    {
        $this->requireAdminPermanent();
        $sectionKey = $this->sectionKey($params);
        $repository = new SettingsDefinitionRepository();
        $section = $repository->section($sectionKey);
        if ($section === null) {
            http_response_code(404);
            View::render('errors/404', []);
            return;
        }
        $values = (new SettingsSectionService())->values($sectionKey);
        $manualPause = null;
        $pauseAccounts = [];
        if ($sectionKey === 'mercadolibre') {
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $manualPause = (new ApiManualPauseService())->summary($accountIds);
            $pauseAccounts = (new ApiHealthService())->accounts();
        }
        View::render('settings/section', compact('sectionKey', 'section', 'values', 'manualPause', 'pauseAccounts'));
    }

    public function save(array $params): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $sectionKey = $this->sectionKey($params);
        try {
            $restore = ($_POST['action'] ?? 'save') === 'restore_recommended';
            (new SettingsSectionService())->save(
                $sectionKey,
                is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [],
                $restore
            );
            Session::flash(
                'success',
                $restore ? 'Se restauraron los valores recomendados de esta sección.' : 'Cambios guardados en esta sección.'
            );
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible guardar la sección.'));
        }
        $this->redirect('/settings/' . rawurlencode($sectionKey));
    }

    private function sectionKey(array $params): string
    {
        return strtolower(trim((string) ($params['section'] ?? '')));
    }

    private function requireAdminPermanent(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            http_response_code(403);
            exit('Los accesos temporales no pueden cambiar la configuración.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
