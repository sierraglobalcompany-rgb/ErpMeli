<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN debe apuntar a MariaDB sin seleccionar una base.\n");
    exit(2);
}

putenv('APP_KEY=base64:' . base64_encode(str_repeat('r', 32)));
putenv('ML_WRITE_ENABLED=false');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Services\RestoreService;

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
$numericVersion = preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/', $version, $match) === 1
    ? (string) $match[1]
    : '0.0.0';
if (stripos($version, 'MariaDB') === false || version_compare($numericVersion, '11.8.0', '<')) {
    throw new RuntimeException('Esta certificación exige MariaDB 11.8 real. Detectado: ' . $version);
}

$suffix = bin2hex(random_bytes(5));
$controlName = 'erp_restore_control_' . $suffix;
$targetName = 'erp_restore_target_' . $suffix;
$quote = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';

try {
    $server->exec(
        'CREATE DATABASE ' . $quote($controlName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci'
    );
    $server->exec(
        'CREATE DATABASE ' . $quote($targetName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci'
    );
    $control = new PDO(
        rtrim($dsn, ';') . ';dbname=' . $controlName,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $target = new PDO(
        rtrim($dsn, ';') . ';dbname=' . $targetName,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    Database::setConnection($control);
    $control->exec(
        "CREATE TABLE system_restore_plans (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            lease_owner VARCHAR(96) NULL,
            lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
            heartbeat_at DATETIME(3) NULL,
            lease_expires_at DATETIME(3) NULL
        ) ENGINE=InnoDB"
    );
    $control->exec(
        "INSERT INTO system_restore_plans
         (id,lease_owner,lease_generation,heartbeat_at,lease_expires_at)
         VALUES (1,'restore-test-owner',7,NULL,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND))"
    );
    $target->exec(
        "CREATE TABLE restore_manifest_probe (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            value_text VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $target->exec(
        "INSERT INTO restore_manifest_probe (id,value_text)
         VALUES (1,'uno'),(2,'dos')"
    );

    $create = $target->query('SHOW CREATE TABLE restore_manifest_probe')->fetch(PDO::FETCH_NUM);
    if (!is_array($create) || !isset($create[1])) {
        throw new RuntimeException('No fue posible construir el fixture de estructura.');
    }
    $chain = hash('sha256', '');
    $rows = $target->query(
        'SELECT * FROM restore_manifest_probe ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $insert = 'INSERT INTO `restore_manifest_probe` (`id`,`value_text`) VALUES ('
            . $target->quote((string) $row['id']) . ','
            . $target->quote((string) $row['value_text']) . ");\n";
        $chain = hash('sha256', hex2bin($chain) . $insert);
    }
    $manifest = [
        'format' => 3,
        'table_count' => 1,
        'tables' => [[
            'table' => 'restore_manifest_probe',
            'rows' => 2,
            'structure_sha256' => hash('sha256', (string) $create[1]),
            'data_sha256' => $chain,
        ]],
    ];

    $service = new RestoreService();
    $verify = new ReflectionMethod(RestoreService::class, 'verifyTarget');
    $verify->invoke(
        $service,
        $target,
        $manifest,
        $control,
        1,
        'restore-test-owner',
        7
    );
    $heartbeat = $control->query(
        'SELECT heartbeat_at IS NOT NULL AND lease_expires_at>UTC_TIMESTAMP(3)
         FROM system_restore_plans WHERE id=1'
    )->fetchColumn();
    if ((int) $heartbeat !== 1) {
        throw new RuntimeException('La verificación no renovó el lease cercado.');
    }

    $target->exec("UPDATE restore_manifest_probe SET value_text='alterado' WHERE id=2");
    $changedRejected = false;
    try {
        $verify->invoke(
            $service,
            $target,
            $manifest,
            $control,
            1,
            'restore-test-owner',
            7
        );
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable) {
        $changedRejected = true;
    }
    if (!$changedRejected) {
        throw new RuntimeException('Una tabla distinta del manifiesto autenticado fue aprobada.');
    }
    $target->exec("UPDATE restore_manifest_probe SET value_text='dos' WHERE id=2");

    $control->exec(
        "UPDATE system_restore_plans
         SET lease_owner='newer-worker',lease_generation=8
         WHERE id=1"
    );
    $staleRejected = false;
    try {
        $verify->invoke(
            $service,
            $target,
            $manifest,
            $control,
            1,
            'restore-test-owner',
            7
        );
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable) {
        $staleRejected = true;
    }
    if (!$staleRejected) {
        throw new RuntimeException('Un worker con generación vencida conservó autoridad.');
    }

    echo json_encode([
        'status' => 'PASS',
        'engine' => $version,
        'authenticated_manifest' => true,
        'lease_heartbeat' => true,
        'stale_generation_rejected' => true,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($targetName));
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($controlName));
}
