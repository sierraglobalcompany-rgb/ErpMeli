<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=d6ff54d5907a631529e270f0c950ddbd5c1c0c42');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.38.9');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.0');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The metadata-only 2.38.9 to 2.39.0 update keeps schema 298. The periodic launcher accepts only queue_v4_clean.php and has no default or database authority lookup. Direct normal process_sync_queue execution always exits as LEGACY_AUTOMATION_RETIRED before business workers or HTTP, while its existing doctor, fake QA, retention and local maintenance paths remain available. It adds no migration, scheduler, capability, remote write or mutable production state.');

require __DIR__ . '/generate_updater_authority_2384.php';
