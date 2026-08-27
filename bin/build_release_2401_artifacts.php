<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=8e102e2711905a3dc9c9c4e62a4afce41adbfa1f');
putenv('ERP_RELEASE_ID=erp-meli-2.40.1-worker-cycle-control-single-truth');
putenv('ERP_RELEASE_SEQUENCE=24001');
putenv('ERP_RELEASE_UPGRADE_FROM=2.40.0');
putenv('ERP_RELEASE_SCHEMA=300');
putenv('ERP_RELEASE_MIGRATION_NOTE=schema 300 already present; 2.40.1 is code-only and does not add, rerun, or rewrite migrations.');
putenv('ERP_RELEASE_INSTRUCTION=Install only after ChatGPT final release audit. Preserve Queue V4 as the only automatic launcher, keep physical Cron unchanged, do not run recovery, OAuth, Billing, worker, or Mercado Libre HTTP during update.');
putenv('ERP_RELEASE_OVERLAY_LABEL=RELEASE_OVERLAY');

require __DIR__ . '/build_release_2384_artifacts.php';
