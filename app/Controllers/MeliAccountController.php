<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\OAuthService;
use App\Services\EnvironmentFileService;
use App\Services\AuditService;
use App\Services\CompanyOptionService;
use App\Services\MeliAccountOverviewService;
use PDO;

final class MeliAccountController
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $accounts = Database::connection()->query(
            "SELECT a.id,a.company_id,a.meli_user_id,a.nickname,a.account_name,a.status,a.last_sync_at,a.created_at,
                    c.name company_name,t.expires_at,t.scope
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             ORDER BY a.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $summaries = (new MeliAccountOverviewService())->summaries();
        foreach ($accounts as &$account) {
            $account += $summaries[(int) $account['id']] ?? [];
        }
        unset($account);
        $companies = (new CompanyOptionService())->active();
        $oauthConfigured = OAuthService::isConfigured();
        $meliClientId = (string) Env::get('MELI_CLIENT_ID', '');
        $redirectUri = (string) Env::get('MELI_REDIRECT_URI', rtrim((string) Env::get('APP_URL', ''), '/') . '/meli_callback.php');
        View::render('accounts/index', compact('accounts', 'companies', 'oauthConfigured', 'meliClientId', 'redirectUri'));
    }

    public function connect(): void
    {
        Auth::requireRole('admin'); Csrf::validate($_POST['_token'] ?? null);
        $companyId = (int) ($_POST['company_id'] ?? 0); $name = trim((string) ($_POST['account_name'] ?? ''));
        if ($companyId < 1 || $name === '') { Session::flash('error', 'Selecciona empresa y nombre de cuenta.'); $this->redirect('/accounts'); }
        try {
            header('Location: ' . (new OAuthService())->authorizationUrl($companyId, $name)); exit;
        } catch (\Throwable $exception) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($exception));
            $this->redirect('/accounts');
        }
    }

    public function saveApplication(): void
    {
        Auth::requireRole('admin'); Csrf::validate($_POST['_token'] ?? null);
        $clientId = trim((string) ($_POST['client_id'] ?? ''));
        $clientSecret = trim((string) ($_POST['client_secret'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{3,100}$/', $clientId) !== 1 || strlen($clientSecret) < 6 || str_contains($clientSecret, "\n") || str_contains($clientSecret, "\r")) {
            Session::flash('error', 'Ingrese un Client ID y un Client Secret válidos.');
            $this->redirect('/accounts');
        }
        try {
            $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
            (new EnvironmentFileService(dirname(__DIR__, 2)))->update([
                'MELI_CLIENT_ID' => $clientId,
                'MELI_CLIENT_SECRET' => $clientSecret,
                'MELI_REDIRECT_URI' => $appUrl . '/meli_callback.php',
                'MELI_WEBHOOK_URL' => $appUrl . '/webhook_mercadolibre.php',
            ]);
            AuditService::record('update', 'settings', 'meli_application', null, null, null, ['client_id' => $clientId, 'secret_updated' => true]);
            Session::flash('success', 'Aplicación de Mercado Libre configurada. Ya puede conectar una cuenta.');
        } catch (\Throwable $exception) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($exception));
        }
        $this->redirect('/accounts');
    }

    public function callback(): void
    {
        Auth::requireRole('admin');
        try {
            if (!empty($_GET['error'])) throw new \RuntimeException('Mercado Libre rechazó la autorización.');
            (new OAuthService())->complete((string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
            Session::flash('success', 'Cuenta Mercado Libre conectada y verificada.');
        } catch (\Throwable $e) { Session::flash('error', \App\Services\SafeErrorPresenter::message($e)); }
        $this->redirect('/accounts');
    }

    private function redirect(string $path): never { header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path); exit; }
}
