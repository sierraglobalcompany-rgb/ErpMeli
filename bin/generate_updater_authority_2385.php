<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=9038524148f3f161f9b9eabe3aca64aa9e1872ca');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.38.4');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.38.5');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.38.4 to 2.38.5 update applies only technical migration 297. It adds a tenant-fenced physical transport journal, a global ten-HTTP cycle budget, one-page sales-audit consumption, bounded safe-GET uncertainty recovery, and a Queue V4 materialized API-health read model. Safe deferrals and known 429 responses do not consume functional attempts. It performs no Mercado Libre writes, creates no scheduler, preserves Queue V4 activation state, and never packages mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
