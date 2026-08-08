<?php

declare(strict_types=1);

use App\Services\QueryPlanAuditService;

require dirname(__DIR__) . '/bootstrap.php';

try {
    echo json_encode(
        (new QueryPlanAuditService())->auditAll(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'No fue posible ejecutar la auditoría de planes de consulta.' . PHP_EOL);
    exit(1);
}
