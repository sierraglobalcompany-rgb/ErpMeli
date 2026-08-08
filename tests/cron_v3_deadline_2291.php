<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronDeadlineContext;
use App\Services\CronDeadlineDeferredException;
use App\Services\CronV3Cli;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class CronV3DeadlineProbe2291
{
    public static bool $seen = false;
}

if (!function_exists('job_try_lock')) {
    function job_try_lock(string $name): mixed
    {
        CronV3DeadlineProbe2291::$seen = $name === 'cron_v3_local'
            && CronDeadlineContext::active()
            && CronDeadlineContext::window() !== null
            && CronDeadlineContext::remainingSeconds() > 0;
        return null;
    }
}

$previousActive = getenv('CRON_V3_ENABLED');
putenv('CRON_V3_ENABLED=true');
ob_start();
$exitCode = CronV3Cli::run('local', ['cron_v3_local.php', '--runtime=17', '--max-items=1']);
$output = (string) ob_get_clean();
if ($previousActive === false) {
    putenv('CRON_V3_ENABLED');
} else {
    putenv('CRON_V3_ENABLED=' . $previousActive);
}

$payload = json_decode(trim($output), true);
$check($exitCode === 0, 'El lock ocupado debe terminar de forma segura.');
$check(CronV3DeadlineProbe2291::$seen, 'CronDeadlineContext debe estar activo antes de adquirir el lock.');
$check(is_array($payload) && ($payload['status'] ?? '') === 'already_running', 'La prueba no recorrió la frontera de lock esperada.');
$check(!CronDeadlineContext::active(), 'CronV3Cli debe limpiar CronDeadlineContext en finally.');

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3Cli.php');
$deadlineSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronDeadlineContext.php');
$runnerSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3Runner.php');
$startPosition = strpos($source, 'CronDeadlineContext::start(');
$lockPosition = strpos($source, "job_try_lock('cron_v3_' . \$lane)");
$runnerPosition = strpos($source, '->run($lane, $remainingRuntime, $maxItems, $shadow)');
$clearPosition = strpos($source, 'CronDeadlineContext::clear()');
$strictClosePosition = strpos($source, 'CronDeadlineContext::remainingSeconds() <= $safeCloseSeconds');
$legacyImportPosition = strpos($source, '$legacyImport =');
$check($startPosition !== false && $lockPosition !== false && $startPosition < $lockPosition,
    'El deadline debe iniciar antes del lock y de cualquier trabajo.');
$check($runnerPosition !== false && $startPosition !== false && $startPosition < $runnerPosition,
    'El deadline debe iniciar antes del runner.');
$check($clearPosition !== false && strpos($source, 'finally {') !== false,
    'La limpieza del deadline debe permanecer en finally.');
$check(str_contains($source, '$safeCloseSeconds') && str_contains($source, '$runtime - $safeCloseSeconds'),
    'La ventana debe reservar tiempo de cierre seguro dentro del runtime real.');
$check(str_contains($source, 'CronDeadlineContext::canAcceptWork()')
    && str_contains($source, '(int) floor(CronDeadlineContext::remainingSeconds())')
    && str_contains($source, '->run($lane, $remainingRuntime, $maxItems, $shadow)'),
    'El delay y el bootstrap deben descontarse del runtime entregado al runner.');
$check($strictClosePosition !== false && $legacyImportPosition !== false && $strictClosePosition < $legacyImportPosition,
    'El cierre seguro debe impedir iniciar incluso el importador legacy cuando queda poca ventana.');
$check(str_contains($deadlineSource, 'min($transportDeadline, $acceptUntil)')
    && str_contains($runnerSource, 'CronDeadlineContext::canAcceptWork(2)')
    && str_contains($runnerSource, 'CronDeadlineContext::assertCanStartRemote(2.0)'),
    'El runner y curl deben usar acceptUntil para impedir HTTP dentro del cierre seguro.');

CronDeadlineContext::start(4, 1, 8, 3);
usleep(1100000);
$check(!CronDeadlineContext::canAcceptWork(), 'acceptUntil agotado todavia permite reclamar trabajo.');
try {
    CronDeadlineContext::curlTimeouts();
    $check(false, 'curlTimeouts autorizo transporte dentro de la ventana de cierre.');
} catch (CronDeadlineDeferredException) {
    $check(true, 'El transporte se difirio al entrar en la ventana de cierre.');
} finally {
    CronDeadlineContext::clear();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS cron_v3_deadline_2291\n";
