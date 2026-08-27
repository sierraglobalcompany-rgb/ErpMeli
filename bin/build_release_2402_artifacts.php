<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=4ee861483f26422eb70c1ded083b45178c088a2a');
putenv('ERP_RELEASE_ID=erp-meli-2.40.2-operator-control-plane-kiss');
putenv('ERP_RELEASE_SEQUENCE=24002');
putenv('ERP_RELEASE_UPGRADE_FROM=2.40.1');
putenv('ERP_RELEASE_SCHEMA=300');
putenv('ERP_RELEASE_MIGRATION_NOTE=schema 300 already present; 2.40.2 is code-only and does not add, rerun, or rewrite migrations.');
putenv('ERP_RELEASE_INSTRUCTION=Install only after ChatGPT final release audit. Preserve Queue V4 as the only automatic launcher, keep physical Cron unchanged, do not run recovery, OAuth, Billing, worker, or Mercado Libre HTTP during update.');
putenv('ERP_RELEASE_OVERLAY_LABEL=RELEASE_OVERLAY');
putenv('ERP_RELEASE_UPDATE_PACKAGE_EXCLUDE_UNSAFE_DOTFILES=1');

require __DIR__ . '/build_release_2384_artifacts.php';
