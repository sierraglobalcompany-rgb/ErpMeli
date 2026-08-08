<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use Throwable;

final class CatalogAccessService
{
    private const TOKEN_SESSION_SECONDS = 21600;

    public function canViewPublic(array $catalog): bool
    {
        $status = $this->publicAccessStatus($catalog, (string) ($_GET['token'] ?? ''));
        $allowed = in_array($status['status'], ['public', 'token_valid', 'token_session', 'password_session', 'internal_login'], true);
        if (!$allowed) {
            $this->recordAccessError((int) ($catalog['id'] ?? 0), (string) $status['status']);
        }
        return $allowed;
    }

    /** @return array{status:string,message:string} */
    public function publicAccessStatus(array $catalog, string $token = ''): array
    {
        if ((int) ($catalog['is_enabled'] ?? 0) !== 1) {
            return ['status' => 'disabled', 'message' => 'Catálogo deshabilitado.'];
        }
        if (!empty($catalog['expires_at']) && strtotime((string) $catalog['expires_at']) < time()) {
            return ['status' => 'expired', 'message' => 'Catálogo vencido.'];
        }
        return match ((string) ($catalog['visibility'] ?? 'internal_only')) {
            'public' => ['status' => 'public', 'message' => 'Acceso público habilitado.'],
            'private_token' => $this->tokenAccessStatus($catalog, $token),
            'private_password' => $this->hasPasswordSession((int) $catalog['id'])
                ? ['status' => 'password_session', 'message' => 'Sesión privada por clave activa.']
                : ['status' => 'password_required', 'message' => 'Requiere clave para empleados.'],
            'internal_only' => Auth::check()
                ? ['status' => 'internal_login', 'message' => 'Acceso interno autenticado.']
                : ['status' => 'internal_login_required', 'message' => 'Requiere iniciar sesión en ERP.'],
            default => ['status' => 'wrong_visibility', 'message' => 'Visibilidad de catálogo no válida.'],
        };
    }

    /** @return array{status:string,message:string} */
    public function testToken(array $catalog, string $token): array
    {
        if ((string) ($catalog['visibility'] ?? '') !== 'private_token') {
            return ['status' => 'wrong_visibility', 'message' => 'El catálogo no está configurado como privado por enlace.'];
        }
        if ((int) ($catalog['is_enabled'] ?? 0) !== 1) {
            return ['status' => 'disabled', 'message' => 'El catálogo está deshabilitado.'];
        }
        if (!empty($catalog['expires_at']) && strtotime((string) $catalog['expires_at']) < time()) {
            return ['status' => 'expired', 'message' => 'El catálogo está vencido.'];
        }
        $token = $this->normalizeToken($token);
        if ($token === '') {
            return ['status' => 'missing_token', 'message' => 'No se recibió token para probar.'];
        }
        if (!$this->tokenShapeIsValid($token)) {
            return ['status' => 'invalid_token', 'message' => 'El formato del token no es válido.'];
        }
        if ($this->validToken($catalog, $token)) {
            return ['status' => 'token_valid', 'message' => 'El token coincide con el enlace privado actual.'];
        }
        return ['status' => 'invalid_token', 'message' => 'El token no coincide con el enlace privado actual.'];
    }

    public function verifyPassword(array $catalog, string $password): bool
    {
        $key = 'catalog_password_attempts_' . (int) $catalog['id'];
        $attempts = (int) Session::get($key, 0);
        if ($attempts >= 8) {
            return false;
        }
        $hash = (string) ($catalog['password_hash'] ?? '');
        $ok = $hash !== '' && password_verify($password, $hash);
        if ($ok) {
            Session::put($this->sessionKey((int) $catalog['id']), time() + 3600);
            Session::forget($key);
            return true;
        }
        Session::put($key, $attempts + 1);
        return false;
    }

    public function accessLabel(array $catalog): string
    {
        return match ((string) ($catalog['visibility'] ?? 'internal_only')) {
            'public' => 'Público',
            'private_token' => 'Privado por enlace',
            'private_password' => 'Clave para empleados',
            default => 'Solo interno',
        };
    }

    private function validToken(array $catalog, string $token): bool
    {
        $token = $this->normalizeToken($token);
        return $this->tokenMatchMode($catalog, $token) !== null;
    }

    /** @return array{status:string,message:string} */
    private function tokenAccessStatus(array $catalog, string $token): array
    {
        $catalogId = (int) ($catalog['id'] ?? 0);
        if ($this->hasTokenSession($catalogId)) {
            return ['status' => 'token_session', 'message' => 'Sesión privada por enlace activa.'];
        }
        $hash = strtolower(trim((string) ($catalog['access_token_hash'] ?? '')));
        if ($hash === '') {
            return ['status' => 'missing_hash', 'message' => 'El catálogo no tiene token privado guardado.'];
        }
        $token = $this->normalizeToken($token);
        if ($token === '') {
            return ['status' => 'missing_token', 'message' => 'Falta token en el enlace privado.'];
        }
        if (!$this->tokenShapeIsValid($token)) {
            return ['status' => 'invalid_token', 'message' => 'El token privado no coincide.'];
        }
        $matchMode = $this->tokenMatchMode($catalog, $token);
        if ($matchMode === null) {
            return ['status' => 'invalid_token', 'message' => 'El token privado no coincide.'];
        }
        if ($matchMode !== 'sha256_full') {
            $this->upgradeTokenHash((int) ($catalog['id'] ?? 0), $token, $matchMode);
        }
        Session::put($this->tokenSessionKey($catalogId), time() + self::TOKEN_SESSION_SECONDS);
        return ['status' => 'token_valid', 'message' => 'Token privado válido.'];
    }

    private function normalizeToken(string $token): string
    {
        return strtolower(trim($token));
    }

    private function tokenShapeIsValid(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32,128}$/', $token) === 1;
    }

    private function tokenMatchMode(array $catalog, string $token): ?string
    {
        $token = $this->normalizeToken($token);
        if (!$this->tokenShapeIsValid($token)) {
            return null;
        }

        $stored = trim((string) ($catalog['access_token_hash'] ?? ''));
        if ($stored === '') {
            return null;
        }

        $storedLower = strtolower($stored);
        $fullHash = hash('sha256', $token);
        if (strlen($storedLower) === 64 && ctype_xdigit($storedLower) && hash_equals($storedLower, $fullHash)) {
            return 'sha256_full';
        }

        if (ctype_xdigit($storedLower) && strlen($storedLower) >= 32 && strlen($storedLower) < 64 && hash_equals($storedLower, substr($fullHash, 0, strlen($storedLower)))) {
            return 'sha256_truncated';
        }

        if (ctype_xdigit($storedLower) && hash_equals($storedLower, $token)) {
            return 'legacy_plain_token';
        }

        if (str_starts_with($stored, '$') && password_verify($token, $stored)) {
            return 'legacy_password_hash';
        }

        return null;
    }

    private function upgradeTokenHash(int $catalogId, string $token, string $matchMode): void
    {
        if ($catalogId < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE catalogs
                 SET access_token_hash=:hash,
                     last_private_access_error=NULL,
                     last_private_access_error_at=NULL,
                     last_token_regenerated_at=COALESCE(last_token_regenerated_at, NOW())
                 WHERE id=:id'
            )->execute(['hash' => hash('sha256', $this->normalizeToken($token)), 'id' => $catalogId]);
            Logger::write('info', 'Hash de token privado de catálogo normalizado.', [
                'catalog_id' => $catalogId,
                'mode' => $matchMode,
            ]);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo normalizar hash de token privado de catálogo.', [
                'catalog_id' => $catalogId,
                'mode' => $matchMode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordAccessError(int $catalogId, string $status): void
    {
        if ($catalogId < 1) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE catalogs
                 SET last_private_access_error=:status, last_private_access_error_at=NOW()
                 WHERE id=:id'
            )->execute(['status' => mb_substr($status, 0, 120), 'id' => $catalogId]);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo registrar rechazo de acceso a catálogo.', [
                'catalog_id' => $catalogId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasTokenSession(int $catalogId): bool
    {
        return (int) Session::get($this->tokenSessionKey($catalogId), 0) > time();
    }

    private function hasPasswordSession(int $catalogId): bool
    {
        return (int) Session::get($this->sessionKey($catalogId), 0) > time();
    }

    private function sessionKey(int $catalogId): string
    {
        return 'catalog_access_' . $catalogId;
    }

    private function tokenSessionKey(int $catalogId): string
    {
        return 'catalog_token_access_' . $catalogId;
    }
}
