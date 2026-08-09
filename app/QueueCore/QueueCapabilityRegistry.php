<?php
declare(strict_types=1);

namespace App\QueueCore;

final class QueueCapabilityRegistry
{
    /** @var array<string,array{lanes:list<string>,scope:string,operation:string,transport:string,retry:string,result:string,test:string,domain:string,launchers:list<string>,method:?string,endpoint_pattern:?string,profile:?string,max_remote_calls:int}> */
    private const CAPABILITIES = [
        'fresh_orders_discovery' => ['lanes' => ['fresh_orders'], 'scope' => 'company_account', 'operation' => 'orders_search_page', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'checkpoint_and_exact_jobs', 'test' => 'producer_checkpoint_overlap', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/orders/search$~','profile'=>'orders_search','max_remote_calls'=>1],
        'order_exact' => ['lanes' => ['fresh_orders'], 'scope' => 'company_account', 'operation' => 'order_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'order_persisted', 'test' => 'duplicate_producer_call', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/orders/[0-9]+$~','profile'=>'order_exact','max_remote_calls'=>1],
        'financial_projection' => ['lanes' => ['local'], 'scope' => 'company_account', 'operation' => 'financial_projection', 'transport' => 'local_only', 'retry' => 'idempotent_local', 'result' => 'financial_state_persisted', 'test' => 'financial_projection_local_only', 'domain'=>'operational','launchers'=>['cron_v4','test'],'method'=>null,'endpoint_pattern'=>null,'profile'=>null,'max_remote_calls'=>0],
        'pack_exact' => ['lanes' => ['normal'], 'scope' => 'company_account', 'operation' => 'pack_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'pack_container_persisted', 'test' => 'pack_container_no_duplicate_sale', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/packs/[0-9]+$~','profile'=>'pack_exact','max_remote_calls'=>1],
        'shipment_exact' => ['lanes' => ['normal'], 'scope' => 'company_account', 'operation' => 'shipment_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'shipment_persisted', 'test' => 'shipment_dependency_exact', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/shipments/[0-9]+$~','profile'=>'shipment_exact','max_remote_calls'=>1],
        'webhook_order_exact' => ['lanes' => ['recovery'], 'scope' => 'company_account', 'operation' => 'order_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'webhook_trigger_closed', 'test' => 'webhook_trigger_coalescing', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/orders/[0-9]+$~','profile'=>'order_exact','max_remote_calls'=>1],
        'webhook_pack_exact' => ['lanes' => ['recovery'], 'scope' => 'company_account', 'operation' => 'pack_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'webhook_trigger_closed', 'test' => 'webhook_trigger_coalescing', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/packs/[0-9]+$~','profile'=>'pack_exact','max_remote_calls'=>1],
        'webhook_shipment_exact' => ['lanes' => ['recovery'], 'scope' => 'company_account', 'operation' => 'shipment_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'webhook_trigger_closed', 'test' => 'webhook_trigger_coalescing', 'domain'=>'operational','launchers'=>['cron_v4','canary_v4','test'],'method'=>'GET','endpoint_pattern'=>'~^/shipments/[0-9]+$~','profile'=>'shipment_exact','max_remote_calls'=>1],
        'oauth_refresh' => ['lanes' => ['recovery'], 'scope' => 'company_account', 'operation' => 'oauth', 'transport' => 'documented_technical_post', 'retry' => 'durable_rotated_credential', 'result' => 'token_generation_advanced', 'test' => 'oauth_supervisor_single_flight', 'domain'=>'operational','launchers'=>['cron_v4','test'],'method'=>'POST','endpoint_pattern'=>'~^/oauth/token$~','profile'=>'oauth','max_remote_calls'=>1],
        'manual_exact' => ['lanes' => ['normal','local'], 'scope' => 'company_account', 'operation' => 'adapter_exact', 'transport' => 'adapter_declared', 'retry' => 'adapter_exact', 'result' => 'adapter_terminal', 'test' => 'manual_cron_same_claim_path', 'domain'=>'manual','launchers'=>['manual','test'],'method'=>null,'endpoint_pattern'=>null,'profile'=>null,'max_remote_calls'=>1],
    ];

    public function certified(string $workType, QueueHandlerRegistry $handlers): bool
    {
        $definition=self::CAPABILITIES[$workType]??null;
        return is_array($definition) && $handlers->has($workType)
            && $definition['lanes']!==[] && $definition['scope']==='company_account'
            && $definition['operation']!=='' && $definition['transport']!==''
            && $definition['retry']!=='' && $definition['result']!=='' && $definition['test']!=='';
    }

    /** @return list<string> */
    public function certifiedTypes(QueueHandlerRegistry $handlers): array
    {
        return array_values(array_filter(array_keys(self::CAPABILITIES), fn (string $type): bool => $this->certified($type, $handlers)));
    }

    /** @return array<string,mixed>|null */
    public function definition(string $workType): ?array
    {
        return self::CAPABILITIES[$workType] ?? null;
    }

    public function authorityHash(): string
    {
        return hash('sha256', json_encode(self::CAPABILITIES, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{domain:string,launcher:string,method:string,endpoint_pattern:string,profile:string,max_remote_calls:int,uses_api:bool}|null */
    public function transportContract(QueueClaim $claim,string $launcher): ?array
    {
        $definition=self::CAPABILITIES[$claim->workType]??null;
        if(!is_array($definition) || !in_array($launcher,$definition['launchers'],true))return null;
        if($claim->workType==='manual_exact'){
            $dynamic=$claim->payload['remote_contract']??null;
            if(!is_array($dynamic))return [
                'domain'=>'manual','launcher'=>$launcher,'method'=>'','endpoint_pattern'=>'',
                'profile'=>'','max_remote_calls'=>0,'uses_api'=>false,
            ];
            return [
                'domain'=>'manual','launcher'=>$launcher,
                'method'=>(string)($dynamic['method']??''),
                'endpoint_pattern'=>(string)($dynamic['endpoint_pattern']??''),
                'profile'=>(string)($dynamic['operation_key']??''),
                'max_remote_calls'=>max(0,(int)($dynamic['max_remote_calls']??0)),
                'uses_api'=>true,
            ];
        }
        $usesApi=(int)$definition['max_remote_calls']>0;
        return [
            'domain'=>(string)$definition['domain'],'launcher'=>$launcher,
            'method'=>(string)$definition['method'],
            'endpoint_pattern'=>(string)$definition['endpoint_pattern'],
            'profile'=>(string)$definition['profile'],
            'max_remote_calls'=>(int)$definition['max_remote_calls'],'uses_api'=>$usesApi,
        ];
    }
}
