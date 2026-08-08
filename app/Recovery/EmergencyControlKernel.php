<?php

declare(strict_types=1);

namespace App\Recovery;

use App\Core\Env;
use App\Core\AppPaths;
use App\Core\SameOriginGuard;
use App\Core\SecurityHeaders;
use App\Services\EmergencyControlService;
use Throwable;

final class EmergencyControlKernel
{
    private const SESSION_TTL = 600;

    private string $root;
    private string $base;
    private EmergencyControlService $control;

    public static function run(string $root): never
    {
        $kernel = new self($root);
        $kernel->dispatch();
    }

    private function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->autoload();
        if (is_file(AppPaths::configFile())) {
            Env::load(AppPaths::configFile());
        }
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/stop/'), PHP_URL_PATH) ?: '/stop/');
        $position = strpos($requestPath, '/stop');
        $this->base = $position === false ? '' : rtrim(substr($requestPath, 0, $position), '/');
        $this->control = new EmergencyControlService(AppPaths::installationRoot());
        $this->startSession();
        SecurityHeaders::apply(true);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        }
    }

    private function dispatch(): never
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/stop/'), PHP_URL_PATH) ?: '/stop/');
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($method === 'POST') {
            $this->validateOrigin();
            try {
                $this->validateCsrf((string) ($_POST['_token'] ?? ''));
            } catch (Throwable $error) {
                $_SESSION['emergency_error'] = $this->safeMessage($error);
                $this->redirect('/stop/');
            }
            if ($action === '') {
                $_SESSION['emergency_error'] = 'No se recibió la acción solicitada. Código: EMERGENCY_ACTION_MISSING.';
                $this->redirect('/stop/');
            }
            $knownActions = [
                'login', 'logout', 'stop_api', 'stop_automation', 'stop_all',
                'start_local_site', 'start_automation', 'clear_maintenance',
                'prepare_api', 'confirm_api', 'start_api_without_canary',
            ];
            if (!in_array($action, $knownActions, true)) {
                $_SESSION['emergency_error'] = 'La acción solicitada no existe. Código: EMERGENCY_ACTION_UNKNOWN.';
                $this->redirect('/stop/');
            }
            if (str_ends_with($path, '/login') || $action === 'login') {
                $this->login();
            }
            if (str_ends_with($path, '/logout') || $action === 'logout') {
                $this->logout();
            }
            $this->requireEmergencySession();
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
            if ($reason === '') {
                $reason = $this->defaultReason($action);
            }
            $actor = EmergencyControlService::USERNAME;
            try {
                match ($action) {
                    'stop_api' => $this->control->stopApi($actor, $reason),
                    'stop_automation' => $this->control->stopAutomation($actor, $reason),
                    'stop_all' => $this->control->stopAll($actor, $reason),
                    'start_local_site' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->startLocalSite($actor, $reason),
                        false
                    ),
                    'start_automation' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->startAutomation($actor, $reason),
                        false
                    ),
                    'clear_maintenance' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->clearLocalMaintenance($actor, $reason),
                        false
                    ),
                    'prepare_api' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->prepareApiStart($actor, $reason),
                        true
                    ),
                    'confirm_api' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->confirmApiStart($actor, $reason),
                        true
                    ),
                    'start_api_without_canary' => $this->reactivate(
                        static fn (EmergencyControlService $service) => $service->startApiWithoutCanary($actor, $reason),
                        true
                    ),
                    default => throw new \RuntimeException('La acción solicitada no existe. Código: EMERGENCY_ACTION_UNKNOWN.'),
                };
                $_SESSION['emergency_notice'] = 'Estado de seguridad actualizado.';
            } catch (Throwable $error) {
                $_SESSION['emergency_error'] = $this->safeMessage($error);
            }
            $this->redirect('/stop/');
        }

        if (!$this->control->credentialExists()) {
            $this->renderNotConfigured();
        }
        if (!$this->authenticated()) {
            $this->renderLogin();
        }
        $_SESSION['emergency_last_seen'] = time();
        $this->renderPanel();
    }

    private function login(): never
    {
        $result = $this->control->authenticate(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        if (!$result['ok']) {
            $_SESSION['emergency_error'] = $result['reason'] === 'locked'
                ? 'Acceso bloqueado temporalmente por intentos fallidos.'
                : 'Usuario o contraseña de emergencia incorrectos.';
            $this->redirect('/stop/');
        }
        session_regenerate_id(true);
        $_SESSION['emergency_authenticated'] = true;
        $_SESSION['emergency_authenticated_at'] = time();
        $_SESSION['emergency_last_seen'] = time();
        $this->redirect('/stop/');
    }

    private function logout(): never
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool) $params['secure'], true);
        }
        session_destroy();
        $this->redirect('/stop/');
    }

    /** @param callable(EmergencyControlService):void $callback */
    private function reactivate(callable $callback, bool $requireReadiness = false): void
    {
        if ($requireReadiness) {
            $readiness = $this->readiness();
            if (!$readiness['ready']) {
                throw new \RuntimeException((string) $readiness['message']);
            }
        }
        $callback($this->control);
    }

    private function defaultReason(string $action): string
    {
        return match ($action) {
            'stop_api' => 'Mercado Libre apagado desde freno de mano.',
            'stop_automation' => 'Automatización apagada desde freno de mano.',
            'stop_all' => 'Freno de mano completo activado desde panel de emergencia.',
            'start_local_site' => 'Sitio local activado desde freno de mano.',
            'start_automation' => 'Automatización activada desde freno de mano.',
            'clear_maintenance' => 'Modo lectura local retirado desde freno de mano.',
            'prepare_api' => 'Prueba canaria preparada desde freno de mano.',
            'confirm_api' => 'Mercado Libre activado después de canario exitoso.',
            'start_api_without_canary' => 'Mercado Libre activado sin canario; V3 respetará ritmo, presupuesto, Retry-After y circuit breaker.',
            default => 'Cambio realizado desde freno de mano.',
        };
    }

    /** @return array{ready:bool,message:string,db:string,pending:int|null} */
    private function readiness(): array
    {
        $diagnostic = $this->control->diagnoseApiReactivation();
        if (!empty($diagnostic['ready'])) {
            return ['ready' => true, 'message' => 'Instalación lista para una prueba canaria.', 'db' => 'ready', 'pending' => 0];
        }
        foreach ((array) ($diagnostic['checks'] ?? []) as $check) {
            if (($check['status'] ?? '') === 'FAIL') {
                return [
                    'ready' => false,
                    'message' => (string) ($check['detail'] ?? 'No se cumplieron las condiciones de seguridad.'),
                    'db' => 'unknown',
                    'pending' => null,
                ];
            }
        }
        foreach ((array) ($diagnostic['checks'] ?? []) as $check) {
            if (($check['status'] ?? '') === 'UNKNOWN') {
                $detail = rtrim((string) ($check['detail'] ?? 'No se pudo comprobar una autoridad local.'), ". \t\n\r\0\x0B");
                return [
                    'ready' => false,
                    'message' => $detail . '. Código: EMERGENCY_PRECONDITION_UNKNOWN.',
                    'db' => 'unknown',
                    'pending' => null,
                ];
            }
        }
        return ['ready' => false, 'message' => 'No se pudo certificar la reactivación local.', 'db' => 'unknown', 'pending' => null];
    }

    private function authenticated(): bool
    {
        $lastSeen = (int) ($_SESSION['emergency_last_seen'] ?? 0);
        return ($_SESSION['emergency_authenticated'] ?? false) === true
            && $lastSeen >= time() - self::SESSION_TTL;
    }

    private function requireEmergencySession(): void
    {
        if (!$this->authenticated()) {
            throw new \RuntimeException('La sesión de emergencia expiró.');
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name('erp_meli_emergency');
        session_set_cookie_params([
            'lifetime' => 0,
            // Debe cubrir /stop/, /stop.php y sus POST aun cuando .htaccess no
            // esté disponible. El nombre de cookie sigue siendo independiente.
            'path' => $this->base !== '' ? $this->base . '/' : '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (!is_string($_SESSION['emergency_csrf'] ?? null)) {
            $_SESSION['emergency_csrf'] = bin2hex(random_bytes(32));
        }
    }

    private function validateCsrf(string $token): void
    {
        $known = (string) ($_SESSION['emergency_csrf'] ?? '');
        if ($known === '' || $token === '' || !hash_equals($known, $token)) {
            throw new \RuntimeException('La sesión de emergencia no es válida. Código: EMERGENCY_CSRF_INVALID.');
        }
    }

    private function validateOrigin(): void
    {
        SameOriginGuard::assertRequest(true);
    }

    private function renderLogin(): never
    {
        $error = $this->pull('emergency_error');
        $this->document('Freno de mano', '
<main class="card narrow">
  <div class="emergency-topbar">
    <span class="eyebrow">CONTROL DE EMERGENCIA</span>
    <a class="nav-return" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
  </div>
  <h1>Freno de mano del ERP</h1>
  <p>Este acceso funciona sin cargar los módulos del ERP. Si solo vino a revisar, puede volver al sistema normal sin tocar ningún interruptor.</p>
  ' . ($error !== '' ? '<div class="notice error" role="alert">' . $this->escape($error) . '</div>' : '') . '
  <form method="post" action="' . $this->escape($this->endpoint()) . '">
    <input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '">
    <input type="hidden" name="action" value="login">
    <label for="emergency-user">Usuario</label>
    <input id="emergency-user" name="username" autocomplete="username" value="' . EmergencyControlService::USERNAME . '" required>
    <label for="emergency-password">Contraseña de emergencia</label>
    <input id="emergency-password" name="password" type="password" autocomplete="current-password" required autofocus>
    <div class="form-actions">
      <button class="primary" type="submit">Entrar al control seguro</button>
      <a class="button secondary" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
    </div>
  </form>
</main>');
    }

    private function renderNotConfigured(): never
    {
        $this->document('Configurar acceso de emergencia', '
<main class="card narrow">
  <div class="emergency-topbar">
    <span class="eyebrow">CONTROL DE EMERGENCIA</span>
    <a class="nav-return" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
  </div>
  <h1>Acceso todavía no configurado</h1>
  <p>Un administrador permanente debe crear la cuenta desde Configuración → Freno de mano.</p>
  <div class="form-actions">
    <a class="button primary" href="' . $this->escape($this->base . '/login.php') . '">Abrir inicio de sesión normal</a>
    <a class="button secondary" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
  </div>
</main>');
    }

    private function renderPanel(): never
    {
        // Reutilizar exactamente la autoridad que autenticó esta sesión.
        // Un kernel mínimo no debe volver a resolver la instalación mediante
        // servicios del runtime general o una release distinta.
        $status = $this->control->status();
        $notice = $this->pull('emergency_notice');
        $error = $this->pull('emergency_error');
        $apiStopped = $status['api'] === 'stopped';
        $automationStopped = $status['automation'] === 'stopped';
        $canary = $status['api'] === 'canary';
        $apiClass = $apiStopped ? 'off' : ($canary ? 'wait' : 'on');
        $automationClass = $automationStopped ? 'off' : 'on';
        $apiLabel = $apiStopped ? 'Bloqueada' : ($canary ? 'Prueba canaria' : 'Disponible');
        $automationLabel = $automationStopped ? 'Detenida' : 'Activa';
        $canaryResult = is_array($status['canary'] ?? null) ? (string) ($status['canary']['last_result'] ?? '') : '';
        $maintenance = is_array($status['maintenance'] ?? null) ? $status['maintenance'] : ['active' => false, 'count' => 0, 'markers' => []];
        $maintenanceActive = !empty($maintenance['active']);
        $maintenanceClass = $maintenanceActive ? 'wait' : 'on';
        $maintenanceLabel = $maintenanceActive ? 'Modo lectura activo' : 'Sin mantenimiento';
        $maintenanceDetail = $maintenanceActive
            ? 'Hay ' . (int) ($maintenance['count'] ?? 0) . ' marcador(es) locales de mantenimiento. Puede retirarlos si ya no hay una copia, saneamiento o restauración en curso.'
            : 'No hay snapshot, freeze ni coordinador local bloqueando el sitio.';

        if ($apiStopped) {
            $apiTitle = 'Bloqueada';
            $apiText = 'No se iniciarán consultas remotas. Puede preparar una prueba canaria o activar lecturas V3 directamente.';
            $apiAction = 'prepare_api';
            $apiButton = 'Preparar prueba canaria';
            $apiConfirm = '¿Seguro que desea preparar la prueba canaria de Mercado Libre? Solo permitirá una consulta de comprobación.';
        } elseif ($canary && $canaryResult === 'success') {
            $apiTitle = 'Listo para activar';
            $apiText = 'El canario ya fue exitoso. Puede activar Mercado Libre completo.';
            $apiAction = 'confirm_api';
            $apiButton = 'Activar Mercado Libre';
            $apiConfirm = '¿Seguro que desea activar Mercado Libre completo después del canario exitoso?';
        } elseif ($canary) {
            $apiTitle = 'Prueba canaria';
            $apiText = 'Mercado Libre está limitado a una consulta de prueba. Aún no hay aprobación completa.';
            $apiAction = 'stop_api';
            $apiButton = 'Detener prueba canaria';
            $apiConfirm = '¿Seguro que desea detener la prueba canaria y bloquear Mercado Libre?';
        } else {
            $apiTitle = 'Disponible';
            $apiText = 'Las consultas de lectura están habilitadas; las escrituras remotas siguen deshabilitadas por configuración.';
            $apiAction = 'stop_api';
            $apiButton = 'Apagar Mercado Libre';
            $apiConfirm = '¿Seguro que desea apagar Mercado Libre ahora?';
        }

        $apiEnableAction = in_array($apiAction, ['prepare_api', 'confirm_api'], true);
        $apiActionBlockedByAutomation = $apiEnableAction && !$automationStopped;
        if ($apiActionBlockedByAutomation) {
            $apiText .= ' Primero detenga la automatización; habilitar lecturas nunca la detendrá ni la iniciará automáticamente.';
        }

        $automationAction = $automationStopped ? 'start_automation' : 'stop_automation';
        $automationButton = $automationStopped ? 'Activar automatización' : 'Apagar automatización';
        $automationConfirm = $automationStopped
            ? '¿Seguro que desea activar la automatización? El cron podrá procesar trabajos permitidos por el ERP.'
            : '¿Seguro que desea apagar la automatización? Los trabajos quedarán guardados sin avanzar.';

        $apiPrimaryForm = $apiActionBlockedByAutomation
            ? '<button class="switch-button ' . $apiClass . '" type="button" disabled aria-disabled="true"><span>Detenga automatización primero</span><i></i></button>'
            : '<form method="post" class="safety-action-form" data-confirm="' . $this->escape($apiConfirm) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="' . $this->escape($apiAction) . '"><button class="switch-button ' . $apiClass . '" aria-pressed="' . ($apiClass === 'on' ? 'true' : 'false') . '" type="submit"><span>' . $this->escape($apiButton) . '</span><i></i></button></form>';

        $directApiConfirm = '¿Seguro que desea activar lecturas sin canario? La automatización permanecerá detenida y ML_WRITE_ENABLED seguirá deshabilitado.';
        $directApiActionHtml = ($automationStopped && ($apiStopped || $canary))
            ? '<form method="post" class="inline-safety-action" data-confirm="' . $this->escape($directApiConfirm) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="start_api_without_canary"><button class="secondary" type="submit">Activar lecturas sin canario</button><p>No activa Cron V3 ni escrituras remotas.</p></form>'
            : '';

        $maintenanceButton = $maintenanceActive ? 'Retirar modo lectura local' : 'Sitio libre para operar';
        $maintenanceConfirm = '¿Seguro que desea retirar el modo lectura local? Úselo solo si no hay copia, saneamiento o restauración en curso.';

        $this->document('Freno de mano', '
<main class="card">
  <div class="head"><div><span class="eyebrow">CONTROL DE EMERGENCIA</span><h1>Freno de mano</h1><p>Toque un interruptor, confirme y listo. Los cambios no consultan Mercado Libre.</p></div>
  <div class="head-actions">
    <a class="button secondary" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
    <form method="post" action="' . $this->escape($this->endpoint()) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="logout"><button class="ghost">Salir</button></form>
  </div></div>
  ' . ($notice !== '' ? '<div class="notice success" role="status">' . $this->escape($notice) . '</div>' : '') . '
  ' . ($error !== '' ? '<div class="notice error" role="alert">' . $this->escape($error) . '</div>' : '') . '
  <div class="switch-panel" aria-label="Controles principales">
    <div class="safety-row safety-row-composite"><div><small>MERCADO LIBRE</small><strong>' . $this->escape($apiTitle) . '</strong><p>' . $this->escape($apiText) . '</p>' . $directApiActionHtml . '</div><div class="safety-action-column">' . $apiPrimaryForm . '</div></div>
    <form method="post" class="safety-row" data-confirm="' . $this->escape($automationConfirm) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="' . $this->escape($automationAction) . '"><div><small>AUTOMATIZACIÓN</small><strong>' . $this->escape($automationLabel) . '</strong><p>Hostinger puede invocar PHP; este interruptor decide si el ERP procesa.</p></div><button class="switch-button ' . $automationClass . '" aria-pressed="' . (!$automationStopped ? 'true' : 'false') . '" type="submit"><span>' . $this->escape($automationButton) . '</span><i></i></button></form>
    ' . ($maintenanceActive
        ? '<form method="post" class="safety-row" data-confirm="' . $this->escape($maintenanceConfirm) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="clear_maintenance"><div><small>MODO LECTURA LOCAL</small><strong>' . $this->escape($maintenanceLabel) . '</strong><p>' . $this->escape($maintenanceDetail) . '</p></div><button class="switch-button ' . $maintenanceClass . '" aria-pressed="false" type="submit"><span>' . $this->escape($maintenanceButton) . '</span><i></i></button></form>'
        : '<div class="safety-row"><div><small>MODO LECTURA LOCAL</small><strong>El sitio está libre para operar</strong><p>' . $this->escape($maintenanceDetail) . '</p></div><span class="switch-button on inert" aria-disabled="true" role="status"><span>' . $this->escape($maintenanceButton) . '</span><i></i></span></div>') . '
  </div>
  <section class="quick-actions" aria-label="Acciones rápidas">
    <form method="post" data-confirm="¿Seguro que desea activar el freno de mano completo? Mercado Libre y automatización quedarán apagados."><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="stop_all"><button class="danger" type="submit">Activar freno de mano completo</button></form>
    <a class="button secondary" href="' . $this->escape($this->erpUrl()) . '">Volver al ERP</a>
    <form method="post" action="' . $this->escape($this->endpoint()) . '"><input type="hidden" name="_token" value="' . $this->escape($this->csrf()) . '"><input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Salir</button></form>
  </section>
  <details class="technical"><summary>Detalle técnico</summary>
    <dl>
      <div><dt>Mercado Libre</dt><dd>' . $this->escape((string) $status['api']) . ($canary ? ' · canario: ' . $this->escape($canaryResult !== '' ? $canaryResult : 'pendiente') : '') . '</dd></div>
      <div><dt>Automatización</dt><dd>' . $this->escape((string) $status['automation']) . '</dd></div>
      <div><dt>Modo lectura local</dt><dd>' . $this->escape($maintenanceActive ? 'activo' : 'inactivo') . '</dd></div>
      <div><dt>Escrituras remotas</dt><dd>' . $this->escape(Env::bool('ML_WRITE_ENABLED', false) ? 'habilitadas — requiere revisión' : 'deshabilitadas') . '</dd></div>
    </dl>
  </details>
  <p class="foot">Último cambio: ' . $this->escape((string) ($status['changed_at'] ?? 'sin registro')) . ' · Escrituras remotas: ' . $this->escape(Env::bool('ML_WRITE_ENABLED', false) ? 'habilitadas — requiere revisión' : 'deshabilitadas') . '</p>
</main>');
    }

    private function document(string $title, string $content): never
    {
        $scriptPath = $this->root . '/public/assets/emergency-control.js';
        $scriptHash = is_file($scriptPath) ? hash_file('sha256', $scriptPath) : false;
        $scriptVersion = is_string($scriptHash) && $scriptHash !== ''
            ? substr($scriptHash, 0, 16)
            : 'unavailable';
        $scriptUrl = ($this->base !== '' ? $this->base : '')
            . '/asset.php?path=emergency-control.js&amp;v=' . rawurlencode($scriptVersion);
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . $this->escape($title) . ' · ERP Meli</title><style>'
            . ':root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0b1f3a;background:#eef3f9}*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px;display:grid;place-items:center}.card{width:min(880px,100%);background:#fff;border:1px solid #d8e2ef;border-radius:18px;padding:30px;box-shadow:0 18px 60px rgba(10,33,63,.09)}.narrow{max-width:560px}.head,.emergency-topbar{display:flex;justify-content:space-between;gap:20px;align-items:start}.head-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.emergency-topbar{align-items:center;margin-bottom:12px}.nav-return{display:inline-flex;min-height:40px;align-items:center;border:1px solid #d7e1ee;border-radius:999px;padding:0 14px;color:#173f72;text-decoration:none;font-weight:800;background:#f8fbff}.nav-return:before{content:"←";margin-right:7px}.nav-return:hover,.nav-return:focus-visible,.secondary:hover,.ghost:hover{background:#eef5ff}h1{font-size:clamp(30px,5vw,44px);margin:5px 0}h2{margin-bottom:4px}h3{margin-bottom:2px}.eyebrow,small{font-size:12px;font-weight:800;letter-spacing:.08em;color:#5d6f89}p{color:#5b6d84;line-height:1.45}label{display:block;font-weight:750;margin-top:12px}input{width:100%;height:46px;margin-top:6px;border:1px solid #bac8d9;border-radius:9px;padding:0 12px;font:inherit}button,.button{display:inline-flex;min-height:44px;align-items:center;justify-content:center;border:0;border-radius:9px;padding:0 18px;font-weight:800;text-decoration:none;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.65}.form-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}.primary{background:#1769e0;color:#fff}.danger{background:#c7353f;color:#fff}.secondary,.ghost{background:#fff;color:#173f72;border:1px solid #bdcadb}.notice{padding:12px 14px;border-radius:10px;margin:14px 0}.notice.success{background:#eaf8f0;color:#17633d}.notice.error{background:#fff0f0;color:#8c2525}.switch-panel{border-top:1px solid #e3e9f1;margin-top:14px}.safety-row{width:100%;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 0;border:0;border-bottom:1px solid #e3e9f1;background:transparent;text-align:left}.safety-main-form{width:100%;display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0}.safety-action-column,.safety-action-form{display:flex;align-items:center;justify-content:flex-end;margin:0}.safety-row strong{display:block;font-size:22px;margin-top:3px}.safety-row p{margin:4px 0}.inline-safety-action{margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}.inline-safety-action p{flex-basis:100%;margin:0;color:#6a7a91;font-size:13px}.switch-button{position:relative;width:116px;height:52px;border-radius:999px;background:#d5dde8;padding:5px;display:block;flex:none;border:0;color:transparent;overflow:hidden}.switch-button span{position:absolute;width:1px;height:1px;clip:rect(0 0 0 0);overflow:hidden}.switch-button i{display:block;width:42px;height:42px;border-radius:50%;background:#fff;box-shadow:0 2px 8px #0002;transition:transform .18s ease}.switch-button.on{background:#1c9a61}.switch-button.on i{transform:translateX(64px)}.switch-button.off{background:#cf3f48}.switch-button.wait{background:#d89b19}.switch-button.wait i{transform:translateX(32px)}.switch-button.inert{cursor:default}.quick-actions{display:flex;gap:12px;flex-wrap:wrap;margin:24px 0}.quick-actions form{margin:0}.technical{border:1px solid #dbe4ef;border-radius:12px;padding:14px}summary{font-weight:850;cursor:pointer}dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0 0}dt{font-weight:800;color:#5d6f89;font-size:12px;text-transform:uppercase;letter-spacing:.06em}dd{margin:2px 0 0}.foot{font-size:13px;margin:20px 0 0}.head form{margin:0}.confirm-backdrop{position:fixed;inset:0;background:rgba(8,25,48,.44);display:none;align-items:center;justify-content:center;padding:18px;z-index:10}.confirm-backdrop.is-open{display:flex}.confirm-box{width:min(440px,100%);background:#fff;border-radius:16px;padding:22px;box-shadow:0 24px 80px rgba(0,0,0,.22)}.confirm-box h2{font-size:24px;margin:0 0 8px}.confirm-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}@media(max-width:640px){body{padding:10px}.card{padding:20px}.head{display:block}.head-actions{justify-content:flex-start;margin-top:12px}.emergency-topbar{align-items:flex-start;gap:10px}.safety-row,.safety-main-form{align-items:center;gap:12px}.inline-safety-action{align-items:stretch}.inline-safety-action button{width:100%}.switch-button{width:92px}.switch-button.on i{transform:translateX(40px)}.switch-button.wait i{transform:translateX(20px)}dl{grid-template-columns:1fr}.form-actions .button,.form-actions button,.quick-actions .button,.quick-actions button{width:100%}.confirm-actions{display:block}.confirm-actions button{width:100%;margin-top:8px}}@media(prefers-reduced-motion:reduce){.switch-button i{transition:none}}'
            . '</style></head><body>' . $content . '<div class="confirm-backdrop" id="confirm-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message" hidden><div class="confirm-box"><h2 id="confirm-title">Confirmar cambio</h2><p id="confirm-message">¿Seguro?</p><div class="confirm-actions"><button class="secondary" type="button" id="confirm-cancel">Cancelar</button><button class="primary" type="button" id="confirm-submit">Sí, cambiar estado</button></div></div></div><script src="' . $this->escape($scriptUrl) . '"></script></body></html>';
        exit;
    }

    private function erpUrl(): string
    {
        return ($this->base !== '' ? $this->base : '') . '/';
    }

    private function csrf(): string
    {
        return (string) ($_SESSION['emergency_csrf'] ?? '');
    }

    private function pull(string $key): string
    {
        $value = is_string($_SESSION[$key] ?? null) ? (string) $_SESSION[$key] : '';
        unset($_SESSION[$key]);
        return $value;
    }

    private function redirect(string $path): never
    {
        $location = in_array($path, ['/stop/', '/stop/login', '/stop/logout'], true)
            ? $this->endpoint()
            : $this->base . $path;
        header('Location: ' . $location, true, 303);
        exit;
    }

    private function endpoint(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/stop/'), PHP_URL_PATH) ?: '/stop/');
        return str_ends_with($path, '/stop.php') ? $this->base . '/stop.php' : $this->base . '/stop/';
    }

    private function autoload(): void
    {
        $vendor = $this->root . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
            return;
        }
        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $file = $this->root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function safeMessage(Throwable $error): string
    {
        $known = trim($error->getMessage());
        if (
            $error instanceof \RuntimeException
            && $known !== ''
            && preg_match('/^(La|El|No |Confirme|Explique|Complete|Active|Otra|Primero)/u', $known) === 1
            && preg_match('/SQLSTATE|PDO|mysqli|unknown column|stack trace|SELECT |UPDATE |INSERT |DELETE |\/home\/|\/var\/|[A-Z]:\\\\|\\\\app\\\\|https?:\/\/|token|secret/i', $known) !== 1
        ) {
            return mb_substr($known, 0, 500);
        }
        $reference = 'STOP-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        error_log('ERP_STOP_ACTION_FAILURE reference=' . $reference . ' class=' . $error::class);
        return 'No fue posible cambiar el estado. Código de diagnóstico: ' . $reference . '.';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
