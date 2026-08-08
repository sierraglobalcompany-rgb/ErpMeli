<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use RuntimeException;

final class AdministrativeReauthenticationService
{
    private const SESSION_KEY_AT = '_admin_reauthenticated_at';
    private const SESSION_KEY_USER = '_admin_reauthenticated_user';

    public function requirePassword(string $password): void
    {
        if ($password === '' || Auth::id() === null) {
            throw new RuntimeException('Confirme nuevamente su contraseña administrativa.');
        }
        $statement = Database::connection()->prepare(
            "SELECT password_hash FROM users
             WHERE id=:id AND role='admin' AND status=1 AND is_temporary=0 LIMIT 1"
        );
        $statement->execute(['id' => Auth::id()]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            throw new RuntimeException('La contraseña administrativa no es correcta.');
        }
        Session::put(self::SESSION_KEY_AT, time());
        Session::put(self::SESSION_KEY_USER, (int) Auth::id());
    }

    public function requireRecent(int $ttlSeconds = 900): void
    {
        $userId = (int) (Auth::id() ?? 0);
        $confirmedAt = (int) Session::get(self::SESSION_KEY_AT, 0);
        $confirmedUser = (int) Session::get(self::SESSION_KEY_USER, 0);
        if (
            $userId < 1
            || $confirmedUser !== $userId
            || $confirmedAt < time() - max(60, min(1800, $ttlSeconds))
        ) {
            throw new RuntimeException(
                'Confirme nuevamente su contraseña administrativa antes de continuar.'
            );
        }
    }
}
