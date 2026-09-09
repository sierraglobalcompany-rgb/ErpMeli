<?php
declare(strict_types=1);

/** Upgrade the legacy worker-only fixture's physical ledger from the canonical migration. */
function cap2_transport_install_legacy_journal(PDO $pdo):void
{
    $sql=file_get_contents(__DIR__.'/../database/migrations/297_queue_v4_transport_sales_api_health_2_38_5.sql');
    if(!is_string($sql) || !preg_match('/CREATE TABLE IF NOT EXISTS queue_v4_clean_transport_events\s*\([\s\S]*?\) ENGINE=[^;]+;/',$sql,$match))throw new RuntimeException('canonical_transport_table_fixture_missing');
    $pdo->exec($match[0]);
}

/** These old tests isolate worker control flow; mirror its simulated call in the real journal. */
function cap2_transport_record_legacy_call(PDO $pdo,array $job,int $status,?string $request=null):void
{
    $request ??= bin2hex(random_bytes(20));
    App\QueueV4Clean\QueueV4CleanTransportJournal::started($pdo,(int)$job['company_id'],(int)$job['meli_account_id'],'queue',(int)$job['id'],(int)$job['attempt_id'],(int)$job['lease_generation'],$request,'GET','order_exact');
    App\QueueV4Clean\QueueV4CleanTransportJournal::enteringCurl($request);
    App\QueueV4Clean\QueueV4CleanTransportJournal::responseKnown($pdo,(int)$job['company_id'],(int)$job['meli_account_id'],'queue',$request,$status);
}
