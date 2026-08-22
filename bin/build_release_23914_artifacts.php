<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=f72f258');
putenv('ERP_RELEASE_ID=erp-meli-2.39.14-v4-authority-legacy-truth');
putenv('ERP_RELEASE_SEQUENCE=23914');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.13');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only de autoridad Queue V4, retiro visible de lanzadores legacy y verdad de transporte HTTP; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, no cambia fechas históricas, no ejecuta recovery ni llama Mercado Libre.');

require __DIR__ . '/build_release_2384_artifacts.php';
