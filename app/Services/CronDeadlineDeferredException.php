<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * El ciclo CLI ya no conserva ventana suficiente para iniciar transporte.
 *
 * Esta condición es una espera operativa local, no un fallo del recurso ni de
 * Mercado Libre. El siguiente ciclo puede reclamar nuevamente el trabajo.
 */
final class CronDeadlineDeferredException extends RuntimeException
{
    public function __construct(
        string $message = 'El cron alcanzó su límite seguro antes de iniciar otra consulta API.',
        public readonly ?string $nextSafeAt = null,
        public readonly bool $reachedRemote = false
    ) {
        parent::__construct($message);
    }
}
