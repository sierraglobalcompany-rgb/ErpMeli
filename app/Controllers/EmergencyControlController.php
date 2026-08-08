<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\SameOriginGuard;
use App\Core\Session;
use App\Core\View;
use App\Services\EmergencyControlService;
use App\Services\SystemSafetyStatusService;
use App\Services\AdministrativeReauthenticationService;

final class EmergencyControlController
{
    public function index(): void
    {
        $this->requirePermanentAdministrator();
        $control = new EmergencyControlService();
        View::render('settings/emergency_control', [
            'safety' => (new SystemSafetyStatusService())->status(),
            'credentialReady' => $control->credentialExists(),
            'generatedCredential' => Session::flash('emergency_credential'),
        ]);
    }

    public function provision(): void
    {
        $this->requirePermanentAdministrator();
        $this->validateMutation();
        $this->verifyAdministratorPassword((string) ($_POST['password'] ?? ''));

        $user = Auth::user() ?? [];
        $credential = (new EmergencyControlService())->provision((string) ($user['email'] ?? 'administrator'));
        Session::flash(
            'emergency_credential',
            json_encode($credential, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        Session::flash('success', 'La credencial de emergencia quedó preparada. Guárdela ahora: no volverá a mostrarse.');
        $this->redirect('/settings/emergency-control');
    }

    public function revoke(): void
    {
        $this->requirePermanentAdministrator();
        $this->validateMutation();
        $this->verifyAdministratorPassword((string) ($_POST['password'] ?? ''));

        $user = Auth::user() ?? [];
        (new EmergencyControlService())->revoke((string) ($user['email'] ?? 'administrator'));
        Session::flash('success', 'El acceso de emergencia quedó revocado.');
        $this->redirect('/settings/emergency-control');
    }

    private function verifyAdministratorPassword(string $password): void
    {
        (new AdministrativeReauthenticationService())->requirePassword($password);
    }

    private function validateMutation(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        SameOriginGuard::assertRequest(true);
    }

    private function requirePermanentAdministrator(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            throw new \App\Core\HttpException(403, 'Un administrador permanente debe realizar esta acción.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . $path, true, 303);
        exit;
    }
}
