<?php
declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use Throwable;

final class FreshOrdersProducer
{
    public function __construct(private readonly PDO $pdo,private readonly QueueCoreRepository $repository,private readonly int $overlapSeconds=300,private readonly int $initialLookbackSeconds=86400){}

    /** @return array{accounts:int,enqueued:int} */
    public function scheduleDueAccounts(int $limit=20): array
    {
        $q=$this->pdo->query("SELECT id,company_id FROM meli_accounts WHERE status IN ('conectado','connected') ORDER BY company_id,id LIMIT ".max(1,min(100,$limit)));
        $accounts=0;$enqueued=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $account){
            $company=(int)$account['company_id'];$accountId=(int)$account['id'];if($company<1||$accountId<1)continue;
            $this->pdo->beginTransaction();
            try{
                $insert=$this->pdo->prepare("INSERT IGNORE INTO queue_core_producer_checkpoints (producer_key,company_id,meli_account_id,watermark_at,window_from,window_to,next_due_at,generation) VALUES ('fresh_orders',?,?,NULL,NULL,NULL,UTC_TIMESTAMP(3),0)");$insert->execute([$company,$accountId]);
                $s=$this->pdo->prepare("SELECT * FROM queue_core_producer_checkpoints WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? FOR UPDATE");$s->execute([$company,$accountId]);$cp=$s->fetch(PDO::FETCH_ASSOC);
                $nextDue=is_array($cp)?strtotime((string)$cp['next_due_at'].' UTC'):false;
                if(!is_array($cp)||($nextDue!==false&&$nextDue>time())){$this->pdo->commit();continue;}
                $generation=(int)$cp['generation'];$to=(string)($cp['window_to']??'');$from=(string)($cp['window_from']??'');
                if($to===''||$from===''){$to=gmdate('Y-m-d H:i:s');$watermark=(string)($cp['watermark_at']??'');$watermarkTime=$watermark!==''?strtotime($watermark.' UTC'):false;$base=$watermarkTime!==false?$watermarkTime-$this->overlapSeconds:time()-$this->initialLookbackSeconds;$from=gmdate('Y-m-d H:i:s',max(0,$base));$this->pdo->prepare("UPDATE queue_core_producer_checkpoints SET window_from=?,window_to=?,cursor_value=NULL WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? AND generation=?")->execute([$from,$to,$company,$accountId,$generation]);}
                $cursor=$cp['cursor_value']!==null?(string)$cp['cursor_value']:null;
                $this->pdo->commit();
                $this->repository->enqueue($this->discoveryJob($company,$accountId,$from,$to,$cursor,$generation));$accounts++;$enqueued++;
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        return compact('accounts','enqueued');
    }

    private function discoveryJob(int $company,int $account,string $from,string $to,?string $cursor,int $generation): QueueJob
    {
        $identity=hash('sha256',implode('|',[$company,$account,$from,$to,$cursor??'0',$generation]));
        return new QueueJob($company,$account,'fresh_orders_discovery','orders_window',null,'fresh_orders',100,$identity,(string)$generation,'fresh_orders_producer','checkpoint:fresh_orders:'.$account,['from'=>$from,'to'=>$to,'cursor'=>$cursor,'generation'=>$generation,'limit'=>20],['producer'=>'native_fresh_orders','overlap_seconds'=>$this->overlapSeconds],5);
    }
}
