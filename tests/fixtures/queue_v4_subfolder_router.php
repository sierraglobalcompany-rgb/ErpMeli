<?php

declare(strict_types=1);

$documentRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$candidate = $documentRoot . $requestPath;

if ($requestPath !== '/' && is_file($candidate)) {
    return false;
}

$applicationRoot = $documentRoot . '/erp-meli';
if (!is_file($applicationRoot . '/index.php')) {
    http_response_code(500);
    echo 'local_subfolder_root_missing';
    return true;
}

require $applicationRoot . '/index.php';

