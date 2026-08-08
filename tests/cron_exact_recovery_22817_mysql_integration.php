<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\Migrator;

$root = dirname(__DIR__);
Env::load($root . '/config.env');
Database::useProfile('migration');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$target = 'erp_meli_22817_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
$temporaryMigrations = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $target;
$admin = null;
$serverVersion = '';

try {
    $source = Database::connectionFresh();
    $database = (string) $source->query('SELECT DATABASE()')->fetchColumn();
    $serverVersion = (string) $source->query('SELECT VERSION()')->fetchColumn();
    $host = (string) Env::get('DB_HOST', '127.0.0.1');
    $port = (int) Env::get('DB_PORT', '3306');
    $user = (string) Env::get('DB_USER', '');
    $password = (string) Env::get('DB_PASS', '');
    $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $admin = new PDO($adminDsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $admin->exec('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $targetPdo = new PDO($adminDsn . ';dbname=' . $target, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $tables = [
        'app_settings',
        'app_versions',
        'system_component_schema_contracts',
        'order_resource_enrichment_jobs',
        'manual_campaigns',
        'manual_campaign_items',
        'cron_task_state',
    ];
    foreach ($tables as $table) {
        $targetPdo->exec('CREATE TABLE `' . $table . '` LIKE `' . $database . '`.`' . $table . '`');
        $targetPdo->exec('INSERT INTO `' . $table . '` SELECT * FROM `' . $database . '`.`' . $table . '`');
    }

    $commercialBefore = [];
    foreach (['manual_campaigns', 'manual_campaign_items'] as $table) {
        $commercialBefore[$table] = (int) $targetPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    $campaignSixBefore = $targetPdo->query(
        'SELECT id,status,total_items,total_units,created_by_user_id,created_at FROM manual_campaigns WHERE id=6'
    )->fetch(PDO::FETCH_ASSOC);
    $campaignSixItemsBefore = $targetPdo->query(
        'SELECT id,total_units FROM manual_campaign_items WHERE manual_campaign_id=6 ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $candidate = $targetPdo->query('SELECT id FROM order_resource_enrichment_jobs ORDER BY id LIMIT 1')->fetchColumn();
    if ($candidate === false) {
        $targetPdo->exec(
            'INSERT INTO order_resource_enrichment_jobs
             (meli_account_id,meli_order_id,resource_type,external_resource_id,status,priority,attempts,next_run_at,
              last_error_code,failure_class,reached_remote,last_error_message,created_at,updated_at)
             VALUES (1,1,"shipment","qa-deadline-22817","pending",50,0,UTC_TIMESTAMP(),
                     NULL,NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $candidate = $targetPdo->lastInsertId();
    }
    $seed = $targetPdo->prepare(
        'UPDATE order_resource_enrichment_jobs
         SET status="error",attempts=3,last_error_code="unknown",failure_class="unknown",reached_remote=0,
             last_error_message="El cron alcanzó su límite seguro antes de iniciar otra consulta API.",
             lock_token="qa-owner",locked_at=UTC_TIMESTAMP(),heartbeat_at=UTC_TIMESTAMP()
         WHERE id=?'
    );
    $seed->execute([(int) $candidate]);

    if (!mkdir($temporaryMigrations, 0770, true) && !is_dir($temporaryMigrations)) {
        throw new RuntimeException('No fue posible preparar las migraciones temporales.');
    }
    $migration = '197_cron_exact_recovery_human_intervention_2_28_17.sql';
    if (!copy($root . '/database/migrations/' . $migration, $temporaryMigrations . '/' . $migration)) {
        throw new RuntimeException('No fue posible copiar la migración bajo prueba.');
    }

    $first = (new Migrator($targetPdo, $temporaryMigrations))->run();
    $second = (new Migrator($targetPdo, $temporaryMigrations))->run();
    $check(count(array_filter($first, static fn (array $row): bool => $row['status'] === 'applied')) === 1,
        'La migración 197 no se aplicó exactamente una vez.');
    $check(count(array_filter($second, static fn (array $row): bool => $row['status'] === 'skip')) === 1,
        'La segunda ejecución de la migración 197 no fue idempotente.');

    $verify = $targetPdo->prepare(
        'SELECT status,attempts,last_error_code,failure_class,reached_remote,lock_token
         FROM order_resource_enrichment_jobs WHERE id=?'
    );
    $verify->execute([(int) $candidate]);
    $row = $verify->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(($row['status'] ?? '') === 'retry', 'El falso error de deadline no volvió a retry.');
    $check((int) ($row['attempts'] ?? -1) === 2, 'El intento técnico del falso error no fue devuelto.');
    $check(($row['last_error_code'] ?? '') === 'cron_deadline_deferred', 'No se guardó el código tipado del deadline.');
    $check(($row['failure_class'] ?? '') === 'waiting_deadline', 'No se guardó la clase de espera esperada.');
    $check((int) ($row['reached_remote'] ?? 1) === 0, 'La reparación afirmó transporte remoto inexistente.');
    $check(($row['lock_token'] ?? null) === null, 'La reparación dejó un lease huérfano.');
    $check((int) $targetPdo->query('SELECT COUNT(*) FROM system_work_resolution_events')->fetchColumn() === 0,
        'La migración creó decisiones administrativas inexistentes.');

    foreach ($commercialBefore as $table => $count) {
        $check((int) $targetPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === $count,
            'La migración cambió la cardinalidad de ' . $table . '.');
    }
    if (is_array($campaignSixBefore)) {
        $campaignSixAfter = $targetPdo->query(
            'SELECT id,status,total_items,total_units,created_by_user_id,created_at FROM manual_campaigns WHERE id=6'
        )->fetch(PDO::FETCH_ASSOC);
        $campaignSixItemsAfter = $targetPdo->query(
            'SELECT id,total_units FROM manual_campaign_items WHERE manual_campaign_id=6 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $check($campaignSixAfter === $campaignSixBefore,
            'La migración cambió la identidad, estado o dimensiones declaradas de la campaña #6.');
        $check($campaignSixItemsAfter === $campaignSixItemsBefore,
            'La migración cambió la identidad o las unidades de los ítems de la campaña #6.');
    }
} catch (Throwable $error) {
    $cause = $error;
    while ($cause->getPrevious() instanceof Throwable) {
        $cause = $cause->getPrevious();
    }
    $failures[] = 'La prueba de motor SQL falló: ' . $error->getMessage()
        . ' Causa raíz local: ' . $cause::class . ': ' . $cause->getMessage();
} finally {
    if ($admin instanceof PDO) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS `' . $target . '`');
        } catch (Throwable) {
        }
    }
    $migrationFile = $temporaryMigrations . '/197_cron_exact_recovery_human_intervention_2_28_17.sql';
    if (is_file($migrationFile)) {
        unlink($migrationFile);
    }
    if (is_dir($temporaryMigrations)) {
        rmdir($temporaryMigrations);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'PASS cron_exact_recovery_22817_mysql_integration server=' . $serverVersion . PHP_EOL);
