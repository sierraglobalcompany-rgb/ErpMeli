<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=30a3a3f8705d40eb0e31e94ac4a4755ff19c6aa4');
putenv('ERP_RELEASE_ID=erp-meli-2.40.0-k10-smart-manual-drain');
putenv('ERP_RELEASE_SEQUENCE=24000');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.19');
putenv('ERP_RELEASE_SCHEMA=300');
putenv('ERP_RELEASE_MIGRATION_NOTE=schema 300 adds manual_drain_sessions for the K10 smart manual drain; no production recovery, no Cron change, no FIFO change and no Mercado Libre HTTP');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación aplica schema300 una sola vez, no crea Cron, no ejecuta worker/OAuth/Billing/recovery y no llama Mercado Libre.');
putenv('ERP_RELEASE_OVERLAY_LABEL=RELEASE_OVERLAY');
putenv('ERP_RELEASE_FORCE_OVERLAY_PATHS=database/migrations/284_queue_core_sales_pipeline_b2.sql');

require __DIR__ . '/build_release_2384_artifacts.php';
