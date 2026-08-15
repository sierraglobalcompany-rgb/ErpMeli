<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=e7c80cccf15dc5323def4a89abdedc79c72248a1');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.2');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.3');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The 2.39.2 schema 298 to 2.39.3 schema 299 update applies only migration 299, extending queue_v4_clean_jobs.job_type with domain_exact. It admits only new tenant-exact financial recalc and reconciliation sources created by sales repair into the existing Queue V4 FIFO. It preserves all historical backlog, Queue V4 state, OAuth, inventory, Cron capacity and fail-closed legacy boundaries.');

require __DIR__ . '/generate_updater_authority_2384.php';
