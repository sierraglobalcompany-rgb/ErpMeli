<?php

declare(strict_types=1);

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$static = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . $path;
if ($path !== '/' && is_file($static)) {
    return false;
}

$port = (int) ($_SERVER['SERVER_PORT'] ?? 8101);
putenv('APP_URL=http://127.0.0.1:' . $port);
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('PAUSE_MELI_API=true');
putenv('PAUSE_ERP_AUTOMATION=true');

require dirname(__DIR__) . '/public/index.php';
