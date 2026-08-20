<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=609fea570d9268e7aea94374e5bac7d4cd95e73d');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.7');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.8');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.7 schema 299 to 2.39.8 schema 299 update publishes H4 pack integrity convergence in OrderSyncService. It applies no migration, performs no automatic historical backfill, preserves Queue V4, OAuth, H1, H2, H3, Billing 900s, max-jobs, FIFO, review/dead/completed state and tenant isolation, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
