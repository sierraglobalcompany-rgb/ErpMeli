<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=28bd74ecd0b528aaad2ffa3af11114dfbff5737c');
putenv('ERP_RELEASE_ID=erp-meli-2.39.18-versioned-23917-hotfix');
putenv('ERP_RELEASE_SEQUENCE=23918');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.17');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=hotfix versionado code-only desde la 2.39.17 productiva; conserva schema 299 y no aplica migraciones ni recovery');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, B429 ni fechas históricas, no ejecuta recovery ni llama Mercado Libre.');

require __DIR__ . '/build_release_2384_artifacts.php';
