<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=c4db6be5e41213a52f476dff03f099b10fae6624');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.18');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.19');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.18 to 2.39.19 update preserves schema 299, Queue V4 global primary FIFO, Billing 900 seconds, B429, OAuth, physical Cron, and historical finance dates. It adds same-tenant compatible financial extras only after the claimed primary and durable cardinality telemetry without migrations, recovery, or Mercado Libre HTTP.');

require __DIR__ . '/generate_updater_authority_2384.php';
