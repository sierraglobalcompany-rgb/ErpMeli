<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=785c657d178b484d377e429b22ae4506e8fadd14');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.4');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.5');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.4 schema 299 to 2.39.5 schema 299 update publishes only the independently audited H1 order_exact execution-context propagation. It applies no migration, preserves all operational and business data, does not alter Cron capacity, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
