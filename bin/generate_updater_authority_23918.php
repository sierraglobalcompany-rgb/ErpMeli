<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=28bd74ecd0b528aaad2ffa3af11114dfbff5737c');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.17');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.18');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.17 to 2.39.18 update versionally promotes the audited finance admission and /erp-meli diagnostic-link fixes. It preserves schema 299, Queue V4 FIFO, Billing 900s, B429 endpoint isolation, OAuth, physical Cron, historical dates, historical orphan sources, and recovery state; it applies neither migrations nor automatic backlog repair.');

require __DIR__ . '/generate_updater_authority_2384.php';
