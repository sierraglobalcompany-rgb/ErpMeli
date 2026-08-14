<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=b7bb9a33607cfe5725a02ac0a96dd0f41019ceeb');
putenv('ERP_RELEASE_ID=erp-meli-2.38.7-oauth-aware-sales-audit-hotfix');
putenv('ERP_RELEASE_SEQUENCE=23807');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.6');
putenv('ERP_RELEASE_SCHEMA=297');
putenv('ERP_RELEASE_MIGRATION_NOTE=no se agregan migraciones; schema 297 permanece intacto');
putenv('ERP_RELEASE_INSTRUCTION=Mantenga el Cron ausente, instale 2.38.7 y audite la reparación OAuth de Sales Audit antes de recrear una única tarea.');

require __DIR__ . '/build_release_2384_artifacts.php';
