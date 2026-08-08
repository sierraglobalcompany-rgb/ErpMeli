<?php

declare(strict_types=1);

namespace App\Services;

interface WorkQueueAdapter
{
    public function key(): string;

    /** @return list<array<string,mixed>> */
    public function project(): array;

    /**
     * Indica si el último inventario se obtuvo realmente. Una lista vacía
     * saludable no es equivalente a una consulta fallida.
     */
    public function projectSucceeded(): bool;
}
