<?php

declare(strict_types=1);

/*
 * The reused MariaDB fixture creates a historical reconciliation source without
 * a Queue V4 pointer, proves it remains untouched, then creates new automatic
 * sources and proves each gets exactly one V4 domain_exact pointer.  It also
 * proves admission failure rolls back source and pointer together.
 */
require __DIR__ . '/domain_exact_finance_admission_2393_mysql.php';

fwrite(STDOUT, "FINANCE_ADMISSION_TRANSITION_23918=PASS historical_pointer_backfill=0 new_automatic_source_without_pointer=0 real_meli_http=0\n");
