<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=3643d2d90b0aca6365acc1e8eeb597abd0c8896a');
putenv('ERP_RELEASE_ID=erp-meli-2.39.4-f2b-b429-rhythm-fail-closed');
putenv('ERP_RELEASE_SEQUENCE=23904');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.3');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar exactamente la configuración física de Cron actualmente establecida por el operador durante el rollout. No aumentar max-jobs hasta completar la auditoría postinstall.');

require __DIR__ . '/build_release_2384_artifacts.php';
