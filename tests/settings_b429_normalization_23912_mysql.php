<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\SettingsDefinitionRepository;
use App\Services\AppSettingsService;
use App\Services\SettingsSectionService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: settings_b429_normalization_23912 requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_settings_b429_' . bin2hex(random_bytes(5));
$server = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    Database::setConnection($pdo);
    $pdo->exec('CREATE TABLE app_settings (
        setting_key varchar(190) NOT NULL PRIMARY KEY,
        setting_value text NULL,
        is_encrypted tinyint(1) NOT NULL DEFAULT 0,
        setting_group varchar(80) NOT NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE audit_logs (
        id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id bigint NULL, action varchar(120) NOT NULL, module varchar(120) NOT NULL,
        entity_type varchar(120) NULL, entity_id bigint NULL, meli_account_id bigint NULL,
        ip_hash varchar(128) NOT NULL, before_json longtext NULL, after_json longtext NULL
    ) ENGINE=InnoDB');

    $definitions = new SettingsDefinitionRepository();
    $fields = $definitions->section('mercadolibre')['fields'] ?? [];
    $submitted = [];
    foreach ($fields as $field) {
        $submitted[(string) $field['key']] = match ((string) $field['type']) {
            'boolean' => !empty($field['recommended']) ? '1' : '0',
            default => (string) $field['recommended'],
        };
    }

    $submitted['api.rhythm.billing_429_backoff_1_minutes'] = '120';
    $submitted['api.rhythm.billing_429_backoff_2_minutes'] = '30';
    $submitted['api.rhythm.billing_429_backoff_3_minutes'] = '5';
    $submitted['api.rhythm.billing_429_backoff_max_minutes'] = '10';
    $saved = (new SettingsSectionService())->save('mercadolibre', $submitted);
    $expected = [
        'api.rhythm.billing_429_backoff_1_minutes' => '120',
        'api.rhythm.billing_429_backoff_2_minutes' => '120',
        'api.rhythm.billing_429_backoff_3_minutes' => '120',
        'api.rhythm.billing_429_backoff_max_minutes' => '120',
    ];
    foreach ($expected as $key => $value) {
        $assert(($saved[$key] ?? null) === $value, 'DISORDERED_VALUE_NOT_NORMALIZED:' . $key);
    }
    $rows = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'api.rhythm.billing_429_backoff%' ORDER BY setting_key")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($rows === $expected, 'NORMALIZED_VALUES_NOT_PERSISTED');

    AppSettingsService::clearCache();
    $invalid = $submitted;
    $invalid['api.rhythm.billing_429_backoff_1_minutes'] = '4';
    try {
        (new SettingsSectionService())->save('mercadolibre', $invalid);
        $assert(false, 'OUT_OF_RANGE_VALUE_DID_NOT_FAIL');
    } catch (InvalidArgumentException) {
        $assert(true, 'OUT_OF_RANGE_VALUE_FAILS');
    }
    $unchanged = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'api.rhythm.billing_429_backoff%' ORDER BY setting_key")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($unchanged === $expected, 'OUT_OF_RANGE_WRITE_WAS_NOT_ATOMIC');

    AppSettingsService::clearCache();
    $restored = (new SettingsSectionService())->save('mercadolibre', [], true);
    $assert(
        array_intersect_key($restored, $expected) === [
            'api.rhythm.billing_429_backoff_1_minutes' => '30',
            'api.rhythm.billing_429_backoff_2_minutes' => '120',
            'api.rhythm.billing_429_backoff_3_minutes' => '360',
            'api.rhythm.billing_429_backoff_max_minutes' => '720',
        ],
        'RECOMMENDED_B429_POLICY_NOT_RESTORED',
    );

    fwrite(STDOUT, 'SETTINGS_B429_NORMALIZATION_23912=PASS checks=' . $checks . PHP_EOL);
} finally {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable) {
    }
}
