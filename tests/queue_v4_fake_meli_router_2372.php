<?php

declare(strict_types=1);

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$tokens = [
    'Bearer local-http-access-1' => 1001,
    'Bearer local-http-access-2' => 1002,
    'Bearer local-http-access-3' => 1003,
];

header('Content-Type: application/json; charset=utf-8');
if ($path !== '/users/me' || !isset($tokens[$authorization])) {
    http_response_code(404);
    echo "{\"error\":\"not_found\"}\n";
    return;
}

echo json_encode(['id' => $tokens[$authorization]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
