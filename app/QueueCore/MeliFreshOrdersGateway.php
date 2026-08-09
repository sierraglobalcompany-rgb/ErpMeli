<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\MeliApiClient;
use RuntimeException;

final class MeliFreshOrdersGateway implements FreshOrdersGateway
{
    public function fetch(int $companyId,int $meliAccountId,string $from,string $to,?string $cursor,int $limit): array
    {
        $s=Database::connectionFresh()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=? AND company_id=? AND status IN (\'conectado\',\'connected\') LIMIT 1');
        $s->execute([$meliAccountId,$companyId]);
        $seller=trim((string)$s->fetchColumn());
        if($seller==='' || !ctype_digit($seller))throw new RuntimeException('Fresh Orders account identity is unavailable.');
        $offset=max(0,(int)($cursor??'0'));$limit=max(1,min(20,$limit));
        $fromIso=gmdate('Y-m-d\TH:i:s.000\Z',strtotime($from.' UTC')?:time()-86400);
        $toIso=gmdate('Y-m-d\TH:i:s.000\Z',strtotime($to.' UTC')?:time());
        $page=(new MeliApiClient($meliAccountId))->get('/orders/search',[
            'seller'=>$seller,'order.date_created.from'=>$fromIso,'order.date_created.to'=>$toIso,
            'sort'=>'date_asc','offset'=>$offset,'limit'=>$limit,
        ],['job_type'=>'fresh_orders_discovery','operation_key'=>'orders_search']);
        $orders=[];
        foreach(($page['results']??[]) as $order){if(!is_array($order))continue;$id=trim((string)($order['id']??''));if($id===''||!ctype_digit($id))continue;$date=(string)($order['date_created']??$fromIso);$version=(string)($order['last_updated']??$date);$orders[]=['id'=>$id,'date_created'=>$date,'input_version'=>hash('sha256',$id.'|'.$version)];}
        $paging=is_array($page['paging']??null)?$page['paging']:[];$total=max(0,(int)($paging['total']??count($orders)));$next=$offset+$limit;
        return ['orders'=>$orders,'next_cursor'=>$next<$total?(string)$next:null,'has_more'=>$next<$total];
    }
}
