<?php

declare(strict_types=1);

putenv('ERP_TRANSITION_BASE_VERSION=2.39.18');
putenv('ERP_TRANSITION_TARGET_VERSION=2.39.19');
require __DIR__ . '/queue_v4_update_transition_23912.php';
