<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=9038524148f3f161f9b9eabe3aca64aa9e1872ca');
putenv('ERP_RELEASE_ID=erp-meli-2.38.5-queue-v4-transport-sales-api-health');
putenv('ERP_RELEASE_SEQUENCE=23805');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.4');
putenv('ERP_RELEASE_SCHEMA=297');
putenv('ERP_RELEASE_MIGRATION_NOTE=se aplica únicamente la migración técnica 297');
putenv('ERP_RELEASE_INSTRUCTION=Mantenga el Cron ausente hasta completar la verificación postinstalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
