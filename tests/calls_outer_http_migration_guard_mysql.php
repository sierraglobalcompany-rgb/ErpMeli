<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\Services\Migrator;

foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33338','DB_USER'=>'root','DB_PASS'=>'local-http-receipt-test-only', 'CALLS_VERIFY_QA_ROOT'=>dirname(__DIR__).'/storage/codex-http-phase1','APP_KEY'=>'synthetic-migration-guard-only','PRIVATE_STORAGE_PATH'=>dirname(__DIR__).'/storage/codex-http-phase1/migration-guard-private-'.getmypid()] as $k=>$v) { putenv($k.'='.$v); }
$path=dirname(__DIR__).'/database/migrations';
$file='303_outer_cron_http_receipt.sql';
putenv('DB_NAME=erp_meli_k1d_test_http_migration_'.bin2hex(random_bytes(5)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    $migrator=new Migrator($pdo,$path);
    $migrator->run(303);
    $migrator->run(303);
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='".$file."'")->fetchColumn()===1,'migration once');
    $stmt=$pdo->prepare('SELECT checksum_sha256 FROM system_update_migrations WHERE migration_key=?');
    $stmt->execute([$file]);
    k1b_assert(hash_equals(hash_file('sha256',$path.'/'.$file),(string)$stmt->fetchColumn()),'migration checksum authoritative');
    $pdo->prepare('UPDATE system_update_migrations SET checksum_sha256=? WHERE migration_key=?')->execute([str_repeat('0',64),$file]);
    $blocked=false;
    try { $migrator->run(303); } catch(Throwable $error) { $blocked=true; }
    k1b_assert($blocked,'checksum drift blocks');
    $stmt=$pdo->prepare('SELECT state FROM system_update_migrations WHERE migration_key=?'); $stmt->execute([$file]);
    k1b_assert($stmt->fetchColumn()==='drifted','checksum drift classified');
    echo "MIGRATION_303_EXACT_ONCE_AND_CHECKSUM_DRIFT=PASS\n";
} finally { $db->cleanup(); }

putenv('DB_NAME=erp_meli_k1d_test_http_partial_'.bin2hex(random_bytes(5)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    $migrator=new Migrator($pdo,$path);
    $migrator->run(302);
    // Actual committed first DDL, simulating a process death before the second.
    $pdo->exec('ALTER TABLE system_execution_runs ADD COLUMN http_receipt_json LONGTEXT NULL');
    $blocked=false;
    try { $migrator->run(303); } catch(Throwable $error) { $blocked=true; }
    k1b_assert($blocked,'partial DDL not silently adopted');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='".$file."'")->fetchColumn()===0,'partial migration never marked applied');
    $stmt=$pdo->prepare('SELECT state FROM system_update_migrations WHERE migration_key=?'); $stmt->execute([$file]);
    k1b_assert($stmt->fetchColumn()==='failed','partial migration failed ledger');
    echo "MIGRATION_303_PARTIAL_DDL_FAILS_CLOSED=PASS\nREAL_MELI_HTTP=0 REAL_OAUTH=0\n";
} finally { $db->cleanup(); }
