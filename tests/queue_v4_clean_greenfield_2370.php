<?php

declare(strict_types=1);

// Compatibility entrypoint: the positive Queue V4 test must always use the
// canonical 001-294 migration materialization, never a hand-written core DB.
require __DIR__ . '/queue_v4_clean_full_flow_2371.php';
