<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/logs/index.php');
$query = (string) file_get_contents($root . '/app/Services/LogQueryService.php');
$failures = [];

foreach (['context_json', 'response_json', 'raw_json'] as $unsafe) {
    if (str_contains($view, $unsafe)) {
        $failures[] = 'view_exposes_' . $unsafe;
    }
}
foreach (['response_json context_json', 'raw_json context_json', 'e.response_json'] as $unsafe) {
    if (str_contains($query, $unsafe)) {
        $failures[] = 'query_selects_' . str_replace([' ', '.'], '_', $unsafe);
    }
}

if (!str_contains($query, 'BusinessScopeContext') || !str_contains($query, 'namedAccountScope')) {
    $failures[] = 'account_scope_missing';
}

if ($failures !== []) {
    fwrite(STDERR, 'MANAGED_LOG_VIEW_SANITIZATION_2361=FAIL ' . implode(',', $failures) . PHP_EOL);
    exit(1);
}

echo 'MANAGED_LOG_VIEW_SANITIZATION_2361=PASS' . PHP_EOL;
