<?php

declare(strict_types=1);

putenv('ERP_RELEASE_ARTIFACT_VERSION=2.40.0');
putenv('ERP_RELEASE_ARTIFACT_UPGRADE_FROM=2.39.19');
putenv('ERP_RELEASE_ARTIFACT_MIGRATION=300_manual_drain_sessions_2_40_0.sql');
putenv('ERP_RELEASE_ARTIFACT_FORBIDDEN_MIGRATION_PREFIX=301_');
putenv('ERP_RELEASE_ARTIFACT_OVERLAY_LABEL=RELEASE_OVERLAY');

require __DIR__ . '/release_artifacts_2384.php';
