<?php
declare(strict_types=1);

// Exercises only the local verification runner, never application services.
if (($argv[1] ?? '') === 'fail') {
    fwrite(STDOUT, "INTENTIONAL_FAILURE_STDOUT\n");
    fwrite(STDERR, "INTENTIONAL_VERIFICATION_FAILURE\n");
    exit(7);
}
if (($argv[1] ?? '') !== 'pass') {
    exit(2);
}
require __DIR__ . '/k1b_bootstrap.php';
if (!str_starts_with(str_replace('\\', '/', App\Core\AppPaths::storage()), 'D:/Codex/tmp/erp-meli/calls-20260906/')) {
    throw new RuntimeException('RUNNER_STORAGE_NOT_ON_D');
}
echo "CONTINUED_AFTER_FAILURE\n";
