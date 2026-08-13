<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'b8ac32ec3a668f06c36b9af86d3bd865142bb0f1';
$previousPath = $root . '/resources/release/updater-authority-2.38.2.json';
$targetPath = $root . '/resources/release/updater-authority-2.38.3.json';

/** @param list<string> $arguments */
$git = static function (array $arguments) use ($root): string {
    $pipes = [];
    $process = proc_open(
        array_merge(['git', '-C', $root], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException('git_failed:' . trim((string) $stderr));
    }
    return $stdout;
};

$blob = static fn (string $path): string => $git(['show', 'HEAD:' . $path]);
$previous = json_decode((string) file_get_contents($previousPath), true, 64, JSON_THROW_ON_ERROR);
$dependencyPaths = [];
foreach ((array) ($previous['new_runtime_dependencies'] ?? []) as $dependency) {
    $path = (string) ($dependency['path'] ?? '');
    if ($path !== '') {
        $dependencyPaths[$path] = true;
    }
}
$changed = preg_split('/\r?\n/', trim($git(['diff', '--name-only', $base . '..HEAD']))) ?: [];
foreach ($changed as $path) {
    if ($path === 'VERSION'
        || preg_match('#^(?:app|jobs)/.+\.php$#D', $path) === 1
        || preg_match('#^database/migrations/.+\.sql$#D', $path) === 1
        || preg_match('#^public/assets/.+$#D', $path) === 1
        || preg_match('#^resources/release/.+\.json$#D', $path) === 1) {
        $dependencyPaths[$path] = true;
    }
}
unset(
    $dependencyPaths['resources/runtime-manifest.json'],
    $dependencyPaths['resources/release/updater-authority-2.38.3.json'],
);
$dependencyPaths['resources/release/managed-runtime-dependencies-2.38.3.json'] = true;
ksort($dependencyPaths, SORT_STRING);
$dependencies = [];
foreach (array_keys($dependencyPaths) as $path) {
    $bytes = $blob($path);
    $dependencies[] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
}

$authority = [
    'schema_version' => 1,
    'target_version' => '2.38.3',
    'contract' => 'The 2.38.2 to 2.38.3 update applies only schema 296 for the Queue V4 automatic OAuth control plane. It preserves the one physical Cron, runs OAuth before Producer/Worker outside the commercial FIFO, caps each scheduler run at one physical OAuth POST, never retries a remotely uncertain refresh, performs no Mercado Libre business writes, and preserves updater state paths.',
    'supersedes_inventory' => $previous['supersedes_inventory'],
    'unchanged_locked' => $previous['unchanged_locked'],
    'intentional_locked_changes' => $previous['intentional_locked_changes'],
    'new_runtime_dependencies' => $dependencies,
];
$json = json_encode($authority, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($targetPath, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('updater_authority_write_failed');
}
fwrite(STDOUT, 'Updater authority 2.38.3 generated dependencies=' . count($dependencies) . PHP_EOL);
