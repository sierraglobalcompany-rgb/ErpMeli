<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Modules\ModuleHealthService;
use App\Core\Modules\ModuleMigrationRunner;
use App\Core\Modules\ModuleQueueHealthService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\ModuleRuntimeReadinessService;
use App\Core\Session;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\ReadModelCacheService;

final class ModuleAdminController
{
    public function index(): void
    {
        $this->requireAdmin();
        View::render('settings/modules', [
            'modules' => (new ModuleHealthService())->all(),
            'queueHealth' => null,
        ]);
    }

    public function queueStatus(): void
    {
        $this->requireAdmin();
        Session::closeReadOnly();
        $cached = (new ReadModelCacheService())->rememberArray(
            'module-queue-health',
            'v1',
            10,
            static fn(): array => (new ModuleQueueHealthService())->status()
        );
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(
            ['ok' => true, 'queue' => $cached['value'], 'meta' => ['cache' => $cached['cache']]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function reconcileEvents(): void
    {
        $this->requireAdmin();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'La reconciliación automática de módulos está retirada. No se creó ningún trabajo.');
        $this->redirect();
    }

    public function migrate(): void
    {
        $this->requireAdmin();
        Csrf::validate($_POST['_token'] ?? null);
        $moduleId = $this->moduleId();
        if (!(new ModuleRuntimeReadinessService())->ready(true)) {
            Session::flash('error', 'Complete primero las migraciones centrales 087 y 088.');
            $this->redirect();
        }
        if (!$this->rolloutAllowed($moduleId)) {
            Session::flash('error', 'Las migraciones de este módulo se instalarán con su versión funcional.');
            $this->redirect();
        }
        $result = (new ModuleMigrationRunner())->run($moduleId);
        $applied = count(array_filter($result, static fn(array $row): bool => $row['status'] === 'applied'));
        Session::flash('success', 'Migraciones del módulo aplicadas: ' . $applied . '.');
        $this->redirect();
    }

    public function enable(): void
    {
        $this->requireAdmin();
        Csrf::validate($_POST['_token'] ?? null);
        $moduleId = $this->moduleId();
        if (!(new ModuleRuntimeReadinessService())->ready(true)) {
            Session::flash('error', 'El runtime modular está esperando que termine la actualización central.');
            $this->redirect();
        }
        if (!$this->rolloutAllowed($moduleId)) {
            Session::flash('error', 'Este módulo todavía no está disponible para activación. Su estructura permanece aislada y sin ejecutar trabajos.');
            $this->redirect();
        }
        $registry = new ModuleRegistry();
        $provider = $registry->provider($moduleId);
        $state = $registry->states()[$moduleId] ?? null;
        if ($provider === null || !is_array($state) || (new ModuleMigrationRunner())->pending($moduleId) !== []) {
            Session::flash('error', 'El módulo no puede habilitarse hasta completar su instalación.');
            $this->redirect();
        }
        (new AppSettingsService())->set($provider->featureFlag(), '1', 'modules');
        Database::connection()->prepare("UPDATE system_modules SET status='enabled',enabled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE module_id=?")->execute([$moduleId]);
        Session::flash('success', 'Módulo habilitado.');
        $this->redirect();
    }

    public function disable(): void
    {
        $this->requireAdmin();
        Csrf::validate($_POST['_token'] ?? null);
        $moduleId = $this->moduleId();
        $provider = (new ModuleRegistry())->provider($moduleId);
        if ($provider !== null) {
            (new AppSettingsService())->set($provider->featureFlag(), '0', 'modules');
        }
        Database::connection()->prepare("UPDATE system_modules SET status='disabled',disabled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE module_id=?")->execute([$moduleId]);
        Database::connection()->prepare("UPDATE system_module_jobs SET status='paused',safe_error_message='Módulo deshabilitado.',updated_at=UTC_TIMESTAMP() WHERE module_id=? AND status IN ('pending','retry')")->execute([$moduleId]);
        Session::flash('success', 'Módulo deshabilitado sin eliminar sus datos.');
        $this->redirect();
    }

    public function diagnose(): void
    {
        $this->requireAdmin();
        Csrf::validate($_POST['_token'] ?? null);
        $moduleId = $this->moduleId();
        $rows = (new ModuleHealthService())->all(true);
        $found = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] === $moduleId));
        (new ModuleHealthService())->record($moduleId, $found !== [] && $found[0]['compatible'] ? 'ok' : 'warning', $found[0] ?? []);
        Session::flash('success', 'Diagnóstico del módulo actualizado.');
        $this->redirect();
    }

    private function moduleId(): string
    {
        $id = trim((string) ($_POST['module_id'] ?? ''));
        if (!preg_match('/^meli-[a-z]+$/', $id)) {
            throw new \RuntimeException('Módulo inválido.');
        }
        return $id;
    }

    private function requireAdmin(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            throw new \App\Core\HttpException(403, 'Esta acción requiere un administrador permanente.');
        }
    }

    private function rolloutAllowed(string $moduleId): bool
    {
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (new AppSettingsService())->get('module.rollout.allowed_modules', 'meli-insights'))
        )));
        return in_array($moduleId, $allowed, true);
    }

    private function redirect(): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . '/settings/modules');
        exit;
    }
}
