<?php

declare(strict_types=1);

putenv('ERP_RELEASE_ARTIFACT_VERSION=2.38.8');
putenv('ERP_RELEASE_ARTIFACT_UPGRADE_FROM=2.38.7');
putenv('ERP_RELEASE_ARTIFACT_MIGRATION=297_queue_v4_transport_sales_api_health_2_38_5.sql');
putenv('ERP_RELEASE_ARTIFACT_FORBIDDEN_MIGRATION_PREFIX=298_');

require __DIR__ . '/release_artifacts_2384.php';
