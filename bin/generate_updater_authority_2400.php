<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=30a3a3f8705d40eb0e31e94ac4a4755ff19c6aa4');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.19');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.40.0');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.39.19/schema299 to 2.40.0/schema300 release installs the K10 smart manual drain canonical runtime, applies migration 300 exactly once, preserves Queue V4, FIFO, Billing pacing, physical Cron and production data, and never performs recovery, OAuth, Billing, Cron, or Mercado Libre HTTP during update.');

require __DIR__ . '/generate_updater_authority_2384.php';
