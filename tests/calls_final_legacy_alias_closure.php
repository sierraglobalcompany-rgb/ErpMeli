<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\AutomationCallBudgetService;
use App\Services\AutomationCliCapacityArgumentParser;

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$tracked = [];
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(
    'git ls-files app jobs public',
    $descriptor,
    $pipes,
    $root
);
if (is_resource($process)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit === 0) {
        foreach (preg_split('/\R/', trim((string) $stdout)) ?: [] as $path) {
            if ($path !== '' && preg_match('/\.(?:php|js)$/', $path) === 1) {
                $tracked[] = $path;
            }
        }
    }
}

$assert($tracked !== [], 'runtime_tracked_files_discovered');

$legacyMatches = [];
$overrideMatches = [];
$maxJobsFieldMatches = [];
foreach ($tracked as $relative) {
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = (string) file_get_contents($absolute);
    foreach ([
        'legacy_max_jobs',
        'legacy_max_jobs_normalized_to_calls',
        'legacy_max_jobs_compat_input_used',
    ] as $needle) {
        if (str_contains($contents, $needle)) {
            $legacyMatches[] = $relative . ':' . $needle;
        }
    }
    if (str_contains($contents, 'LEGACY_MAX_JOBS_OVERRIDE')) {
        $overrideMatches[] = $relative;
    }
    if (preg_match('/[\'"]max_jobs[\'"]\s*(?:=>|=|\])|DEFAULT_MAX_JOBS|HARD_MAX_JOBS/', $contents) === 1) {
        $maxJobsFieldMatches[] = $relative;
    }
}

$assert($legacyMatches === [], 'runtime_legacy_max_jobs_data_fields_zero:' . json_encode($legacyMatches, JSON_UNESCAPED_SLASHES));
$assert($overrideMatches === [], 'legacy_max_jobs_override_sources_zero:' . json_encode($overrideMatches, JSON_UNESCAPED_SLASHES));
$assert($maxJobsFieldMatches === [], 'runtime_max_jobs_success_fields_zero:' . json_encode($maxJobsFieldMatches, JSON_UNESCAPED_SLASHES));

$budgetReflection = new ReflectionMethod(AutomationCallBudgetService::class, 'resolve');
$assert($budgetReflection->getNumberOfParameters() === 1, 'automation_budget_resolve_has_one_parameter');
$assert($budgetReflection->getParameters()[0]->getName() === 'cliMaxCalls', 'automation_budget_resolve_parameter_is_cli_max_calls');

$parser = new AutomationCliCapacityArgumentParser();
$parsed = $parser->parse(['max-calls' => '9']);
$assert($parsed === ['max_calls' => 9], 'parser_returns_only_max_calls');

$legacyRejected = false;
try {
    $parser->parse(['max-jobs' => '9']);
} catch (InvalidArgumentException $error) {
    $legacyRejected = $error->getMessage() === 'legacy_capacity_argument_removed';
}
$assert($legacyRejected, 'parser_rejects_legacy_max_jobs');

$command = '"' . PHP_BINARY . '" "' . $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'queue_v4_clean.php" --max-jobs=9';
$process = proc_open($command, $descriptor, $pipes, $root, ['APP_ENV' => 'test', 'ML_WRITE_ENABLED' => 'false']);
$stdout = '';
$stderr = '';
$exitCode = 1;
if (is_resource($process)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
}
$safeOutput = $stdout . $stderr;
$assert($exitCode === 2, 'cli_max_jobs_exit_code_2:' . $exitCode);
$assert(str_contains($safeOutput, 'unidad trabajos fue retirada'), 'cli_max_jobs_safe_message');
$assert(!str_contains($safeOutput, 'legacy_max_jobs'), 'cli_max_jobs_output_has_no_capacity_alias');

if ($failures !== []) {
    fwrite(STDERR, "FAIL calls_final_legacy_alias_closure\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "STATUS=PASS CALLS_FINAL_LEGACY_ALIAS_CLOSURE\n";
echo "RUNTIME_LEGACY_MAX_JOBS_DATA_FIELDS=0\n";
echo "RUNTIME_LEGACY_MAX_JOBS_PARAMETERS=0\n";
echo "LEGACY_MAX_JOBS_OVERRIDE_SOURCES=0\n";
echo "CLI_MAX_JOBS_EXIT_CODE=2\n";
echo "CLI_MAX_JOBS_SAFE_MESSAGE=PASS\n";
