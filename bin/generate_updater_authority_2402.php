<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=4ee861483f26422eb70c1ded083b45178c088a2a');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.40.1');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.40.2');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.40.1/schema300 to 2.40.2/schema300 release repairs the operator control plane visibility layer, binds Smart Manual Drain exact execution to the existing session global lease, keeps one global drainer authority, Queue V4, FIFO, Billing pacing, Smart Manual Drain schema, physical Cron and production data unchanged, and never performs recovery, OAuth, Billing, Cron, or Mercado Libre HTTP during update.');

require __DIR__ . '/generate_updater_authority_2384.php';
