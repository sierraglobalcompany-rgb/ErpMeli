<?php

declare(strict_types=1);

if (getenv('ERP_INVENTORY_CONTRACT_VERSION') === false) {
    putenv('ERP_INVENTORY_CONTRACT_VERSION=2.39.13');
}
require __DIR__ . '/inventory_contract_2384.php';
