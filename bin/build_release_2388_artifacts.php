<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=0ce4afe5269eb282734e36a0913fb2bf0f871088');
putenv('ERP_RELEASE_ID=erp-meli-2.38.8-simple-exact-sales-repair-hotfix');
putenv('ERP_RELEASE_SEQUENCE=23808');
putenv('ERP_RELEASE_UPGRADE_FROM=2.38.7');
putenv('ERP_RELEASE_SCHEMA=297');
putenv('ERP_RELEASE_MIGRATION_NOTE=no se agregan migraciones; schema 297 permanece intacto');
putenv('ERP_RELEASE_INSTRUCTION=Mantenga el Cron ausente, instale 2.38.8 y verifique un repair exacto acotado antes de habilitar la única tarea Queue V4.');

require __DIR__ . '/build_release_2384_artifacts.php';
