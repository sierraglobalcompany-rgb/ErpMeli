<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;

final class ModuleView
{
    public static function render(string $moduleId, string $view, array $data = []): void
    {
        $directories = [
            'meli-insights' => 'MeliInsights',
            'meli-growth' => 'MeliGrowth',
            'meli-ads' => 'MeliAds',
            'meli-postsale' => 'MeliPostSale',
            'meli-logistics' => 'MeliLogistics',
        ];
        $directory = $directories[$moduleId] ?? '';
        $file = dirname(__DIR__, 2) . '/Modules/' . $directory . '/Views/' . ltrim($view, '/\\') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Vista del módulo no encontrada.');
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();
        require dirname(__DIR__, 2) . '/Views/layouts/app.php';
    }
}
