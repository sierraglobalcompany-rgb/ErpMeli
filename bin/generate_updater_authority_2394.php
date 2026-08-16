<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=3643d2d90b0aca6365acc1e8eeb597abd0c8896a');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.3');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.4');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.3 schema 299 to 2.39.4 schema 299 update adds F2B fresh-frontier isolation, bounded repair backpressure, persistent Billing rhythm containment, and Queue V4 fail-closed behavior when its rhythm authority is unavailable. It applies no migration, preserves all operational and business data, does not alter Cron capacity, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
