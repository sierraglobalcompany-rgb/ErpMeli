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
            'source'=>self::canonical($source),
            'state'=>$state->sourceState,
            'operation'=>$state->operationKey,
            'uses_api'=>$state->usesApi,
            'next_eligible_at'=>$state->nextEligibleAt,
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
}
