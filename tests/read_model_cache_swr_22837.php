<?php

declare(strict_types=1);

$temporaryRoot = sys_get_temp_dir() . '/erp-meli-cache-22837-' . bin2hex(random_bytes(5));
if (!mkdir($temporaryRoot . '/storage/cache/read-models', 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('No se pudo preparar la carpeta temporal.');
}
define('ERP_RELEASE_ROOT', $temporaryRoot);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $cache = new App\Services\ReadModelCacheService();
    $calls = 0;
    $first = $cache->rememberArray('qa-22837', 'same-key', 1, static function () use (&$calls): array {
        $calls++;
        return ['generation' => $calls];
    });
    $second = $cache->rememberArray('qa-22837', 'same-key', 1, static function () use (&$calls): array {
        $calls++;
        return ['generation' => $calls];
    });
    if ($first['value']['generation'] !== 1
        || $second['value']['generation'] !== 1
        || $calls !== 1) {
        throw new RuntimeException('Una lectura fresca volvió a ejecutar el resolver.');
    }

    sleep(2);
    $shouldFail = getenv('ERP_CACHE_QA_ALLOW_REFRESH') !== '1';
    $refreshCalls = 0;
    $stale = $cache->rememberArray('qa-22837', 'same-key', 1, static function () use ($shouldFail, &$refreshCalls): array {
        $refreshCalls++;
        if ($shouldFail) {
            throw new RuntimeException('fallo controlado');
        }
        return ['generation' => 2];
    });
    if ($refreshCalls !== 1 || !str_starts_with((string) $stale['cache'], 'stale-error')) {
        throw new RuntimeException('El fallo de refresco no conservó el último valor válido.');
    }
    echo "PASS read_model_cache_swr_22837\n";
} finally {
    $files = glob($temporaryRoot . '/storage/cache/read-models/*') ?: [];
    foreach ($files as $file) {
        if (str_starts_with(realpath($file) ?: '', realpath($temporaryRoot) ?: $temporaryRoot)) {
            @unlink($file);
        }
    }
    @rmdir($temporaryRoot . '/storage/cache/read-models');
    @rmdir($temporaryRoot . '/storage/cache');
    @rmdir($temporaryRoot . '/storage');
    @rmdir($temporaryRoot);
}
