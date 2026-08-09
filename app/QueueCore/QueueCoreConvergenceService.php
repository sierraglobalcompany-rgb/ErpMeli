<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;

/** Compara identidades capturadas por readiness contra la autoridad local. */
final class QueueCoreConvergenceService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function compare(int $companyId,int $accountId,string $fromUtc,string $toUtc,int $limit=500): array
    {
        $limit=max(1,min(1000,$limit));
        $from=strtotime($fromUtc.' UTC');$to=strtotime($toUtc.' UTC');
        if($companyId<1||$accountId<1||$from===false||$to===false||$from>$to
            ||!$this->accountBelongsToCompany($companyId,$accountId)){
            throw new RuntimeException('Queue Core convergence scope or window is invalid.');
        }
        $authority=$this->pdo->query(
            "SELECT generation,readiness_context_hash FROM queue_engine_control
             WHERE control_key='primary' AND active_engine='disabled' AND readiness_mode='preparing'"
        )->fetch(PDO::FETCH_ASSOC);
        $generation=max(0,(int)($authority['generation']??-1));
        $contextHash=strtolower(trim((string)($authority['readiness_context_hash']??'')));
        if(!is_array($authority)||preg_match('/^[a-f0-9]{64}$/',$contextHash)!==1){
            throw new RuntimeException('Queue Core convergence readiness authority is unavailable.');
        }
        $captureQuery=$this->pdo->prepare(
            'SELECT id,window_from,window_to,page_offset,response_count,complete
             FROM queue_core_readiness_captures
             WHERE engine_generation=? AND readiness_context_hash=?
               AND company_id=? AND meli_account_id=?
               AND window_to>=? AND window_from<=?
             ORDER BY window_from,page_offset,id LIMIT '.($limit+1)
        );
        $fromSql=gmdate('Y-m-d H:i:s',$from);$toSql=gmdate('Y-m-d H:i:s',$to);
        $captureQuery->execute([$generation,$contextHash,$companyId,$accountId,$fromSql,$toSql]);
        $captures=$captureQuery->fetchAll(PDO::FETCH_ASSOC);
        if(count($captures)>$limit)throw new RuntimeException('Queue Core convergence evidence exceeds its bounded limit.');
        $windows=[];$captureIds=[];$groups=[];
        foreach($captures as $capture){
            $key=(string)$capture['window_from'].'|'.(string)$capture['window_to'];
            $groups[$key][]=$capture;
        }
        foreach($groups as $pages){
            usort($pages,static fn(array $a,array $b):int=>(int)$a['page_offset']<=>(int)$b['page_offset']);
            $expectedOffset=0;$terminal=false;$pageIds=[];
            foreach($pages as $page){
                if((int)$page['page_offset']!==$expectedOffset || $terminal){
                    $pageIds=[];break;
                }
                $pageIds[]=(int)$page['id'];
                $expectedOffset+=(int)$page['response_count'];
                $terminal=(int)$page['complete']===1;
            }
            if(!$terminal||$pageIds===[])continue;
            $start=strtotime((string)$pages[0]['window_from'].' UTC');
            $end=strtotime((string)$pages[0]['window_to'].' UTC');
            if($start===false||$end===false)continue;
            $windows[]=[max($from,$start),min($to,$end)];
            array_push($captureIds,...$pageIds);
        }
        $coverage=$this->continuousCoverage($windows,$from,$to);
        $remote=$this->remoteIdentities($captureIds,$limit);
        $local=$this->localIdentities($companyId,$accountId,$fromSql,$toSql,$limit);
        $missingLocal=array_values(array_diff($remote,$local));
        $unexpectedLocal=array_values(array_diff($local,$remote));
        $unresolved=$this->unresolvedExactCount($companyId,$accountId);
        $passed=$coverage&&$unresolved===0&&$missingLocal===[]&&$unexpectedLocal===[];
        return [
            'ok'=>$passed,'company_id'=>$companyId,'meli_account_id'=>$accountId,
            'window_from_utc'=>$fromSql,'window_to_utc'=>$toSql,
            'authoritative_page_count'=>count($captures),'remote_identity_count'=>count($remote),
            'local_identity_count'=>count($local),'missing_local_count'=>count($missingLocal),
            'unexpected_local_count'=>count($unexpectedLocal),'unresolved_exact_count'=>$unresolved,
            'continuous_coverage'=>$coverage,
            'authoritative_empty_window'=>$coverage&&$remote===[]&&$local===[],
            'capture_set_hash'=>hash('sha256',implode("\n",$remote)),
            'local_set_hash'=>hash('sha256',implode("\n",$local)),
            'remote_http_calls'=>0,'business_db_writes'=>0,
        ];
    }

    /** @param list<int> $captureIds @return list<string> */
    private function remoteIdentities(array $captureIds,int $limit): array
    {
        if($captureIds===[])return [];
        $placeholders=implode(',',array_fill(0,count($captureIds),'?'));
        $statement=$this->pdo->prepare(
            'SELECT DISTINCT resource_id FROM queue_core_readiness_capture_items
             WHERE capture_id IN ('.$placeholders.') ORDER BY resource_id LIMIT '.($limit+1)
        );
        $statement->execute($captureIds);$ids=array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN));
        if(count($ids)>$limit)throw new RuntimeException('Queue Core remote identity set exceeds its bounded limit.');
        sort($ids,SORT_STRING);return $ids;
    }

    /** @return list<string> */
    private function localIdentities(int $companyId,int $accountId,string $fromUtc,string $toUtc,int $limit): array
    {
        $dateColumn=$this->orderDateColumn();
        $statement=$this->pdo->prepare(
            'SELECT CAST(o.external_order_id AS CHAR) resource_id FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.`'.$dateColumn.'` BETWEEN ? AND ?
             ORDER BY o.external_order_id LIMIT '.($limit+1)
        );
        $statement->execute([$companyId,$accountId,$fromUtc,$toUtc]);
        $ids=array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN));
        if(count($ids)>$limit)throw new RuntimeException('Queue Core local identity set exceeds its bounded limit.');
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);return $ids;
    }

    /** @param list<array{0:int,1:int}> $windows */
    private function continuousCoverage(array $windows,int $from,int $to): bool
    {
        if($windows===[])return false;
        usort($windows,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);$covered=$from;
        foreach($windows as [$start,$end]){if($start>$covered+1)return false;$covered=max($covered,$end);if($covered>=$to)return true;}
        return false;
    }

    private function unresolvedExactCount(int $companyId,int $accountId): int
    {
        $statement=$this->pdo->prepare(
            "SELECT COUNT(*) FROM queue_core_jobs WHERE company_id=? AND meli_account_id=?
             AND work_type IN ('fresh_orders_discovery','order_exact','webhook_order_exact')
             AND state IN ('pending','claimed','running','retry_wait','waiting_oauth','review','dead')"
        );
        $statement->execute([$companyId,$accountId]);return max(0,(int)$statement->fetchColumn());
    }

    private function orderDateColumn(): string
    {
        foreach(['date_created_ml','date_created_utc','date_created'] as $candidate){
            $statement=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="meli_orders" AND column_name=?');
            $statement->execute([$candidate]);if((int)$statement->fetchColumn()===1)return $candidate;
        }
        throw new RuntimeException('Queue Core convergence order timestamp authority is unavailable.');
    }

    private function accountBelongsToCompany(int $companyId,int $accountId): bool
    {
        $statement=$this->pdo->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND status IN ('conectado','connected')");
        $statement->execute([$accountId,$companyId]);return (int)$statement->fetchColumn()===1;
    }
}
