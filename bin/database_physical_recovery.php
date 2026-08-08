<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

\App\Core\Database::useProfile('migration');
$arguments = $_SERVER['argv'] ?? [];
$table = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--table=')) {
        $table = substr($argument, 8);
    }
}
$service = new \App\Services\PhysicalTableRecoveryService();
if ($table !== null) {
    fwrite(
        STDERR,
        'RETIRED: prepare la tabla desde Configuración > Saneamiento. '
        . 'El único lanzador process_sync_queue.php ejecutará la solicitud exacta.'
        . PHP_EOL
    );
    exit(2);
}
$result = [
    'status' => 'diagnostic_only',
    'message' => 'Este comando solo presenta el plan; no reconstruye tablas.',
    'plan' => $service->plan(),
];
echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
