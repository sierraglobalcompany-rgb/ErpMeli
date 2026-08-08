<?php

declare(strict_types=1);

require_once __DIR__ . '/launcher/entrypoint.php';
if (erp_dispatch_active_entrypoint(__DIR__, 'index.php')) {
    return;
}
require __DIR__ . '/public/index.php';
