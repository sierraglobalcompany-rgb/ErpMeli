<?php
declare(strict_types=1);
namespace App\QueueCore;
interface FreshOrdersGateway
{
    /** @return array{orders:list<array{id:string,date_created:string,input_version?:string}>,next_cursor:?string,has_more:bool,http_status?:int,response_count_state?:string,paging_offset?:int,paging_total?:int,received_count?:int} */
    public function fetch(int $companyId,int $meliAccountId,string $from,string $to,?string $cursor,int $limit): array;
}
