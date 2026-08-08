<?php
declare(strict_types=1);
namespace App\Modules\MeliLogistics;
use App\Core\Modules\AbstractModuleProvider;
use App\Core\Router;
use App\Modules\MeliLogistics\Controllers\DashboardController;
final class ModuleProvider extends AbstractModuleProvider
{
    public function id(): string { return 'meli-logistics'; }
    public function label(): string { return 'Logística avanzada'; }
    public function version(): string { return '1.0.0'; }
    public function registerRoutes(Router $router): void { foreach(['shipments','inventory','reconciliation'] as $page){$router->get('/meli-logistics/'.$page,[DashboardController::class,'index']);} $router->get('/meli-logistics',[DashboardController::class,'index']); $router->post('/meli-logistics/refresh',[DashboardController::class,'refresh']);}
    public function navigation(): array { return [
        $this->nav('sales','Ventas','/meli-logistics/shipments','Logística avanzada'),
        $this->nav('products','Productos','/meli-logistics/inventory','Inventario por origen'),
        $this->nav('products','Productos','/meli-logistics/reconciliation','Conciliación de inventario'),
    ]; }
    public function eventTopics(): array { return ['shipment','stock_location']; }
    public function jobTypes(): array { return ['snapshot_sync','event_sync']; }
    public function processJob(array $job): array { return (new Services\LogisticsSyncService())->process($job); }
    public function healthChecks(): array { return [['key'=>'readonly','label'=>'Inventario seguro','ok'=>true,'message'=>'No modifica stock remoto ni inventario interno.']]; }
    private function nav(string $section,string $sectionLabel,string $href,string $label): array { return ['section'=>$section,'section_label'=>$sectionLabel,'section_icon'=>$section==='sales'?'cart':'building','href'=>$href,'icon'=>'link','label'=>$label,'matches'=>[$href],'roles'=>['admin','operator','consulta']]; }
}
