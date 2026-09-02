<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$nav = file_get_contents(__DIR__ . '/../app/Views/settings/_automation_nav.php');
$cron = file_get_contents(__DIR__ . '/../app/Views/settings/cron_shell.php');

k1b_assert(is_string($nav) && is_string($cron), 'Cron UI files must be readable.');
k1b_assert(substr_count($nav, "=> ['/settings/") === 5, 'Automation nav must expose at most five top tabs.');
k1b_assert(str_contains($cron, 'Centro de automatización'), 'Cron shell must read as an automation center.');
k1b_assert(str_contains($cron, 'Máximo de llamadas API por ciclo'), 'Cron shell must expose physical API call capacity.');
k1b_assert(str_contains($cron, 'queue_v4_clean.php --runtime=45 --max-calls=2'), 'Cron shell must expose max-calls command.');
k1b_assert(str_contains($cron, 'Pendientes ahora'), 'Cron shell must show pending state in human language.');
k1b_assert(str_contains($cron, 'Comando') && str_contains($cron, '--max-jobs=2'), 'Legacy max-jobs alias must remain only in advanced diagnostics.');

echo "STATUS=PASS K1C_CRON_STATUS_HUMAN_READINESS\n";

