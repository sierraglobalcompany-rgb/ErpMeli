<?php

declare(strict_types=1);

/*
 * Disposable localhost router for Task 7 browser preparation.
 *
 * The production controller, Auth, CSRF/same-origin guard, preview service,
 * single-step launcher, queue adapters, handlers, SQL fences and templates are
 * used unchanged. Only the physical cURL wire is replaced by the existing test
 * boundary. The full schema is created once by cap2_browser_step_setup.php,
 * never from an HTTP request.
 */

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;
use App\Services\Cap2BrowserStepWire;
use App\Services\SessionGenerationService;
use App\Services\WorkQueueProjectionService;

const CAP2_BROWSER_STEP_ROOT = 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/browser-step';
const CAP2_BROWSER_STEP_INSTALL = CAP2_BROWSER_STEP_ROOT . '/install';
const CAP2_BROWSER_STEP_META = CAP2_BROWSER_STEP_ROOT . '/fixture-meta.json';
const CAP2_BROWSER_STEP_WIRE = CAP2_BROWSER_STEP_ROOT . '/wire.jsonl';

if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', CAP2_BROWSER_STEP_INSTALL);
}

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_browser_step_wire_fixture.php';

K1dSafeTestDatabase::assertGuard(
    (string) getenv('APP_ENV'),
    (string) getenv('ML_WRITE_ENABLED'),
    (string) getenv('DB_HOST'),
    (string) getenv('DB_NAME')
);
$dbName = (string) getenv('DB_NAME');
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (PHP_SAPI !== 'cli-server'
    || (string) getenv('DB_PORT') !== '33079'
    || preg_match('/^erp_meli_k1d_test_cap2_browser_step_[a-z0-9_]+$/', $dbName) !== 1
    || !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local fixture only.');
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}
if ($path === '/asset.php') {
    $asset = str_replace('\\', '/', rawurldecode((string) ($_GET['path'] ?? '')));
    $allowed = ['app.css', 'catalog.css', 'ux.css', 'performance.css', 'app.js', 'catalog.js', 'ux.js', 'performance.js', 'icons.svg'];
    if (!in_array($asset, $allowed, true)) {
        http_response_code(404);
        exit('Asset not found.');
    }
    $file = dirname(__DIR__) . '/public/assets/' . $asset;
    if (!is_file($file)) {
        http_response_code(404);
        exit('Asset not found.');
    }
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . match ($extension) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        default => 'application/octet-stream',
    });
    readfile($file);
    exit;
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=33079;dbname=' . $dbName . ';charset=utf8mb4',
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$pdo->exec("SET time_zone='+00:00'");
$schemaReady = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='manual_campaign_previews'"
)->fetchColumn() === 1;
if (!$schemaReady) {
    http_response_code(503);
    exit('Run the local CLI setup first.');
}
Database::setConnection($pdo);
Session::start();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
};
$meta = static function (): array {
    $decoded = json_decode((string) @file_get_contents(CAP2_BROWSER_STEP_META), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Fixture metadata is unavailable.');
    }
    return $decoded;
};
$authenticate = static function (): void {
    $_SESSION = [];
    $_SESSION['user'] = [
        'id' => 9107,
        'name' => 'CAP2 browser user',
        'email' => 'browser-step@example.invalid',
        'role' => 'admin',
        'is_temporary' => 0,
        'expires_at' => null,
        'session_generation' => (new SessionGenerationService())->current(),
    ];
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
};
$previewAccount = static function (string $token) use ($pdo): int {
    if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
        return 0;
    }
    $query = $pdo->prepare('SELECT meli_account_id FROM manual_campaign_previews WHERE preview_token=? LIMIT 1');
    $query->execute([$token]);
    return (int) ($query->fetchColumn() ?: 0);
};
$order = static function (int $sellerId): array {
    return [
        'id' => 940000 + $sellerId,
        'status' => 'paid',
        'date_created' => '2026-08-01T01:00:00Z',
        'last_updated' => '2026-08-01T01:00:00Z',
        'total_amount' => 10,
        'paid_amount' => 10,
        'currency_id' => 'COP',
        'buyer' => ['id' => 500],
        'seller' => ['id' => $sellerId],
        'shipping' => null,
        'pack_id' => null,
        'tags' => [],
        'order_items' => [],
        'payments' => [],
    ];
};

try {
    if ($path === '/__fixture/session') {
        $authenticate();
        $json(['ok' => true, 'fixture' => 'cap2-browser-step']);
    }
    if ($path === '/__fixture/meta') {
        $json(['ok' => true, 'sources' => $meta()]);
    }
    if ($path === '/__fixture/wire-count') {
        $lines = is_file(CAP2_BROWSER_STEP_WIRE)
            ? file(CAP2_BROWSER_STEP_WIRE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];
        $json(['ok' => true, 'count' => is_array($lines) ? count($lines) : 0]);
    }
    if ($path === '/__fixture/state') {
        $token = trim((string) ($_GET['token'] ?? ''));
        if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
            throw new HttpException(422, 'Token local inválido.');
        }
        $preview = $pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=? LIMIT 1');
        $preview->execute([$token]);
        $accountId = $previewAccount($token);
        $chunkQuery = $pdo->prepare(
            'SELECT id,status,cursor_offset,next_run_at FROM sync_batch_chunks WHERE meli_account_id=? ORDER BY id'
        );
        $chunkQuery->execute([$accountId]);
        $notificationQuery = $pdo->prepare(
            'SELECT id,status,resource_type,remote_resource_id AS resource_id
             FROM meli_notification_work_items WHERE meli_account_id=? ORDER BY id'
        );
        $notificationQuery->execute([$accountId]);
        $sources = $meta();
        $chunks = [];
        foreach ((array) ($sources['failure'] ?? []) as $key => $id) {
            if (!str_ends_with((string) $key, '_chunk_id')) {
                continue;
            }
            $chunk = $pdo->prepare('SELECT status,cursor_offset,next_run_at FROM sync_batch_chunks WHERE id=?');
            $chunk->execute([(int) $id]);
            $chunks[(string) $key] = $chunk->fetch() ?: null;
        }
        $json([
            'ok' => true,
            'preview_status' => (string) ($preview->fetchColumn() ?: ''),
            'account_id' => $accountId,
            'chunks' => $chunkQuery->fetchAll(),
            'notifications' => $notificationQuery->fetchAll(),
            'failure_chunks' => $chunks,
        ]);
    }
    if ($path === '/__fixture/failure-on') {
        $sources = $meta();
        $failureId = (int) ($sources['failure']['second_work_id'] ?? 0);
        if ($failureId < 1) {
            throw new RuntimeException('Failure source is unavailable.');
        }
        $pdo->exec('DROP TRIGGER IF EXISTS cap2_browser_step_fail_enqueue');
        $pdo->exec(
            "CREATE TRIGGER cap2_browser_step_fail_enqueue BEFORE INSERT ON queue_core_jobs FOR EACH ROW
             BEGIN
               IF NEW.queue_domain='manual' AND NEW.resource_type='notification_fallback' AND NEW.resource_id='" . $failureId . "' THEN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated browser-step checkpoint failure';
               END IF;
             END"
        );
        $json(['ok' => true]);
    }
    if ($path === '/__fixture/prepare-continuation') {
        $sources = $meta();
        $firstId = (int) ($sources['failure']['first_chunk_id'] ?? 0);
        $pdo->exec('DROP TRIGGER IF EXISTS cap2_browser_step_fail_enqueue');
        $statement = $pdo->prepare(
            'UPDATE sync_batch_chunks SET next_run_at=UTC_TIMESTAMP() WHERE id=?'
        );
        $statement->execute([$firstId]);
        if (!(new WorkQueueProjectionService())->refreshQueue('orders_sync')) {
            throw new RuntimeException('Continuation projection did not refresh.');
        }
        $json(['ok' => true]);
    }
    if ($path === '/shell/snapshot.json') {
        $json([
            'ok' => true,
            'companies' => [['id' => 9101, 'name' => 'CAP2 browser step']],
            'accounts' => [],
            'unread_notifications' => 0,
        ]);
    }
    if ($path === '/login') {
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Ingreso</title><body><h1>Ingreso</h1></body></html>';
        exit;
    }

    $accountId = 0;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $path === '/settings/manual-processing/start') {
        $accountId = $previewAccount(trim((string) ($_POST['preview_token'] ?? '')));
    }
    $sellerId = match ($accountId) {
        9111 => 99111,
        9114 => 99114,
        default => 99113,
    };
    Cap2BrowserStepWire::$responder = static function (string $url, string $wirePath) use ($order, $sellerId): array {
        if ($wirePath === '/orders/search') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $limit = max(1, (int) ($query['limit'] ?? 50));
            return [200, [
                'paging' => ['total' => 2, 'offset' => $offset, 'limit' => $limit],
                'results' => [$order($sellerId)],
            ]];
        }
        return match ($wirePath) {
            '/questions/93101' => [200, ['id' => 93101, 'text' => 'after checkpoint', 'status' => 'UNANSWERED', 'seller_id' => 99111]],
            '/questions/93402' => [200, ['id' => 93402, 'text' => 'continued after checkpoint failure', 'status' => 'UNANSWERED', 'seller_id' => 99114]],
            '/questions/93301' => [429, ['message' => 'protected', 'error' => 'protected', 'status' => 429]],
            '/questions/93302' => [200, ['id' => 93302, 'text' => 'must remain untouched', 'status' => 'UNANSWERED', 'seller_id' => 99113]],
            default => throw new RuntimeException('UNEXPECTED_WIRE_PATH:' . $wirePath),
        };
    };
    Cap2BrowserStepWire::$onWire = static function () use ($accountId): void {
        $last = Cap2BrowserStepWire::$calls[array_key_last(Cap2BrowserStepWire::$calls)] ?? [];
        $row = [
            'path' => (string) ($last['path'] ?? ''),
            'account_id' => $accountId,
            'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        file_put_contents(
            CAP2_BROWSER_STEP_WIRE,
            json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        usleep(2200000);
    };

    $controller = new App\Controllers\SettingsController();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ($path === '/settings/manual-processing/preview') {
            $controller->manualProcessingPreview();
        }
        if ($path === '/settings/manual-processing/start') {
            $controller->manualProcessingStart();
        }
        throw new HttpException(404, 'Ruta local no encontrada.');
    }
    if ($path === '/settings/manual-processing') {
        $controller->manualProcessing();
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
    error_log('cap2_browser_step_fixture_error=' . get_class($error) . ':' . $error->getMessage());
} finally {
    Cap2BrowserStepWire::$onWire = null;
    Cap2BrowserStepWire::$responder = null;
}
