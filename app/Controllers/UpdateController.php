<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\SameOriginGuard;
use App\Core\Session;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\DiagnosticService;
use App\Services\Logger;
use App\Services\MigrationDiagnosticService;
use App\Services\MigrationExecutionException;
use App\Services\MigrationTraceService;
use App\Services\SecureUpdateEngineService;
use App\Services\UpdateRemoteService;
use App\Services\UpdateService;
use App\Services\AdministrativeReauthenticationService;
use App\Core\Database;
use RuntimeException;
use Throwable;

final class UpdateController
{
    public function index(): void
    {
        $this->requireAdminPermanent();
        // La inspección de paquetes y backups puede tardar. Mantener abierta
        // la sesión aquí bloquearía todas las demás pestañas del administrador.
        Session::closeReadOnly();
        $diagnostic = (new DiagnosticService())->summary();
        $refreshRemote = ($_GET['refresh_remote'] ?? '') === '1';
        try {
            $engine = (new SecureUpdateEngineService())->dashboard($refreshRemote);
        } catch (Throwable $e) {
            $engine = [
                'engine_ready' => false,
                'error' => \App\Services\SafeErrorPresenter::message($e, 'No se pudo comprobar el motor de actualizaciones.'),
                'local_sources' => [],
                'remote_releases' => [],
                'runs' => [],
                'backups' => [],
            ];
        }
        $run = null;
        $runId = (int) ($_GET['run_id'] ?? 0);
        if ($runId > 0 && ($engine['engine_ready'] ?? false)) {
            $run = (new SecureUpdateEngineService())->run($runId);
        }
        View::render('settings/update', compact('diagnostic', 'engine', 'run'));
    }

    public function migrate(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        $this->reauthenticate();
        try {
            $migrationDiagnostic = (new MigrationDiagnosticService())->summary();
            $firstPending = (string) ($migrationDiagnostic['first_pending']['migration_key'] ?? '');
            $updateService = new UpdateService();
            $results = [];

            // La 059 crea el motor seguro. Se procesa primero de forma aislada y,
            // si finaliza correctamente, la misma acción continúa con el resto.
            if (str_starts_with($firstPending, '059_')) {
                $results = $updateService->runPendingMigrations(1);
                $bootstrapFailed = array_filter(
                    $results,
                    static fn(array $row): bool => ($row['status'] ?? '') === 'failed'
                );
                if ($bootstrapFailed === []) {
                    $results = array_merge($results, $updateService->runPendingMigrations());
                }
            } else {
                $results = $updateService->runPendingMigrations();
            }

            $applied = count(array_filter($results, static fn(array $row): bool => $row['status'] === 'applied'));
            $adopted = count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'adopted'));
            $remaining = (new MigrationDiagnosticService())->summary()['pending_count'] ?? 0;
            if ((int) $remaining === 0) {
                Session::flash('success', "Actualización completada. Migraciones aplicadas: {$applied}; adoptadas: {$adopted}.");
            } else {
                Session::flash(
                    'warning',
                    "La actualización avanzó de forma segura. Aplicadas: {$applied}; adoptadas: {$adopted}; pendientes: {$remaining}."
                );
            }
        } catch (MigrationExecutionException $e) {
            Logger::write('error', 'Migración detenida con diagnóstico.', [
                'diagnostic_id' => $e->diagnosticId(),
                'migration_key' => $e->migrationKey(),
                'stage' => $e->stage(),
                'safe_to_retry' => $e->safeToRetry(),
                'error' => MigrationTraceService::safeMessage($e),
            ]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        } catch (Throwable $e) {
            $safeMessage = MigrationTraceService::safeMessage($e) ?? 'Error no identificado.';
            Logger::write('error', 'No fue posible ejecutar migraciones.', ['error' => $safeMessage]);
            Session::flash('error', 'No fue posible ejecutar migraciones: ' . $safeMessage);
        }
        $this->redirect('/settings/update');
    }

    public function clearCache(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            Session::flash('success', (new UpdateService())->clearCache());
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible limpiar la caché.'));
        }
        $this->redirect('/settings/update');
    }

    public function uploadPackage(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            $this->reauthenticate();
            (new SecureUpdateEngineService())->upload($_FILES['package'] ?? []);
            Session::flash('success', 'Paquete recibido. Revíselo en Fuentes disponibles antes de iniciar.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible cargar el paquete.'));
        }
        $this->redirect('/settings/update');
    }

    public function createRun(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            $this->reauthenticate();
            $engine = new SecureUpdateEngineService();
            $sourceType = (string) ($_POST['source_type'] ?? 'local');
            $sourceKey = trim((string) ($_POST['source_key'] ?? ''));
            $remote = null;
            if ($sourceType === 'remote') {
                foreach ((new UpdateRemoteService())->releases(false) as $release) {
                    $candidateKey = hash('sha256', (string) ($release['package_url'] ?? ''));
                    if (hash_equals($candidateKey, $sourceKey)) {
                        $remote = $release;
                        break;
                    }
                }
                if ($remote === null) {
                    throw new RuntimeException('La release remota ya no está disponible.');
                }
            }
            $run = $engine->createRun(
                $sourceKey,
                (string) ($_POST['mode'] ?? 'atomic'),
                isset($_POST['backup_requested']),
                isset($_POST['backup_requested'])
                    ? null
                    : 'ACTUALIZAR SIN RESPALDO',
                Auth::id(),
                $remote
            );
            Session::flash('success', 'Actualización preparada. Revise el diagnóstico y avance por etapas.');
            $this->redirect('/settings/update?run_id=' . (int) $run['id']);
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible preparar la actualización.'));
            $this->redirect('/settings/update');
        }
    }

    public function processRun(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        $runId = (int) ($_POST['run_id'] ?? 0);
        try {
            $this->requireRecentReauthentication();
            $run = (new SecureUpdateEngineService())->process($runId);
            Session::flash('success', 'Etapa completada: ' . (string) ($run['current_step'] ?? $run['state']) . '.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'La actualización se detuvo de forma segura.'));
        }
        $this->redirect('/settings/update?run_id=' . $runId);
    }

    public function pauseRun(): void
    {
        $this->runMutation('pause');
    }

    public function resumeRun(): void
    {
        $this->runMutation('resume');
    }

    public function rollbackRun(): void
    {
        $this->runMutation('rollback');
    }

    public function status(): void
    {
        $this->requireAdminPermanent();
        Session::closeReadOnly();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        $runId = (int) ($_GET['run_id'] ?? 0);
        try {
            $run = (new SecureUpdateEngineService())->run($runId);
            if ($run === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Ejecución no encontrada.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode(['ok' => true, 'run' => $run], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'No fue posible leer el estado.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function saveEngineSettings(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            $this->reauthenticate();
            $url = rtrim(trim((string) ($_POST['remote_url'] ?? '')), '/');
            if ($url !== '' && (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) === null)) {
                throw new RuntimeException('El servidor remoto debe usar una URL HTTPS válida.');
            }
            $channel = (string) ($_POST['channel'] ?? 'stable');
            if (!in_array($channel, ['stable', 'beta', 'internal'], true)) {
                throw new RuntimeException('El canal seleccionado no es válido.');
            }
            $settings = new AppSettingsService();
            $settings->set('update.remote_url', $url, 'update');
            $settings->set('update.channel', $channel, 'update');
            $settings->set('update.telemetry_enabled', isset($_POST['telemetry_enabled']) ? '1' : '0', 'update');
            $settings->set('update.backup_default', isset($_POST['backup_default']) ? '1' : '0', 'update');
            Session::flash('success', 'Configuración del motor de actualizaciones guardada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible guardar la configuración.'));
        }
        $this->redirect('/settings/update');
    }

    public function addTrustedKey(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            $this->reauthenticate();
            $keyId = strtolower(trim((string) ($_POST['key_id'] ?? '')));
            $publicKey = trim((string) ($_POST['public_key'] ?? ''));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $keyId) !== 1 || !str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
                throw new RuntimeException('El identificador o la clave pública PEM no son válidos.');
            }
            if (openssl_pkey_get_public($publicKey) === false) {
                throw new RuntimeException('OpenSSL no pudo leer la clave pública.');
            }
            $stmt = Database::connectionFresh()->prepare(
                "INSERT INTO system_update_trusted_keys (key_id,public_key_pem,channels_json,status,created_by)
                 VALUES (:key_id,:public_key,:channels,'active',:user)
                 ON DUPLICATE KEY UPDATE public_key_pem=VALUES(public_key_pem),channels_json=VALUES(channels_json),
                   status='active',revoked_at=NULL"
            );
            $stmt->execute([
                'key_id' => $keyId,
                'public_key' => $publicKey,
                'channels' => json_encode(['stable', 'beta', 'internal']),
                'user' => Auth::id(),
            ]);
            Session::flash('success', 'Clave pública confiable registrada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible registrar la clave.'));
        }
        $this->redirect('/settings/update');
    }

    public function revokeTrustedKey(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        try {
            $this->reauthenticate();
            $keyId = strtolower(trim((string) ($_POST['key_id'] ?? '')));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $keyId) !== 1) {
                throw new RuntimeException('El identificador de clave no es válido.');
            }
            $stmt = Database::connectionFresh()->prepare(
                "UPDATE system_update_trusted_keys
                 SET status='revoked',revoked_at=UTC_TIMESTAMP()
                 WHERE key_id=:key_id AND status<>'revoked'"
            );
            $stmt->execute(['key_id' => $keyId]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('La clave no existe o ya estaba revocada.');
            }
            Session::flash('success', 'Clave de actualizaciones revocada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible revocar la clave.'));
        }
        $this->redirect('/settings/update');
    }

    public function generateKey(): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        $this->reauthenticate();
        throw new \App\Core\HttpException(
            410,
            'La llave heredada de rescate fue retirada. Use el actualizador autenticado.'
        );
    }

    public function protectedRun(): void
    {
        throw new \App\Core\HttpException(
            410,
            'La actualización por enlace fue retirada. Ingrese como administrador y abra el actualizador seguro.'
        );
    }

    private function requireAdminPermanent(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            http_response_code(403);
            exit('Los accesos temporales no pueden usar el actualizador.');
        }
    }

    private function runMutation(string $action): void
    {
        $this->requireAdminPermanent();
        $this->validateMutation();
        $runId = (int) ($_POST['run_id'] ?? 0);
        try {
            if ($action === 'rollback') {
                $this->reauthenticate();
            } else {
                $this->requireRecentReauthentication();
            }
            $engine = new SecureUpdateEngineService();
            if ($action === 'pause') {
                $engine->pause($runId);
                Session::flash('success', 'Actualización pausada.');
            } elseif ($action === 'resume') {
                $engine->resume($runId);
                Session::flash('success', 'Actualización reanudada.');
            } elseif ($action === 'rollback') {
                $engine->rollback($runId);
                Session::flash('success', 'Rollback completado.');
            }
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible ejecutar la acción.'));
        }
        $this->redirect('/settings/update?run_id=' . $runId);
    }

    private function reauthenticate(): void
    {
        (new AdministrativeReauthenticationService())->requirePassword(
            (string) ($_POST['admin_password'] ?? '')
        );
    }

    private function requireRecentReauthentication(): void
    {
        (new AdministrativeReauthenticationService())->requireRecent();
    }

    private function validateMutation(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        SameOriginGuard::assertRequest(true);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
