<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=ce234142ad34cab7b37fbc16eb923e2e97b1efcd');
putenv('ERP_RELEASE_ID=erp-meli-2.38.6-private-filesystem-authority-oauth-escrow');
putenv('ERP_RELEASE_SEQUENCE=23806');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.5');
putenv('ERP_RELEASE_SCHEMA=297');
putenv('ERP_RELEASE_MIGRATION_NOTE=no se agregan migraciones; schema 297 permanece intacto');
putenv('ERP_RELEASE_INSTRUCTION=Mantenga el Cron ausente y ejecute primero el self-check manual y la comparación de identidad con una única tarea temporal de Hostinger.');

require __DIR__ . '/build_release_2384_artifacts.php';
