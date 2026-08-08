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
use App\Services\ImportedMeliDataResetService;
use App\Services\AdministrativeReauthenticationService;
use RuntimeException;

final class ImportedMeliDataResetController
{
    public function index(): void
    {
        $this->requirePermanent();
        $requestId = max(0, (int) ($_GET['id'] ?? 0));
        View::render(
            'settings/imported_data_reset',
            (new ImportedMeliDataResetService())->overview(
                (int) Auth::id(),
                $requestId
            )
        );
    }

    public function status(): void
    {
        $this->requirePermanent();
        $requestId = max(0, (int) ($_GET['id'] ?? 0));
        $overview = (new ImportedMeliDataResetService())->overview(
            (int) Auth::id(),
            $requestId
        );
        $request = is_array($overview['request'] ?? null) ? $overview['request'] : null;
        $backup = is_array($overview['fresh_backup'] ?? null)
            ? $overview['fresh_backup']
            : null;
        $this->json([
            'request' => $request === null ? null : [
                'id' => (int) ($request['id'] ?? 0),
                'status' => (string) ($request['status'] ?? 'unknown'),
                'phase' => (string) ($request['phase'] ?? 'unknown'),
                'safe_message' => (string) ($request['safe_message'] ?? ''),
                'candidate_total' => (int) ($request['plan']['candidate_total'] ?? 0),
                'counters' => is_array($request['counters'] ?? null)
                    ? $request['counters']
                    : [],
                'created_at' => $request['created_at'] ?? null,
                'started_at' => $request['started_at'] ?? null,
                'completed_at' => $request['completed_at'] ?? null,
            ],
            'safety' => [
                'api' => (string) ($overview['safety']['api'] ?? 'unknown'),
                'automation' => (string) ($overview['safety']['automation'] ?? 'unknown'),
            ],
            'account_count' => count(is_array($overview['accounts'] ?? null)
                ? $overview['accounts']
                : []),
            'backup' => $backup === null ? null : [
                'id' => (int) ($backup['id'] ?? 0),
                'verified_at' => $backup['verified_at'] ?? null,
            ],
            'recent_steps' => is_array($overview['recent_steps'] ?? null)
                ? $overview['recent_steps']
                : [],
        ]);
    }

    public function analyze(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $request = (new ImportedMeliDataResetService())->analyze((int) Auth::id());
        Session::flash('success', 'Análisis listo. No se eliminó ningún dato.');
        $this->redirect('/settings/imported-data-reset?id=' . (int) $request['id'] . '#resultado');
    }

    public function authorize(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $backupId = max(0, (int) ($_POST['backup_id'] ?? 0));
        if ($backupId < 1) {
            throw new RuntimeException('Seleccione la copia verificada mostrada en el análisis.');
        }
        $request = (new ImportedMeliDataResetService())->authorize(
            max(0, (int) ($_POST['request_id'] ?? 0)),
            (int) Auth::id(),
            $backupId
        );
        Session::flash(
            'success',
            'Solicitud autorizada. Mercado Libre y la automatización quedaron detenidos.'
        );
        $this->redirect('/settings/imported-data-reset?id=' . (int) $request['id'] . '#progreso');
    }

    public function pause(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $request = (new ImportedMeliDataResetService())->pause(
            max(0, (int) ($_POST['request_id'] ?? 0)),
            (int) Auth::id()
        );
        Session::flash('success', 'Se solicitó una pausa segura después del lote actual.');
        $this->redirect('/settings/imported-data-reset?id=' . (int) $request['id'] . '#progreso');
    }

    public function resume(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $request = (new ImportedMeliDataResetService())->resume(
            max(0, (int) ($_POST['request_id'] ?? 0)),
            (int) Auth::id()
        );
        Session::flash('success', 'La operación continuará desde el último lote aprobado.');
        $this->redirect('/settings/imported-data-reset?id=' . (int) $request['id'] . '#progreso');
    }

    public function abandon(): void
    {
        $this->requirePermanent();
        $this->validateMutation();
        $this->verifyPassword((string) ($_POST['password'] ?? ''));
        $request = (new ImportedMeliDataResetService())->abandon(
            max(0, (int) ($_POST['request_id'] ?? 0)),
            (int) Auth::id()
        );
        Session::flash('success', 'La solicitud se cerró. Las paradas continúan activas.');
        $this->redirect('/settings/imported-data-reset?id=' . (int) $request['id'] . '#progreso');
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
            throw new HttpException(403, 'Esta acción requiere un administrador permanente.');
        }
    }

    /** @param array<string,mixed> $payload */
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

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Env::get('APP_URL', ''), '/') . $path, true, 303);
        exit;
    }
}
