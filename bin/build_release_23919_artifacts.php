<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=c4db6be5e41213a52f476dff03f099b10fae6624');
putenv('ERP_RELEASE_ID=erp-meli-2.39.19-billing-throughput-kiss');
putenv('ERP_RELEASE_SEQUENCE=23919');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.18');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=code-only batching KISS and Billing cardinality observability; no migration and no historical recovery');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, B429 ni fechas históricas, no ejecuta recovery ni llama Mercado Libre.');
putenv('ERP_RELEASE_OVERLAY_LABEL=FTP_OVERLAY');

require __DIR__ . '/build_release_2384_artifacts.php';
