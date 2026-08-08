<?php

declare(strict_types=1);

namespace App\Recovery;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\SameOriginGuard;
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Services\AdministrativeReauthenticationService;
use App\Services\DatabaseMaintenanceService;
use App\Services\SafeErrorPresenter;
use Throwable;

final class DatabaseMaintenanceRecoveryKernel
{
    private string $root;
    private string $base;

    public static function run(string $root): never
    {
        (new self($root))->dispatch();
    }

    private function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        if (!defined('ERP_RELEASE_ROOT')) {
            define('ERP_RELEASE_ROOT', $this->root);
        }
        require $this->root . '/bootstrap.php';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/mantenimiento.php'));
        $directory = str_replace('\\', '/', dirname($script));
        $this->base = rtrim($directory, '/.');
        SecurityHeaders::apply(true);
    }

    private function dispatch(): never
    {
        try {
            if (!Auth::check()) {
                header('Location: ' . $this->base . '/login.php', true, 303);
                exit;
            }
            Auth::requireRole('admin');
            if (Auth::isTemporary()) {
                throw new \RuntimeException('Se requiere un administrador permanente.');
            }
            $_SESSION['maintenance_direct_control'] ??= bin2hex(random_bytes(32));
            $service = new DatabaseMaintenanceService();
            $sessionId = max(0, (int) ($_REQUEST['id'] ?? 0));
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                Csrf::validate($_POST['_token'] ?? null);
                $this->validateOrigin();
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'analyze') {
                    $session = $service->analyze((int) Auth::id());
                    $this->redirect((int) $session['id'], 'Análisis terminado sin borrar.');
                }
                if ($action === 'protect_external' || $action === 'protect_waived') {
                    $this->verifyPassword((string) ($_POST['password'] ?? ''));
                    $mode = $action === 'protect_external' ? 'external_backup' : 'waived';
                    $session = $service->setProtection($sessionId, (int) Auth::id(), $mode);
                    $this->redirect((int) $session['id'], 'Protección registrada. Puede iniciar el lote canario.');
                }
                if ($action === 'start') {
                    $this->verifyPassword((string) ($_POST['password'] ?? ''));
                    $session = $service->start(
                        $sessionId,
                        (int) Auth::id(),
                        $this->currentStepIdempotencyKey(),
                        null
                    );
                    $this->redirect((int) $session['id'], 'Saneamiento autorizado. Mantenga esta pestaña abierta.');
                }
                if ($action === 'step') {
                    $service->runInteractiveStep(
                        $sessionId,
                        (int) Auth::id(),
                        $this->currentStepIdempotencyKey()
                    );
                    $this->redirect($sessionId, 'Micro-lote local registrado.');
                }
                if ($action === 'pause') {
                    $service->pause($sessionId, (int) Auth::id());
                    $this->redirect($sessionId, 'Sesión pausada.');
                }
                if ($action === 'finish') {
                    $service->finish($sessionId, (int) Auth::id());
                    $this->redirect($sessionId, 'Sesión finalizada.');
                }
                throw new \RuntimeException('La acción solicitada no está disponible.');
            }
            $data = $service->overview(
                $sessionId > 0 ? $sessionId : null,
                (int) Auth::id()
            );
            $this->render($data, trim((string) ($_GET['notice'] ?? '')));
        } catch (Throwable $error) {
            $reported = SafeErrorPresenter::report(
                $error,
                $this->safeMessage($error),
                ['surface' => 'database_maintenance_recovery']
            );
            $status = $error instanceof HttpException
                ? max(400, min(599, $error->status))
                : 503;
            $this->renderFailure((string) $reported['message'], $status);
        }
    }

    /** @param array<string,mixed> $data */
    private function render(array $data, string $notice): never
    {
        $analysis = is_array($data['analysis'] ?? null) ? $data['analysis'] : [];
        $session = is_array($data['session'] ?? null) ? $data['session'] : null;
        $csrf = $this->e(Csrf::token());
        $action = $this->e($this->base . '/mantenimiento.php');
        $normal = $this->e($this->base . '/settings/database-maintenance'
            . ($session ? '?id=' . (int) $session['id'] : ''));
        $id = (int) ($session['id'] ?? 0);
        $status = $this->e((string) ($session['status'] ?? 'Sin sesión'));
        $rawStatus = (string) ($session['status'] ?? '');
        $message = $this->e((string) ($session['safe_message'] ?? 'Primero analice sin borrar.'));
        $next = $this->e((string) ($session['next_action'] ?? 'Analizar la base.'));
        $protection = is_array($session['protection'] ?? null) ? $session['protection'] : [];
        $protectionReady = !empty($protection['ready']);
        $eligible = ($analysis['eligible_total'] ?? null) === null
            ? 'Por analizar'
            : number_format((int) $analysis['eligible_total'], 0, ',', '.');
        $payloads = ($analysis['payloads']['rows'] ?? null) === null
            ? 'Por analizar'
            : number_format((int) $analysis['payloads']['rows'], 0, ',', '.');
        $backup = !empty($analysis['verified_backup']['file_available'])
            ? 'Copia verificada disponible'
            : 'Falta copia verificada';
        $controls = '<form method="post" action="' . $action . '"><input type="hidden" name="_token" value="' . $csrf . '"><input type="hidden" name="action" value="analyze"><button>Analizar sin borrar</button></form>';
        if ($session !== null && $rawStatus === 'analyzed' && !$protectionReady) {
            $controls .= $this->passwordForm($action, $csrf, 'protect_external', $id, 'Usar respaldo externo y continuar');
            $controls .= $this->passwordForm($action, $csrf, 'protect_waived', $id, 'Continuar sin respaldo interno', true);
            $controls .= '<a class="button secondary" href="' . $this->e($this->base . '/settings/backups?return_to=' . rawurlencode('/settings/database-maintenance?id=' . $id . '#resultado')) . '">Crear copia en esta pestaña</a>';
        }
        if ($session !== null && (($rawStatus === 'analyzed' && $protectionReady) || $rawStatus === 'paused')) {
            $controls .= $this->passwordForm($action, $csrf, 'start', $id, $rawStatus === 'paused' ? 'Continuar desde el checkpoint' : 'Iniciar lote canario');
        }
        if ($session !== null && $rawStatus === 'running') {
            $controls .= $this->form($action, $csrf, 'step', $id, 'Procesar un micro-lote local');
            $controls .= $this->form($action, $csrf, 'pause', $id, 'Pausar');
            $controls .= $this->form($action, $csrf, 'finish', $id, 'Finalizar sesión');
        }
        $noticeHtml = $notice !== '' ? '<p class="notice">' . $this->e($notice) . '</p>' : '';
        echo $this->document('Recuperación local de la base', <<<HTML
<main>
  <span class="eyebrow">MANTENIMIENTO DIRECTO</span>
  <h1>Saneamiento de base de datos</h1>
  <p>Esta ruta no carga Dashboard, módulos, cuentas, notificaciones ni Salud API.</p>
  {$noticeHtml}
  <section class="facts">
    <div><span>Filas listas ahora</span><strong>{$eligible}</strong></div>
    <div><span>Payloads completos</span><strong>{$payloads}</strong></div>
    <div><span>Protección</span><strong>{$this->e($backup)}</strong></div>
    <div><span>Sesión</span><strong>{$status}</strong></div>
  </section>
  <section class="state"><h2>{$message}</h2><p>Siguiente: {$next}</p><p>Esta página puede analizar y ejecutar micro-lotes locales mientras permanezca abierta. No consulta Mercado Libre ni usa Cron.</p></section>
  <div class="actions">{$controls}<a class="button secondary" href="{$normal}">Abrir vista completa</a></div>
</main>
HTML);
        exit;
    }

    private function form(
        string $action,
        string $csrf,
        string $name,
        int $id,
        string $label,
        string $idempotencyKey = ''
    ): string
    {
        $idempotency = $idempotencyKey !== ''
            ? '<input type="hidden" name="idempotency_key" value="' . $this->e($idempotencyKey) . '">'
            : '';
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="' . $this->e($name) . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . $idempotency
            . '<button>' . $this->e($label) . '</button></form>';
    }

    private function passwordForm(
        string $action,
        string $csrf,
        string $name,
        int $id,
        string $label,
        bool $danger = false
    ): string {
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="' . $this->e($name) . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label class="sr-only">Contraseña administrativa<input type="password" name="password" required autocomplete="current-password" placeholder="Contraseña administrativa"></label>'
            . '<button' . ($danger ? ' class="danger"' : '') . '>' . $this->e($label) . '</button></form>';
    }

    private function currentStepIdempotencyKey(): string
    {
        $current = (string) ($_SESSION['maintenance_direct_step_key'] ?? '');
        if ($this->isValidStepIdempotencyKey($current)) {
            return $current;
        }
        $current = 'direct-' . bin2hex(random_bytes(16));
        $_SESSION['maintenance_direct_step_key'] = $current;
        return $current;
    }

    private function validateStepIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if (!$this->isValidStepIdempotencyKey($key)) {
            throw new \RuntimeException('El identificador del micro-paso no es válido.');
        }
        $current = $this->currentStepIdempotencyKey();
        $previous = (string) ($_SESSION['maintenance_direct_previous_step_key'] ?? '');
        $matchesCurrent = hash_equals($current, $key);
        $matchesPrevious = $this->isValidStepIdempotencyKey($previous)
            && hash_equals($previous, $key);
        if (!$matchesCurrent && !$matchesPrevious) {
            throw new \RuntimeException('Recargue el saneamiento antes de repetir este micro-paso.');
        }
        return $key;
    }

    private function rotateStepIdempotencyKey(string $acceptedKey): void
    {
        $current = (string) ($_SESSION['maintenance_direct_step_key'] ?? '');
        if (!$this->isValidStepIdempotencyKey($current) || !hash_equals($current, $acceptedKey)) {
            return;
        }
        $_SESSION['maintenance_direct_previous_step_key'] = $acceptedKey;
        $_SESSION['maintenance_direct_step_key'] = 'direct-' . bin2hex(random_bytes(16));
    }

    private function isValidStepIdempotencyKey(string $key): bool
    {
        return preg_match('/^direct-[A-Fa-f0-9]{32}$/', $key) === 1;
    }

    private function renderFailure(string $message, int $status = 503): never
    {
        http_response_code(max(400, min(599, $status)));
        $apiState = is_file($this->root . '/PAUSE_MELI_API')
            ? 'La salida hacia Mercado Libre está detenida por el marcador físico.'
            : 'No se pudo confirmar una parada de Mercado Libre. Revise el freno de mano.';
        echo $this->document('Mantenimiento local', '<main><span class="eyebrow">MANTENIMIENTO DIRECTO</span><h1>No se pudo abrir el saneamiento</h1><p class="notice">' . $this->e($message) . '</p><p>' . $this->e($apiState) . '</p><a class="button" href="' . $this->e($this->base . '/actualizar.php') . '">Abrir actualizador seguro</a></main>');
        exit;
    }

    private function redirect(int $id, string $notice): never
    {
        header(
            'Location: ' . $this->base . '/mantenimiento.php?id=' . $id
                . '&notice=' . rawurlencode($notice),
            true,
            303
        );
        exit;
    }

    private function validateOrigin(): void
    {
        SameOriginGuard::assertRequest(true);
    }

    private function verifyPassword(string $password): void
    {
        (new AdministrativeReauthenticationService())->requirePassword($password);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));
        return $port > 0 ? $scheme . '://' . $host . ':' . $port : '';
    }

    private function safeMessage(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if (
            $message === ''
            || preg_match(
                '/SQLSTATE|PDOException|unknown column|\/home\/|[A-Z]:\\\\|SELECT |UPDATE |INSERT /i',
                $message
            ) === 1
        ) {
            return 'La base no pudo completar la comprobación local.';
        }
        return mb_substr($message, 0, 350);
    }

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . $this->e($title) . ' · ERP Meli</title><style>'
            . ':root{font:16px system-ui;color:#102743;background:#f2f6fb}*{box-sizing:border-box}body{margin:0;padding:20px}main{max-width:820px;margin:6vh auto;background:#fff;border:1px solid #dbe4ef;border-radius:16px;padding:28px}h1{margin:.3rem 0;font-size:clamp(30px,5vw,42px)}p{color:#5c6d84;line-height:1.5}.eyebrow{font-size:12px;font-weight:850;letter-spacing:.08em}.facts{display:grid;grid-template-columns:1fr 1fr;margin:22px 0;border:1px solid #dfe7f0;border-radius:12px;overflow:hidden}.facts div{padding:14px;border-bottom:1px solid #e8edf3}.facts span,.facts strong{display:block}.facts span{font-size:12px;color:#687991}.facts strong{margin-top:5px}.state{padding:14px;background:#f3f7fc;border-radius:10px}.state h2{font-size:18px}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}.actions form{margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap}input{min-height:44px;border:1px solid #bdcce0;border-radius:9px;padding:0 12px}button,.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border:0;border-radius:9px;background:#1769e0;color:#fff;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.secondary{background:#fff;color:#1755a9;border:1px solid #bdcce0}.danger{background:#b42318}.notice{padding:12px 14px;background:#fff6df;color:#745100;border-radius:9px}@media(max-width:600px){.facts{grid-template-columns:1fr}.actions>*{width:100%}.actions button,.actions .button,.actions input{width:100%}}</style></head><body>'
            . $body . '</body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
