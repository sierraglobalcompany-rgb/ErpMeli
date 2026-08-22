<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=ac6cfadf008ed79e994d98ce6db942eecde2d7f2');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.15');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.16');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.15 to 2.39.16 update restores truthful API incident visibility by materializing bounded local incident work before the remote Queue V4 worker and refusing to render stale incident catalogues as zero. It preserves schema 299, Queue V4 FIFO, Billing 900s, B429 policy, OAuth, physical Cron, historical dates, and recovery state; it applies neither migrations nor automatic backlog repair.');

require __DIR__ . '/generate_updater_authority_2384.php';
