<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=0ce4afe5269eb282734e36a0913fb2bf0f871088');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.38.7');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.38.8');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The metadata-only 2.38.7 to 2.38.8 update keeps schema 297. It adds one bounded exact Sales Repair step to the existing Queue V4 scheduler, consumes only source_kind exact, imports through syncOrderByIdForQueueV4Clean, shares the cycle HTTP and claimed-work budgets, and defers temporary OAuth or rate limits without consuming an item attempt. It creates no job automatically, no migration, no scheduler and no Mercado Libre write, and never packages mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
