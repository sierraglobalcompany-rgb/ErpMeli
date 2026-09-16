<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$beforePointers = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_job_orders")->fetchColumn();
$beforeQueue = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200")->fetchColumn();

$pdo->exec('DROP TRIGGER IF EXISTS r0_h3_fail_pack_queue_insert');
$pdo->exec(
    "CREATE TRIGGER r0_h3_fail_pack_queue_insert
     BEFORE INSERT ON queue_v4_clean_jobs
     FOR EACH ROW
     BEGIN
       IF NEW.job_type='domain_exact' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.capability'))='order_enrichment_pack' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='r0_after_creation_admission_failure';
       END IF;
     END"
);

$thrown = null;
try {
    $producer = new App\QueueV4Clean\QueueV4CleanProducer($pdo, new App\QueueV4Clean\QueueV4CleanRepository($pdo));
    $method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
    $method->invoke($producer, [
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ]);
} catch (Throwable $error) {
    $thrown = $error;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS r0_h3_fail_pack_queue_insert');
}

$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$afterPointers = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_job_orders")->fetchColumn();
$afterQueue = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200")->fetchColumn();

echo json_encode([
    'thrown' => $thrown ? $thrown->getMessage() : null,
    'before_sources' => $beforeSources,
    'after_sources' => $afterSources,
    'before_pointers' => $beforePointers,
    'after_pointers' => $afterPointers,
    'before_queue' => $beforeQueue,
    'after_queue' => $afterQueue,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

r0h3_assert($thrown !== null && str_contains($thrown->getMessage(), 'r0_after_creation_admission_failure'), 'test_trigger_must_reach_post_coverage_admission_failure', ['thrown' => $thrown ? $thrown->getMessage() : null]);
r0h3_assert($afterSources === $beforeSources, 'post_creation_failure_must_rollback_created_source', ['before' => $beforeSources, 'after' => $afterSources]);
r0h3_assert($afterPointers === $beforePointers, 'post_creation_failure_must_rollback_source_pointer', ['before' => $beforePointers, 'after' => $afterPointers]);
r0h3_assert($afterQueue === $beforeQueue, 'post_creation_failure_must_not_leave_queue_pointer', ['before' => $beforeQueue, 'after' => $afterQueue]);

