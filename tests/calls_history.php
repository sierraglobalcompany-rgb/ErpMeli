<?php
declare(strict_types=1);
namespace App\Services { final class CronOperationalAccessScope { public function assertGlobal(): void {} } }
namespace {
require __DIR__.'/k1b_bootstrap.php'; require __DIR__.'/K1dSafeTestDatabase.php';
putenv('APP_ENV=test');putenv('ML_WRITE_ENABLED=false');putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=33079');putenv('DB_USER=root');putenv('DB_PASS=');putenv('DB_NAME=erp_meli_k1d_test_calls_history_'.bin2hex(random_bytes(4)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
 $pdo=$db->pdo();
 $pdo->exec('CREATE TABLE queue_v4_clean_runs(id BIGINT PRIMARY KEY,launcher VARCHAR(20),status VARCHAR(20),started_at DATETIME,finished_at DATETIME,jobs_claimed INT,jobs_completed INT,jobs_deferred INT)');
 $pdo->exec('CREATE TABLE queue_v4_clean_attempts(id BIGINT PRIMARY KEY,job_id BIGINT,run_id BIGINT,company_id BIGINT,meli_account_id BIGINT,lease_generation BIGINT)');
 $pdo->exec('CREATE TABLE queue_v4_clean_transport_events(id BIGINT PRIMARY KEY,source_kind VARCHAR(20),work_id BIGINT,attempt_id BIGINT,company_id BIGINT,meli_account_id BIGINT,lease_generation BIGINT,dispatch_state VARCHAR(30),http_status INT)');
 $pdo->exec("INSERT INTO queue_v4_clean_runs VALUES(1,'scheduler','completed',UTC_TIMESTAMP(),UTC_TIMESTAMP(),2,1,1),(2,'scheduler','running',UTC_TIMESTAMP(),NULL,1,0,0)");
 $pdo->exec('INSERT INTO queue_v4_clean_attempts VALUES(11,21,1,1,101,1),(12,21,2,1,101,2)');
 $pdo->exec("INSERT INTO queue_v4_clean_transport_events VALUES(1,'queue',21,11,1,101,1,'RESPONSE_KNOWN',200),(2,'queue',21,11,1,101,1,'PHYSICAL_STARTED',NULL),(3,'sales_repair',21,11,1,101,1,'RESPONSE_KNOWN',200),(4,'queue',21,11,2,102,1,'RESPONSE_KNOWN',200),(5,'queue',21,12,1,101,2,'RESPONSE_KNOWN',429)");
 $history=(new App\Services\WorkQueueRunService())->v4HistoryPage(1,25);
 $rows=array_column($history['runs'],null,'id');
 k1b_assert($rows[1]['known_worker_http_calls']===2,'run_counter_mixed_source_tenant_or_attempt');
 k1b_assert($rows[1]['uncertain_worker_http_calls']===1,'uncertain_send_not_counted');
 k1b_assert($rows[2]['known_worker_http_calls']===1,'later_attempt_lost');
 k1b_assert($rows[1]['physical_http_calls']===null && $rows[1]['evidence_state']==='PARTIAL','worker_subset_presented_as_cycle_total');
 k1b_assert($history['source_engine']==='queue_v4_clean','legacy_history_as_current');
 echo "PASS V4 history exact worker evidence, uncertain consumes, cycle total UNKNOWN\n";
} finally { $db->cleanup(); }
}
