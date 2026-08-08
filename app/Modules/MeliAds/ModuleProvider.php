<?php
declare(strict_types=1);
namespace App\Modules\MeliAds;
use App\Core\Modules\AbstractModuleProvider;
use App\Core\Router;
use App\Modules\MeliAds\Controllers\DashboardController;
final class ModuleProvider extends AbstractModuleProvider
{
    public function id(): string { return 'meli-ads'; }
    public function label(): string { return 'Mercado Ads'; }
    public function version(): string { return '1.0.0'; }
    public function registerRoutes(Router $router): void { $router->get('/meli-ads',[DashboardController::class,'index']); $router->post('/meli-ads/refresh',[DashboardController::class,'refresh']); }
    public function navigation(): array { return [['section'=>'growth','section_label'=>'Crecimiento','section_icon'=>'chart','href'=>'/meli-ads','icon'=>'chart','label'=>'Mercado Ads','matches'=>['/meli-ads'],'roles'=>['admin','operator','consulta']]]; }
    public function jobTypes(): array { return ['snapshot_sync']; }
    public function processJob(array $job): array { return (new Services\AdsSyncService())->process($job); }
    public function healthChecks(): array { return [['key'=>'readonly','label'=>'Solo lectura','ok'=>true,'message'=>'Crear o modificar campañas está bloqueado.']]; }
}
