<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=8d68d505d5e47e3ba0e69e688da7064e772b889a');
putenv('ERP_RELEASE_ID=erp-meli-2.39.10-v4-bulk-parity-convergence');
putenv('ERP_RELEASE_SEQUENCE=23910');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.9');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only Bulk Parity final; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física actual de Cron; esta actualización no crea ni reactiva Cron automáticamente, no cambia max-jobs, no ejecuta backfill, no repara backlog histórico y no llama Mercado Libre durante instalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
