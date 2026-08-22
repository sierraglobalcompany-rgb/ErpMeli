<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=ac6cfadf008ed79e994d98ce6db942eecde2d7f2');
putenv('ERP_RELEASE_ID=erp-meli-2.39.16-api-incident-visibility');
putenv('ERP_RELEASE_SEQUENCE=23916');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.15');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=corrección code-only de visibilidad del catálogo materializado de incidentes API; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, B429 ni fechas históricas, no ejecuta recovery ni llama Mercado Libre. La primera etapa local acotada del scheduler actualiza el catálogo de incidentes antes del worker remoto.');

require __DIR__ . '/build_release_2384_artifacts.php';
