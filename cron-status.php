<?php

declare(strict_types=1);

require_once __DIR__ . '/launcher/entrypoint.php';
if (erp_dispatch_active_entrypoint(__DIR__, 'cron-status.php', true)) {
    return;
}

require __DIR__ . '/bootstrap.php';

use App\Core\Auth;
use App\Services\CronOperationalReadService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (!Auth::check() || Auth::role() !== 'admin' || Auth::isTemporary()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso administrativo requerido.'], JSON_UNESCAPED_UNICODE);
    return;
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$endpoint = basename($path);
$service = new CronOperationalReadService();

try {
    $payload = match ($endpoint) {
        'overview.json' => $service->overview(),
        'tasks.json' => (static function () use ($service): array {
            $rows = $service->tasks();
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(10, min(50, (int) ($_GET['per_page'] ?? 50)));
            return [
                'ok' => true,
                'version' => trim((string) @file_get_contents(__DIR__ . '/VERSION')),
                'page' => $page,
                'per_page' => $perPage,
                'total' => count($rows),
                'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            ];
        })(),
        'section.json' => (static function () use ($service): array {
            $tasks = $service->tasks();
            return [
                'ok' => true,
                'overview' => $service->overview($tasks),
                'tasks' => $tasks,
            ];
        })(),
        'run.json' => $service->run(trim((string) ($_GET['token'] ?? ''))),
        default => null,
    };
    if ($payload === null) {
        http_response_code(404);
        $payload = ['ok' => false, 'error' => 'No se encontró la lectura solicitada.'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'No se pudo comprobar esta sección. Puede reintentar sin bloquear el ERP.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
