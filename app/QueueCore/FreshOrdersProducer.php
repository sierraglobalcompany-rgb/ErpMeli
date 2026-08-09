<?php
declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

final class FreshOrdersProducer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueCoreRepository $repository,
        private readonly int $overlapSeconds=300,
        private readonly int $initialLookbackSeconds=0,
        private readonly int $maximumWindowSeconds=86400,
    ){}

    /** @return array{accounts:int,enqueued:int,bootstrap_required:int} */
    public function scheduleDueAccounts(int $limit=20): array
    {
        // Eligibility is filtered before LIMIT. Accounts with a future
        // checkpoint cannot hide a later due account.
        $q=$this->pdo->query("SELECT a.id,a.company_id
            FROM meli_accounts a
            LEFT JOIN queue_core_producer_checkpoints cp
              ON cp.producer_key='fresh_orders' AND cp.company_id=a.company_id AND cp.meli_account_id=a.id
            WHERE a.status IN ('conectado','connected')
              AND (cp.meli_account_id IS NULL OR cp.next_due_at<=UTC_TIMESTAMP(3))
            ORDER BY COALESCE(cp.next_due_at,'1970-01-01') ASC,a.company_id ASC,a.id ASC
            LIMIT ".max(1,min(100,$limit)));
        $accounts=0;$enqueued=0;$bootstrap_required=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $account){
            $company=(int)$account['company_id'];$accountId=(int)$account['id'];if($company<1||$accountId<1)continue;
            $this->pdo->beginTransaction();
            try{
                $insert=$this->pdo->prepare("INSERT IGNORE INTO queue_core_producer_checkpoints (producer_key,company_id,meli_account_id,watermark_at,window_from,window_to,next_due_at,generation) VALUES ('fresh_orders',?,?,NULL,NULL,NULL,UTC_TIMESTAMP(3),0)");$insert->execute([$company,$accountId]);
                $s=$this->pdo->prepare("SELECT * FROM queue_core_producer_checkpoints WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? FOR UPDATE");$s->execute([$company,$accountId]);$cp=$s->fetch(PDO::FETCH_ASSOC);
                $nextDue=is_array($cp)?strtotime((string)$cp['next_due_at'].' UTC'):false;
                if(!is_array($cp)||($nextDue!==false&&$nextDue>time())){$this->pdo->commit();continue;}
                $generation=(int)$cp['generation'];$to=(string)($cp['window_to']??'');$from=(string)($cp['window_from']??'');
                if($to===''||$from===''){
                    $now=time();
                    $watermark=(string)($cp['watermark_at']??'');
                    $watermarkTime=$watermark!==''?strtotime($watermark.' UTC'):false;
                    $bootstrap=$watermarkTime!==false
                        ? ['at'=>$watermarkTime,'source'=>'queue_core_checkpoint']
                        : $this->bootstrapAuthority($company,$accountId);
                    if($bootstrap===null){
                        $blocked=$this->pdo->prepare("UPDATE queue_core_producer_checkpoints SET last_error_class='bootstrap_required',next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),updated_at=UTC_TIMESTAMP(3) WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? AND generation=?");
                        $blocked->execute([$company,$accountId,$generation]);
                        if($blocked->rowCount()!==1)throw new RuntimeException('Fresh Orders bootstrap fence changed.');
                        $this->pdo->commit();$bootstrap_required++;continue;
                    }
                    $base=(int)$bootstrap['at']-$this->overlapSeconds;
                    $base=max(0,min($base,$now));
                    $window=max(300,min(86400*7,$this->maximumWindowSeconds));
                    $windowTo=min($now,$base+$window);
                    if($windowTo<=$base)$windowTo=min($now,$base+300);
                    $from=gmdate('Y-m-d H:i:s',$base);
                    $to=gmdate('Y-m-d H:i:s',$windowTo);
                    $windowUpdate=$this->pdo->prepare("UPDATE queue_core_producer_checkpoints SET window_from=?,window_to=?,cursor_value=NULL,last_error_class=NULL WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? AND generation=?");
                    $windowUpdate->execute([$from,$to,$company,$accountId,$generation]);
                    if($windowUpdate->rowCount()!==1)throw new RuntimeException('Fresh Orders window fence changed.');
                }
                $cursor=$cp['cursor_value']!==null?(string)$cp['cursor_value']:null;
                $this->pdo->commit();
                $job=$this->discoveryJob($company,$accountId,$from,$to,$cursor,$generation);
                $jobId=$this->repository->enqueue($job);
                $this->repository->reviveExhaustedTransient($jobId,$job->workType,$job->inputVersion);
                $accounts++;$enqueued++;
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        return compact('accounts','enqueued','bootstrap_required');
    }

    private function discoveryJob(int $company,int $account,string $from,string $to,?string $cursor,int $generation): QueueJob
    {
        $identity=hash('sha256',implode('|',[$company,$account,$from,$to,$cursor??'0',$generation]));
        return new QueueJob($company,$account,'fresh_orders_discovery','orders_window',null,'fresh_orders',100,$identity,(string)$generation,'fresh_orders_producer','checkpoint:fresh_orders:'.$account,['from'=>$from,'to'=>$to,'cursor'=>$cursor,'generation'=>$generation,'limit'=>20],['producer'=>'native_fresh_orders','overlap_seconds'=>$this->overlapSeconds],5);
    }

    /** @return array{at:int,source:string}|null */
    private function bootstrapAuthority(int $companyId,int $accountId): ?array
    {
        $local=$this->latestLocalOrderAt($companyId,$accountId);
        if($local!==null)return ['at'=>$local,'source'=>'local_order_max'];
        $legacy=$this->certifiedLegacyCheckpointAt($companyId,$accountId);
        if($legacy!==null)return ['at'=>$legacy,'source'=>'certified_legacy_checkpoint'];
        $explicit=$this->explicitBootstrapAt($companyId,$accountId);
        if($explicit!==null)return ['at'=>$explicit,'source'=>'explicit_bootstrap_from'];
        if($this->initialLookbackSeconds>0)return ['at'=>time()-$this->initialLookbackSeconds,'source'=>'explicit_constructor_lookback'];
        return null;
    }

    private function latestLocalOrderAt(int $companyId,int $accountId): ?int
    {
        $column=null;
        foreach(['date_created_ml','date_created_utc','date_created'] as $candidate){
            $s=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="meli_orders" AND column_name=?');
            $s->execute([$candidate]);
            if((int)$s->fetchColumn()>0){
                $max=$this->pdo->prepare('SELECT MAX(o.`'.$candidate.'`) FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id WHERE o.meli_account_id=? AND a.company_id=?');
                $max->execute([$accountId,$companyId]);
                $value=trim((string)($max->fetchColumn()?:''));
                if($value==='')continue;
                $timestamp=strtotime($value.' UTC');
                if($timestamp!==false)return $timestamp;
            }
        }
        return null;
    }

    private function certifiedLegacyCheckpointAt(int $companyId,int $accountId): ?int
    {
        if(!$this->tableExists('meli_sync_checkpoints'))return null;
        $s=$this->pdo->prepare("SELECT c.range_to FROM meli_sync_checkpoints c JOIN meli_accounts a ON a.id=c.meli_account_id WHERE c.meli_account_id=? AND a.company_id=? AND c.sync_type='orders' AND c.cursor_value IS NULL AND c.status IN ('complete','completed') AND c.range_to IS NOT NULL AND c.range_to<=UTC_TIMESTAMP() ORDER BY c.range_to DESC LIMIT 1");
        $s->execute([$accountId,$companyId]);$value=trim((string)($s->fetchColumn()?:''));
        $at=$value===''?false:strtotime($value.' UTC');return $at===false?null:$at;
    }

    private function explicitBootstrapAt(int $companyId,int $accountId): ?int
    {
        if(!$this->tableExists('app_settings'))return null;
        $key='queue_core.fresh_orders.bootstrap_from.'.$companyId.'.'.$accountId;
        $s=$this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $s->execute([$key]);$value=trim((string)($s->fetchColumn()?:''));
        $at=$value===''?false:strtotime($value.' UTC');return $at===false?null:$at;
    }

    private function tableExists(string $table): bool
    {
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $s->execute([$table]);return (int)$s->fetchColumn()>0;
    }
}
