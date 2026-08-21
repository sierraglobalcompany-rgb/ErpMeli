<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=99203c97931aadc3c511cf2dc0d18cd0e12ae2d6');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.10');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.11');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.10 schema 299 to 2.39.11 schema 299 update publishes only the audited B429 configurable KISS policy. It applies no migration, performs no automatic replay or backlog repair, preserves Queue V4, OAuth, H1, H2, H3, H4, B429 durable breaker, Billing 900s, max-jobs physical configuration, FIFO, review/dead/completed state and tenant isolation, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
