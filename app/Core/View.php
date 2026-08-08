<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = [], bool $layout = true): void
    {
        $file = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vista no encontrada: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();
        if (!$layout) {
            echo $content;
            return;
        }
        require dirname(__DIR__) . '/Views/layouts/app.php';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function asset(string $base, string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('La ruta del recurso visual no es válida.');
        }
        return rtrim($base, '/') . '/asset.php?path=' . rawurlencode($path);
    }
}
