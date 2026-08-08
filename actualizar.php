<?php

declare(strict_types=1);

require_once __DIR__ . '/launcher/entrypoint.php';
if (erp_dispatch_active_entrypoint(__DIR__, 'actualizar.php', true)) {
    return;
}
require __DIR__ . '/app/Recovery/RecoveryKernel.php';
\App\Recovery\RecoveryKernel::run(__DIR__, 'update');
