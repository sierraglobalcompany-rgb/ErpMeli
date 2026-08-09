<?php
declare(strict_types=1);

namespace App\QueueCore;

final class QueueCapabilityRegistry
{
    /** @var array<string,array{lanes:list<string>,scope:string,operation:string,transport:string,retry:string,result:string,test:string}> */
    private const CAPABILITIES = [
        'fresh_orders_discovery' => ['lanes' => ['fresh_orders'], 'scope' => 'company_account', 'operation' => 'orders_search_page', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'checkpoint_and_exact_jobs', 'test' => 'producer_checkpoint_overlap'],
        'order_exact' => ['lanes' => ['fresh_orders'], 'scope' => 'company_account', 'operation' => 'order_exact', 'transport' => 'documented_read', 'retry' => 'safe_read', 'result' => 'order_persisted', 'test' => 'duplicate_producer_call'],
        'manual_exact' => ['lanes' => ['normal','local'], 'scope' => 'company_account', 'operation' => 'adapter_exact', 'transport' => 'adapter_declared', 'retry' => 'adapter_exact', 'result' => 'adapter_terminal', 'test' => 'manual_cron_same_claim_path'],
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

    /** @return array{lanes:list<string>,scope:string,operation:string,transport:string,retry:string,result:string,test:string}|null */
    public function definition(string $workType): ?array
    {
        return self::CAPABILITIES[$workType] ?? null;
    }
}
