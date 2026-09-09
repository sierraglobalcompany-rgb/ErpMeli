<?php

declare(strict_types=1);

// Optional isolation for the calls verification runner. Production never loads
// this test bootstrap; existing independent fixtures retain their own roots.
$callsQaStorage = str_replace('\\', '/', (string) getenv('CALLS_QA_STORAGE_ROOT'));
if ($callsQaStorage !== '' && !defined('ERP_SHARED_ROOT')) {
    if (PHP_SAPI !== 'cli'
        || !str_starts_with($callsQaStorage, 'D:/Codex/tmp/erp-meli/calls-20260906/')
        || str_contains($callsQaStorage, '..')) {
        throw new RuntimeException('Invalid calls QA storage root.');
    }
    define('ERP_SHARED_ROOT', $callsQaStorage . '/' . getmypid());
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function k1b_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
