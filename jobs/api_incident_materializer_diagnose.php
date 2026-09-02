<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApiIncidentMaterializerService;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

Database::useProfile('diagnostic');
$pdo = Database::connectionFresh();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();

try {
    $result = (new ApiIncidentMaterializerService())->diagnose($pdo);
    $pdo->rollBack();
    echo json_encode([
        'mode' => 'READ_ONLY',
        'production_mutations' => 0,
        'real_meli_http' => 0,
        'materializer' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "api_incident_materializer_diagnose_unavailable\n");
    exit(2);
}
