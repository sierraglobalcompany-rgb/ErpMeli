<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=99218574782e642e8ae3f9b5b95c8af531dcf56d');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.12');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.13');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.12 schema 299 to 2.39.13 schema 299 update isolates durable remote HTTP 429 blocks to their endpoint, preserves account-level adaptive pacing without a cross-endpoint hard stop, and exposes mutually exclusive remote versus local transport classes. It applies no migration, recovery, replay, Cron change, or Mercado Libre call during installation; it preserves Queue V4, OAuth, Billing 900s, FIFO, tenant isolation, review/dead/completed state, and retired legacy automation entrypoints.');

require __DIR__ . '/generate_updater_authority_2384.php';
