<?php

declare(strict_types=1);

putenv('ERP_RELEASE_ARTIFACT_VERSION=2.39.4');
putenv('ERP_RELEASE_ARTIFACT_UPGRADE_FROM=2.39.3');
putenv('ERP_RELEASE_ARTIFACT_MIGRATION=299_queue_v4_domain_exact_admission_2_39_3.sql');
putenv('ERP_RELEASE_ARTIFACT_FORBIDDEN_MIGRATION_PREFIX=300_');
require __DIR__ . '/release_artifacts_2384.php';
