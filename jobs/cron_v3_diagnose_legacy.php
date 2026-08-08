<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

\App\Core\Database::useProfile('diagnostic');

$limit = 50;
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $arg) {
    if (str_starts_with((string) $arg, '--limit=')) {
        $limit = max(1, min(200, (int) substr((string) $arg, 8)));
    }
}

$snapshot = (new \App\Services\CronV3OperationalSnapshotService())->snapshot();
$queues = array_values(array_filter(
    (array) ($snapshot['queues'] ?? []),
    static fn (mixed $row): bool => is_array($row)
        && in_array((string) ($row['capability_state'] ?? ''), ['waiting_capability', 'review_unsupported', 'legacy_readonly_backlog'], true)
));

$queues = array_slice($queues, 0, $limit);
echo json_encode([
    'ok' => true,
    'read_only' => true,
    'mode' => 'diagnose_legacy',
    'limit' => $limit,
    'count' => count($queues),
    'queues' => $queues,
    'message' => 'Diagnóstico local: no ejecuta Mercado Libre ni reprograma colas.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(0);
