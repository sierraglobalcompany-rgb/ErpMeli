<?php

declare(strict_types=1);

require_once __DIR__ . '/launcher/entrypoint.php';
if (erp_dispatch_active_entrypoint(__DIR__, 'mantenimiento.php', true)) {
    return;
}
require __DIR__ . '/app/Recovery/DatabaseMaintenanceRecoveryKernel.php';
\App\Recovery\DatabaseMaintenanceRecoveryKernel::run(__DIR__);
