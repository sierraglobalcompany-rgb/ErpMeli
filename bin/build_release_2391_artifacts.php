<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=aa755117fadcd499908a7640724c639f5600b07c');
putenv('ERP_RELEASE_ID=erp-meli-2.39.1-legacy-reactivation-fail-closed');
putenv('ERP_RELEASE_SEQUENCE=23901');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.0');
putenv('ERP_RELEASE_SCHEMA=298');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización metadata-only; no existe migración 299');
putenv('ERP_RELEASE_INSTRUCTION=Instale 2.39.1 y conserve exactamente un Cron Hostinger apuntando a jobs/queue_v4_clean.php; V3 y Queue Core legacy no pueden reactivarse.');

require __DIR__ . '/build_release_2384_artifacts.php';
