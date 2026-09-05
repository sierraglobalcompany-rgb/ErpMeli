<?php
declare(strict_types=1);
// Local fixture only: real templates, controller confirmation, policy and isolated MariaDB.
// Start with APP_ENV=test ML_WRITE_ENABLED=false DB_HOST=127.0.0.1 DB_PORT=33079
// DB_NAME=erp_meli_k1d_test_capacity_browser DB_USER=root DB_PASS= SESSION_SECURE=false
// APP_URL=http://127.0.0.1:8097 php -S 127.0.0.1:8097 tests/capacity_browser_router.php
namespace App\Core {
    final class Auth {
        public static function requireRole(string $role): void {
            if (($_SESSION['fixture_role'] ?? 'admin') !== 'admin') throw new HttpException(403, 'Sólo administrador.');
        }
        public static function isTemporary(): bool { return !empty($_SESSION['fixture_temporary']); }
        public static function id(): int { return 7; }
    }
    final class View {
        public static function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        public static function asset(string $base, string $path): string { return '/assets/' . $path; }
        public static function render(string $view, array $data = [], bool $layout = true): void {
            $base = dirname(__DIR__);
            echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Capacidad · fixture local</title><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/ux.css"><body><main class="page-content">';
            echo '<nav><a href="/settings/cron/rhythm">Automático</a> · <a href="/settings/manual-processing">Manual</a></nav><p>QA local · salud simulada · no existe ejecución remota</p>';
            foreach (['success', 'error'] as $key) if ($message = Session::flash($key)) echo '<div role="status" class="alert ' . self::e($key) . '">' . self::e($message) . '</div>';
            extract($data, EXTR_SKIP);
            require dirname(__DIR__) . '/app/Views/' . $view . '.php';
            echo '</main></body></html>';
        }
    }
}
namespace App\Services {
    final class ApiHealthAccessScope { public function snapshot(): array { return ['company_ids' => [1], 'account_ids' => [1]]; } }
    final class ApiHealthService { public function incidents(array $filter, int $limit): array { return ($_SESSION['fixture_health'] ?? '') === '429' ? [['transport_class' => 'REMOTE_HTTP_429']] : []; } }
    final class SafeErrorPresenter { public static function message(\Throwable $error, string $fallback, array $context = []): string { return $error instanceof \PDOException ? $fallback : $error->getMessage(); } }
}
namespace App\QueueV4Clean {
    final class QueueV4CleanHealthSnapshotService {
        public function __construct(\PDO $pdo) {}
        public function snapshot(?int $account, array $companies, array $accounts): array {
            $health = $_SESSION['fixture_health'] ?? 'healthy';
            return ['state' => $health === 'unknown' ? 'unavailable' : 'healthy', 'totals' => ['dead' => $health === 'dead' ? 1 : 0, 'stale_running' => $health === 'stale' ? 1 : 0]];
        }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';
    K1dSafeTestDatabase::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), (string) getenv('DB_HOST'), (string) getenv('DB_NAME'));
    if (PHP_SAPI !== 'cli-server' || getenv('DB_PORT') !== '33079' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Local fixture only'); }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (in_array($path, ['/assets/app.css', '/assets/ux.css'], true)) { header('Content-Type: text/css'); readfile(__DIR__ . '/../public' . $path); exit; }
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    $pdo = $harness->pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    App\Core\Session::start();
    if (isset($_GET['health'])) $_SESSION['fixture_health'] = $_GET['health'];
    if (isset($_GET['role'])) $_SESSION['fixture_role'] = $_GET['role'];
    if (isset($_GET['temporary'])) $_SESSION['fixture_temporary'] = $_GET['temporary'] === '1';
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $controller = new App\Controllers\SettingsController();
            if ($path === '/settings/cron/call-budget') $controller->saveCronCallBudget();
            elseif ($path === '/settings/manual-processing/call-budget') $controller->saveManualCallBudget();
            else throw new App\Core\HttpException(403, 'Procesamiento deshabilitado en fixture.');
        } elseif ($path === '/settings/manual-processing') {
            App\Core\View::render('settings/manual_processing', ['capacity' => (new App\Services\CapacityPolicyService())->snapshot('manual'), 'scope' => 'available_queue', 'preview' => null, 'campaignReady' => true]);
        } else {
            App\Core\View::render('settings/api_workload', ['capacity' => (new App\Services\CapacityPolicyService())->snapshot('automation'), 'rhythm' => []]);
        }
    } catch (App\Core\HttpException $error) { http_response_code($error->status); echo App\Core\View::e($error->publicMessage); }
}
