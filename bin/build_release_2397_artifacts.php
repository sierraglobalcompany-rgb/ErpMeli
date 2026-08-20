<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=54b6280c6eb67bea21ed651fa68fffe965d49330');
putenv('ERP_RELEASE_ID=erp-meli-2.39.7-h3-financial-nonfailure-defer');
putenv('ERP_RELEASE_SEQUENCE=23907');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.6');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only H3; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física actual de Cron, max-jobs, Billing 900s, FIFO y backlog histórico; esta actualización no ejecuta replay ni reparación automática.');

require __DIR__ . '/build_release_2384_artifacts.php';
