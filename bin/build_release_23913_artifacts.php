<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=99218574782e642e8ae3f9b5b95c8af531dcf56d');
putenv('ERP_RELEASE_ID=erp-meli-2.39.13-billing-endpoint-isolation');
putenv('ERP_RELEASE_SEQUENCE=23913');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.12');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only que aísla Billing HTTP 429 por endpoint y aclara la observabilidad de transporte; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar el único Cron físico en queue_v4_clean.php --runtime=45 --max-jobs=3; esta actualización no crea ni reactiva Cron automáticamente, no cambia Billing 900s, no ejecuta recovery ni llama Mercado Libre durante instalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
