<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=24d8751aeeb0d9825f0ff3336dca3780d1e5dd58');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.11');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.12');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.11 schema 299 to 2.39.12 schema 299 update makes Queue V4 the sole rhythm-screen authority, normalizes configurable Billing 429 backoff values, and distinguishes remote HTTP 429 from local preventive deferral. It applies no migration, performs no recovery or replay, preserves Queue V4, OAuth, H1, H2, H3, Billing 900s, max-jobs physical configuration, FIFO, review/dead/completed state, tenant isolation, and retired legacy automation entrypoints.');

require __DIR__ . '/generate_updater_authority_2384.php';
