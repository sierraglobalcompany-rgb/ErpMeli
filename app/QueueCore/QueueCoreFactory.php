<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Core\Database;
use PDO;
final class QueueCoreFactory
{
    /** @return array{repository:QueueCoreRepository,registry:QueueHandlerRegistry,runner:QueueRunner,producer:FreshOrdersProducer,oauth_supervisor:QueueCoreOAuthSupervisor,execution_leases:QueueExecutionLeaseService,capabilities:QueueCapabilityRegistry} */
    public static function build(?PDO $pdo=null,?FreshOrdersGateway $gateway=null): array
    {$pdo??=Database::connectionFresh();$repository=new QueueCoreRepository($pdo);$registry=new QueueHandlerRegistry();$registry->register('fresh_orders_discovery',new FreshOrdersDiscoveryHandler($pdo,$repository,$gateway??new MeliFreshOrdersGateway()));$registry->register('order_exact',new OrderExactHandler());$registry->register('oauth_refresh',new QueueCoreOAuthRefreshHandler(null,$pdo));$registry->register('manual_exact',new ManualExactHandler());$leases=new QueueExecutionLeaseService($pdo);$capabilities=new QueueCapabilityRegistry();return ['repository'=>$repository,'registry'=>$registry,'runner'=>new QueueRunner($repository,$registry,$leases,$capabilities),'producer'=>new FreshOrdersProducer($pdo,$repository),'oauth_supervisor'=>new QueueCoreOAuthSupervisor($pdo,$repository),'execution_leases'=>$leases,'capabilities'=>$capabilities];}
}
