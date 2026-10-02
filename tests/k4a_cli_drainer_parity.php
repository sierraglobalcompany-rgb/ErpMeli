<?php
declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
$root = dirname(__DIR__);
$dir = $root . '/storage/codex-k4a-cli-20261002/parity-' . getmypid();
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('fixture_directory_failed'); }

function k4aProcess(array $command, string $cwd, ?array $env = null): array {
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, $env);
    if (!is_resource($process)) { throw new RuntimeException('fixture_process_failed'); }
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
}
$base = k4aProcess(['git','show','d9c1c6801d0a8f8a8267ef177793b93b85a0e431:jobs/queue_v4_clean.php'], $root);
k1b_assert($base['exit'] === 0, 'base_authority_available');
$candidate = file_get_contents($root . '/jobs/queue_v4_clean.php');
$bootstrap = 'require ' . var_export(__DIR__ . '/k4a_cli_fixture.php', true) . ';';
foreach (['old'=>$base['stdout'],'new'=>$candidate] as $name=>$source) {
    k1b_assert(substr_count($source, "require dirname(__DIR__) . '/bootstrap.php';") === 1, 'exact_bootstrap_boundary');
    file_put_contents($dir . '/' . $name . '.php', str_replace("require dirname(__DIR__) . '/bootstrap.php';", $bootstrap, $source));
}
$cases = [
    'success'=>['success',[],0], 'scheduler_failed'=>['failed',[],1],
    'legacy_max_jobs'=>['success',['--max-jobs=2'],2],
    'explicit_calls'=>['success',['--max-calls=4'],0], 'default_calls'=>['success',[],0],
    'runtime_min'=>['success',['--runtime=1'],0], 'runtime_max'=>['success',['--runtime=99'],0],
    'exception'=>['exception',[],1], 'invalid_calls'=>['success',['--max-calls=invalid'],2],
    'unknown_physical_count'=>['unknown',[],0],
];
foreach ($cases as $name=>[$scenario,$args,$expectedExit]) {
    $runs=[]; $traces=[];
    foreach (['old','new'] as $mode) {
        $trace=$dir . '/' . $name . '-' . $mode . '.json';
        $env=getenv(); $env['K4A_SCENARIO']=$scenario; $env['K4A_TRACE']=$trace;
        $runs[$mode]=k4aProcess([PHP_BINARY,'-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
            '-d','allow_url_fopen=0',$dir . '/' . $mode . '.php',...$args],$root,$env);
        $traces[$mode]=is_file($trace) ? json_decode(file_get_contents($trace),true,512,JSON_THROW_ON_ERROR) : null;
    }
    k1b_assert($runs['old'] === $runs['new'], 'old_new_stdout_stderr_exit_' . $name);
    k1b_assert($runs['new']['exit'] === $expectedExit, 'expected_exit_' . $name);
    k1b_assert($traces['old'] === $traces['new'], 'scheduler_arguments_deadline_' . $name);
    k1b_assert(!str_contains($runs['new']['stdout'] . $runs['new']['stderr'],'private-token'), 'safe_diagnostic_' . $name);
    if ($expectedExit === 0) {
        $json=json_decode($runs['new']['stdout'],true,512,JSON_THROW_ON_ERROR);
        k1b_assert($json['physical_http_calls'] === ($scenario==='unknown' ? null : 3)
            && $json['worker'] === ['completed'=>2,'deferred'=>1], 'raw_metadata_retained_' . $name);
        k1b_assert($json['control_unit'] === 'PHYSICAL_API_CALL', 'physical_unit_' . $name);
        k1b_assert($traces['new']['calls'] === ($name==='explicit_calls' ? 4 : 10), 'requested_calls_' . $name);
        k1b_assert($traces['new']['runtime'] === ($name==='runtime_min' ? 5 : 45), 'runtime_clamp_' . $name);
    }
    echo 'PARITY_CASE=' . $name . ' PASS' . PHP_EOL;
}
k1b_assert(!str_contains($candidate,'new QueueV4CleanScheduler('), 'cli_direct_scheduler_dependency_removed');
k1b_assert(str_contains($candidate,'DrainerContract') && str_contains($candidate,'new QueueV4CurrentDrainer('), 'existing_drainer_boundary_used');
k1b_assert(str_contains($candidate,"->drain('cron_v4', \$requestedMaxCalls, \$runtime)->metadata"), 'raw_scheduler_result_no_dto_projection');
$expected=str_replace('use App\\QueueV4Clean\\QueueV4CleanScheduler;',
    "use App\\Work\\Adapters\\QueueV4CurrentDrainer;\nuse App\\Work\\Contracts\\DrainerContract;", str_replace("\r\n", "\n", $base['stdout']));
$expected=str_replace('    $result = (new QueueV4CleanScheduler(Database::connectionFresh()))->run($requestedMaxCalls, $runtime);',
    "    /** @var DrainerContract \$drainer */\n    \$drainer = new QueueV4CurrentDrainer(Database::connectionFresh());\n    \$result = \$drainer->drain('cron_v4', \$requestedMaxCalls, \$runtime)->metadata;", $expected);
k1b_assert($expected === str_replace("\r\n", "\n", $candidate), 'only_agreed_cli_substitution_no_other_semantic_change');
echo "K4A_CLI_DRAINER_PARITY=PASS\nNO_DATABASE_OR_HTTP=YES\n";
