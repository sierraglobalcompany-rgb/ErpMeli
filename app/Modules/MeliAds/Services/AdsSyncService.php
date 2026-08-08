<?php
declare(strict_types=1);
namespace App\Modules\MeliAds\Services;
use App\Core\Database;
use App\Modules\Shared\Services\AbstractModuleSyncService;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Services\MeliEndpointRegistry;
final class AdsSyncService extends AbstractModuleSyncService
{
    protected function syncAccount(int $accountId,array $account,array $job): array
    {
        $path = '/advertising/advertisers';
        if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
            return [
                'account_id' => $accountId,
                'processed' => 0,
                'errors' => 0,
                'status' => 'ignored_unsupported',
                'stage' => 'endpoint_not_confirmed',
                'safe_message' => 'Mercado Ads permanece deshabilitado hasta confirmar su contrato en el mapa API local.',
            ];
        }
        try{
            $payload=(new MeliReadGateway())->get('meli-ads',$accountId,$path,[],'module_ads');
            $rows=$payload['advertisers']??$payload['results']??[];
            $count=0;
            foreach(is_array($rows)?$rows:[] as $row){
                if(!is_array($row)||empty($row['advertiser_id'])&&empty($row['id'])){continue;}
                $id=(string)($row['advertiser_id']??$row['id']);
                Database::connection()->prepare(
                    'INSERT INTO ml_ads_advertisers (meli_account_id,external_advertiser_id,name,status,snapshot_json,observed_at)
                     VALUES (?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()'
                )->execute([$accountId,$id,$row['name']??'Advertiser',$row['status']??'supported',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $count++;
            }
            return ['account_id'=>$accountId,'processed'=>$count,'errors'=>0];
        }catch(\Throwable){return ['account_id'=>$accountId,'processed'=>0,'errors'=>1];}
    }
}
