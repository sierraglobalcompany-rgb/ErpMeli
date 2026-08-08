<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use App\Services\TemporaryAccessService;

final class UserController
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $users = Database::connection()->query('SELECT id,name,email,role,status,is_temporary,expires_at,revoked_at,temporary_reason,last_login_at,created_at FROM users ORDER BY is_temporary DESC, name')->fetchAll();
        View::render('users/index', [
            'users' => $users,
            'generatedPassword' => Session::flash('temporary_password'),
            'generatedEmail' => Session::flash('temporary_email'),
        ]);
    }

    public function store(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $role = (string) ($_POST['role'] ?? 'consulta');
        $password = (string) ($_POST['password'] ?? '');
        if (!in_array($role, ['admin', 'operador', 'consulta'], true)) {
            Session::flash('error', 'Rol inválido.');
            $this->redirect('/users');
        }
        if (!PasswordPolicy::isValid($password)) {
            Session::flash('error', PasswordPolicy::MESSAGE);
            $this->redirect('/users');
        }
        $stmt = Database::connection()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (:name,:email,:password,:role)');
        $stmt->execute([
            'name' => trim((string) $_POST['name']),
            'email' => strtolower(trim((string) $_POST['email'])),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        AuditService::record('create', 'users', 'user', (int) Database::connection()->lastInsertId());
        Session::flash('success', 'Usuario creado.');
        $this->redirect('/users');
    }

    public function storeTemporary(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $result = (new TemporaryAccessService())->create($_POST);
            Session::flash('temporary_password', $result['password']);
            Session::flash('temporary_email', strtolower(trim((string) ($_POST['email'] ?? ''))));
            Session::flash('success', 'Acceso temporal creado. Copie la contraseña ahora; no se volverá a mostrar.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/users');
    }

    public function revokeTemporary(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new TemporaryAccessService())->revoke((int) ($_POST['id'] ?? 0));
            Session::flash('success', 'Acceso temporal revocado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/users');
    }

    public function extendTemporary(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new TemporaryAccessService())->extend((int) ($_POST['id'] ?? 0), (string) ($_POST['expires_at'] ?? ''));
            Session::flash('success', 'Vencimiento del acceso temporal actualizado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/users');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
