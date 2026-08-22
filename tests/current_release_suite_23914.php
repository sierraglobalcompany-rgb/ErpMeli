<?php

declare(strict_types=1);

require __DIR__ . '/current_release_suite_23913.php';
require __DIR__ . '/legacy_authority_v4_23914.php';
require __DIR__ . '/api_log_transport_truth_23914.php';
require __DIR__ . '/queue_v4_update_transition_23914.php';

fwrite(STDOUT, 'CURRENT_RELEASE_SUITE_23914=PASS inherited=23913 authority_v4=1 transport_truth=1' . PHP_EOL);
