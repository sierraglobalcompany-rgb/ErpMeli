<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=0fc9b222d4626b0121b08d98d51f69169a3082ee');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.16');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.17');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.16 to 2.39.17 update restores operational truth for API incidents and Cron risks, keeps the API incident read model fail-visible with a bounded direct fallback, and retires legacy queue launchers from the operational UI. It preserves schema 299, Queue V4 FIFO, Billing 900s, B429 endpoint isolation, OAuth, physical Cron, historical dates, and recovery state; it applies neither migrations nor automatic backlog repair.');

require __DIR__ . '/generate_updater_authority_2384.php';
