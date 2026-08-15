<?php

declare(strict_types=1);

putenv('ERP_RELEASE_ARTIFACT_VERSION=2.39.2');
putenv('ERP_RELEASE_ARTIFACT_UPGRADE_FROM=2.39.1');
putenv('ERP_RELEASE_ARTIFACT_MIGRATION=298_queue_v4_sales_repair_transport_authority_2_38_9.sql');
putenv('ERP_RELEASE_ARTIFACT_FORBIDDEN_MIGRATION_PREFIX=299_');
require __DIR__ . '/release_artifacts_2384.php';
