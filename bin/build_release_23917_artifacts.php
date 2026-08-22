<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=0fc9b222d4626b0121b08d98d51f69169a3082ee');
putenv('ERP_RELEASE_ID=erp-meli-2.39.17-operations-visibility-kiss');
putenv('ERP_RELEASE_SEQUENCE=23917');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.16');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=corrección code-only de visibilidad operativa: Incidentes API, riesgos de Cron y retiro visible de launcher legacy; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, B429 ni fechas históricas, no ejecuta recovery ni llama Mercado Libre. Verifique después Incidentes API materializados o el fallback degradado explícito.');

require __DIR__ . '/build_release_2384_artifacts.php';
