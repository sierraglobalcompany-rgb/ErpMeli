<?php
declare(strict_types=1);
namespace App\Modules\MeliPostSale;
use App\Core\Modules\AbstractModuleProvider;
use App\Core\Router;
use App\Modules\MeliPostSale\Controllers\DashboardController;
final class ModuleProvider extends AbstractModuleProvider
{
    public function id(): string { return 'meli-postsale'; }
    public function label(): string { return 'Posventa'; }
    public function version(): string { return '1.0.0'; }
    public function registerRoutes(Router $router): void { foreach(['messages','returns','reviews'] as $page){$router->get('/meli-postsale/'.$page,[DashboardController::class,'index']);} $router->get('/meli-postsale',[DashboardController::class,'index']); $router->post('/meli-postsale/refresh',[DashboardController::class,'refresh']);}
    public function navigation(): array { return [
        $this->nav('/meli-postsale/messages','Mensajes'),
        $this->nav('/meli-postsale/returns','Devoluciones'),
        $this->nav('/meli-postsale/reviews','Opiniones'),
    ]; }
    public function eventTopics(): array { return ['message','claim','order']; }
    public function jobTypes(): array { return ['snapshot_sync','event_sync']; }
    public function processJob(array $job): array { return (new Services\PostSaleSyncService())->process($job); }
    public function healthChecks(): array { return [['key'=>'readonly','label'=>'Solo lectura','ok'=>true,'message'=>'No responde mensajes ni modifica reclamos.']]; }
    private function nav(string $href,string $label): array { return ['section'=>'sales','section_label'=>'Ventas','section_icon'=>'cart','href'=>$href,'icon'=>'bell','label'=>$label,'matches'=>[$href],'roles'=>['admin','operator','consulta']]; }
}
