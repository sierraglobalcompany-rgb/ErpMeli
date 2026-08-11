<?php

declare(strict_types=1);

$dsn = getenv('ERP_2363_READY_DSN');
if (!is_string($dsn) || $dsn === '') {
    exit(2);
}

try {
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_PERSISTENT => false,
    ]);
    exit((int) $pdo->query('SELECT 1')->fetchColumn() === 1 ? 0 : 3);
} catch (Throwable) {
    exit(1);
}
