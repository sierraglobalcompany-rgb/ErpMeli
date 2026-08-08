<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$worker = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($service, 'AND last_run_token=:run_token'), 'La finalizacion legacy debe exigir el token propietario.');
$check(str_contains($service, 'return $finished->rowCount() === 1;'), 'Una finalizacion tardia debe detectarse por rowCount.');
$check(str_contains($worker, '$result, $runToken)'), 'El worker debe entregar el mismo token usado para reclamar.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK cron_task_generation_fence_2290\n";
