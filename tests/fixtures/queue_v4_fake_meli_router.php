<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/users/me') {
    http_response_code(404);
    echo '{}';
    return;
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer local-http-access-([123])$/D', $authorization, $match) !== 1) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"message":"unauthorized"}';
    return;
}

header('Content-Type: application/json');
echo json_encode(['id' => 1000 + (int) $match[1]], JSON_UNESCAPED_SLASHES);
