<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$failed = 0;
$phpCommand = escapeshellarg(PHP_BINARY);

// A compatibility matrix may intentionally start PHP with `-n` and explicit
// extensions. Preserve the "no php.ini" choice in lint subprocesses so an
// unrelated system php.ini cannot load DLLs from another PHP build.
if (php_ini_loaded_file() === false) {
    $phpCommand .= ' -n';
} else {
    $phpCommand .= ' -c ' . escapeshellarg((string) php_ini_loaded_file());
    $extensionDirectory = trim((string) ini_get('extension_dir'));
    if ($extensionDirectory !== '') {
        $phpCommand .= ' -d ' . escapeshellarg('extension_dir=' . $extensionDirectory);
    }
}

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $topLevel = explode('/', $relative, 2)[0];
    if (
        in_array($topLevel, [
            'vendor', 'releases', 'storage', 'graphify-out',
            '.release-staging', '.release-verify-22510', '.release-verify-2256',
            '.release-verify-2257', '.release-verify-2259', '.release-zip-verify-2259',
        ], true)
        || str_starts_with($topLevel, '.graphify-')
    ) {
        continue;
    }

    $output = [];
    $exitCode = 0;
    exec($phpCommand . ' -l ' . escapeshellarg($file->getPathname()), $output, $exitCode);
    if ($exitCode !== 0) {
        $failed++;
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
}

echo $failed > 0 ? "lint_failed={$failed}\n" : "lint_ok\n";
exit($failed > 0 ? 1 : 0);
