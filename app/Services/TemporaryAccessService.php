<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class TemporaryAccessService
{
    private const TEMPORARY_ROLES = ['operador', 'consulta'];

    /** @return array{user_id:int,password:string} */
    public function create(array $data): array
    {
        $role = (string) ($data['role'] ?? 'consulta');
        if (!in_array($role, self::TEMPORARY_ROLES, true)) {
            throw new RuntimeException('Un acceso temporal solo puede ser operador o consulta.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $reason = trim((string) ($data['temporary_reason'] ?? ''));
        $expiresAt = $this->normalizeExpiry((string) ($data['expires_at'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $reason === '') {
            throw new RuntimeException('Nombre, correo y motivo son obligatorios para crear un acceso temporal.');
        }

        $password = trim((string) ($data['password'] ?? ''));
        if ($password === '') {
            $password = self::generatePassword();
        }
        if (!PasswordPolicy::isValid($password)) {
            throw new RuntimeException(PasswordPolicy::MESSAGE);
        }

        $stmt = Database::connection()->prepare(
            "INSERT INTO users (name,email,password_hash,role,status,is_temporary,expires_at,temporary_reason,must_change_password)
             VALUES (:name,:email,:password,:role,1,1,:expires,:reason,1)"
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'expires' => $expiresAt,
            'reason' => $reason,
        ]);
        $userId = (int) Database::connection()->lastInsertId();
        AuditService::record('create_temporary_access', 'users', 'user', $userId, null, null, [
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
            'temporary_reason' => $reason,
        ]);
        return ['user_id' => $userId, 'password' => $password];
    }

    public function revoke(int $userId): void
    {
        $user = $this->temporaryUser($userId);
        Database::connection()->prepare('UPDATE users SET revoked_at=NOW(), revoked_by=:admin, status=0 WHERE id=:id AND is_temporary=1')
            ->execute(['admin' => Auth::id(), 'id' => $userId]);
        AuditService::record('revoke_temporary_access', 'users', 'user', $userId, null, ['revoked_at' => $user['revoked_at']], ['revoked_by' => Auth::id()]);
    }

    public function extend(int $userId, string $expiresAt): void
    {
        $user = $this->temporaryUser($userId);
        $normalized = $this->normalizeExpiry($expiresAt);
        Database::connection()->prepare('UPDATE users SET expires_at=:expires, status=1, revoked_at=NULL, revoked_by=NULL WHERE id=:id AND is_temporary=1')
            ->execute(['expires' => $normalized, 'id' => $userId]);
        AuditService::record('extend_temporary_access', 'users', 'user', $userId, null, ['expires_at' => $user['expires_at']], ['expires_at' => $normalized]);
    }

    public static function generatePassword(): string
    {
        return 'Tmp!' . random_int(10, 99) . bin2hex(random_bytes(4));
    }

    public static function temporaryAccessError(array $user): ?string
    {
        if ((int) ($user['is_temporary'] ?? 0) !== 1) {
            return null;
        }
        if (!empty($user['revoked_at'])) {
            return 'El acceso temporal fue revocado.';
        }
        if (!empty($user['expires_at']) && strtotime((string) $user['expires_at']) <= time()) {
            return 'El acceso temporal ya expiró.';
        }
        return null;
    }

    private function normalizeExpiry(string $value): string
    {
        if ($value === '') {
            throw new RuntimeException('La fecha de expiración es obligatoria.');
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('America/Bogota'));
        if ($date->getTimestamp() <= time()) {
            throw new RuntimeException('La fecha de expiración debe ser futura.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function temporaryUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id=:id AND is_temporary=1 LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('Acceso temporal no encontrado.');
        }
        return $user;
    }
}
