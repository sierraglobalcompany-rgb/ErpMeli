<?php
declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

final class FreshOrdersDiscoveryHandler implements QueueHandler
{
    public function __construct(private readonly PDO $pdo,private readonly QueueCoreRepository $repository,private readonly FreshOrdersGateway $gateway){}
    public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult
    {
        if(!$context->hasTime(1.0))return QueueResult::retry('deadline',gmdate('Y-m-d H:i:s',time()+5));
        $from=(string)($job->payload['from']??'');$to=(string)($job->payload['to']??'');$cursor=isset($job->payload['cursor'])?(string)$job->payload['cursor']:null;$generation=(int)($job->payload['generation']??-1);$limit=max(1,min(20,(int)($job->payload['limit']??20)));
        if($from===''||$to===''||$generation<0) return QueueResult::dead('invalid_fresh_window');
        $page=$this->gateway->fetch($job->companyId,$job->meliAccountId,$from,$to,$cursor,$limit);
        $discovered=0;
        foreach($page['orders'] as $order){$id=trim((string)$order['id']);if($id===''||!ctype_digit($id))continue;$version=(string)($order['input_version']??hash('sha256',$id.'|'.(string)$order['date_created']));$this->repository->enqueue(new QueueJob($job->companyId,$job->meliAccountId,'order_exact','order',$id,'fresh_orders',90,'order:'.$id,$version,'fresh_orders_discovery','order:'.$id,['order_id'=>$id,'date_created'=>(string)$order['date_created']],['window_from'=>$from,'window_to'=>$to],5));$discovered++;}
        $status=(int)($page['http_status']??200);
        $countState=(string)($page['response_count_state']??'complete');
        $requestedOffset=max(0,(int)($cursor??'0'));
        $pagingOffset=max(0,(int)($page['paging_offset']??$requestedOffset));
        $received=max(0,(int)($page['received_count']??count($page['orders'])));
        $total=max(0,(int)($page['paging_total']??($page['has_more']?$pagingOffset+$received+1:$pagingOffset+$received)));
        $nextCursor=$page['next_cursor']!==null?(int)$page['next_cursor']:null;
        $partial=$status===206 || $countState==='partial' || $countState==='unknown';
        $incoherent=$pagingOffset!==$requestedOffset
            || ($total>$pagingOffset && $received===0)
            || ($page['has_more'] && ($nextCursor===null || $nextCursor<=$pagingOffset))
            || ($nextCursor!==null && $nextCursor!==$pagingOffset+$received);
        if($partial || $incoherent){
            return QueueResult::retry(
                $partial?'partial_remote_page':'incoherent_remote_paging',
                gmdate('Y-m-d H:i:s',time()+5)
            );
        }
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare("SELECT generation,window_from,window_to,cursor_value FROM queue_core_producer_checkpoints WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? FOR UPDATE");$s->execute([$job->companyId,$job->meliAccountId]);$cp=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($cp)||(int)$cp['generation']!==$generation
                || self::sqlTime((string)$cp['window_from'])!==self::sqlTime($from)
                || self::sqlTime((string)$cp['window_to'])!==self::sqlTime($to)){
                $this->pdo->rollBack();return QueueResult::completed(0,$discovered);
            }
            $nextGeneration=$generation+1;
            if($page['has_more']&&$page['next_cursor']!==null){$u=$this->pdo->prepare("UPDATE queue_core_producer_checkpoints SET cursor_value=?,generation=?,last_discovered=?,last_enqueued=?,last_error_class=NULL,updated_at=UTC_TIMESTAMP(3) WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? AND generation=?");$u->execute([(string)$page['next_cursor'],$nextGeneration,$discovered,$discovered,$job->companyId,$job->meliAccountId,$generation]);}
            else{$watermark=self::sqlTime($to);$nextDue=(strtotime($to.' UTC')?:time())<time()-60?gmdate('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s',time()+60);$u=$this->pdo->prepare("UPDATE queue_core_producer_checkpoints SET watermark_at=?,window_from=NULL,window_to=NULL,cursor_value=NULL,next_due_at=?,generation=?,last_discovered=?,last_enqueued=?,last_error_class=NULL,updated_at=UTC_TIMESTAMP(3) WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? AND generation=?");$u->execute([$watermark,$nextDue,$nextGeneration,$discovered,$discovered,$job->companyId,$job->meliAccountId,$generation]);}
            if($u->rowCount()!==1)throw new RuntimeException('Fresh Orders checkpoint CAS was lost.');
            $this->pdo->commit();
            if($page['has_more']&&$page['next_cursor']!==null){$next=new QueueJob($job->companyId,$job->meliAccountId,'fresh_orders_discovery','orders_window',null,'fresh_orders',100,hash('sha256',implode('|',[$job->companyId,$job->meliAccountId,$from,$to,(string)$page['next_cursor'],$nextGeneration])),(string)$nextGeneration,'fresh_orders_producer','checkpoint:fresh_orders:'.$job->meliAccountId,['from'=>$from,'to'=>$to,'cursor'=>(string)$page['next_cursor'],'generation'=>$nextGeneration,'limit'=>$limit],['producer'=>'native_fresh_orders'],5);$this->repository->enqueue($next);}
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return QueueResult::completed(0,$discovered);
    }
    private static function sqlTime(string $iso): string {$t=strtotime($iso.' UTC');return gmdate('Y-m-d H:i:s',$t===false?time():$t);}
}
