<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=2f5170a44f697d6ef6bf3afd48ce3523d7e13cbe');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.1');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.2');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The metadata-only 2.39.1 to 2.39.2 update keeps schema 298 and adds no migration 299. It stops new admission for six orphan legacy capabilities while preserving certified exact manual paths and all existing backlog. Queue V4 Clean, Cron capacity, remote writes, finance, reviews, campaign 6, health and performance remain unchanged.');

require __DIR__ . '/generate_updater_authority_2384.php';
