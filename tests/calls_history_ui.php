<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$_ENV['APP_URL']='https://local.invalid';
$page=2; $perPage=25; $total=100; $runs=[];
$history=['total'=>100,'runs'=>[]];
foreach (['calls_history','automation_history'] as $view) {
    ob_start();require __DIR__.'/../app/Views/settings/'.$view.'.php';$html=ob_get_clean();
    k1b_assert(str_contains($html,'page=1&amp;per_page=25'),'previous_page_loses_size:'.$view);
    k1b_assert(str_contains($html,'page=3&amp;per_page=25'),'next_page_loses_size:'.$view);
    if ($view==='automation_history') {
        k1b_assert(substr_count($html,'archive=legacy')===2,'archive_page_changes_engine');
        k1b_assert(!str_contains($html,'después de ejecutar Cron'),'archive_promises_future_execution');
    }
}
echo "PASS history pagination preserves size and explicit legacy archive\n";
