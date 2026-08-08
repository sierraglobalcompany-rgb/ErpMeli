<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\SameOriginGuard;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditService;
use App\Services\AppVersionService;
use App\Services\InstalledVersionMarkerService;
use App\Services\AuthenticationAttemptService;

final class AuthController
{
    public function show(): void
    {
        if (Auth::check()) { $this->redirect('/'); }
        View::render('auth/login', ['error' => Session::flash('error')], false);
    }

    public function login(): void
    {
        try {
            Csrf::validate($_POST['_token'] ?? null);
            SameOriginGuard::assertRequest(true);
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $attempts = new AuthenticationAttemptService();
            if ($attempts->isLimited($email, $ip)) {
                Session::flash('error', 'Demasiados intentos. Intenta nuevamente en 15 minutos.');
                $this->redirect('/login');
            }
            $success = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
            $attempts->record($email, $ip, $success);
            if (!$success) {
                $reason = Auth::failureReason();
                if ($reason !== null && $reason !== 'invalid_credentials') {
                    AuditService::recordForUser(Auth::failureUserId(), 'login_blocked', 'auth', 'user', Auth::failureUserId(), null, null, ['reason' => $reason]);
                    Session::flash('error', $reason);
                    $this->redirect('/login');
                }
                AuditService::recordForUser(Auth::failureUserId(), 'login_failed', 'auth', 'user', Auth::failureUserId(), null, null, ['email_hash' => hash('sha256', $email)]);
                Session::flash('error', 'Correo o contraseña incorrectos.');
                $this->redirect('/login');
            }
            AuditService::record('login', 'auth', 'user', Auth::id());
            if (
                Auth::role() === 'admin'
                && !Auth::isTemporary()
                && (new InstalledVersionMarkerService())->requiresUpdate(AppVersionService::fileVersion())
            ) {
                $this->redirect('/actualizar.php');
            }
            $this->redirect('/');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/login');
        }
    }

    public function logout(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        SameOriginGuard::assertRequest(true);
        AuditService::record('logout', 'auth', 'user', Auth::id());
        Session::destroy();
        $this->redirect('/login');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
