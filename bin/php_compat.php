<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/app/Core/RuntimeCompatibility.php';

$failed = 0;
$warnings = 0;
$print = static function (string $status, string $message) use (&$failed, &$warnings): void {
    echo $status . ' ' . $message . PHP_EOL;
    if ($status === 'ERROR') {
        $failed++;
    } elseif ($status === 'WARN') {
        $warnings++;
    }
};

$runtime = \App\Core\RuntimeCompatibility::snapshot();
$print(($runtime['status'] ?? 'error') === 'ok' ? 'OK' : 'ERROR', 'PHP ' . (string) ($runtime['version'] ?? PHP_VERSION) . ' rango >=8.3 <8.6');
$print('OK', 'SAPI=' . PHP_SAPI);
$print('OK', 'PHP_BINARY=' . PHP_BINARY);
$print('OK', 'php.ini=' . (php_ini_loaded_file() ?: 'ninguno'));
$print('OK', 'INI adicionales=' . (php_ini_scanned_files() ?: 'ninguno'));

foreach (\App\Core\RuntimeCompatibility::requiredExtensions() as $extension) {
    $print(extension_loaded($extension) ? 'OK' : 'ERROR', 'ext-' . $extension);
}

$composerPath = $root . '/composer.json';
$composer = is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), true) : null;
if (!is_array($composer)) {
    $print('ERROR', 'composer.json ausente o inválido');
} else {
    $constraint = (string) ($composer['require']['php'] ?? '');
    $print($constraint === '>=8.3 <8.6' ? 'OK' : 'ERROR', 'composer require php=' . ($constraint !== '' ? $constraint : 'no detectado'));
    foreach (\App\Core\RuntimeCompatibility::requiredExtensions() as $extension) {
        $key = 'ext-' . $extension;
        $print(isset($composer['require'][$key]) ? 'OK' : 'ERROR', 'composer requiere ' . $key);
    }
    $print(isset($composer['require-dev']['phpstan/phpstan']) ? 'OK' : 'ERROR', 'composer require-dev phpstan/phpstan');
}
$print(is_file($root . '/composer.lock') ? 'OK' : 'ERROR', 'composer.lock');
if (is_file($root . '/phpstan.neon')) {
    $print('OK', 'phpstan.neon (herramienta opcional de desarrollo)');
} else {
    echo 'INFO phpstan.neon no está incluido en esta entrega de producción; no es requerido por el runtime.' . PHP_EOL;
}

/** @return list<string> */
$phpFiles = static function (string $base): array {
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($base) + 1));
        if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'storage/')) {
            continue;
        }
        $paths[] = $path;
    }
    sort($paths);
    return $paths;
};

/** @return array{calls:list<string>,fputcsv_args:list<int>,implicit_nullable:int} */
$tokenAudit = static function (string $contents): array {
    $calls = [];
    $fputcsvArgs = [];
    $implicitNullable = 0;
    $tokens = token_get_all($contents);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($token[1]);
        $next = $index + 1;
        while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            continue;
        }
        $previous = $index - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $previous--;
        }
        $previousToken = $tokens[$previous] ?? null;
        if (is_array($previousToken) && in_array($previousToken[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }
        $calls[] = $name;
        if ($name !== 'fputcsv') {
            continue;
        }
        $depth = 0;
        $arguments = 0;
        $hasContent = false;
        for ($cursor = $next; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            if ($part === '(') {
                $depth++;
                continue;
            }
            if ($part === ')') {
                $depth--;
                if ($depth === 0) {
                    $arguments = $hasContent ? $arguments + 1 : 0;
                    break;
                }
                continue;
            }
            if ($depth === 1 && $part === ',') {
                $arguments++;
                continue;
            }
            if ($depth === 1 && !(is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                $hasContent = true;
            }
        }
        $fputcsvArgs[] = $arguments;
    }

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || !in_array($token[0], [T_FUNCTION, T_FN], true)) {
            continue;
        }
        $open = $index + 1;
        while ($open < $count && $tokens[$open] !== '(') {
            $open++;
        }
        if ($open >= $count) {
            continue;
        }
        $depth = 0;
        $segment = '';
        for ($cursor = $open; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            $text = is_array($part) ? $part[1] : $part;
            if ($part === '(' || $part === '[' || $part === '{') {
                $depth++;
                if ($depth > 1) {
                    $segment .= $text;
                }
                continue;
            }
            if ($part === ')' || $part === ']' || $part === '}') {
                $depth--;
                if ($depth === 0) {
                    $segments = [$segment];
                } else {
                    $segment .= $text;
                    continue;
                }
            } elseif ($depth === 1 && $part === ',') {
                $segments = [$segment];
                $segment = '';
            } else {
                if ($depth >= 1) {
                    $segment .= $text;
                }
                continue;
            }
            foreach ($segments as $parameter) {
                if (
                    preg_match('/=\s*null\b/i', $parameter) !== 1
                    || preg_match('/^(.*?)\$[A-Za-z_][A-Za-z0-9_]*/s', $parameter, $matches) !== 1
                ) {
                    continue;
                }
                $type = trim((string) $matches[1]);
                $type = preg_replace('/\b(?:public|protected|private|readonly|static)\b/', '', $type) ?? $type;
                $type = trim(str_replace(['&', '...'], '', $type));
                if ($type !== '' && !str_contains($type, '?') && !preg_match('/(?:^|\|)\s*(?:null|mixed)\s*(?:\||$)/i', $type)) {
                    $implicitNullable++;
                }
            }
            if ($depth === 0) {
                break;
            }
        }
    }
    return ['calls' => $calls, 'fputcsv_args' => $fputcsvArgs, 'implicit_nullable' => $implicitNullable];
};

$forbiddenCalls = [
    'curl_close' => 'curl_close está obsoleto en PHP 8.5',
    'utf8_encode' => 'utf8_encode está obsoleto',
    'utf8_decode' => 'utf8_decode está obsoleto',
    'create_function' => 'create_function fue eliminado',
    'each' => 'each fue eliminado',
    'money_format' => 'money_format fue eliminado',
    'mysql_query' => 'mysql_query fue eliminado',
];
$patterns = [
    '/\bE_STRICT\b/' => 'E_STRICT no debe usarse en PHP moderno',
    '/trigger_error\s*\([^;]*\bE_USER_ERROR\b/s' => 'trigger_error con E_USER_ERROR es incompatible con la política moderna',
    '/\bclass_alias\s*\(\s*[\'"](array|callable)[\'"]/i' => 'class_alias no debe usar nombres reservados',
    '/\((?:boolean|integer|double|real|binary)\)/i' => 'cast no canónico deprecado en PHP 8.5',
];

foreach ($phpFiles($root) as $path) {
    if (realpath($path) === realpath(__FILE__)) {
        continue;
    }
    $contents = (string) file_get_contents($path);
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $audit = $tokenAudit($contents);
    foreach ($forbiddenCalls as $call => $description) {
        if (in_array($call, $audit['calls'], true)) {
            $print('ERROR', $description . ': ' . $relative);
        }
    }
    foreach ($audit['fputcsv_args'] as $argumentCount) {
        if ($argumentCount < 6) {
            $print('ERROR', 'fputcsv debe declarar separator, enclosure, escape y eol: ' . $relative);
        }
    }
    if ($audit['implicit_nullable'] > 0) {
        $print('ERROR', 'parámetro nullable implícito deprecado en PHP 8.4: ' . $relative);
    }
    foreach ($patterns as $pattern => $description) {
        if (preg_match($pattern, $contents) === 1) {
            $print('ERROR', $description . ': ' . $relative);
        }
    }
}

$ok = $failed === 0 && $warnings === 0;
echo $ok
    ? 'php_compat_ok warnings=0' . PHP_EOL
    : 'php_compat_failed errors=' . $failed . ' warnings=' . $warnings . PHP_EOL;
exit($ok ? 0 : 1);
