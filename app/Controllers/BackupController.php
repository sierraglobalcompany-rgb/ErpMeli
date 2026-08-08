<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Core\SameOriginGuard;
use App\Services\BackupCenterService;
use App\Services\BackupKeyringService;
use App\Services\AdministrativeReauthenticationService;
use App\Services\RestoreConfigSwitchService;
use App\Services\RestoreMaintenanceRequestService;
use App\Services\RestoreService;
use RuntimeException;

final class BackupController
{
    public function index(): void
    {
        $this->requirePermanent();
        $overview = (new BackupCenterService())->overview(max(0, (int) ($_GET['backup'] ?? 0)));
        View::render('settings/backups', $overview);
    }

    public function status(): void
    {
        $this->requirePermanent();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(
            (new BackupCenterService())->statusOverview(max(0, (int) ($_GET['backup'] ?? 0))),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function create(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $returnTo = $this->safeMaintenanceReturn((string) ($_POST['return_to'] ?? ''));
        $contextId = 0;
        if ($returnTo !== '' && preg_match('/[?&]id=(\d+)/', $returnTo, $match) === 1) {
            $contextId = (int) $match[1];
        }
        $result = (new BackupCenterService())->enqueue(
            (int) Auth::id(),
            'manual',
            $contextId > 0 ? 'database_sanitation' : 'general',
            $contextId > 0 ? $contextId : null
        );
        Session::flash('success', 'La copia quedó preparada. Mantenga esta pestaña abierta para crearla por micro-lotes locales.');
        $query = '?backup=' . (int) $result['id'];
        if ($returnTo !== '') {
            $query .= '&return_to=' . rawurlencode($returnTo);
        }
        $this->redirect('/settings/backups' . $query);
    }

    public function interactiveStart(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $returnTo = $this->safeMaintenanceReturn((string) ($_POST['return_to'] ?? ''));
        $contextId = 0;
        if ($returnTo !== '' && preg_match('/[?&]id=(\d+)/', $returnTo, $match) === 1) {
            $contextId = (int) $match[1];
        }
        $result = (new BackupCenterService())->enqueue(
            (int) Auth::id(),
            'manual',
            $contextId > 0 ? 'database_sanitation' : 'general',
            $contextId > 0 ? $contextId : null
        );
        $payload = [
            'ok' => true,
            'backup_id' => (int) $result['id'],
            'message' => 'Copia preparada. Esta pestaña continuará con el primer lote.',
        ];
        $this->respondOrRedirect(
            $payload,
            '/settings/backups?backup=' . (int) $result['id']
                . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : '')
        );
    }

    public function interactiveStep(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        try {
            $result = (new BackupCenterService())->processInteractiveStep(
                (int) ($_POST['backup_id'] ?? 0),
                (int) Auth::id()
            );
            $this->json(['ok' => true] + $result);
        } catch (\Throwable $error) {
            $message = $this->safeErrorMessage($error);
            $this->json([
                'ok' => false,
                'code' => 'backup_interactive_step_failed',
                'message' => $message,
                'action' => 'Revise la copia indicada, recargue la página o use Cancelar/Limpiar si quedó una copia anterior bloqueando.',
                'backup_id' => max(0, (int) ($_POST['backup_id'] ?? 0)),
            ], 409);
        }
    }

    public function interactiveCancel(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $result = (new BackupCenterService())->delete(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id()
        );
        $this->json(['ok' => true] + $result);
    }

    public function verify(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        (new BackupCenterService())->queueVerification((int) ($_POST['backup_id'] ?? 0), (int) Auth::id());
        Session::flash('success', 'La comprobación quedó solicitada. Continúela desde esta pestaña sin consultar Mercado Libre.');
        $this->redirect('/settings/backups');
    }

    public function downloadGrant(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $grant = (new BackupCenterService())->issueDownloadGrant(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id()
        );
        $this->redirect('/settings/backups/download?token=' . rawurlencode($grant['token']));
    }

    public function download(): void
    {
        $this->requirePermanent();
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '' || strlen($token) > 120) {
            throw new \App\Core\HttpException(404, 'La descarga no está disponible.');
        }
        $archive = (new BackupCenterService())->consumeDownloadGrant($token, (int) Auth::id());
        $path = (string) $archive['_path'];
        $size = (int) filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
            $start = $match[1] === '' ? 0 : max(0, (int) $match[1]);
            $end = $match[2] === '' ? $end : min($end, (int) $match[2]);
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="erp-meli-' . rawurlencode((string) $archive['public_id']) . '.erpbackup"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('No fue posible abrir la copia cifrada.');
        }
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1048576, $remaining));
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }

    public function delete(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $result = (new BackupCenterService())->delete(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id()
        );
        Session::flash('success', (string) $result['message']);
        $this->redirect('/settings/backups');
    }

    public function cancel(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $result = (new BackupCenterService())->delete(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id()
        );
        Session::flash('success', (string) $result['message']);
        $this->redirect('/settings/backups');
    }

    public function recover(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $result = (new BackupCenterService())->recover(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id()
        );
        Session::flash('success', (string) $result['message']);
        $this->redirect('/settings/backups');
    }

    public function recoveryKey(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $packagePassword = (string) ($_POST['package_password'] ?? '');
        $confirmation = (string) ($_POST['package_password_confirmation'] ?? '');
        if ($packagePassword === '' || !hash_equals($packagePassword, $confirmation)) {
            throw new RuntimeException('Las contraseñas del paquete de recuperación no coinciden.');
        }
        $payload = (new BackupKeyringService())->recoveryPackage($packagePassword);
        (new BackupCenterService())->recordRecoveryKeyExport((int) Auth::id());
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="erp-meli-claves-' . gmdate('Ymd-His') . '.erpkeys"');
        header('Content-Length: ' . strlen($payload));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $payload;
        exit;
    }

    public function recoveryKeyImport(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $uploaded = $_FILES['recovery_package'] ?? null;
        if (
            !is_array($uploaded)
            || (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($uploaded['tmp_name'] ?? ''))
        ) {
            throw new RuntimeException('Seleccione un paquete de recuperación válido.');
        }
        $size = (int) ($uploaded['size'] ?? 0);
        if ($size < 80 || $size > 1048576) {
            throw new RuntimeException('El paquete de recuperación no tiene un tamaño válido.');
        }
        $payload = @file_get_contents((string) $uploaded['tmp_name']);
        if (!is_string($payload)) {
            throw new RuntimeException('No fue posible leer el paquete de recuperación.');
        }
        $count = (new BackupKeyringService())->importRecoveryPackage(
            $payload,
            (string) ($_POST['package_password'] ?? '')
        );
        (new BackupCenterService())->recordRecoveryKeyImport((int) Auth::id(), $count);
        Session::flash(
            'success',
            $count > 0
                ? "Se incorporaron {$count} claves de recuperación."
                : 'El paquete ya estaba incorporado; no se duplicaron claves.'
        );
        $this->redirect('/settings/backups');
    }

    public function restore(): void
    {
        $this->requirePermanent();
        $overview = (new BackupCenterService())->overview();
        $restores = (new RestoreService())->overview();
        View::render('settings/backup_restore', $overview + $restores);
    }

    public function restorePrepare(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['admin_password'] ?? ''));
        $result = (new RestoreService())->prepare(
            (int) ($_POST['backup_id'] ?? 0),
            (int) Auth::id(),
            (string) ($_POST['mode'] ?? ''),
            [
                'host' => (string) ($_POST['db_host'] ?? ''),
                'port' => (string) ($_POST['db_port'] ?? '3306'),
                'database' => (string) ($_POST['db_name'] ?? ''),
                'user' => (string) ($_POST['db_user'] ?? ''),
                'password' => (string) ($_POST['db_password'] ?? ''),
            ]
        );
        Session::flash('success', 'La base nueva está vacía y es compatible. La restauración quedó preparada para CLI.');
        $this->redirect('/settings/backups/restore?restore_id=' . (int) $result['id']);
    }

    public function restoreStart(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['admin_password'] ?? ''));
        $plan = (new RestoreService())->plan((int) ($_POST['restore_id'] ?? 0));
        if (!in_array((string) $plan['status'], ['queued', 'restoring'], true)) {
            throw new RuntimeException('La restauración no está disponible para continuar.');
        }
        (new RestoreMaintenanceRequestService())->publish((int) $plan['id'], (string) $plan['public_id']);
        Session::flash('success', 'La restauración continuará desde su último checkpoint.');
        $this->redirect('/settings/backups/restore?restore_id=' . (int) $plan['id']);
    }

    public function restoreSwitch(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        (new RestoreConfigSwitchService())->switch(
            (int) ($_POST['restore_id'] ?? 0),
            (int) Auth::id(),
            (string) ($_POST['reason'] ?? '')
        );
        Session::destroy();
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . '/login.php?restored=1', true, 303);
        exit;
    }

    public function restoreRollback(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        (new RestoreConfigSwitchService())->rollbackLatest();
        Session::destroy();
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . '/login.php?rollback=1', true, 303);
        exit;
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

    private function respondOrRedirect(array $payload, string $path): never
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            $this->json($payload);
            exit;
        }
        $this->redirect($path);
    }

    private function requirePermanent(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            throw new \App\Core\HttpException(403, 'Esta acción requiere un administrador permanente.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . $path, true, 303);
        exit;
    }

    private function json(array $payload, int $status = 200): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function safeMaintenanceReturn(string $path): string
    {
        $path = trim($path);
        return preg_match(
            '~^/settings/database-maintenance(?:\?id=\d+)?(?:\#[A-Za-z0-9_-]+)?$~',
            $path
        ) === 1 ? $path : '';
    }

    private function safeErrorMessage(\Throwable $error): string
    {
        $message = trim((string) $error->getMessage());
        if ($message === '') {
            return 'No fue posible avanzar este lote de copia.';
        }
        if (preg_match('/SQLSTATE|PDO|Stack trace|\\\\|\/home\/|C:\\\\|token|secret|password/i', $message) === 1) {
            return 'No fue posible avanzar este lote de copia. Revise el estado y reintente desde esta misma pantalla.';
        }
        return mb_substr($message, 0, 240);
    }
}
