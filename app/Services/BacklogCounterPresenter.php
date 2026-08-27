<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Vocabulario único para que Cron, Procesar ahora y Salud API no mezclen
 * trabajo ejecutable con esperas de política o deuda que requiere revisión.
 */
final class BacklogCounterPresenter
{
    public const ACTIONABLE_NOW = 'ACTIONABLE_NOW';
    public const WAITING_POLICY = 'WAITING_POLICY';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const ERROR = 'ERROR';
    public const NON_ACTIONABLE = 'NON_ACTIONABLE';

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::ACTIONABLE_NOW => 'Listo ahora',
            self::WAITING_POLICY => 'Esperando protección',
            self::REVIEW_REQUIRED => 'Requiere revisión',
            self::ERROR => 'Con error',
            self::NON_ACTIONABLE => 'No accionable ahora',
        ];
    }

    public static function label(string $bucket): string
    {
        return self::labels()[$bucket] ?? self::labels()[self::NON_ACTIONABLE];
    }
}
