<?php

declare(strict_types=1);

if (getenv('ERP_QUEUE_V4_CONTRACT_VERSION') === false) {
    putenv('ERP_QUEUE_V4_CONTRACT_VERSION=2.39.13');
}
require __DIR__ . '/queue_v4_clean_contract_2393.php';
