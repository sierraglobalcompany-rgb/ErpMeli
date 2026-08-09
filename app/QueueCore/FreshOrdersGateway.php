<?php
declare(strict_types=1);
namespace App\QueueCore;
interface FreshOrdersGateway
{
    /** @return array{orders:list<array{id:string,date_created:string,input_version?:string}>,next_cursor:?string,has_more:bool} */
    public function fetch(int $companyId,int $meliAccountId,string $from,string $to,?string $cursor,int $limit): array;
}
