<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=99218574782e642e8ae3f9b5b95c8af531dcf56d');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.13');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.14');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.13 to 2.39.14 update keeps schema 299 and makes Queue V4 the sole visible automatic authority. It retires legacy launcher surfaces before bootstrap or writes, converts the web Cron check to a Queue V4 read-only preflight, and distinguishes remote HTTP 429 from local policy delays without changing Queue V4 FIFO, Billing 900s, OAuth, physical Cron, historical dates, or recovery state.');

require __DIR__ . '/generate_updater_authority_2384.php';
