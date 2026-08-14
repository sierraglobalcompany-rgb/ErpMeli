<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

// The 2.38.5 transport test deliberately changes the shared 429 backoff.
// Pin this independent OAuth regression to its own canonical fixture values
// so execution order cannot create a false failure.
$pdo2387 = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$statement2387 = $pdo2387->prepare(
    "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
     VALUES (?, ?, 0, 'mercadolibre')
     ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0"
);
foreach ([
    'api.rhythm.shared_429_backoff_seconds' => '300',
    'api.rhythm.shared_429_jitter_seconds' => '0',
] as $key2387 => $value2387) {
    $statement2387->execute([$key2387, $value2387]);
}
unset($statement2387, $pdo2387, $key2387, $value2387);

require __DIR__ . '/queue_v4_oauth_real_path_2386.php';

fwrite(STDOUT, 'QUEUE_V4_OAUTH_REAL_PATH_2387=PASS deterministic_backoff=pass' . PHP_EOL);
