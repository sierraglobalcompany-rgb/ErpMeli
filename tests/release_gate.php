<?php

declare(strict_types=1);

if (trim((string) getenv('ERP_MIGRATOR_TEST_DSN')) === '') {
    fwrite(
        STDERR,
        "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio. La release no puede certificarse sin MariaDB/MySQL real.\n"
    );
    exit(2);
}

putenv('ERP_RELEASE_STRICT=1');
require __DIR__ . '/sales_control_2211_mysql_integration.php';
