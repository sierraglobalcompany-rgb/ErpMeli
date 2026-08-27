<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=8e102e2711905a3dc9c9c4e62a4afce41adbfa1f');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.40.0');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.40.1');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.40.0/schema300 to 2.40.1/schema300 release installs the Worker Cycle Control single-truth runtime, preserves Queue V4, FIFO, Billing pacing, Smart Manual Drain schema, physical Cron and production data, and never performs recovery, OAuth, Billing, Cron, or Mercado Libre HTTP during update.');

require __DIR__ . '/generate_updater_authority_2384.php';
