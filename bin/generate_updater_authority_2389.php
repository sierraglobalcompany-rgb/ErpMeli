<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=93ca572501e13124ae5fdb271e7a02a102b9147b');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.38.8');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.38.9');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.38.8 to 2.38.9 update adds migration 298, whose only schema change extends the existing Queue V4 transport journal source_kind enum with sales_repair. Exact Sales Repair reuses the existing read fence, durable journal, cycle HTTP budget and states; pre-transport failures consume no item attempt, while physically uncertain GETs retry only in a later cycle. It creates no queue, table, column, job type, Cron or remote write and never packages mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
