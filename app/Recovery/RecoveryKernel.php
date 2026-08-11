<?php

declare(strict_types=1);

namespace App\Recovery;

use App\Core\AppPaths;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\SameOriginGuard;
use App\Core\SecurityHeaders;
use App\Services\AppVersionService;
use App\Services\BackupCenterService;
use App\Services\CacheInvalidationService;
use App\Services\DirectUpdateMetadataPromotionService;
use App\Services\DirectUpdateTransitionPolicy;
use App\Services\InstalledVersionMarkerService;
use App\Services\Migrator;
use App\Services\MigrationExecutionException;
use App\Services\MigrationTraceService;
use App\Services\ReleaseIntegrityService;
use App\Services\SystemSafetyStatusService;
use App\Services\AuthenticationAttemptService;
use App\Services\CronV3SetupAssistantService;
use PDO;
use Throwable;

final class RecoveryKernel
{
    private const REAUTH_TTL_SECONDS = 900;

    private string $root;
    private string $base;
    private ?PDO $pdo = null;
    private ?string $databaseMessage = null;

    public static function run(string $root, string $screen): never
    {
        $kernel = new self($root);
        $kernel->dispatch($screen);
    }

    private function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->autoload();
        if (!defined('ERP_RELEASE_ROOT')) {
            define('ERP_RELEASE_ROOT', $this->root);
        }
        Env::load(AppPaths::configFile());
        $timezone = (string) Env::get('APP_TIMEZONE', 'America/Bogota');
        date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'America/Bogota');
        Database::useProfile('diagnostic');
        Session::start();
        $configuredBase = rtrim((string) Env::get('APP_URL', ''), '/');
        $fallbackBase = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        $this->base = $configuredBase !== '' ? $configuredBase : rtrim($fallbackBase === '/' ? '' : $fallbackBase, '/');
        SecurityHeaders::apply(true);
    }

    private function dispatch(string $screen): never
    {
        if ($screen === 'login') {
            $this->login();
        }
        if ($screen !== 'update') {
            $this->redirect('/login.php');
        }
        $this->update();
    }

    private function login(): never
    {
        $reauthIntent = Session::get('_recovery_login_intent');
        if (
            is_array($reauthIntent)
            && (int) ($reauthIntent['issued_at'] ?? 0) < time() - self::REAUTH_TTL_SECONDS
        ) {
            Session::forget('_recovery_login_intent');
            $reauthIntent = null;
        }
        if (Auth::check() && !is_array($reauthIntent)) {
            $this->redirect($this->afterLoginPath());
        }
        $error = '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            try {
                $this->validateOrigin();
                Csrf::validate($_POST['_token'] ?? null);
                Database::useProfile('web');
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $this->connect('web');
                $attempts = new AuthenticationAttemptService();
                if ($attempts->isLimited($email, $ip)) {
                    $error = 'Demasiados intentos. Espere 15 minutos antes de volver a intentar.';
                } elseif (!Auth::attempt($email, (string) ($_POST['password'] ?? ''))) {
                    $attempts->record($email, $ip, false);
                    $error = 'Correo o contraseña incorrectos.';
                } else {
                    $attempts->record($email, $ip, true);
                    if (is_array($reauthIntent)) {
                        if (Auth::role() !== 'admin' || Auth::isTemporary()) {
                            Session::forget('_recovery_login_intent');
                            Session::forget('user');
                            Session::regenerate();
                            $error = 'La actualización requiere un administrador permanente.';
                        } else {
                            Session::put('_recovery_authorized_at', time());
                            Session::put('_recovery_authorized_user', (int) Auth::id());
                            Session::forget('_recovery_login_intent');
                            Session::put(
                                '_recovery_notice',
                                'Identidad confirmada. Elija si usará una copia externa ya descargada '
                                . 'o si desea solicitar un respaldo seguro local.'
                            );
                            Session::forget('_recovery_error');
                            $this->redirect('/actualizar.php?result=reauthenticated');
                        }
                    } else {
                        $this->redirect($this->afterLoginPath());
                    }
                }
            } catch (HttpException $denied) {
                http_response_code(max(400, min(599, $denied->status)));
                $error = 'No se pudo comprobar el origen de la solicitud.';
            } catch (Throwable) {
                $error = 'No fue posible comprobar el acceso. La base de datos puede estar ocupada. Intente de nuevo en unos segundos.';
            }
        }
        $this->renderLogin($error);
    }

    private function update(): never
    {
        if (!Auth::check()) {
            $this->redirect('/login.php?return=actualizar.php');
        }
        if (Auth::role() !== 'admin' || Auth::isTemporary()) {
            http_response_code(403);
            $this->renderMessage(
                'Actualización pendiente',
                'Un administrador permanente debe completar esta actualización.',
                'Volver al ERP',
                '/index.php'
            );
        }

        $notice = '';
        $error = '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $action = (string) ($_POST['action'] ?? 'migrate');
            $identityConfirmed = false;
            try {
                $this->validateOrigin();
                Csrf::validate($_POST['_token'] ?? null);
                if ($action === 'restart_login') {
                    Session::put('_recovery_login_intent', [
                        'purpose' => 'update',
                        'backup_choice' => 'external',
                        'email' => strtolower(trim((string) (Auth::user()['email'] ?? ''))),
                        'issued_at' => time(),
                    ]);
                    Session::forget('_recovery_authorized_at');
                    Session::forget('_recovery_authorized_user');
                    Session::forget('user');
                    Session::regenerate();
                    $this->redirect('/login.php?reauth=update');
                } elseif ($action === 'authorize') {
                    $this->connect('diagnostic');
                    $this->authorizeMigration((string) ($_POST['password'] ?? ''));
                    $identityConfirmed = true;
                    $backupChoice = (string) ($_POST['backup_choice'] ?? 'external');
                    if (in_array($backupChoice, ['external', 'skip'], true)) {
                        Session::put('_recovery_backup_decision', 'skipped');
                        Session::forget('_recovery_backup_summary');
                        $notice = $backupChoice === 'external'
                            ? 'Identidad confirmada. Continuará usando la copia externa confirmada por el administrador.'
                            : 'Identidad confirmada. Continuará sin respaldo interno por confirmación explícita del administrador.';
                    } else {
                        $backup = $this->prepareSecureBackup();
                        $notice = (string) $backup['message'];
                    }
                } elseif ($action === 'check_backup') {
                    $this->connect('diagnostic');
                    $backup = $this->inspectSecureBackup();
                    $notice = (string) $backup['message'];
                } elseif ($action === 'recover_backup') {
                    $this->connect('diagnostic');
                    $this->requireRecoveryIdentity();
                    $backupId = (int) Session::get('_recovery_backup_id', 0);
                    if ($backupId < 1) {
                        throw new \RuntimeException('recovery_backup_missing');
                    }
                    $result = (new BackupCenterService())->recover($backupId, (int) Auth::id());
                    Session::put('_recovery_backup_decision', 'pending');
                    $notice = (string) $result['message'];
                } elseif ($action === 'cancel_backup' || $action === 'cleanup_backup') {
                    $this->connect('diagnostic');
                    $this->requireRecoveryIdentity();
                    $backupId = (int) Session::get('_recovery_backup_id', 0);
                    if ($backupId < 1) {
                        throw new \RuntimeException('recovery_backup_missing');
                    }
                    $result = (new BackupCenterService())->delete($backupId, (int) Auth::id());
                    Session::forget('_recovery_backup_id');
                    Session::forget('_recovery_backup_decision');
                    Session::forget('_recovery_backup_summary');
                    $notice = (string) $result['message']
                        . ' Puede preparar un respaldo nuevo cuando la limpieza quede liberada.';
                } else {
                    $this->connect('diagnostic');
                    $backupDecision = (string) Session::get('_recovery_backup_decision', '');
                    if ($backupDecision === 'skipped') {
                        $backup = [
                            'status' => 'skipped',
                            'id' => 0,
                            'raw_status' => '',
                            'message' => 'El administrador confirmó una copia externa.',
                        ];
                    } else {
                        $backup = $this->inspectSecureBackup();
                        if (in_array($backup['status'], ['pending', 'blocked'], true)) {
                            throw new \RuntimeException('recovery_backup_pending');
                        }
                        if ($backup['status'] === 'failed') {
                            throw new \RuntimeException('recovery_backup_failed');
                        }
                        if (!in_array($backup['status'], ['ready', 'skipped'], true)) {
                            throw new \RuntimeException('recovery_backup_missing');
                        }
                    }
                    if (!$this->migrationAuthorized()) {
                        throw new \RuntimeException('recovery_reauthentication_required');
                    }
                    $this->connect('migration');
                    $this->revalidateAdministrator();
                    $this->assertReleaseFilesReady();
                    $before = $this->migrationState();
                    $fileVersion = AppVersionService::fileVersion();
                    if ((int) $before['pending_count'] === 0
                        && preg_match('/^\d+\.\d+\.\d+$/D', (string) $before['installed_version']) === 1
                        && version_compare((string) $before['installed_version'], $fileVersion, '>')
                    ) {
                        throw new \RuntimeException('direct_update_downgrade_refused');
                    }
                    $migrator = new Migrator($this->pdo, $this->root . '/database/migrations');
                    $results = $migrator->run(1);
                    $applied = array_values(array_filter(
                        $results,
                        static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['applied', 'adopted'], true)
                    ));
                    $state = $this->migrationState();
                    if ($state['pending_count'] === 0) {
                        $integrity = (new ReleaseIntegrityService())->inspectDirectory($this->root, true, false);
                        if (!(bool) ($integrity['ok'] ?? false)) {
                            throw new \RuntimeException('release_schema_inconsistent');
                        }
                        (new DirectUpdateMetadataPromotionService())->promote(
                            $this->pdo,
                            AppVersionService::fileVersion(),
                            (string) ($state['last_applied'] ?? ''),
                            'Actualización completada desde el actualizador directo.'
                        );
                        CacheInvalidationService::invalidateKnown('direct_update_completed', AppVersionService::fileVersion());
                        $fileVersion = AppVersionService::fileVersion();
                        if (version_compare($fileVersion, '2.30.0', '>=')) {
                            (new CronV3SetupAssistantService($this->pdo, null, $this->root))
                                ->prepareOperationalConfig((int) Auth::id());
                        } else {
                            (new CronV3SetupAssistantService($this->pdo, null, $this->root))
                                ->prepareSafeConfig((int) Auth::id());
                        }
                        Session::forget('_recovery_authorized_at');
                        Session::forget('_recovery_authorized_user');
                        Session::forget('_recovery_backup_decision');
                        Session::forget('_recovery_backup_summary');
                        Session::forget('_recovery_backup_id');
                        $safety = (new SystemSafetyStatusService())->status();
                        $notice = version_compare(AppVersionService::fileVersion(), '2.30.0', '>=')
                            ? 'Actualización completada. Cron V3 quedó preparado como motor operativo y V2 se saltará solo. '
                            : 'Actualización completada. Cron V3 quedó preparado en modo seguro. ';
                        $notice .= ((string) ($safety['api'] ?? '') === 'stopped'
                            ? 'La salida hacia Mercado Libre continúa detenida.'
                            : 'Revise el freno de mano antes de habilitar consultas.');
                    } else {
                        $notice = $applied !== []
                            ? 'Se instaló un paso de forma segura. La actualización continuará con el siguiente.'
                            : 'El paso ya estaba instalado. Se comprobó sin duplicarlo.';
                    }
                }
            } catch (Throwable $failure) {
                // Un paso fallido nunca conserva el permiso de continuación
                // automática. El administrador debe revisar y autorizar de
                // nuevo para evitar un bucle de POST contra MariaDB.
                $keepRecoveryIdentity = in_array(
                    $action,
                    ['authorize', 'check_backup', 'recover_backup', 'cancel_backup', 'cleanup_backup'],
                    true
                ) && ($identityConfirmed || $this->hasRecoveryIdentity());
                if (!$keepRecoveryIdentity) {
                    Session::forget('_recovery_authorized_at');
                    Session::forget('_recovery_authorized_user');
                }
                $failureCode = $failure->getMessage();
                $error = match (true) {
                    $failure instanceof HttpException
                        && str_contains(mb_strtolower($failure->getMessage()), 'origen')
                        => 'La solicitud no llegó desde esta instalación. Recargue el actualizador '
                            . 'desde su dirección habitual e inténtelo nuevamente.',
                    $failure instanceof HttpException
                        => 'La sesión de seguridad venció. Recargue el actualizador e inténtelo nuevamente.',
                    $failureCode === 'administrator_password_invalid'
                        => 'La contraseña no coincide con el administrador de esta sesión. '
                            . 'Puede confirmar su identidad desde el inicio de sesión normal.',
                    $failureCode === 'administrator_revalidation_failed'
                        => 'La sesión pertenece a un usuario que ya no está activo como administrador permanente. '
                            . 'Inicie sesión nuevamente.',
                    $failureCode === 'database_unavailable'
                        => 'MariaDB no respondió dentro del tiempo seguro. No se modificó la base de datos.',
                    $failureCode === 'direct_update_downgrade_refused'
                        => 'La versión registrada en MariaDB es posterior a los archivos subidos. Se bloqueó el downgrade sin modificar datos.',
                    $failureCode === 'direct_update_lock_busy'
                        => 'Otra finalización local conserva el bloqueo de actualización. Espere a que termine y vuelva a comprobar.',
                    $failureCode === 'direct_update_version_cas_miss'
                        => 'La versión instalada cambió durante la comprobación. No se firmó la actualización; recargue el estado.',
                    $action === 'authorize' && $identityConfirmed
                        => $this->backupFailureMessage($failure),
                    in_array($action, ['recover_backup', 'cancel_backup', 'cleanup_backup'], true)
                        => $this->backupFailureMessage($failure),
                    $failure instanceof MigrationExecutionException
                        => $this->migrationFailureMessage($failure),
                    $failureCode === 'cron_v3_ml_write_enabled'
                        => 'La base quedó migrada, pero Cron V3 no se preparó: ML_WRITE_ENABLED debe permanecer en false.',
                    $failureCode === 'cron_v3_doctor_blocked'
                        => 'La base quedó migrada, pero Cron V3 no se preparó: Doctor local/remoto todavía no aprueba.',
                    str_starts_with($failureCode, 'cron_v3_process_env_override:')
                        => 'La base quedó migrada, pero Cron V3 no se preparó: Hostinger o PHP sobrescribe '
                            . substr($failureCode, strlen('cron_v3_process_env_override:')) . '.',
                    default
                        => 'La actualización se detuvo sin consultar Mercado Libre. '
                            . 'Revise el diagnóstico local e intente nuevamente.',
                };
                if ($failure->getMessage() === 'installed_release_marker_write_failed') {
                    $error = 'Las migraciones terminaron, pero no se pudo guardar el marcador firmado. Revise APP_KEY y los permisos de storage.';
                } elseif ($failure->getMessage() === 'recovery_reauthentication_required') {
                    $error = 'Confirme nuevamente su contraseña antes de modificar la base de datos.';
                } elseif ($failureCode === 'recovery_backup_pending') {
                    $error = 'El respaldo interno todavía se está preparando. Puede continuar con una copia externa confirmada o limpiar esa solicitud desde Copias.';
                } elseif ($failureCode === 'recovery_backup_failed') {
                    $error = 'El respaldo no pudo completarse. Revise Copias y recuperación; ninguna migración fue aplicada.';
                } elseif ($failureCode === 'recovery_backup_missing') {
                    $error = 'No existe un respaldo previo listo. Prepare, recupere o cancele la solicitud actual antes de migrar.';
                } elseif ($failureCode === 'release_upload_incomplete') {
                    $error = 'La subida de archivos está incompleta o mezclada. Vuelva a subir el paquete completo y recargue esta página; no se aplicó ninguna migración.';
                } elseif ($failureCode === 'release_schema_inconsistent') {
                    $error = 'Los archivos terminaron de migrar, pero la versión del esquema no coincide con el manifiesto. No se firmó la actualización; revise el diagnóstico de migraciones.';
                }
                $this->writeRecoveryDiagnostic($failure);
            }
            $query = $notice !== '' ? '?result=advanced' : '?result=stopped';
            Session::put('_recovery_notice', $notice);
            Session::put('_recovery_error', $error);
            $this->redirect('/actualizar.php' . $query);
        }

        $notice = (string) Session::get('_recovery_notice', '');
        $error = (string) Session::get('_recovery_error', '');
        Session::forget('_recovery_notice');
        Session::forget('_recovery_error');
        // Las sesiones creadas por versiones anteriores pueden no tener CSRF.
        // El token debe existir y quedar persistido antes de liberar el lock;
        // generarlo después de session_write_close solo modificaría el snapshot
        // en memoria y el siguiente POST sería rechazado.
        $csrfToken = Csrf::token();
        // El diagnóstico puede tardar hasta el límite corto de conexión. No
        // debe conservar el lock de sesión mientras espera a MariaDB.
        Session::closeReadOnly();
        $this->connect('diagnostic', false);
        $state = $this->migrationState();
        $markerService = new InstalledVersionMarkerService();
        $marker = $markerService->read();
        $integrity = (new ReleaseIntegrityService())->inspectDirectory($this->root, true, false);
        if (
            $this->pdo instanceof PDO
            && (int) $state['pending_count'] === 0
            && hash_equals(AppVersionService::fileVersion(), (string) $state['installed_version'])
            && (bool) ($integrity['ok'] ?? false)
            && (!(bool) $marker['valid']
                || !hash_equals(AppVersionService::fileVersion(), (string) $marker['version']))
        ) {
            $markerService->write(
                AppVersionService::fileVersion(),
                (string) ($state['last_applied'] ?? '')
            );
            $marker = $markerService->read();
        }
        $this->renderUpdate($state, $marker, $notice, $error, $csrfToken);
    }

    private function connect(string $profile, bool $throw = true): void
    {
        try {
            Database::useProfile($profile);
            $this->pdo = Database::connectionFresh();
            $this->databaseMessage = null;
        } catch (Throwable) {
            $this->pdo = null;
            $this->databaseMessage = 'MariaDB no respondió dentro del tiempo seguro. No se intentó consultar Mercado Libre.';
            if ($throw) {
                throw new \RuntimeException('database_unavailable');
            }
        }
    }

    /** @return array{pending:list<string>,pending_count:int,installed_version:string,last_applied:string} */
    private function migrationState(): array
    {
        $files = glob($this->root . '/database/migrations/*.sql') ?: [];
        natcasesort($files);
        $versions = array_values(array_map('basename', $files));
        $applied = [];
        $installed = 'Por comprobar';
        if ($this->pdo instanceof PDO) {
            try {
                $applied = array_map('strval', $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
                sort($applied, SORT_STRING);
                $value = $this->pdo->query(
                    "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
                )->fetchColumn();
                if ($value !== false) {
                    $installed = (string) $value;
                }
            } catch (Throwable) {
                $this->databaseMessage = 'La conexión existe, pero todavía no se pudo comprobar el esquema.';
            }
        }
        $pending = $this->pdo instanceof PDO ? array_values(array_diff($versions, $applied)) : [];
        $appliedLookup = array_fill_keys($applied, true);
        $lastApplied = '';
        foreach ($versions as $version) {
            if (isset($appliedLookup[$version])) {
                $lastApplied = $version;
            }
        }
        return [
            'pending' => $pending,
            'pending_count' => count($pending),
            'installed_version' => $installed,
            'last_applied' => $lastApplied,
        ];
    }

    private function assertReleaseFilesReady(): void
    {
        $integrity = (new ReleaseIntegrityService())->inspectDirectory($this->root, false, false);
        if (!(bool) ($integrity['ok'] ?? false)) {
            throw new \RuntimeException('release_upload_incomplete');
        }
    }

    /** @return array<string,mixed> */
    private function revalidateAdministrator(): array
    {
        if (!$this->pdo instanceof PDO) {
            throw new \RuntimeException('database_unavailable');
        }
        $statement = $this->pdo->prepare(
            'SELECT id,role,status,is_temporary,password_hash FROM users WHERE id=? LIMIT 1'
        );
        $statement->execute([Auth::id()]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($user)
            || (string) ($user['role'] ?? '') !== 'admin'
            || (int) ($user['status'] ?? 0) !== 1
            || (int) ($user['is_temporary'] ?? 0) !== 0
        ) {
            throw new \RuntimeException('administrator_revalidation_failed');
        }
        return $user;
    }

    private function authorizeMigration(string $password): void
    {
        $user = $this->revalidateAdministrator();
        if ($password === '' || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            throw new \RuntimeException('administrator_password_invalid');
        }
        Session::put('_recovery_authorized_at', time());
        Session::put('_recovery_authorized_user', (int) Auth::id());
    }

    private function hasRecoveryIdentity(): bool
    {
        $authorizedAt = (int) Session::get('_recovery_authorized_at', 0);
        $authorizedUser = (int) Session::get('_recovery_authorized_user', 0);
        return $authorizedAt > 0
            && $authorizedAt >= time() - self::REAUTH_TTL_SECONDS
            && $authorizedUser > 0
            && $authorizedUser === (int) Auth::id();
    }

    private function requireRecoveryIdentity(): void
    {
        if (!$this->hasRecoveryIdentity()) {
            throw new \RuntimeException('recovery_reauthentication_required');
        }
        $this->revalidateAdministrator();
    }

    /** @return array{status:string,message:string,id:int,raw_status:string} */
    private function prepareSecureBackup(): array
    {
        $currentId = (int) Session::get('_recovery_backup_id', 0);
        if ($currentId < 1 && $this->pdo instanceof PDO) {
            $existing = $this->pdo->prepare(
                'SELECT id
                   FROM system_backup_archives
                  WHERE requested_by=? AND purpose="pre_update" AND deleted_at IS NULL
                    AND (
                        status IN ("prepared","queued","creating","verifying","ready_pending_release","cancel_requested","deleting","failed")
                        OR (status="ready" AND verified_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 24 HOUR))
                    )
                  ORDER BY id DESC
                  LIMIT 1'
            );
            $existing->execute([(int) Auth::id()]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                $currentId = (int) $existingId;
                Session::put('_recovery_backup_id', $currentId);
            }
        }
        if ($currentId > 0) {
            $current = $this->inspectSecureBackup();
            if (in_array($current['status'], ['pending', 'ready', 'blocked', 'failed'], true)) {
                return $current;
            }
        }
        if (!$this->pdo instanceof PDO) {
            throw new \RuntimeException('database_unavailable');
        }
        $request = (new BackupCenterService())->enqueue((int) Auth::id(), 'pre_update', 'direct_update');
        Session::put('_recovery_backup_id', (int) $request['id']);
        Session::put('_recovery_backup_decision', 'pending');
        Session::forget('_recovery_backup_summary');

        return [
            'status' => 'pending',
            'id' => (int) $request['id'],
            'raw_status' => 'queued',
            'message' => 'Identidad confirmada. El respaldo seguro quedó solicitado. '
                . 'Ábralo en Copias para continuarlo desde el navegador sin consultar Mercado Libre.',
        ];
    }

    /** @return array{status:string,message:string,id:int,raw_status:string} */
    private function inspectSecureBackup(): array
    {
        $backupId = (int) Session::get('_recovery_backup_id', 0);
        if ($backupId < 1) {
            return [
                'status' => (string) Session::get('_recovery_backup_decision', '') === 'skipped'
                    ? 'skipped'
                    : 'none',
                'id' => 0,
                'raw_status' => '',
                'message' => '',
            ];
        }
        if (!$this->pdo instanceof PDO) {
            throw new \RuntimeException('database_unavailable');
        }
        $statement = $this->pdo->prepare(
            'SELECT id,status,size_bytes,table_count,checksum_sha256,verified_at,completed_at,requested_at
              FROM system_backup_archives
              WHERE id=? AND requested_by=? AND purpose="pre_update" AND deleted_at IS NULL
              LIMIT 1'
        );
        $statement->execute([$backupId, (int) Auth::id()]);
        $backup = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($backup)) {
            Session::forget('_recovery_backup_id');
            Session::forget('_recovery_backup_decision');
            Session::forget('_recovery_backup_summary');
            return [
                'status' => 'none',
                'id' => 0,
                'raw_status' => '',
                'message' => 'La solicitud anterior ya no existe. Prepare un respaldo nuevo o continúe sin respaldo con confirmación explícita.',
            ];
        }
        $status = (string) ($backup['status'] ?? '');
        if ($status === 'ready' && !empty($backup['verified_at'])) {
            Session::put('_recovery_backup_decision', 'verified');
            Session::put('_recovery_backup_summary', [
                'size' => max(0, (int) ($backup['size_bytes'] ?? 0)),
                'checksum' => (string) ($backup['checksum_sha256'] ?? ''),
                'tables' => max(0, (int) ($backup['table_count'] ?? 0)),
                'adapter' => 'chunked_xchacha20poly1305_v3',
                'created_at' => (string) ($backup['verified_at'] ?? $backup['completed_at'] ?? ''),
            ]);
            return [
                'status' => 'ready',
                'id' => $backupId,
                'raw_status' => 'ready',
                'message' => 'Respaldo cifrado y verificado. Confirme la contraseña nuevamente si la autorización ya venció.',
            ];
        }
        if (in_array($status, ['prepared', 'queued', 'creating', 'verifying', 'ready_pending_release'], true)) {
            Session::put('_recovery_backup_decision', 'pending');
            return [
                'status' => 'pending',
                'id' => $backupId,
                'raw_status' => $status,
                'message' => $status === 'ready_pending_release'
                    ? 'El respaldo ya se verificó y está liberando su protección local. Continúe la copia desde Copias si no queda lista.'
                    : 'El respaldo continúa en preparación. Puede cerrar esta página y volver sin perder el avance.',
            ];
        }
        if (in_array($status, ['cancel_requested', 'deleting'], true)) {
            Session::put('_recovery_backup_decision', 'pending');
            Session::forget('_recovery_backup_summary');
            return [
                'status' => 'blocked',
                'id' => $backupId,
                'raw_status' => $status,
                'message' => 'La copia está cancelando o limpiando restos. Continúe o limpie desde Copias antes de crear otra.',
            ];
        }

        Session::put('_recovery_backup_decision', 'failed');
        Session::forget('_recovery_backup_summary');
        return [
            'status' => 'failed',
            'id' => $backupId,
            'raw_status' => $status,
            'message' => 'El respaldo quedó fallido o no recuperable. Cancele la solicitud, limpie restos o cree una copia nueva.',
        ];
    }

    private function migrationAuthorized(): bool
    {
        return $this->hasRecoveryIdentity()
            && in_array((string) Session::get('_recovery_backup_decision', ''), ['verified', 'skipped'], true);
    }

    private function afterLoginPath(): string
    {
        if (Auth::role() === 'admin' && !Auth::isTemporary()) {
            if ((new InstalledVersionMarkerService())->requiresUpdate(AppVersionService::fileVersion())) {
                return '/actualizar.php';
            }
        }
        return '/index.php';
    }

    /** @return array{status:string,message:string,id:int,raw_status:string} */
    private function inspectBackupForDisplay(): array
    {
        try {
            return $this->inspectSecureBackup();
        } catch (Throwable $failure) {
            return [
                'status' => 'failed',
                'id' => (int) Session::get('_recovery_backup_id', 0),
                'raw_status' => 'unknown',
                'message' => $this->backupFailureMessage($failure),
            ];
        }
    }

    private function backupFailureMessage(Throwable $failure): string
    {
        $message = $failure->getMessage();
        if (str_contains($message, 'Complete las migraciones de copias')) {
            return 'El módulo de copias todavía necesita migraciones antes de crear respaldos internos. '
                . 'Si ya descargó una copia externa verificada, use “Usar respaldo externo y continuar”.';
        }
        return match ($message) {
            'database_unavailable'
                => 'La identidad se confirmó, pero MariaDB no respondió dentro del tiempo seguro. No se modificó la base de datos.',
            'recovery_backup_missing'
                => 'No hay un respaldo previo listo. Use una copia externa confirmada o solicite un respaldo seguro local.',
            'recovery_backup_pending'
                => 'Hay una copia en preparación. Puede recuperarla, cancelarla si está vacía, limpiar restos o continuar con una copia externa confirmada.',
            'recovery_backup_failed'
                => 'El respaldo quedó fallido. Use Limpiar restos, cancele la solicitud o continúe con una copia externa confirmada.',
            default
                => str_contains($message, 'solicitud no alcanzó a crear')
                    ? 'La solicitud de respaldo está vacía. Puede cancelarla de forma segura y crear una nueva.'
                    : (str_contains($message, 'Ya existe una copia en preparación')
                        ? 'Ya existe una copia en preparación. No se aplicó ninguna migración; recupérela, cancélela o use una copia externa confirmada.'
                        : (str_contains($message, 'fragmentos')
                        ? 'El respaldo conserva fragmentos o checkpoints. Ejecute Limpiar restos para liberar el flujo.'
                        : (str_contains($message, 'worker vigente')
                            ? 'El respaldo todavía pertenece a un lote vigente. Espere a que venza el lease o continúe desde Copias.'
                            : (str_contains($message, 'trabajo ejecutable')
                                ? 'El respaldo no conserva un trabajo ejecutable. Cancele la solicitud vacía o limpie sus restos.'
                                : 'El respaldo no está listo para migrar. No se modificó la base de datos; use una copia externa confirmada o revise la solicitud de respaldo.')))),
        };
    }

    private function migrationFailureMessage(MigrationExecutionException $failure): string
    {
        $migration = $failure->migrationKey() ?: 'migración sin identificar';
        $stage = $failure->stage();
        $previous = $failure->getPrevious();
        $cause = $previous !== null
            ? (MigrationTraceService::safeMessage($previous) ?? '')
            : (MigrationTraceService::safeMessage($failure) ?? '');
        $diagnostic = $failure->diagnosticId();
        $retry = $failure->safeToRetry()
            ? ' Puede reintentar después de revisar el diagnóstico.'
            : ' No reintente hasta revisar el diagnóstico porque el SQL pudo haber iniciado.';

        return 'Falló ' . $migration . ' en ' . $stage . '. '
            . ($cause !== '' ? 'Causa local: ' . $cause . '. ' : '')
            . 'Diagnóstico: ' . $diagnostic . '.'
            . $retry
            . ' No se consultó Mercado Libre.';
    }

    private function validateOrigin(): void
    {
        // missing_origin y origen diferente se resuelven en la misma autoridad.
        SameOriginGuard::assertRequest(true);
    }

    private function recoveryLoginRateLimited(string $email, string $ip): bool
    {
        if (!$this->pdo instanceof PDO) {
            return false;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts
                 WHERE email_hash=? AND ip_hash=? AND succeeded=0
                   AND attempted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)'
            );
            $statement->execute([hash('sha256', $email), hash('sha256', $ip)]);
            return (int) $statement->fetchColumn() >= 5;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordRecoveryLoginAttempt(string $email, string $ip, bool $success): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO login_attempts (email_hash,ip_hash,succeeded) VALUES (?,?,?)'
            )->execute([hash('sha256', $email), hash('sha256', $ip), $success ? 1 : 0]);
        } catch (Throwable) {
            // Una instalación antigua todavía puede no tener esta tabla.
        }
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

    private function redirect(string $path): never
    {
        header('Location: ' . $this->base . $path, true, 303);
        exit;
    }

    private function writeRecoveryDiagnostic(Throwable $failure): void
    {
        $directory = AppPaths::storage('logs');
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $record = [
            'at' => gmdate(DATE_ATOM),
            'code' => 'RECOVERY-' . gmdate('Ymd-His'),
            'stage' => 'direct_update',
            'exception' => $failure::class,
            'reason' => $this->safeFailureReason($failure),
        ];
        if ($failure instanceof MigrationExecutionException) {
            $record['migration_key'] = $failure->migrationKey();
            $record['migration_stage'] = $failure->stage();
            $record['migration_diagnostic_id'] = $failure->diagnosticId();
            $record['safe_to_retry'] = $failure->safeToRetry();
            $previous = $failure->getPrevious();
            if ($previous !== null) {
                $record['safe_message'] = MigrationTraceService::safeMessage($previous);
            }
        }
        @file_put_contents(
            $directory . '/recovery-update.jsonl',
            (json_encode($record, JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function renderLogin(string $error): never
    {
        $token = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars($this->base . '/login.php', ENT_QUOTES, 'UTF-8');
        $errorHtml = $error !== '' ? '<div class="notice error" role="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
        $intent = Session::get('_recovery_login_intent');
        $reauth = is_array($intent);
        $email = $reauth ? (string) ($intent['email'] ?? '') : '';
        $emailValue = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $description = $reauth
            ? 'Confirme el mismo acceso que utiliza normalmente. Al ingresar podrá usar una copia externa o solicitar un respaldo local opcional.'
            : 'Este acceso ligero no carga paneles, módulos ni reportes.';
        $buttonText = $reauth ? 'Confirmar identidad y continuar' : 'Ingresar de forma segura';
        echo $this->document('Ingresar', <<<HTML
<main class="card narrow">
  <span class="eyebrow">ACCESO DE RECUPERACIÓN</span>
  <h1>Ingresar al ERP</h1>
  <p>{$this->escape($description)}</p>
  {$errorHtml}
  <form method="post" action="{$action}">
    <input type="hidden" name="_token" value="{$token}">
    <label for="email">Correo electrónico</label>
    <input id="email" name="email" type="email" autocomplete="username" value="{$emailValue}" required autofocus>
    <label for="password">Contraseña</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">{$buttonText}</button>
  </form>
</main>
HTML);
        exit;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $marker */
    private function renderUpdate(
        array $state,
        array $marker,
        string $notice,
        string $error,
        string $csrfToken
    ): never
    {
        $fileVersion = AppVersionService::fileVersion();
        $dbReady = $this->pdo instanceof PDO;
        $pending = (int) $state['pending_count'];
        $fileIntegrity = (new ReleaseIntegrityService())->inspectDirectory($this->root, false, false);
        $databaseIntegrity = $dbReady
            ? (new ReleaseIntegrityService())->inspectDirectory($this->root, true, false)
            : ['ok' => false, 'schema' => []];
        $filesReady = (bool) ($fileIntegrity['ok'] ?? false);
        $schemaMatches = $dbReady
            && hash_equals($fileVersion, (string) $state['installed_version']);
        $markerMatches = (bool) $marker['valid']
            && hash_equals($fileVersion, (string) $marker['version']);
        $minimumApplied = (bool) ($databaseIntegrity['schema']['migration_applied'] ?? false);
        $transition = DirectUpdateTransitionPolicy::evaluate(
            $fileVersion,
            (string) $state['installed_version'],
            $pending,
            $filesReady,
            $minimumApplied
        );
        $metadataOnlyEligible = (bool) $transition['metadata_only_eligible'];
        $schemaHardBlocked = $dbReady && $pending === 0 && !$schemaMatches && !$metadataOnlyEligible;
        $finished = $dbReady
            && $pending === 0
            && $filesReady
            && $schemaMatches
            && $markerMatches
            && $minimumApplied
            && (bool) ($databaseIntegrity['ok'] ?? false);
        $token = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars($this->base . '/actualizar.php', ENT_QUOTES, 'UTF-8');
        $home = htmlspecialchars($this->base . '/index.php', ENT_QUOTES, 'UTF-8');
        $safety = (new SystemSafetyStatusService())->status();
        $pause = $safety['api'] === 'stopped';
        $automationPause = $safety['automation'] === 'stopped';
        $authorized = $this->migrationAuthorized();
        $backupDecision = (string) Session::get('_recovery_backup_decision', '');
        $backupSummary = Session::get('_recovery_backup_summary', []);
        $backupState = $dbReady && $backupDecision !== 'skipped' ? $this->inspectBackupForDisplay() : [
            'status' => 'none',
            'id' => 0,
            'raw_status' => '',
            'message' => '',
        ];
        $next = $state['pending'][0] ?? 'Ninguna';
        $statusClass = $finished ? 'success' : ($dbReady && $filesReady ? 'warning' : 'error');
        $statusTitle = $finished
            ? 'Actualización completada'
            : (!$filesReady
                ? 'La subida de archivos está incompleta'
                : ($dbReady ? 'Actualización lista para continuar' : 'Base de datos no disponible'));
        $noticeHtml = $notice !== '' ? '<div class="notice success" role="status">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>' : '';
        $errorHtml = $error !== '' ? '<div class="notice error" role="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
        $dbMessage = htmlspecialchars($this->databaseMessage ?? 'Conexión local disponible.', ENT_QUOTES, 'UTF-8');
        $markerVersion = (bool) $marker['valid'] && (string) ($marker['version'] ?? '') !== ''
            ? (string) $marker['version']
            : 'Sin marcador válido';
        $integrityHtml = '';
        if (!$filesReady) {
            $issueList = $this->releaseIntegrityIssuesHtml((array) ($fileIntegrity['errors'] ?? []));
            $integrityHtml = '<div class="notice error" role="alert"><strong>Paquete incompleto o mezclado</strong><br>'
                . 'Vuelva a subir todos los archivos de la misma release. El actualizador no aplicará migraciones hasta que VERSION, manifiesto, componentes y migración mínima coincidan.'
                . $issueList
                . '</div>';
        } elseif ($metadataOnlyEligible) {
            $integrityHtml = '<div class="notice warning" role="status"><strong>Falta confirmar la versión instalada</strong><br>'
                . 'El esquema ya está completo y no hay migraciones SQL pendientes. Confirme el respaldo y su identidad para finalizar únicamente app.version y el marcador firmado.</div>';
        } elseif ($schemaHardBlocked) {
            $integrityHtml = '<div class="notice error" role="alert"><strong>El esquema no coincide con los archivos</strong><br>'
                . 'Abra el diagnóstico de migraciones. La actualización no se marcará como terminada mientras exista esta diferencia.</div>';
        } elseif ($dbReady && $pending === 0 && !$markerMatches) {
            $integrityHtml = '<div class="notice warning" role="status"><strong>Falta confirmar el marcador firmado</strong><br>'
                . 'Recargue la página después de comprobar el esquema. No se consultará Mercado Libre.</div>';
        }
        $button = '';
        if (!$filesReady || $schemaHardBlocked) {
            $button = '<a class="button secondary" href="' . $action . '">Comprobar de nuevo</a>';
        } elseif ($dbReady && !$finished && in_array($backupDecision, ['pending', 'failed'], true)) {
            $recoverButton = '';
            $cancelLabel = 'Cancelar solicitud vacía';
            $cleanupLabel = 'Limpiar restos';
            if ((int) ($backupState['id'] ?? 0) > 0) {
                $recoverButton = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="recover_backup">
  <button type="submit" class="secondary">Recuperar respaldo</button>
</form>
HTML;
                if (($backupState['status'] ?? '') === 'blocked') {
                    $cancelLabel = 'Cancelar y limpiar';
                }
                if (($backupState['status'] ?? '') === 'failed') {
                    $cleanupLabel = 'Limpiar copia fallida';
                }
            }
            $button = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="check_backup">
  <button type="submit">Ver estado del respaldo</button>
</form>
{$recoverButton}
<form method="post" action="{$action}">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="cancel_backup">
  <button type="submit" class="secondary">{$cancelLabel}</button>
</form>
<form method="post" action="{$action}">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="cleanup_backup">
  <button type="submit" class="secondary">{$cleanupLabel}</button>
</form>
<form method="post" action="{$action}" class="reauth-fallback">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="authorize">
  <input type="hidden" name="backup_choice" value="external">
  <label for="recovery-password-external">Contraseña de administrador</label>
  <input id="recovery-password-external" name="password" type="password" autocomplete="current-password" required>
  <button type="submit" class="secondary">Usar respaldo externo y continuar</button>
  <p>La copia interna pendiente quedará como limpieza pendiente; no bloqueará la migración.</p>
</form>
HTML;
        } elseif ($dbReady && !$finished && !$authorized) {
            $button = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="authorize">
  <label for="recovery-password">Confirme su contraseña de administrador</label>
  <input id="recovery-password" name="password" type="password" autocomplete="current-password" required>
  <fieldset class="backup-choice">
    <legend>¿Cómo desea continuar?</legend>
    <label class="choice"><input type="radio" name="backup_choice" value="external" checked> <span><strong>Usar respaldo externo y continuar</strong><small>Use esta opción si ya descargó una copia por phpMyAdmin u otro medio. No depende de tareas automáticas.</small></span></label>
    <label class="choice"><input type="radio" name="backup_choice" value="skip"> <span><strong>Continuar sin respaldo interno</strong><small>Solo si acepta avanzar sin crear una copia desde el ERP.</small></span></label>
    <label class="choice"><input type="radio" name="backup_choice" value="secure"> <span><strong>Crear respaldo seguro local</strong><small>Opcional: se continúa desde Copias en micro-lotes de navegador. No consulta Mercado Libre.</small></span></label>
  </fieldset>
  <button type="submit">Confirmar y continuar actualización</button>
</form>
<form method="post" action="{$action}" class="reauth-fallback">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="restart_login">
  <button type="submit" class="secondary">Confirmar desde el inicio de sesión</button>
  <p>Use esta opción si la sesión actual es antigua o si la contraseña correcta fue rechazada.</p>
</form>
HTML;
        } elseif ($dbReady && !$finished) {
            $button = <<<HTML
<form method="post" action="{$action}" id="recovery-update-form">
  <input type="hidden" name="_token" value="{$token}">
  <input type="hidden" name="action" value="migrate">
  <button type="submit">Continuar actualización</button>
</form>
HTML;
        } elseif ($finished) {
            $button = '<a class="button" href="' . $home . '">Abrir ERP</a>';
        } else {
            $button = '<a class="button secondary" href="' . $action . '">Comprobar de nuevo</a>';
        }
        $auto = '';
        $backupHtml = '';
        if (is_array($backupSummary) && $backupSummary !== []) {
            $size = number_format(((int) ($backupSummary['size'] ?? 0)) / 1048576, 2, ',', '.');
            $tables = (int) ($backupSummary['tables'] ?? 0);
            $created = $this->escape((string) ($backupSummary['created_at'] ?? ''));
            $backupHtml = '<div class="notice success"><strong>Respaldo verificado</strong><br>'
                . $size . ' MB · ' . $tables . ' tablas · Cifrado XChaCha20-Poly1305 · ' . $created . '</div>';
        } elseif ($backupDecision === 'skipped') {
            $backupHtml = '<div class="notice warning">La actualización continuará usando una copia externa confirmada por decisión explícita del administrador.</div>';
        } elseif ($backupDecision === 'pending') {
            $rawStatus = $this->escape((string) ($backupState['raw_status'] ?? ''));
            $backupMessage = $this->escape((string) ($backupState['message'] ?? 'Se procesa en micro-lotes locales.'));
            $backupHtml = '<div class="notice warning"><strong>Respaldo en preparación</strong><br>'
                . $backupMessage
                . ($rawStatus !== '' ? '<br><small>Estado técnico: ' . $rawStatus . '</small>' : '')
                . '<br>La base todavía no ha sido modificada por el actualizador.</div>';
        } elseif ($backupDecision === 'failed') {
            $backupHtml = '<div class="notice error"><strong>La copia local necesita revisión</strong><br>'
                . 'Puede limpiarla desde Copias o continuar con una copia externa confirmada. No se modificó la base.</div>';
        }
        echo $this->document('Actualizador seguro', <<<HTML
<main class="card">
  <div class="status {$statusClass}">{$statusTitle}</div>
  <span class="eyebrow">ACTUALIZADOR DIRECTO</span>
  <h1>Recuperación local del ERP</h1>
  <p>Instala un paso por vez. No carga Dashboard, módulos, respaldos remotos ni Mercado Libre.</p>
  {$noticeHtml}{$errorHtml}{$integrityHtml}
  <dl class="facts">
    <div><dt>Archivos activos</dt><dd>{$this->escape($fileVersion)}</dd></div>
    <div><dt>Esquema instalado</dt><dd>{$this->escape((string) $state['installed_version'])}</dd></div>
    <div><dt>Marcador firmado</dt><dd>{$this->escape($markerVersion)}</dd></div>
    <div><dt>Migraciones pendientes</dt><dd>{$pending}</dd></div>
    <div><dt>Siguiente paso</dt><dd>{$this->escape((string) $next)}</dd></div>
    <div><dt>MariaDB</dt><dd>{$dbMessage}</dd></div>
    <div><dt>Mercado Libre</dt><dd>{$this->escape($pause ? 'Bloqueado por mantenimiento' : 'Bloqueo local ausente')}</dd></div>
    <div><dt>Automatización</dt><dd>{$this->escape($automationPause ? 'Detenida' : 'Disponible')}</dd></div>
    <div><dt>Escrituras remotas</dt><dd>Bloqueadas</dd></div>
  </dl>
  <div class="notice warning">La actualización no retirará <code>PAUSE_MELI_API</code> ni <code>PAUSE_ERP_AUTOMATION</code>.</div>
  {$backupHtml}
  {$button}
  <noscript><p>JavaScript está desactivado. Pulse “Continuar actualización” una vez por cada paso.</p></noscript>
</main>
{$auto}
HTML);
        exit;
    }

    /** @param list<array<string,mixed>> $errors */
    private function releaseIntegrityIssuesHtml(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $items = [];
        foreach (array_slice($errors, 0, 6) as $error) {
            $code = $this->escape((string) ($error['code'] ?? 'integrity_error'));
            $component = $this->escape((string) ($error['component'] ?? 'runtime'));
            $path = trim((string) ($error['path'] ?? ''));
            $detail = $path !== '' ? ' · ' . $this->escape($path) : '';
            $items[] = '<li><code>' . $code . '</code>: ' . $component . $detail . '</li>';
        }
        if (count($errors) > 6) {
            $items[] = '<li>' . $this->escape('+ ' . (string) (count($errors) - 6) . ' verificación(es) adicional(es).') . '</li>';
        }

        return '<div class="integrity-issues"><strong>Detalle seguro detectado:</strong><ul>'
            . implode('', $items)
            . '</ul></div>';
    }

    private function renderMessage(string $title, string $message, string $action, string $path): never
    {
        $href = htmlspecialchars($this->base . $path, ENT_QUOTES, 'UTF-8');
        echo $this->document($title, '<main class="card narrow"><h1>' . $this->escape($title) . '</h1><p>' . $this->escape($message) . '</p><a class="button" href="' . $href . '">' . $this->escape($action) . '</a></main>');
        exit;
    }

    private function document(string $title, string $content): string
    {
        $safeTitle = $this->escape($title);
        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$safeTitle} · ERP Meli</title><style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#0b1f3a;background:#f3f6fb}*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(820px,100%);background:#fff;border:1px solid #d9e2ef;border-radius:16px;padding:32px;box-shadow:0 16px 50px rgba(11,31,58,.08)}.narrow{max-width:520px}
h1{font-size:clamp(28px,5vw,40px);line-height:1.08;margin:8px 0 10px}p{color:#5c6c83;line-height:1.55}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;color:#4f6482}
.status{border-left:4px solid;padding:12px 14px;margin:-8px 0 24px;background:#f4f7fb;font-weight:800}.status.success{border-color:#1c9a61}.status.warning{border-color:#d99500}.status.error{border-color:#cc3d3d}
.facts{display:grid;grid-template-columns:1fr 1fr;border:1px solid #dce4ef;border-radius:12px;overflow:hidden;margin:24px 0}.facts div{padding:14px 16px;border-bottom:1px solid #e8edf4}.facts div:nth-child(odd){border-right:1px solid #e8edf4}.facts div:nth-last-child(-n+2){border-bottom:0}dt{font-size:12px;color:#60708a}dd{margin:5px 0 0;font-weight:750;overflow-wrap:anywhere}
label{display:block;font-weight:700;margin:16px 0 6px}input{width:100%;height:46px;border:1px solid #bdc9d9;border-radius:9px;padding:0 12px;font:inherit}button,.button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;margin-top:16px;padding:0 20px;border:0;border-radius:9px;background:#1769e0;color:#fff;font-weight:800;text-decoration:none;cursor:pointer}.secondary{background:#fff;color:#1755a9;border:1px solid #bfcde0}
.backup-choice{display:grid;gap:8px;margin:20px 0 4px;padding:14px;border:1px solid #dce4ef;border-radius:11px}.backup-choice legend{padding:0 6px;font-weight:800}.backup-choice .choice{display:grid;grid-template-columns:22px 1fr;gap:9px;align-items:start;margin:0;padding:10px;border:1px solid #e3e9f1;border-radius:9px}.backup-choice .choice input{width:18px;height:18px;margin:2px 0}.backup-choice .choice strong,.backup-choice .choice small{display:block}.backup-choice .choice small{margin-top:3px;color:#60708a;font-weight:400}
.reauth-fallback{margin-top:12px;padding-top:12px;border-top:1px solid #e3e9f1}.reauth-fallback button{margin-top:0}.reauth-fallback p{font-size:13px;margin:8px 0 0}
.command-help{margin-top:12px;padding:10px 12px;border:1px solid #dce4ef;border-radius:9px;background:#f7f9fc}
.notice{padding:12px 14px;border-radius:10px;margin:14px 0;background:#edf4ff;color:#27496f}.notice.success{background:#eaf8f0;color:#17633d}.notice.warning{background:#fff6df;color:#775000}.notice.error{background:#fff0f0;color:#8c2525}.integrity-issues{margin-top:10px;padding-top:10px;border-top:1px solid rgba(140,37,37,.18)}.integrity-issues ul{margin:8px 0 0;padding-left:18px}.integrity-issues li{margin:4px 0}code{font-size:.9em}
@media(max-width:600px){body{padding:12px}.card{padding:22px}.facts{grid-template-columns:1fr}.facts div:nth-child(odd){border-right:0}.facts div:nth-last-child(2){border-bottom:1px solid #e8edf4}}
</style></head><body>{$content}</body></html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeFailureReason(Throwable $failure): string
    {
        if ($failure instanceof HttpException) {
            return 'request_security_failed';
        }
        return match ($failure->getMessage()) {
            'administrator_password_invalid' => 'administrator_password_invalid',
            'administrator_revalidation_failed' => 'administrator_session_stale',
            'database_unavailable' => 'database_unavailable',
            'recovery_backup_missing' => 'recovery_backup_missing',
            'recovery_backup_pending' => 'recovery_backup_pending',
            'recovery_backup_failed' => 'recovery_backup_failed',
            'recovery_reauthentication_required' => 'recovery_reauthentication_required',
            'installed_release_marker_write_failed' => 'installed_release_marker_write_failed',
            'release_upload_incomplete' => 'release_upload_incomplete',
            'release_schema_inconsistent' => 'release_schema_inconsistent',
            default => $failure instanceof MigrationExecutionException
                ? 'migration_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($failure->stage()))
                : 'unexpected_recovery_failure',
        };
    }
}
