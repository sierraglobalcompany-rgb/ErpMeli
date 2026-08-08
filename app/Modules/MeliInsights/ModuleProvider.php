<?php
declare(strict_types=1);
namespace App\Modules\MeliInsights;
use App\Core\Modules\AbstractModuleProvider;
use App\Core\Router;
use App\Modules\MeliInsights\Controllers\DashboardController;
final class ModuleProvider extends AbstractModuleProvider
{
    public function id(): string { return 'meli-insights'; }
    public function label(): string { return 'Rendimiento Mercado Libre'; }
    public function version(): string { return '1.1.1'; }
    public function registerRoutes(Router $router): void {
        $router->get('/meli-insights/performance', [DashboardController::class, 'performance']);
        $router->get('/meli-insights/pricing', [DashboardController::class, 'pricing']);
        $router->get('/meli-insights/moderations', [DashboardController::class, 'moderations']);
        $router->get('/meli-insights/capabilities', [DashboardController::class, 'capabilities']);
        $router->get('/meli-insights', [DashboardController::class, 'index']);
        $router->post('/meli-insights/refresh', [DashboardController::class, 'refresh']);
        $router->post('/meli-insights/jobs/action', [DashboardController::class, 'jobAction']);
    }
    public function navigation(): array { return [
        $this->nav('products','Productos','chart','/meli-insights/performance','chart','Rendimiento y calidad'),
        $this->nav('products','Productos','chart','/meli-insights/pricing','money','Precios y competencia'),
        $this->nav('administration','Administración','users','/meli-insights/capabilities','file','Capacidades Mercado Libre',['admin']),
    ]; }
    public function eventTopics(): array { return ['item','items_prices']; }
    public function jobTypes(): array { return ['snapshot_sync','event_sync']; }
    public function processJob(array $job): array { return (new Services\InsightsSyncService())->process($job); }
    public function healthChecks(): array { return [['key'=>'schema','label'=>'Esquema aislado','ok'=>true,'message'=>'El módulo usa tablas ml_insights_*.']]; }
    private function nav(string $section,string $sectionLabel,string $sectionIcon,string $href,string $icon,string $label,array $roles=['admin','operator','consulta']): array {
        return compact('section','sectionLabel','sectionIcon','href','icon','label','roles') + ['section_label'=>$sectionLabel,'section_icon'=>$sectionIcon,'matches'=>[$href]];
    }
}
