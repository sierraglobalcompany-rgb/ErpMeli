<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function managedReleaseGit(string $root, array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', $root], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'git_unavailable'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

$arguments = [];
foreach (array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $arguments[$key] = $value;
    }
}

$source = realpath((string) ($arguments['source'] ?? dirname(__DIR__)));
$ref = trim((string) ($arguments['ref'] ?? 'HEAD'));
$output = trim((string) ($arguments['output'] ?? ''));
$dryRun = ($arguments['dry-run'] ?? '0') === '1';
if ($source === false || !is_dir($source) || $ref === '') {
    fwrite(STDERR, "Invalid Git source or ref.\n");
    exit(2);
}
$top = managedReleaseGit($source, ['rev-parse', '--show-toplevel']);
if ($top['exit'] !== 0 || realpath(trim($top['stdout'])) !== $source) {
    fwrite(STDERR, "The source must be the exact Git toplevel.\n");
    exit(2);
}
$resolved = managedReleaseGit($source, ['rev-parse', '--verify', $ref . '^{commit}']);
$commit = strtolower(trim($resolved['stdout']));
if ($resolved['exit'] !== 0 || preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
    fwrite(STDERR, "The release ref does not resolve to a commit.\n");
    exit(2);
}

try {
    $version = trim(ManagedRuntimePublicationPolicy::gitBlob($source, $commit, 'VERSION'));
    if (!hash_equals(ManagedRuntimePublicationPolicy::VERSION, $version)) {
        throw new RuntimeException('Release VERSION does not match publication policy.');
    }
    $manifest = json_decode(
        ManagedRuntimePublicationPolicy::gitBlob($source, $commit, 'resources/runtime-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $entries = ManagedRuntimePublicationPolicy::packageEntries($source, $commit);
    $files = [];
    foreach ($entries as $entry) {
        $files[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($source, $commit, $entry['path']);
    }
    $issues = ManagedRuntimePublicationPolicy::packageIssues($source, $manifest, $files, $commit);
    if ($issues !== []) {
        throw new RuntimeException('Git-exact package authority failed: ' . implode(',', $issues));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(3);
}

if ($dryRun) {
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'source' => 'GIT_OBJECT_DATABASE',
        'commit' => $commit,
        'version' => $version,
        'files' => count($files),
        'manifest_components' => count((array) ($manifest['components'] ?? [])),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

if ($output === '' || !str_ends_with(strtolower($output), '.zip') || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "A .zip output and the Zip extension are required.\n");
    exit(2);
}
$outputDirectory = realpath(dirname($output));
if ($outputDirectory === false || !is_dir($outputDirectory) || !is_writable($outputDirectory)) {
    fwrite(STDERR, "The output directory is unavailable.\n");
    exit(2);
}
$finalOutput = $outputDirectory . DIRECTORY_SEPARATOR . basename($output);
$temporary = $finalOutput . '.tmp-' . bin2hex(random_bytes(6));
$zip = new ZipArchive();
$check = null;
$zipOpen = false;
$checkOpen = false;
try {
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Unable to create the temporary archive.');
    }
    $zipOpen = true;
    $timestamp = (int) strtotime(ManagedRuntimePublicationPolicy::BUILT_AT);
    foreach ($entries as $entry) {
        $path = $entry['path'];
        if (!$zip->addFromString($path, $files[$path])) {
            throw new RuntimeException('Unable to add Git blob: ' . $path);
        }
        if (!$zip->setMtimeName($path, $timestamp)) {
            throw new RuntimeException('Unable to set deterministic archive time: ' . $path);
        }
        $mode = $entry['mode'] === '100755' ? 0100755 : 0100644;
        if (!$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, $mode << 16)) {
            throw new RuntimeException('Unable to set archive mode: ' . $path);
        }
    }
    if (!$zip->close()) {
        $zipOpen = false;
        throw new RuntimeException('Unable to finalize the archive.');
    }
    $zipOpen = false;

    $check = new ZipArchive();
    if ($check->open($temporary, ZipArchive::RDONLY) !== true || $check->numFiles !== count($entries)) {
        throw new RuntimeException('Archive integrity or file count verification failed.');
    }
    $checkOpen = true;
    $seen = [];
    for ($index = 0; $index < $check->numFiles; $index++) {
        $stat = $check->statIndex($index);
        $path = is_array($stat) ? (string) $stat['name'] : '';
        $bytes = $path !== '' ? $check->getFromIndex($index) : false;
        if (!isset($files[$path]) || !is_string($bytes)
            || !hash_equals(hash('sha256', $files[$path]), hash('sha256', $bytes))
            || isset($seen[strtolower($path)])) {
            throw new RuntimeException('Archive Git-blob verification failed.');
        }
        $seen[strtolower($path)] = true;
    }
    $check->close();
    $checkOpen = false;
    if (count($seen) !== count($files)) {
        throw new RuntimeException('Archive inventory verification failed.');
    }
    if (is_file($finalOutput) && !unlink($finalOutput)) {
        throw new RuntimeException('Unable to replace the existing output artifact.');
    }
    if (!rename($temporary, $finalOutput)) {
        throw new RuntimeException('Unable to publish the verified artifact.');
    }
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'source' => 'GIT_OBJECT_DATABASE',
        'commit' => $commit,
        'version' => $version,
        'files' => count($files),
        'sha256' => hash_file('sha256', $finalOutput),
        'output' => $finalOutput,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    if ($checkOpen) {
        @$check->close();
    }
    if ($zipOpen) {
        @$zip->close();
    }
    if (is_file($temporary)) {
        @unlink($temporary);
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(3);
}
