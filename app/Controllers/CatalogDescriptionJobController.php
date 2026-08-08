<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\CatalogDescriptionJobService;
use App\Services\CatalogService;
use App\Services\SafeErrorPresenter;
use Throwable;

final class CatalogDescriptionJobController
{
    public function create(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalogId = (int) ($params['id'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? 'missing');
        try {
            $result = (new CatalogDescriptionJobService())->create($catalogId, $mode, Auth::id());
            if ($this->wantsJson()) {
                $this->json(['ok' => true] + $result);
            }
            Session::flash('success', $result['message']);
            $this->redirect('/catalogs/description-jobs/' . $result['job_id'] . '?autostart=1');
        } catch (Throwable $e) {
            $message = SafeErrorPresenter::message(
                $e,
                'No se pudo crear la cola de descripciones.',
                ['module' => 'catalogs', 'catalog_id' => $catalogId]
            );
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'message' => $message], 422);
            }
            Session::flash('error', $message);
            $this->redirect('/catalogs/' . $catalogId);
        }
    }

    public function index(array $params = []): void
    {
        Auth::requireLogin();
        $catalogId = (int) ($params['id'] ?? 0);
        (new CatalogDescriptionJobService())->assertCatalogAuthorized($catalogId);
        $catalog = (new CatalogService())->find($catalogId);
        if (!$catalog) {
            http_response_code(404);
            View::render('errors/404', [], false);
            return;
        }
        $jobs = (new CatalogDescriptionJobService())->recentForCatalog($catalogId, 30);
        View::render('catalogs/description_jobs', compact('catalog', 'jobs'));
    }

    public function show(array $params = []): void
    {
        Auth::requireLogin();
        $jobId = (int) ($params['jobId'] ?? 0);
        $service = new CatalogDescriptionJobService();
        $service->assertJobAuthorized($jobId);
        $job = $service->statusPayload($jobId);
        if (empty($job['available'])) {
            http_response_code(404);
            View::render('errors/404', [], false);
            return;
        }
        $status = (string) ($_GET['status'] ?? '');
        $items = $service->items($jobId, $status, 250);
        View::render('catalogs/description_job_show', compact('job', 'items', 'status'));
    }

    public function status(array $params = []): void
    {
        Auth::requireLogin();
        $jobId = (int) ($params['jobId'] ?? 0);
        $service = new CatalogDescriptionJobService();
        $service->assertJobAuthorized($jobId);
        $payload = $service->statusPayload($jobId);
        $this->json(['ok' => !empty($payload['available']), 'job' => $payload], !empty($payload['available']) ? 200 : 404);
    }

    public function step(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        http_response_code(410);
        $this->json([
            'ok' => false,
            'retired' => true,
            'redirect' => '/settings/manual-processing?scope=descriptions',
            'message' => 'El ejecutor web fue retirado. El lanzador CLI procesa las descripciones.',
        ], 410);
    }

    public function pause(array $params = []): void
    {
        $this->mutate($params, 'pause', 'Trabajo pausado.');
    }

    public function resume(array $params = []): void
    {
        $this->mutate($params, 'resume', 'Trabajo reanudado.');
    }

    public function cancel(array $params = []): void
    {
        $this->mutate($params, 'cancel', 'Trabajo cancelado. El historial y checkpoint se conservaron.');
    }

    public function reopen(array $params = []): void
    {
        $this->mutate($params, 'reopen', 'Trabajo reabierto desde su checkpoint.');
    }

    public function retry(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = (int) ($params['jobId'] ?? 0);
        try {
            $service = new CatalogDescriptionJobService();
            $service->assertJobAuthorized($jobId);
            $count = $service->retryErrors($jobId);
            $message = $count > 0 ? $count . ' productos con error fueron reencolados.' : 'No había errores para reintentar.';
            $this->respondMutation($jobId, $message);
        } catch (Throwable $e) {
            $this->respondMutationError($jobId, $e);
        }
    }

    private function mutate(array $params, string $method, string $message): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = (int) ($params['jobId'] ?? 0);
        try {
            $service = new CatalogDescriptionJobService();
            $service->assertJobAuthorized($jobId);
            $service->{$method}($jobId);
            $this->respondMutation($jobId, $message);
        } catch (Throwable $e) {
            $this->respondMutationError($jobId, $e);
        }
    }

    private function respondMutation(int $jobId, string $message): never
    {
        if ($this->wantsJson()) {
            $this->json([
                'ok' => true,
                'message' => $message,
                'job' => $this->authorizedStatus($jobId),
            ]);
        }
        Session::flash('success', $message);
        $this->redirect('/catalogs/description-jobs/' . $jobId);
    }

    /** @return array<string,mixed> */
    private function authorizedStatus(int $jobId): array
    {
        $service = new CatalogDescriptionJobService();
        $service->assertJobAuthorized($jobId);
        return $service->statusPayload($jobId);
    }

    private function respondMutationError(int $jobId, Throwable $e): never
    {
        $message = SafeErrorPresenter::message(
            $e,
            'No fue posible cambiar el estado del trabajo de descripciones.',
            ['catalog_description_job_id' => $jobId]
        );
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => $message], 422);
        }
        Session::flash('error', $message);
        $this->redirect('/catalogs/description-jobs/' . $jobId);
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (string) ($_POST['format'] ?? '') === 'json';
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
