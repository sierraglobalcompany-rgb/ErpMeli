<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=404d279a12b7541f765f7a79fce172bde9cb90f4');
putenv('ERP_RELEASE_ID=erp-meli-2.39.15-versioned-23914-amendment');
putenv('ERP_RELEASE_SEQUENCE=23915');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.14');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=enmienda code-only versionada de los cierres P1 de lanzadores legacy y verdad de transporte HTTP; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conserve exactamente el único Cron físico queue_v4_clean.php --runtime=45 --max-jobs=3; la instalación no crea ni reactiva Cron, no altera Billing 900s, B429 ni fechas históricas, no ejecuta recovery ni llama Mercado Libre.');

require __DIR__ . '/build_release_2384_artifacts.php';
