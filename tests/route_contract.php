<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(str_repeat('r', 32)));
putenv('ML_WRITE_ENABLED=false');
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Router;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
$indexPath = $root . '/public/index.php';
$source = (string) file_get_contents($indexPath);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$imports = [];
preg_match_all('/^use\s+([^;]+);/m', $source, $useMatches);
foreach ($useMatches[1] ?? [] as $class) {
    $class = trim((string) $class);
    $parts = explode('\\', $class);
    $imports[(string) end($parts)] = $class;
}

preg_match_all(
    '/\$router->(get|post)\(\s*\'([^\']+)\'\s*,\s*\[([A-Za-z_][A-Za-z0-9_]*)::class\s*,\s*\'([^\']+)\'\]\s*\)/',
    $source,
    $routeMatches,
    PREG_SET_ORDER
);

$assert(count($routeMatches) >= 208, 'Se esperaban al menos 208 rutas registradas; encontradas: ' . count($routeMatches) . '.');
$seen = [];
foreach ($routeMatches as $route) {
    $method = strtoupper((string) $route[1]);
    $path = (string) $route[2];
    $shortClass = (string) $route[3];
    $handler = (string) $route[4];
    $key = $method . ' ' . $path;
    $assert(!isset($seen[$key]), 'Ruta duplicada: ' . $key . '.');
    $seen[$key] = true;
    $class = $imports[$shortClass] ?? '';
    $assert($class !== '' && class_exists($class), 'Controlador no encontrado para ' . $key . ': ' . $shortClass . '.');
    if ($class !== '' && class_exists($class)) {
        $assert(method_exists($class, $handler), 'Método no encontrado para ' . $key . ': ' . $class . '::' . $handler . '.');
        if (method_exists($class, $handler)) {
            $assert((new ReflectionMethod($class, $handler))->isPublic(), 'El handler no es público: ' . $class . '::' . $handler . '.');
        }
    }
}

$publicStart = strpos($source, "\$router->get('/catalogo/{slug}'");
$dispatchPosition = strrpos($source, '$router->dispatch(');
$assert($publicStart !== false && $dispatchPosition !== false && $publicStart < $dispatchPosition, 'Las rutas públicas de catálogo deben registrarse al final, antes del dispatch.');

foreach (glob($root . '/app/Controllers/*.php') ?: [] as $controllerPath) {
    $controllerSource = (string) file_get_contents($controllerPath);
    preg_match_all('/View::render\(\s*[\'"]([^\'"]+)[\'"]/', $controllerSource, $viewMatches);
    foreach ($viewMatches[1] ?? [] as $view) {
        $viewPath = $root . '/app/Views/' . str_replace('\\', '/', (string) $view) . '.php';
        $assert(is_file($viewPath), 'Vista no encontrada desde ' . basename($controllerPath) . ': ' . $view . '.');
    }
}

$router = new Router();
$router->get('/probe/{id}', static fn(array $params): string => 'dynamic:' . (string) ($params['id'] ?? ''));
$router->get('/probe/fixed', static fn(): string => 'exact');
$assert($router->dispatch('GET', '/probe/fixed') === 'exact', 'Una ruta exacta debe tener prioridad sobre una dinámica.');
$assert($router->dispatch('GET', '/probe/abc%20123') === 'dynamic:abc 123', 'La ruta dinámica debe decodificar parámetros.');

$missing = new Router();
ob_start();
$result = $missing->dispatch('GET', '/route-contract-missing');
$output = (string) ob_get_clean();
$assert($result === null && http_response_code() === 404 && str_contains($output, 'La página solicitada no existe.'), 'Una ruta inexistente debe responder 404 controlado.');

restore_error_handler();
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'route_contract_ok routes=' . count($routeMatches) . PHP_EOL;
