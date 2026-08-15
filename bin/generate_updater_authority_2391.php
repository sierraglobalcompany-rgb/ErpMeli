<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=aa755117fadcd499908a7640724c639f5600b07c');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.0');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.1');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The metadata-only 2.39.0 to 2.39.1 update keeps schema 298 and adds no migration 299. Direct V3, legacy Queue Core cron and canary entrypoints retire before bootstrap; legacy engine control can only read or disable; historical status and sources remain read-only while mutations retire; administrative V3 mutations return HTTP 410. Queue V4 Clean stays byte-identical. It adds no scheduler, remote write or mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
