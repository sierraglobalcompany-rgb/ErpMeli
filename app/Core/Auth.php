<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\TemporaryAccessService;
use App\Services\SessionGenerationService;
use PDO;

final class Auth
{
    private static ?string $failureReason = null;
    private static ?int $failureUserId = null;

    public static function attempt(string $email, string $password): bool
    {
        self::$failureReason = null;
        self::$failureUserId = null;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::$failureReason = 'invalid_credentials';
            self::$failureUserId = is_array($user) ? (int) $user['id'] : null;
            return false;
        }

        $temporaryError = TemporaryAccessService::temporaryAccessError($user);
        if ($temporaryError !== null) {
            self::$failureReason = $temporaryError;
            self::$failureUserId = (int) $user['id'];
            return false;
        }
        if ((int) ($user['status'] ?? 0) !== 1) {
            self::$failureReason = 'Usuario inactivo.';
            self::$failureUserId = (int) $user['id'];
            return false;
        }

        Session::regenerate();
        Session::put('user', [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_temporary' => (int) ($user['is_temporary'] ?? 0),
            'expires_at' => $user['expires_at'] ?? null,
            'session_generation' => (new SessionGenerationService())->current(),
        ]);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=:id')->execute(['id' => (int) $user['id']]);
        return true;
    }

    public static function user(): ?array
    {
        $user = Session::get('user');
        if (!is_array($user)) {
            return null;
        }
        $current = (new SessionGenerationService())->current();
        $known = (string) ($user['session_generation'] ?? '');
        if ($current !== '' && !hash_equals($current, $known)) {
            Session::forget('user');
            return null;
        }
        return $user;
    }
    public static function check(): bool { return self::user() !== null; }
    public static function id(): ?int { return self::user()['id'] ?? null; }
    public static function role(): ?string { return self::user()['role'] ?? null; }
    public static function failureReason(): ?string { return self::$failureReason; }
    public static function failureUserId(): ?int { return self::$failureUserId; }
    public static function isTemporary(): bool { return (int) (self::user()['is_temporary'] ?? 0) === 1; }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . '/login');
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        $current = self::normalizeRole((string) self::role());
        $allowed = array_map([self::class, 'normalizeRole'], $roles);
        if (!in_array($current, $allowed, true)) {
            throw new HttpException(403, 'No tiene permisos para realizar esta operación.');
        }
    }

    private static function normalizeRole(string $role): string
    {
        return $role === 'operator' ? 'operador' : $role;
    }
}
