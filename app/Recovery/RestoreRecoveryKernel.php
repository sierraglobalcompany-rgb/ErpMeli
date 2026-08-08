<?php

declare(strict_types=1);

namespace App\Recovery;

use App\Core\AppPaths;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\SameOriginGuard;
use App\Core\SecurityHeaders;
use App\Services\EmergencyControlService;
use App\Services\RestoreConfigSwitchService;
use App\Services\SafeErrorPresenter;
use Throwable;

final class RestoreRecoveryKernel
{
    private string $root;
    private string $base;
    private EmergencyControlService $control;

    public static function run(string $root): never
    {
        (new self($root))->dispatch();
    }

    private function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->autoload();
        if (!defined('ERP_RELEASE_ROOT')) {
            define('ERP_RELEASE_ROOT', $this->root);
        }
        Env::load(AppPaths::configFile());
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/recuperar.php'));
        $this->base = rtrim(dirname($script), '/.');
        $this->control = new EmergencyControlService($this->root);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name('erp_meli_recovery');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => ($this->base !== '' ? $this->base : '') . '/',
            'secure' => Env::bool('SESSION_SECURE', true), 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_start();
        $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
        SecurityHeaders::apply(true);
    }

    private function dispatch(): never
    {
        $error = '';
        $notice = '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            try {
                $this->validateCsrf();
                $this->validateOrigin();
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'login') {
                    $result = $this->control->authenticate(
                        (string) ($_POST['username'] ?? ''),
                        (string) ($_POST['password'] ?? ''),
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                    );
                    if (!$result['ok']) {
                        throw new \RuntimeException('Acceso de recuperación incorrecto o bloqueado.');
                    }
                    session_regenerate_id(true);
                    $_SESSION['restore_authenticated_until'] = time() + 600;
                    $notice = 'Identidad de emergencia confirmada.';
                } elseif ($action === 'rollback') {
                    $this->requireAuthenticated();
                    $result = $this->control->authenticate(
                        EmergencyControlService::USERNAME,
                        (string) ($_POST['password'] ?? ''),
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                    );
                    if (!$result['ok'] || trim((string) ($_POST['confirmation'] ?? '')) !== 'VOLVER A LA BASE ANTERIOR') {
                        throw new \RuntimeException('Confirme la contraseña y la frase exacta para volver.');
                    }
                    (new RestoreConfigSwitchService())->rollbackLatest();
                    $_SESSION = [];
                    session_destroy();
                    $this->render('Configuración anterior restaurada', 'El ERP volverá a usar la base anterior. Las dos paradas físicas permanecen activas.', '', true);
                } elseif ($action === 'logout') {
                    $_SESSION = [];
                    session_destroy();
                    header('Location: ' . $this->base . '/recuperar.php', true, 303);
                    exit;
                }
            } catch (Throwable $failure) {
                if ($failure instanceof HttpException) {
                    http_response_code(max(400, min(599, $failure->status)));
                }
                $error = SafeErrorPresenter::message($failure, 'No fue posible completar la recuperación.');
            }
        }
        if (!$this->authenticated()) {
            $this->renderLogin($error);
        }
        $this->render(
            'Recuperación independiente',
            'Use este acceso únicamente para volver a la configuración anterior si la base restaurada impide abrir el ERP.',
            $error !== '' ? $error : $notice,
            false
        );
    }

    private function renderLogin(string $error): never
    {
        $body = '<form method="post"><input type="hidden" name="_token" value="' . $this->e((string) $_SESSION['_csrf']) . '">'
            . '<input type="hidden" name="action" value="login"><label>Usuario de emergencia<input name="username" required></label>'
            . '<label>Contraseña<input type="password" name="password" required></label><button>Ingresar</button></form>';
        $this->page('Recuperación del ERP', 'No utiliza Dashboard, módulos ni Mercado Libre.', $error, $body);
    }

    private function render(string $title, string $message, string $notice, bool $completed): never
    {
        $body = $completed
            ? '<p><a href="' . $this->e($this->base . '/login.php') . '">Volver al inicio de sesión</a></p>'
            : '<form method="post"><input type="hidden" name="_token" value="' . $this->e((string) $_SESSION['_csrf']) . '">'
                . '<input type="hidden" name="action" value="rollback"><label>Contraseña de emergencia<input type="password" name="password" required></label>'
                . '<label>Escriba VOLVER A LA BASE ANTERIOR<input name="confirmation" required></label>'
                . '<button class="danger">Restaurar configuración anterior</button></form>';
        $this->page($title, $message, $notice, $body);
    }

    private function page(string $title, string $message, string $notice, string $body): never
    {
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($title) . '</title><style>body{margin:0;background:#f3f6fb;color:#10233f;font:16px system-ui}main{max-width:680px;margin:8vh auto;padding:32px;background:#fff;border:1px solid #dbe4ef;border-radius:16px}h1{margin-top:0}p{line-height:1.55}form{display:grid;gap:16px;margin-top:24px}label{display:grid;gap:6px;font-weight:700}input,button{min-height:44px;padding:0 12px;font:inherit;border:1px solid #c9d5e5;border-radius:9px}button{background:#1769e0;color:#fff;font-weight:800;cursor:pointer}.danger{background:#b42318}.notice{padding:12px;background:#fff5dc;border-radius:8px}</style></head><body><main>'
            . '<p><strong>RECUPERACIÓN LOCAL</strong></p><h1>' . $this->e($title) . '</h1><p>' . $this->e($message) . '</p>'
            . ($notice !== '' ? '<p class="notice">' . $this->e($notice) . '</p>' : '') . $body . '</main></body></html>';
        exit;
    }

    private function authenticated(): bool
    {
        return (int) ($_SESSION['restore_authenticated_until'] ?? 0) >= time();
    }

    private function requireAuthenticated(): void
    {
        if (!$this->authenticated()) {
            throw new \RuntimeException('La sesión de recuperación expiró.');
        }
    }

    private function validateCsrf(): void
    {
        $known = (string) ($_SESSION['_csrf'] ?? '');
        $sent = (string) ($_POST['_token'] ?? '');
        if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
            throw new \RuntimeException('La solicitud expiró.');
        }
    }

    private function validateOrigin(): void
    {
        SameOriginGuard::assertRequest(true);
    }

    private function autoload(): void
    {
        $vendor = $this->root . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require $vendor;
            return;
        }
        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $path = $this->root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
