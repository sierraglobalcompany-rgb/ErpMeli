<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=54b6280c6eb67bea21ed651fa68fffe965d49330');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.6');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.7');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.6 schema 299 to 2.39.7 schema 299 update publishes only H3 financial non-failure defer in SaleFinancialService. It applies no migration, preserves Queue V4, OAuth, H1, H2, Billing 900s, max-jobs, FIFO, review/dead/completed state and tenant isolation, performs no automatic replay or backlog repair, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
