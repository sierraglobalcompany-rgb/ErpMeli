<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

echo 'LEGACY_AUTOMATION_RETIRED component=queue_core_cron_v4 remote=false http=0' . PHP_EOL;
exit(0);
