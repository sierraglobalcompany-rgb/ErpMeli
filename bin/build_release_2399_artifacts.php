<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=f41656558ddd334b9e49b9bd71f3525e61f995cb');
putenv('ERP_RELEASE_ID=erp-meli-2.39.9-billing-429-emergency-hotfix');
putenv('ERP_RELEASE_SEQUENCE=23909');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.8');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only B429; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física actual de Cron; esta actualización no crea ni reactiva Cron automáticamente, no cambia max-jobs, no ejecuta backfill, no repara backlog histórico y no llama Mercado Libre durante instalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
