<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=2f5170a44f697d6ef6bf3afd48ce3523d7e13cbe');
putenv('ERP_RELEASE_ID=erp-meli-2.39.2-b1-stop-orphan-admission');
putenv('ERP_RELEASE_SEQUENCE=23902');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.1');
putenv('ERP_RELEASE_SCHEMA=298');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización metadata-only; no existe migración 299');
putenv('ERP_RELEASE_INSTRUCTION=Instale 2.39.2 conservando exactamente un Cron Queue V4 Clean. B1 bloquea seis admisiones legacy sin consumidor y no procesa ni altera sus backlogs existentes.');

require __DIR__ . '/build_release_2384_artifacts.php';
