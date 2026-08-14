<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=93ca572501e13124ae5fdb271e7a02a102b9147b');
putenv('ERP_RELEASE_ID=erp-meli-2.38.9-sales-repair-transport-authority-hotfix');
putenv('ERP_RELEASE_SEQUENCE=23809');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.8');
putenv('ERP_RELEASE_SCHEMA=298');
putenv('ERP_RELEASE_MIGRATION_NOTE=se agrega únicamente migration 298 para extender source_kind del journal existente con sales_repair');
putenv('ERP_RELEASE_INSTRUCTION=Mantenga el Cron ausente, instale 2.38.9, verifique schema 298 y audite un único ciclo antes de reanudar Exact Sales Repair.');

require __DIR__ . '/build_release_2384_artifacts.php';
