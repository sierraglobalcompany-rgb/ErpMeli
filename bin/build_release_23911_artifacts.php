<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=99203c97931aadc3c511cf2dc0d18cd0e12ae2d6');
putenv('ERP_RELEASE_ID=erp-meli-2.39.11-b429-configurable-kiss');
putenv('ERP_RELEASE_SEQUENCE=23911');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.10');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only B429 configurable; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar Cron físico en max-jobs=3; esta actualización no crea ni reactiva Cron automáticamente, no cambia Billing 900s, no ejecuta backfill, no repara backlog histórico y no llama Mercado Libre durante instalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
