<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=432b2ce74d95c03809ea659a091890a6e02a79f9');
putenv('ERP_RELEASE_ID=erp-meli-2.39.6-h2-billing-terminal-hy093');
putenv('ERP_RELEASE_SEQUENCE=23906');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.5');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física actual de Cron establecida por el operador durante la auditoría postinstalación.');

require __DIR__ . '/build_release_2384_artifacts.php';
