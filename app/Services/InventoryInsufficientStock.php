<?php

declare(strict_types=1);

namespace App\Services;

final class InventoryInsufficientStock extends \RuntimeException
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $warehouseId,
        public readonly int $productId,
        public readonly string $required,
        public readonly string $available,
    ) {
        parent::__construct('Inventario disponible insuficiente.');
    }
}
