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
        $depth=[];$q=$this->pdo->query("SELECT lane,company_id,meli_account_id,state,COUNT(*) total,MIN(created_at) oldest,TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP(3)) oldest_age_seconds FROM queue_core_jobs WHERE state IN ('pending','claimed','running','retry_wait','waiting_oauth','review','dead') GROUP BY lane,company_id,meli_account_id,state");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$depth[]=$r;
        $attempts=$this->pdo->query("SELECT COALESCE(SUM(physical_http_calls),0) physical_http,COALESCE(SUM(dispatch_reserved_at IS NOT NULL),0) dispatch_reserved,COALESCE(SUM(response_known_at IS NOT NULL),0) response_known,COALESCE(SUM(outcome='completed'),0) completed_jobs,COALESCE(SUM(resources_discovered),0) discovered,COALESCE(SUM(resources_persisted),0) persisted FROM queue_core_attempts WHERE started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE)")->fetch(PDO::FETCH_ASSOC)?:[];
        $last=$this->pdo->query("SELECT MAX(response_known_at) last_http_response_known_at,MAX(CASE WHEN resources_persisted>0 THEN finished_at END) last_resource_persisted_at,MAX(source_closed_at) last_source_closed_at FROM queue_core_attempts")->fetch(PDO::FETCH_ASSOC)?:[];
        $cumulative=$this->pdo->query("SELECT COUNT(*) jobs_total,SUM(state='completed') completed_jobs,SUM(state='review') review_jobs,SUM(state='dead') dead_jobs FROM queue_core_jobs")->fetch(PDO::FETCH_ASSOC)?:[];
        $window=['created'=>(int)($events['created']['events']??0),'claimed'=>(int)($events['claimed']['events']??0),'dispatch_reserved'=>(int)($attempts['dispatch_reserved']??0),'physical_http_started'=>(int)($attempts['physical_http']??0),'response_known'=>(int)($attempts['response_known']??0),'completed_jobs'=>(int)($attempts['completed_jobs']??0),'resources_discovered'=>(int)($attempts['discovered']??0),'resources_persisted'=>(int)($attempts['persisted']??0)];
        return [
            'window_60m'=>$window,
            'current_depth'=>$depth,
            'cumulative'=>array_map('intval',$cumulative),
            'last_http_response_known_at'=>$last['last_http_response_known_at']?:null,
            'last_resource_persisted_at'=>$last['last_resource_persisted_at']?:null,
            'last_source_closed_at'=>$last['last_source_closed_at']?:null,
            // Compatibility aliases keep old readers working, with honest
            // 60-minute semantics and physical HTTP only.
            'jobs_created'=>$window['created'],'jobs_claimed'=>$window['claimed'],
            'jobs_dispatched'=>$window['physical_http_started'],'jobs_successful'=>$window['completed_jobs'],
            'jobs_retry_wait'=>(int)($events['retry_wait']['events']??0),'jobs_dead'=>(int)($events['dead']['events']??0),
            'fresh_orders_discovered'=>$window['resources_discovered'],'resources_persisted'=>$window['resources_persisted'],
            'queue_depth'=>$depth,'last_useful_progress_at'=>$last['last_resource_persisted_at']?:$last['last_http_response_known_at']?:null,
        ];
    }
}
