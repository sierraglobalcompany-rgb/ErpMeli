<?php

declare(strict_types=1);

putenv('ERP_UPDATER_GENERATOR_BASE=8d68d505d5e47e3ba0e69e688da7064e772b889a');
putenv('ERP_UPDATER_GENERATOR_PREVIOUS=2.39.9');
putenv('ERP_UPDATER_GENERATOR_TARGET=2.39.10');
putenv('ERP_UPDATER_GENERATOR_CONTRACT=The code-only 2.39.9 schema 299 to 2.39.10 schema 299 update publishes only the audited V4 Bulk Parity convergence release metadata and packaging authority. It applies no migration, performs no automatic replay or backlog repair, preserves Queue V4, OAuth, H1, H2, H3, H4, B429, Billing 900s, max-jobs physical configuration, FIFO, review/dead/completed state and tenant isolation, and keeps every legacy automation entrypoint retired.');

require __DIR__ . '/generate_updater_authority_2384.php';
