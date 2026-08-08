<?php
declare(strict_types=1);
namespace App\Modules\MeliGrowth;
use App\Core\Modules\AbstractModuleProvider;
use App\Core\Router;
use App\Modules\MeliGrowth\Controllers\DashboardController;
use App\Services\SchemaInspectorService;
final class ModuleProvider extends AbstractModuleProvider
{
    public function id(): string { return 'meli-growth'; }
    public function label(): string { return 'Crecimiento'; }
    public function version(): string { return '1.1.0'; }
    public function registerRoutes(Router $router): void {
        $router->get('/meli-growth',[DashboardController::class,'index']);
        $router->get('/meli-growth/promotions',[DashboardController::class,'promotions']);
        $router->get('/meli-growth/performance',[DashboardController::class,'performance']);
        $router->get('/meli-growth/trends',[DashboardController::class,'trends']);
        $router->post('/meli-growth/refresh',[DashboardController::class,'refresh']);
        $router->post('/meli-growth/jobs/action',[DashboardController::class,'jobAction']);
    }
    public function navigation(): array { return [
        $this->nav('/meli-growth/promotions','Promociones'),
        $this->nav('/meli-growth/performance','Rendimiento comercial'),
        $this->nav('/meli-growth/trends','Tendencias'),
    ]; }
    public function eventTopics(): array { return ['promotion_candidate','promotion_offer']; }
    public function jobTypes(): array { return ['snapshot_sync','event_sync']; }
    public function processJob(array $job): array { return (new Services\GrowthSyncService())->process($job); }
    public function healthChecks(): array {
        $schema = new SchemaInspectorService();
        $required = ['ml_growth_promotions','ml_growth_account_visits_daily','ml_growth_conversion_daily','ml_growth_capabilities'];
        $missing = array_values(array_filter($required, static fn(string $table): bool => !$schema->hasTable($table)));
        return [[
            'key'=>'schema',
            'label'=>'Datos aislados de Growth',
            'ok'=>$missing === [],
            'message'=>$missing === [] ? 'El módulo tiene sus tablas y puede habilitarse para una cuenta canaria.' : 'Faltan migraciones internas del módulo.',
        ]];
    }
    private function nav(string $href,string $label): array { return ['section'=>'growth','section_label'=>'Crecimiento','section_icon'=>'chart','href'=>$href,'icon'=>'chart','label'=>$label,'matches'=>[$href],'roles'=>['admin','operator','consulta']]; }
}
