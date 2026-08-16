<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=432b2ce74d95c03809ea659a091890a6e02a79f9');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.5');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.6');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.5 schema 299 to 2.39.6 schema 299 update publishes only the independently audited H2 billing terminal HY093 parameter-binding correction in SaleFinancialService. It applies no migration, preserves Queue V4, OAuth, inventory, notification and finance state, performs no automatic replay or repair, does not alter Cron capacity, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
