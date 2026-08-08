<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\MigrationExecutionException;
use App\Services\Migrator;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn === '') {
    echo "SKIP applied_migration_015_drift_2299: ERP_MIGRATOR_TEST_DSN is required.\n";
    exit(0);
}

$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$root = dirname(__DIR__);
$server = new PDO($dsn, $user, $pass, $options);
$database = 'erp_applied_015_drift_2299_' . bin2hex(random_bytes(5));

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$makeTempRoot = static function (string $migrationName) use ($root): string {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_mig2299_' . bin2hex(random_bytes(5));
    mkdir($tmp . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations', 0777, true);
    mkdir($tmp . DIRECTORY_SEPARATOR . 'resources', 0777, true);
    copy(
        $root . '/database/migrations/' . $migrationName,
        $tmp . '/database/migrations/' . $migrationName
    );
    $checksum = hash_file('sha256', $tmp . '/database/migrations/' . $migrationName);
    file_put_contents($tmp . '/resources/runtime-manifest.json', json_encode([
        'product' => 'erp-meli',
        'version' => '2.29.9-test',
        'components' => [
            'migration_015_sync_products_claims' => [
                'path' => 'database/migrations/' . $migrationName,
                'sha256' => $checksum,
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $tmp;
};

$apply015Contract = static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) NOT NULL,
        setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE app_versions (
        version VARCHAR(40) NOT NULL PRIMARY KEY,
        notes TEXT NULL,
        installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE meli_sync_offsets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sync_type VARCHAR(80) NOT NULL,
        cursor_value TEXT NULL,
        last_resource_id VARCHAR(120) NULL,
        last_synced_at DATETIME NULL,
        status ENUM("pending","running","complete","error") NOT NULL DEFAULT "pending",
        error_message VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sync_offset (meli_account_id, sync_type),
        CONSTRAINT fk_sync_offsets_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted) VALUES
        ('sync.products.page_limit','50','sync',0),
        ('sync.products.max_items_per_run','500','sync',0),
        ('sync.claims.page_limit','30','sync',0),
        ('sync.claims.max_claims_per_run','200','sync',0),
        ('reports.default_include_returns','0','reports',0)");
};

$seedMigrationMetadata = static function (PDO $pdo, string $migration, string $checksum, string $state): void {
    $pdo->exec('CREATE TABLE schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO schema_migrations(version) VALUES (" . $pdo->quote($migration) . ")");
    $pdo->exec("CREATE TABLE system_update_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration_key VARCHAR(180) NOT NULL,
        checksum_sha256 CHAR(64) NOT NULL,
        release_version VARCHAR(40) NULL,
        state VARCHAR(40) NOT NULL DEFAULT 'pending',
        checkpoint_json LONGTEXT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        safe_error_message VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_system_update_migration_key (migration_key),
        KEY idx_system_update_migrations_state (state,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare(
        "INSERT INTO system_update_migrations
         (migration_key,checksum_sha256,release_version,state,safe_error_message,finished_at)
         VALUES (?,?,?,?,?,UTC_TIMESTAMP())"
    );
    $stmt->execute([$migration, $checksum, '2.1.0', $state, 'El archivo aplicado cambió después de su registro.']);
};

try {
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    $migration015 = '015_sync_products_claims_2_1.sql';
    $unknownChecksum = str_repeat('a', 64);
    $tempRoot = $makeTempRoot($migration015);
    $apply015Contract($pdo);
    $seedMigrationMetadata($pdo, $migration015, $unknownChecksum, 'drifted');
    $result = (new Migrator($pdo, $tempRoot . '/database/migrations'))->run(1);
    $row = $pdo->query(
        'SELECT checksum_sha256,state,safe_error_message FROM system_update_migrations WHERE migration_key='
        . $pdo->quote($migration015)
    )->fetch();
    $expectedChecksum = hash_file('sha256', $tempRoot . '/database/migrations/' . $migration015);
    $assert(($result[0]['status'] ?? null) === 'adopted', 'La 015 aplicada debe adoptarse por contrato.');
    $assert((string) $row['state'] === 'adopted', 'La metadata de 015 debe quedar adopted.');
    $assert((string) $row['checksum_sha256'] === $expectedChecksum, 'La 015 debe refrescarse al checksum activo firmado.');
    $assert(str_contains((string) $row['safe_error_message'], 'No se ejecutó SQL'), 'La adopción debe dejar claro que no ejecutó SQL.');

    $server->exec("DROP DATABASE `{$database}`");
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    $apply015Contract($pdo);
    $pdo->exec("DELETE FROM app_settings WHERE setting_key='sync.claims.max_claims_per_run'");
    $seedMigrationMetadata($pdo, $migration015, $unknownChecksum, 'drifted');
    $blocked = false;
    try {
        (new Migrator($pdo, $tempRoot . '/database/migrations'))->run(1);
    } catch (MigrationExecutionException $e) {
        $blocked = str_contains($e->getMessage(), 'applied_015_schema_contract_missing')
            || str_contains($e->getMessage(), 'No se puede certificar la 015');
    }
    $assert($blocked, 'La 015 debe bloquear si falta su contrato de esquema.');

    $server->exec("DROP DATABASE `{$database}`");
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    $migration016 = '016_date_billing_2_1_2.sql';
    $tempRootOther = $makeTempRoot($migration016);
    $seedMigrationMetadata($pdo, $migration016, $unknownChecksum, 'drifted');
    $blockedOther = false;
    try {
        (new Migrator($pdo, $tempRootOther . '/database/migrations'))->run(1);
    } catch (MigrationExecutionException) {
        $blockedOther = true;
    }
    $assert($blockedOther, 'Un drift real de otra migración debe seguir bloqueado.');

    echo "PASS applied_migration_015_drift_2299\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
