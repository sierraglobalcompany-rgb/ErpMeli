<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\AlertService;

final class AlertController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pageData = (new AlertService())->paginateOpen(
            max(1, (int) ($_GET['page'] ?? 1)),
            (int) ($_GET['per_page'] ?? 50)
        );
        $alerts = $pageData['items'];
        View::render('alerts/index', compact('alerts', 'pageData'));
    }

    public function refresh(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $count = (new AlertService())->refresh();
            Session::flash('success', "Alertas actualizadas: {$count} señales revisadas.");
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/alerts');
    }

    public function resolve(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        (new AlertService())->resolve((int) ($_POST['id'] ?? 0));
        Session::flash('success', 'Alerta resuelta.');
        $this->redirect('/alerts');
    }

    public function resolveBatch(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $count = (new AlertService())->resolveMany($ids);
        Session::flash('success', $count . ' alertas fueron marcadas como revisadas.');
        $this->redirect('/alerts');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
