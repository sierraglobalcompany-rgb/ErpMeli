<?php
declare(strict_types=1);
namespace App\QueueCore;
use PDO;
final class QueueMetricsService
{
    public function __construct(private readonly PDO $pdo){}
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $events=[];$q=$this->pdo->query("SELECT event_type,SUM(event_count) events,SUM(resources_count) resources FROM queue_core_events WHERE occurred_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE) GROUP BY event_type");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$events[(string)$r['event_type']]=['events'=>(int)$r['events'],'resources'=>(int)$r['resources']];
        $depth=[];$q=$this->pdo->query("SELECT lane,company_id,meli_account_id,state,COUNT(*) total,MIN(created_at) oldest FROM queue_core_jobs WHERE state IN ('pending','claimed','running','retry_wait','review','dead') GROUP BY lane,company_id,meli_account_id,state");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$depth[]=$r;
        $attempts=$this->pdo->query("SELECT COALESCE(SUM(physical_http_calls),0) dispatched,COALESCE(SUM(resources_discovered),0) discovered,COALESCE(SUM(resources_persisted),0) persisted FROM queue_core_attempts WHERE started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE)")->fetch(PDO::FETCH_ASSOC)?:[];
        $useful=$this->pdo->query("SELECT MAX(occurred_at) FROM queue_core_events WHERE event_type IN ('response_known','completed') AND (event_type='response_known' OR resources_count>0)")->fetchColumn();
        $ages=$this->pdo->query("SELECT TIMESTAMPDIFF(SECOND,MIN(CASE WHEN lane='fresh_orders' AND state IN ('pending','retry_wait') THEN created_at END),UTC_TIMESTAMP(3)) oldest_fresh_age_seconds,TIMESTAMPDIFF(SECOND,MIN(CASE WHEN lane='historical_backfill' AND state IN ('pending','retry_wait') THEN created_at END),UTC_TIMESTAMP(3)) oldest_historical_age_seconds,SUM(CASE WHEN lane='historical_backfill' AND state='completed' THEN 1 ELSE 0 END) historical_completed,SUM(CASE WHEN lane='fresh_orders' AND work_type='order_exact' AND state='completed' THEN 1 ELSE 0 END) fresh_orders_completed FROM queue_core_jobs")->fetch(PDO::FETCH_ASSOC)?:[];
        return ['jobs_created'=>(int)($events['created']['events']??0),'jobs_claimed'=>(int)($events['claimed']['events']??0),'jobs_dispatched'=>(int)($attempts['dispatched']??0),'jobs_successful'=>(int)($events['completed']['events']??0),'jobs_retry_wait'=>(int)($events['retry_wait']['events']??0),'jobs_dead'=>(int)($events['dead']['events']??0),'fresh_orders_discovered'=>(int)($attempts['discovered']??0),'fresh_orders_persisted'=>(int)($ages['fresh_orders_completed']??0),'historical_completed'=>(int)($ages['historical_completed']??0),'resources_persisted'=>(int)($attempts['persisted']??0),'oldest_fresh_age_seconds'=>$ages['oldest_fresh_age_seconds']!==null?(int)$ages['oldest_fresh_age_seconds']:null,'oldest_historical_age_seconds'=>$ages['oldest_historical_age_seconds']!==null?(int)$ages['oldest_historical_age_seconds']:null,'queue_depth'=>$depth,'last_useful_progress_at'=>$useful!==false?(string)$useful:null];
    }
}
