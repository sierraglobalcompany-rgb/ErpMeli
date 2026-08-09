<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\CampaignItemState;

final class ManualInputVersion
{
    /** @param array<string,mixed> $source */
    public static function derive(array $source,CampaignItemState $state): string
    {
        $evidence=[
            'source'=>self::durableSource($source),
            'state'=>$state->sourceState,
            'operation'=>$state->operationKey,
            'uses_api'=>$state->usesApi,
        ];
        return hash('sha256',json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $previewRow */
    public static function deriveFromSourceAuthority(
        array $previewRow,
        ManualSourceAuthority $authority
    ): string {
        if(preg_match('/^[a-f0-9]{64}$/',$authority->durableInputVersion)!==1){
            throw new \RuntimeException('La fuente exacta no entregó una versión durable certificada.');
        }
        $identity=[];
        foreach(['queue_key','source_id','meli_account_id'] as $key){
            if(array_key_exists($key,$previewRow))$identity[$key]=$previewRow[$key];
        }
        $evidence=[
            'identity'=>self::canonical($identity),
            'durable_source_version'=>$authority->durableInputVersion,
            'operation'=>$authority->operationKey,
            'uses_api'=>$authority->usesApi,
        ];
        return hash('sha256',json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(self::canonical(...),$value);
        ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=self::canonical($item);
        return $value;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private static function durableSource(array $source): array
    {
        $keys=[
            'queue_key','source_id','meli_account_id','company_id','resource_id',
            'external_id','status','state','source_state','version','source_version',
            'input_version','generation','updated_at','source_updated_at','payload_hash',
        ];
        $durable=[];
        foreach($keys as $key){if(array_key_exists($key,$source))$durable[$key]=$source[$key];}
        return self::canonical($durable);
    }
}
