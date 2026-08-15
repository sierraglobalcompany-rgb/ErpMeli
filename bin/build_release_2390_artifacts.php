<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=d6ff54d5907a631529e270f0c950ddbd5c1c0c42');
putenv('ERP_RELEASE_ID=erp-meli-2.39.0-legacy-cron-fail-closed');
putenv('ERP_RELEASE_SEQUENCE=23900');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.9');
putenv('ERP_RELEASE_SCHEMA=298');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización metadata-only; no existe migración 299');
putenv('ERP_RELEASE_INSTRUCTION=Instale 2.39.0 y conserve exactamente un Cron Hostinger apuntando a jobs/queue_v4_clean.php; los entrypoints legacy quedan retirados.');

require __DIR__ . '/build_release_2384_artifacts.php';
