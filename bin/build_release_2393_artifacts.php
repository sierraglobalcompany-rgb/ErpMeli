<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=e7c80cccf15dc5323def4a89abdedc79c72248a1');
putenv('ERP_RELEASE_ID=erp-meli-2.39.3-domain-exact-finance');
putenv('ERP_RELEASE_SEQUENCE=23903');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.2');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=aplica únicamente 299_queue_v4_domain_exact_admission_2_39_3.sql; amplía el ENUM job_type sin reinterpretar backlog');
putenv('ERP_RELEASE_INSTRUCTION=Instale 2.39.3 conservando exactamente un Cron Queue V4 Clean con --runtime=45 --max-jobs=3. No importe backlog histórico ni cree otro Cron.');

require __DIR__ . '/build_release_2384_artifacts.php';
