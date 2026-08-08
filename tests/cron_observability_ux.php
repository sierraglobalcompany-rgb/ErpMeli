<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\InternalUrl;
use App\Services\WorkAttentionPresenter;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$root = dirname(__DIR__);

$previous = getenv('APP_URL');
putenv('APP_URL=https://www.example.test/erp-meli');
$_ENV['APP_URL'] = 'https://www.example.test/erp-meli';
$check(
    InternalUrl::to('/settings/cron') === 'https://www.example.test/erp-meli/settings/cron',
    'Las URLs internas deben conservar el subdirectorio configurado.'
);
$presented = (new WorkAttentionPresenter())->present([
    'queue_key' => 'order_enrichment',
    'source_id' => '19',
    'display_status' => 'error',
    'safe_error_message' => 'Shipment sin identificador',
    'meli_account_id' => 3,
    'is_api_task' => 1,
]);
$check(
    $presented['detail_url'] === 'https://www.example.test/erp-meli/settings/cron/work?queue_key=order_enrichment&source_id=19',
    'El enlace de detalle no puede perder /erp-meli.'
);
$check(
    $presented['context_url'] === 'https://www.example.test/erp-meli/orders?account_id=3',
    'El enlace de contexto debe conservar base path y cuenta.'
);
if ($previous === false) {
    putenv('APP_URL');
    unset($_ENV['APP_URL']);
} else {
    putenv('APP_URL=' . $previous);
    $_ENV['APP_URL'] = $previous;
}

$registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
$projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');
$nextView = (string) file_get_contents($root . '/app/Views/settings/automation_next.php');
$cronRead = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$cronView = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$apiView = (string) file_get_contents($root . '/app/Views/settings/api_health.php');

$check(
    preg_match('/ORDER BY[^\n]*LIMIT (?:200|500|1000)/', $registry) !== 1,
    'Una proyección no puede recortar silenciosamente la cola antes de contarla.'
);
$check(
    str_contains($projection, "'returned' => \$returned")
        && str_contains($projection, "'has_more' => \$hasMore")
        && str_contains($projection, "'truncated' => \$hasMore"),
    'La página debe declarar total, filas entregadas y si existen más resultados.'
);
$check(
    str_contains($queueView, 'Mostrando <?= count($rows) ?> de')
        && str_contains($queueView, 'Página <?= $currentPage ?> de <?= $totalPages ?>'),
    'La cola debe explicar cuántas filas muestra respecto al total y permitir navegar.'
);
$check(
    !str_contains($nextView, "\$base . \$item['attention']['detail_url']"),
    'Próxima ejecución no debe anteponer APP_URL a un enlace interno ya resuelto.'
);
foreach (['attempted_remote_calls', 'remote_calls', 'blocked_remote_calls'] as $metric) {
    $check(str_contains($cronRead, "'{$metric}'"), 'Falta telemetría de Cron: ' . $metric);
}
$check(
    str_contains($cronView, 'bloqueados antes de salir') && str_contains($apiView, 'Este bloque describe Cron.'),
    'Cron y Salud API deben separar transporte, automatización y disponibilidad.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_observability_ux\n");
