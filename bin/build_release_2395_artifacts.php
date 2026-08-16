<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=785c657d178b484d377e429b22ae4506e8fadd14');
putenv('ERP_RELEASE_ID=erp-meli-2.39.5-h1-order-exact-context');
putenv('ERP_RELEASE_SEQUENCE=23905');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.4');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física de Cron establecida por el operador. No aumentar max-jobs durante la auditoría postinstall H1.');

require __DIR__ . '/build_release_2384_artifacts.php';
