<?php

declare(strict_types=1);

$temporary = sys_get_temp_dir() . '/erp-meli-spool-' . bin2hex(random_bytes(6));
define('ERP_SHARED_ROOT', $temporary);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\WebhookSpoolService;

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $remove($path . '/' . $entry);
    }
    @rmdir($path);
};

try {
    $directory = $temporary . '/storage/spool/mercadolibre-webhooks';
    if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible preparar el spool temporal.');
    }
    $line = json_encode([
        'spooled_at' => gmdate(DATE_ATOM),
        'payload' => ['topic' => 'orders_v2', 'resource' => '/orders/1'],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    file_put_contents($directory . '/webhooks-fixture.jsonl', str_repeat((string) $line, 100));

    $service = new WebhookSpoolService();
    if ($service->pendingCount() !== 100) {
        throw new RuntimeException('El conteo inicial del spool no coincide.');
    }
    if (!$service->append(json_encode(['topic' => 'orders_v2', 'resource' => '/orders/2']) ?: '{}')) {
        throw new RuntimeException('No fue posible anexar el evento de prueba.');
    }
    if ($service->pendingCount() !== 101) {
        throw new RuntimeException('El contador persistente no se incrementó exactamente una vez.');
    }

    $result = $service->replay(20, microtime(true) - 1.0);
    if ((int) ($result['processed'] ?? -1) !== 0 || $service->pendingCount() !== 101) {
        throw new RuntimeException('Un deadline vencido no debe consumir eventos.');
    }

    file_put_contents($directory . '/.pending-count.json', json_encode([
        'count' => 999,
        'reconciled_at' => time() - 600,
    ], JSON_UNESCAPED_SLASHES));
    if ($service->pendingCount() !== 101) {
        throw new RuntimeException('La reconciliación periódica no corrigió un contador obsoleto.');
    }

    fwrite(STDOUT, "PASS webhook_spool_bounded_22811\n");
} finally {
    $remove($temporary);
}
