<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class ModuleHealthService
{
    /**
     * @return list<array{module:string,path:string,controller:string,view:string,status:string,message:string}>
     */
    public function expectedModules(): array
    {
        $root = dirname(__DIR__, 2);
        $modules = [
            ['Panel', '/', 'DashboardController.php', 'dashboard/index.php'],
            ['Órdenes', '/orders', 'OrderController.php', 'orders/index.php'],
            ['Sincronizaciones', '/sync', 'SyncController.php', 'sync/index.php'],
            ['Packs', '/packs', 'PackController.php', 'sales/packs/index.php'],
            ['Envíos', '/shipments', 'ShipmentController.php', 'sales/shipments/index.php'],
            ['Pagos', '/payments', 'PaymentController.php', 'sales/payments/index.php'],
            ['Preguntas', '/questions', 'QuestionController.php', 'sales/questions/index.php'],
            ['Reclamos', '/claims', 'ClaimController.php', 'sales/claims/index.php'],
            ['Productos ML', '/products/meli', 'MeliProductController.php', 'products/meli/index.php'],
            ['Bodega', '/products/internal', 'InternalProductController.php', 'products/internal/index.php'],
            ['Importaciones', '/products/imports', 'ProductImportController.php', 'products/imports/index.php'],
            ['Vinculación', '/products/links', 'ProductLinkController.php', 'products/links/index.php'],
            ['Facturación mensual', '/billing', 'MonthlyReportController.php', 'billing/index.php'],
            ['Facturación por fechas', '/billing/date', 'DateReportController.php', 'billing/date/index.php'],
            ['Reportes', '/reports/profitability', 'ProfitabilityController.php', 'reports/profitability/index.php'],
            ['Logs', '/logs', 'LogController.php', 'logs/index.php'],
            ['Configuración', '/settings', 'SettingsController.php', 'settings/index.php'],
            ['Usuarios', '/users', 'UserController.php', 'users/index.php'],
            ['Empresas', '/companies', 'CompanyController.php', 'companies/index.php'],
        ];

        return array_map(static function (array $module) use ($root): array {
            [$name, $path, $controller, $view] = $module;
            $controllerExists = is_file($root . '/app/Controllers/' . $controller);
            $viewExists = is_file($root . '/app/Views/' . $view);
            $ok = $controllerExists && $viewExists;
            return [
                'module' => $name,
                'path' => $path,
                'controller' => $controller,
                'view' => $view,
                'status' => $ok ? 'ok' : 'missing',
                'message' => $ok ? 'Archivos base presentes' : 'Falta controlador o vista',
            ];
        }, $modules);
    }

    public function record(string $module, string $status, string $message, array $context = []): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO module_health_checks (module_name,status,message,context_json,checked_at)
                 VALUES (:module,:status,:message,:context,NOW())'
            );
            $stmt->execute([
                'module' => mb_substr($module, 0, 120),
                'status' => in_array($status, ['ok', 'warning', 'error'], true) ? $status : 'warning',
                'message' => mb_substr($message, 0, 500),
                'context' => json_encode(Logger::redact($context), JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            Logger::write($status === 'error' ? 'error' : 'warning', 'No se pudo registrar diagnóstico de módulo.', [
                'module' => $module,
                'status' => $status,
            ]);
        }
    }
}
