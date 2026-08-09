<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Core\Database;
use PDO;
final class QueueCoreFactory
{
    /** @return array{repository:QueueCoreRepository,registry:QueueHandlerRegistry,runner:QueueRunner,producer:FreshOrdersProducer,webhook_producer:WebhookProducer,oauth_supervisor:QueueCoreOAuthSupervisor,execution_leases:QueueExecutionLeaseService,capabilities:QueueCapabilityRegistry,sale_pipeline:SalePipelineCapabilityRepository,feature_flags:QueueCoreFeatureFlagService} */
    public static function build(?PDO $pdo=null,?FreshOrdersGateway $gateway=null): array
    {
        $pdo??=Database::connectionFresh();
        $repository=new QueueCoreRepository($pdo);
        $flags=new QueueCoreFeatureFlagService($pdo);
        $salePipeline=new SalePipelineCapabilityRepository($pdo,$repository);
        $registry=new QueueHandlerRegistry();
        $registry->register('fresh_orders_discovery',new FreshOrdersDiscoveryHandler($pdo,$repository,$gateway??new MeliFreshOrdersGateway()));
        $registry->register('order_exact',new OrderExactHandler(null,$flags->enabled('pack_shipment_followups')?$salePipeline:null));
        $registry->register('financial_projection',new FinancialProjectionHandler($salePipeline));
        $registry->register('pack_exact',new PackExactHandler($salePipeline));
        $registry->register('shipment_exact',new ShipmentExactHandler($salePipeline));
        $webhookTriggers=new WebhookTriggerService($pdo);
        $webhookGateway=new MeliWebhookExactGateway();
        $registry->register('webhook_order_exact',new WebhookOrderExactHandler($webhookTriggers,$webhookGateway));
        $registry->register('webhook_pack_exact',new WebhookPackExactHandler($webhookTriggers,$webhookGateway));
        $registry->register('webhook_shipment_exact',new WebhookShipmentExactHandler($webhookTriggers,$webhookGateway));
        $registry->register('oauth_refresh',new QueueCoreOAuthRefreshHandler(null,$pdo));
        $registry->register('manual_exact',new ManualExactHandler());
        $leases=new QueueExecutionLeaseService($pdo);
        $capabilities=new QueueCapabilityRegistry();
        return ['repository'=>$repository,'registry'=>$registry,
            'runner'=>new QueueRunner($repository,$registry,$leases,$capabilities),
            'producer'=>new FreshOrdersProducer($pdo,$repository),
            'webhook_producer'=>new WebhookProducer($pdo,$repository,$webhookTriggers),
            'oauth_supervisor'=>new QueueCoreOAuthSupervisor($pdo,$repository),
            'execution_leases'=>$leases,'capabilities'=>$capabilities,
            'sale_pipeline'=>$salePipeline,'feature_flags'=>$flags];
    }
}
