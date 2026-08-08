<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\SameOriginGuard;
use App\Core\Session;
use App\Core\View;
use App\Services\AdministrativeReauthenticationService;
use App\Services\DatabaseMaintenanceService;

final class DatabaseMaintenanceController
{
    public function index(): void
    {
        $this->requirePermanent();
        $sessionId = max(0, (int) ($_GET['id'] ?? 0));
        $data = (new DatabaseMaintenanceService())->overview(
            $sessionId > 0 ? $sessionId : null,
            (int) Auth::id()
        );
        $data['tabTokenSeed'] = bin2hex(random_bytes(24));
        View::render('settings/database_maintenance', $data);
    }

    public function protectInfo(): void
    {
        $this->requirePermanent();
        $sessionId = max(0, (int) ($_GET['id'] ?? $_GET['session_id'] ?? 0));
        Session::flash(
            'info',
            'Elija la protección desde esta pantalla. Puede usar una copia ERP, un respaldo externo o continuar sin respaldo interno.'
        );
        $this->redirect('/settings/database-maintenance'
            . ($sessionId > 0 ? '?id=' . $sessionId : '')
            . '#resultado');
    }

    public function status(): void
    {
        $this->requirePermanent();
        $status = (new DatabaseMaintenanceService())->status(
            max(0, (int) ($_GET['id'] ?? 0)),
            (int) Auth::id()
        );
        $session = is_array($status['session'] ?? null) ? $status['session'] : [];
        $this->json([
            'ok' => true,
            'server_time' => (string) ($status['server_time'] ?? gmdate(DATE_ATOM)),
            'session' => [
                'id' => (int) ($session['id'] ?? 0),
                'status' => (string) ($session['status'] ?? 'unknown'),
                'phase' => (string) ($session['phase'] ?? 'unknown'),
                'dataset_key' => $session['dataset_key'] ?? null,
                'safe_message' => (string) ($session['safe_message'] ?? ''),
                'next_action' => (string) ($session['next_action'] ?? ''),
                'progress_percent' => (float) ($session['progress_percent'] ?? 0),
                'counters' => is_array($session['counters'] ?? null)
                    ? $session['counters']
                    : [],
                'last_step_at' => $session['last_step_at'] ?? null,
                'started_at' => $session['started_at'] ?? null,
                'completed_at' => $session['completed_at'] ?? null,
                'protection' => is_array($session['protection'] ?? null)
                    ? $session['protection']
                    : [],
            ],
            'recent_steps' => array_map(
                static fn (array $step): array => [
                    'safe_message' => (string) ($step['safe_message'] ?? ''),
                    'duration_ms' => (int) ($step['duration_ms'] ?? 0),
                    'started_at' => $step['started_at'] ?? null,
                    'completed_at' => $step['completed_at'] ?? null,
                ],
                is_array($status['recent_steps'] ?? null) ? $status['recent_steps'] : []
            ),
            'physical_recovery' => is_array($status['physical_recovery'] ?? null)
                ? [
                    'table' => (string) ($status['physical_recovery']['table'] ?? ''),
                    'status' => (string) ($status['physical_recovery']['status'] ?? ''),
                    'safe_message' => (string) (
                        $status['physical_recovery']['safe_message'] ?? ''
                    ),
                    'requested_at' => $status['physical_recovery']['requested_at'] ?? null,
                    'started_at' => $status['physical_recovery']['started_at'] ?? null,
                    'completed_at' => $status['physical_recovery']['completed_at'] ?? null,
                ]
                : null,
        ]);
    }

    public function analyze(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $session = (new DatabaseMaintenanceService())->analyze((int) Auth::id());
        Session::flash('success', 'Análisis terminado. No se eliminó ninguna fila.');
        $this->redirect('/settings/database-maintenance?id=' . (int) $session['id'] . '#resultado');
    }

    public function start(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $backupId = max(0, (int) ($_POST['backup_id'] ?? 0));
        $session = (new DatabaseMaintenanceService())->start(
            max(0, (int) ($_POST['session_id'] ?? 0)),
            (int) Auth::id(),
            (string) ($_POST['control_token'] ?? ''),
            $backupId > 0 ? $backupId : null
        );
        $this->respondOrRedirect(
            ['ok' => true, 'session' => $session],
            '/settings/database-maintenance?id=' . (int) $session['id'] . '#progreso'
        );
    }

    public function protect(): void
    {
        $this->requirePermanent();
        $sessionId = max(0, (int) ($_POST['session_id'] ?? 0));
        try {
            $this->validateMutation();
            $this->verifyPassword((string) ($_POST['password'] ?? ''));
            $session = (new DatabaseMaintenanceService())->setProtection(
                $sessionId,
                (int) Auth::id(),
                (string) ($_POST['protection_mode'] ?? ''),
                isset($_POST['backup_id']) ? max(0, (int) $_POST['backup_id']) : null
            );
            Session::flash('success', 'La protección quedó registrada para esta sesión.');
            $this->respondOrRedirect(
                ['ok' => true, 'session' => $session],
                '/settings/database-maintenance?id=' . (int) $session['id'] . '#resultado'
            );
        } catch (\Throwable $error) {
            $message = $this->safeErrorMessage($error);
            if (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
                $this->json([
                    'ok' => false,
                    'code' => 'database_maintenance_protection_failed',
                    'message' => $message,
                    'session_id' => $sessionId,
                    'action' => 'Revise la copia bloqueante, use respaldo externo o continúe sin respaldo interno desde esta misma pantalla.',
                ]);
                return;
            }
            Session::flash('error', $message);
            $this->redirect('/settings/database-maintenance'
                . ($sessionId > 0 ? '?id=' . $sessionId : '')
                . '#resultado');
        }
    }

    public function step(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $sessionId = max(0, (int) ($_POST['session_id'] ?? 0));
        $service = new DatabaseMaintenanceService();
        $result = $service->runInteractiveStep(
            $sessionId,
            (int) Auth::id(),
            (string) ($_POST['control_token'] ?? '')
        );
        $this->json([
            'ok' => true,
            'result' => $result,
            'status' => $service->status($sessionId, (int) Auth::id()),
        ]);
    }

    public function pause(): void
    {
        $this->changeState('pause');
    }

    public function finish(): void
    {
        $this->changeState('finish');
    }

    public function rebuild(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $sessionId = max(0, (int) ($_POST['session_id'] ?? 0));
        $session = (new DatabaseMaintenanceService())->requestPhysicalRecovery(
            $sessionId,
            (int) Auth::id(),
            trim((string) ($_POST['table'] ?? '')),
            true
        );
        Session::flash(
            'success',
            'La recuperación física quedó preparada para una ejecución controlada de una sola tabla.'
        );
        $this->respondOrRedirect(
            ['ok' => true, 'session' => $session],
            '/settings/database-maintenance?id=' . $sessionId . '#espacio-fisico'
        );
    }

    private function changeState(string $action): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $service = new DatabaseMaintenanceService();
        $sessionId = max(0, (int) ($_POST['session_id'] ?? 0));
        $session = $action === 'pause'
            ? $service->pause($sessionId, (int) Auth::id())
            : $service->finish($sessionId, (int) Auth::id());
        Session::flash(
            'success',
            $action === 'pause'
                ? 'La pausa fue solicitada. Se aplicará después del lote actual.'
                : 'La finalización fue solicitada. Primero se verificará la integridad.'
        );
        $this->respondOrRedirect(
            ['ok' => true, 'session' => $session],
            '/settings/database-maintenance?id=' . $sessionId . '#progreso'
        );
    }

    private function respondOrRedirect(array $payload, string $path): never
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            $this->json($payload);
            exit;
        }
        $this->redirect($path);
    }

    private function verifyPassword(string $password): void
    {
        (new AdministrativeReauthenticationService())->requirePassword($password);
    }

    private function validateMutation(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        SameOriginGuard::assertRequest(true);
    }

    private function requirePermanent(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            throw new \App\Core\HttpException(
                403,
                'Esta acción requiere un administrador permanente.'
            );
        }
    }

    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function safeErrorMessage(\Throwable $error): string
    {
        $message = trim((string) $error->getMessage());
        if ($message === '') {
            return 'No fue posible registrar la protección de esta sesión.';
        }
        if (preg_match('/SQLSTATE|PDO|Stack trace|\\\\|\/home\/|C:\\\\|token|secret|password/i', $message) === 1) {
            return 'No fue posible registrar la protección. Revise la copia bloqueante o reintente desde esta pantalla.';
        }
        return mb_substr($message, 0, 260);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . $path, true, 303);
        exit;
    }
}
