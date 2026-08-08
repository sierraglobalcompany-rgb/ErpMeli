<?php

declare(strict_types=1);

namespace App\Core;

/** Construye enlaces del ERP conservando el subdirectorio configurado en APP_URL. */
final class InternalUrl
{
    public static function to(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $configured = rtrim(trim(Env::get('APP_URL', '')), '/');
        if ($configured === '') {
            return $path;
        }

        return $configured . $path;
    }
}
