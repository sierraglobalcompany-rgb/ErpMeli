<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Logger;

final class Session
{
    /** @var array<string,string> */
    private static array $readOnlyFlashes = [];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('erp_meli_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Env::bool('SESSION_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed { return $_SESSION[$key] ?? $default; }
    public static function put(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public static function forget(string $key): void { unset($_SESSION[$key]); }
    public static function regenerate(): void { session_regenerate_id(true); }

    /**
     * Libera el lock de la sesión después de validar autenticación y permisos.
     *
     * Los endpoints GET pesados pueden seguir leyendo el snapshot de $_SESSION,
     * pero no deben intentar escribir flashes o preferencias después de llamarlo.
     */
    public static function closeReadOnly(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Consume los flashes y libera el lock de una sesión autenticada en GET.
     * El resto de $_SESSION continúa disponible como snapshot en memoria.
     */
    public static function releaseReadOnlySnapshot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_string($_SESSION['_csrf'] ?? null) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        $flashes = $_SESSION['_flash'] ?? [];
        if (is_array($flashes)) {
            foreach ($flashes as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    self::$readOnlyFlashes[$key] = $value;
                }
            }
        }
        unset($_SESSION['_flash']);
        session_write_close();
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            if ($key === 'error' && self::looksTechnical($value)) {
                $reference = 'ERR-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
                Logger::write('error', 'Se ocultó un mensaje técnico antes de mostrarlo al usuario.', [
                    'reference' => $reference,
                    'original_message' => Logger::redactString(mb_substr($value, 0, 1000)),
                ]);
                $value = 'No fue posible completar la operación. Código de diagnóstico: ' . $reference . '.';
            }
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        if (array_key_exists($key, self::$readOnlyFlashes)) {
            $current = self::$readOnlyFlashes[$key];
            unset(self::$readOnlyFlashes[$key]);
            return $current;
        }
        $current = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $current;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    private static function looksTechnical(string $message): bool
    {
        return preg_match(
            '/SQLSTATE|PDOException|mysqli|unknown column|invalid parameter|stack trace|uncaught|in [A-Z]:\\\\|\/home\/|\/var\/www\/|select\s+.+\s+from|insert\s+into|update\s+\w+\s+set/i',
            $message
        ) === 1;
    }
}
