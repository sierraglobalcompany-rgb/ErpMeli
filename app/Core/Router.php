<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\RouteMetadataRepository;

final class Router
{
    private array $routes = [];
    private array $dynamicRoutes = [];
    private array $definitions = [];

    public function __construct(
        private readonly ?Container $container = null,
        private readonly ?RouteMetadataRepository $metadata = null
    ) {
    }

    public function get(string $path, callable|array $handler, array $metadata = []): void { $this->add('GET', $path, $handler, $metadata); }
    public function post(string $path, callable|array $handler, array $metadata = []): void { $this->add('POST', $path, $handler, $metadata); }

    private function add(string $method, string $path, callable|array $handler, array $metadata = []): void
    {
        $metadata = array_merge($this->metadata?->for($method, $path) ?? [], $metadata);
        $this->definitions[] = compact('method', 'path', 'handler', 'metadata');
        if (str_contains($path, '{')) {
            $this->dynamicRoutes[$method][] = [
                'path' => $path,
                'regex' => $this->compile($path),
                'handler' => $handler,
                'metadata' => $metadata,
            ];
            return;
        }
        $this->routes[$method][$path] = ['handler' => $handler, 'metadata' => $metadata];
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $method = strtoupper($method);
        $path = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        $base = rtrim((string) parse_url(Env::get('APP_URL', ''), PHP_URL_PATH), '/');
        if ($base === '') {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $publicPosition = strpos($script, '/public/');
            if ($publicPosition !== false) {
                $base = rtrim(substr($script, 0, $publicPosition), '/');
            } else {
                $base = rtrim(str_replace('/index.php', '', $script), '/');
            }
        }
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if ($path === '/public/index.php' || $path === '/index.php') {
            $path = '/';
        }
        $exact = $this->routes[$method][$path] ?? null;
        $handler = is_array($exact) && array_key_exists('handler', $exact) ? $exact['handler'] : null;
        $metadata = is_array($exact) && array_key_exists('metadata', $exact) ? (array) $exact['metadata'] : [];
        $params = [];
        if ($handler === null) {
            foreach ($this->dynamicRoutes[$method] ?? [] as $route) {
                if (preg_match($route['regex'], $path, $matches) === 1) {
                    $handler = $route['handler'];
                    $metadata = (array) ($route['metadata'] ?? []);
                    foreach ($matches as $key => $value) {
                        if (is_string($key)) {
                            $params[$key] = rawurldecode((string) $value);
                        }
                    }
                    break;
                }
            }
        }
        if ($handler === null) {
            http_response_code(404);
            View::render('errors/404', [], false);
            return null;
        }
        $this->authorize($method, $metadata);
        if (is_array($handler)) {
            $controllerClass = $handler[0];
            $controller = is_string($controllerClass)
                ? ($this->container?->get($controllerClass) ?? new $controllerClass())
                : $controllerClass;
            return $params === [] ? $controller->{$handler[1]}() : $controller->{$handler[1]}($params);
        }
        return $params === [] ? $handler() : $handler($params);
    }

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    private function compile(string $path): string
    {
        $regex = '';
        $offset = 0;
        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => $match) {
                $literal = substr($path, $offset, $match[1] - $offset);
                $regex .= preg_quote($literal, '#');
                $regex .= '(?P<' . $matches[1][$index][0] . '>[^/]+)';
                $offset = $match[1] + strlen($match[0]);
            }
        }
        $regex .= preg_quote(substr($path, $offset), '#');
        return '#^' . $regex . '$#';
    }

    /** @param array<string,mixed> $metadata */
    private function authorize(string $method, array $metadata): void
    {
        if ($this->metadata === null || $metadata === []) {
            return;
        }

        if (($metadata['authentication'] ?? 'required') !== 'public') {
            Auth::requireLogin();
        }

        if (!empty($metadata['permanent_admin'])) {
            Auth::requireRole('admin');
            if (Auth::isTemporary()) {
                throw new HttpException(403, 'Esta acción requiere un administrador permanente.');
            }
        }

        if ($method === 'POST') {
            $this->assertSameOrigin();
        }

        if (!empty($metadata['csrf'])) {
            Csrf::validate($_POST['_token'] ?? null);
        }
    }

    private function assertSameOrigin(): void
    {
        if ($this->expectedOrigin() === null) {
            throw new HttpException(403, 'No se pudo comprobar el origen de la solicitud.');
        }
        SameOriginGuard::assertRequest(true);
    }

    private function expectedOrigin(): ?string
    {
        return SameOriginGuard::expectedOrigin();
    }
}
