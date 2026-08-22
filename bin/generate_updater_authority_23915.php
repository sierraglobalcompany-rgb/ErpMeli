<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=404d279a12b7541f765f7a79fce172bde9cb90f4');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.14');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.15');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.14 to 2.39.15 update version-controls the audited P1 legacy fail-closed and transport-truth amendment. It preserves schema 299, Queue V4 FIFO, Billing 900s, B429 policy, OAuth, physical Cron, historical dates, and recovery state; it applies neither migrations nor automatic backlog repair.');

require __DIR__ . '/generate_updater_authority_2384.php';
