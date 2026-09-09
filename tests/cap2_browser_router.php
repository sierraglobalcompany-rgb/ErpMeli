<?php

declare(strict_types=1);

/*
 * Local browser fixture. The adapter renders production templates without the
 * application shell so browser QA does not need the unrelated full schema.
 * Authentication, controller writes, CSRF/same-origin checks, capacity policy,
 * CapacityChangeGuard, Queue V4 health snapshot and all SQL are production code.
 */
namespace App\Core {
    final class View
    {
        public static function render(string $view, array $data = [], bool $layout = true): void
        {
            $file = dirname(__DIR__) . '/app/Views/' . $view . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException('Vista local no encontrada.');
            }
            extract($data, EXTR_SKIP);
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>Capacidad · QA local</title>';
            echo '<link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/ux.css">';
            echo '</head><body><main class="page-content">';
            echo '<p class="alert info">QA local: controlador, autorización, salud y SQL reales; sin transporte remoto.</p>';
            foreach (['success' => 'success', 'error' => 'danger'] as $key => $tone) {
                $message = Session::flash($key);
                if (is_string($message) && $message !== '') {
                    echo '<div class="alert ' . $tone . '" role="' . ($key === 'error' ? 'alert' : 'status') . '">'
                        . self::e($message) . '</div>';
                }
            }
            require $file;
            echo '</main></body></html>';
        }

        public static function e(mixed $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public static function asset(string $base, string $path): string
        {
            return '/assets/' . ltrim($path, '/');
        }
    }
}

namespace {
    use App\Core\Auth;
    use App\Core\Database;
    use App\Core\HttpException;
    use App\Core\Session;
    use App\Core\View;
    use App\Services\CapacityPolicyService;
    use App\Services\SessionGenerationService;

    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';
    require __DIR__ . '/cap2_health_fixture.php';

    K1dSafeTestDatabase::assertGuard(
        (string) getenv('APP_ENV'),
        (string) getenv('ML_WRITE_ENABLED'),
        (string) getenv('DB_HOST'),
        (string) getenv('DB_NAME')
    );
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (PHP_SAPI !== 'cli-server'
        || (string) getenv('DB_PORT') !== '33079'
        || !in_array($remote, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Local fixture only.');
    }

    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    if ($path === '/favicon.ico') {
        http_response_code(204);
        exit;
    }
    if (in_array($path, ['/assets/app.css', '/assets/ux.css'], true)) {
        header('Content-Type: text/css; charset=utf-8');
        readfile(dirname(__DIR__) . '/public' . $path);
        exit;
    }

    $database = K1dSafeTestDatabase::createFromEnvironment();
    $pdo = $database->pdo();
    $schemaExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='companies'"
    )->fetchColumn() === 1;
    if (!$schemaExists) {
        cap2_health_create_schema($pdo);
        cap2_health_seed($pdo);
    }
    Database::setConnection($pdo);
    Session::start();
    header('Cache-Control: private, no-store, max-age=0');

    $authenticate = static function (bool $temporary = false): void {
        $_SESSION['user'] = [
            'id' => 7,
            'name' => 'Admin QA',
            'email' => 'admin@local.test',
            'role' => 'admin',
            'is_temporary' => $temporary ? 1 : 0,
            'expires_at' => $temporary ? gmdate('Y-m-d H:i:s', time() + 3600) : null,
            'session_generation' => (new SessionGenerationService())->current(),
        ];
        if (!is_string($_SESSION['_csrf'] ?? null) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    };
    $setHealth = static function (string $state) use ($pdo): void {
        $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1,last_scheduler_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)");
        $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',lease_expires_at=NULL,completed_at=NULL");
        $pdo->exec('DELETE FROM oauth_refresh_operations');
        $pdo->exec('DELETE FROM api_request_logs');
        if ($state === '429') {
            $pdo->exec("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at) VALUES ('account',1,11,429,1,UTC_TIMESTAMP())");
        } elseif ($state === 'unknown') {
            $pdo->exec("UPDATE queue_v4_clean_control SET readiness_state='FAILED'");
        } elseif ($state !== 'healthy') {
            throw new HttpException(422, 'Estado de salud local inválido.');
        }
    };

    try {
        if ($path === '/__fixture/reset') {
            $pdo->exec('DELETE FROM app_settings');
            $setHealth((string) ($_GET['health'] ?? 'healthy'));
            $_SESSION = [];
            $authenticate(false);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'fixture' => 'cap2-browser-config'], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($path === '/__fixture/health') {
            $setHealth((string) ($_GET['state'] ?? 'healthy'));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($path === '/__fixture/session') {
            $kind = (string) ($_GET['kind'] ?? 'admin');
            $_SESSION = [];
            if ($kind === 'admin') {
                $authenticate(false);
            } elseif ($kind === 'temporary') {
                $authenticate(true);
            } elseif ($kind !== 'anonymous') {
                throw new HttpException(422, 'Sesión local inválida.');
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'kind' => $kind], JSON_THROW_ON_ERROR);
            exit;
        }
        if ($path === '/login') {
            echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Ingreso</title><body><h1>Ingreso</h1></body></html>';
            exit;
        }

        $controller = new App\Controllers\SettingsController();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if ($path === '/settings/cron/call-budget') {
                $controller->saveCronCallBudget();
            } elseif ($path === '/settings/manual-processing/call-budget') {
                $controller->saveManualCallBudget();
            } else {
                throw new HttpException(404, 'Ruta local no encontrada.');
            }
            exit;
        }
        if ($path === '/settings/cron/rhythm') {
            $controller->cronRhythm();
            exit;
        }
        if ($path === '/settings/manual-processing') {
            $guard = new ReflectionMethod($controller, 'requireAdminPermanent');
            $guard->invoke($controller);
            View::render('settings/manual_processing', [
                'capacity' => (new CapacityPolicyService())->snapshot('manual'),
                'scope' => 'available_queue',
                'preview' => null,
                'campaignReady' => true,
                'emergencyStop' => false,
                'availableQueueCount' => 0,
                'manualResult' => null,
                'manualAvailableQueueResult' => null,
            ]);
            exit;
        }
        throw new HttpException(404, 'Ruta local no encontrada.');
    } catch (HttpException $error) {
        http_response_code($error->status);
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Error</title><body><main>'
            . View::e($error->publicMessage) . '</main></body></html>';
    } catch (Throwable $error) {
        http_response_code(500);
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Error</title><body><main>'
            . 'No fue posible completar la prueba local.' . '</main></body></html>';
        error_log('cap2_browser_fixture_error=' . get_class($error) . ':' . $error->getMessage());
    }
}
