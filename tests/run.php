<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
putenv('ML_WRITE_ENABLED=false');
require dirname(__DIR__) . '/bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use App\Core\Crypto;
use App\Core\Env;
use App\Services\InstallerService;
use App\Services\MeliApiException;
use App\Services\MonthlyReportStateMachine;
use App\Services\OAuthService;
use App\Services\PaymentDetailPolicy;
use App\Services\PaymentSummaryService;
use App\Services\PasswordPolicy;
use App\Services\SyncLockService;
use App\Services\SyncCenterService;
use App\Services\SyncSettingsService;
use App\Services\MeliDateTimeNormalizer;
use App\Services\TemporaryAccessService;
use App\Services\WriteGuard;
use App\Services\MeliEndpointRegistry;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    try { $callback(); $tests[] = ['ok', $name]; }
    catch (Throwable $e) { $tests[] = ['fail', $name . ': ' . $e->getMessage()]; }
};
$assert = static function (bool $condition, string $message='Fallo de aserción'): void { if (!$condition) throw new RuntimeException($message); };

$test('Crypto cifra con nonce y descifra correctamente', static function () use ($assert): void {
    $one = Crypto::encrypt('token-secreto');
    $two = Crypto::encrypt('token-secreto');
    $assert($one !== $two, 'El cifrado debe ser no determinista.');
    $assert(Crypto::decrypt($one) === 'token-secreto');
});

$test('WriteGuard bloquea mutaciones cuando la bandera está apagada', static function () use ($assert): void {
    $blocked = false;
    try { WriteGuard::assertAllowed(true); } catch (RuntimeException) { $blocked = true; }
    $assert($blocked);
    WriteGuard::assertAllowed(false);
});

$test('Flujo mensual permite revisión y aprobación ordenadas', static function () use ($assert): void {
    $assert(MonthlyReportStateMachine::can('borrador', 'revisado'));
    $assert(MonthlyReportStateMachine::can('revisado', 'aprobado'));
    $assert(MonthlyReportStateMachine::can('aprobado', 'facturado'));
    $assert(!MonthlyReportStateMachine::can('borrador', 'facturado'));
    $assert(!MonthlyReportStateMachine::can('facturado', 'borrador'));
});

$test('Instalador escapa el archivo de entorno y rechaza saltos de línea', static function () use ($assert): void {
    $original = 'abc\\"xyz';
    $encoded = InstallerService::encodeEnvValue($original);
    $decoder = new ReflectionMethod(Env::class, 'decodeValue');
    $assert($decoder->invoke(null, $encoded) === $original, 'El valor no sobrevivió el ciclo de escritura y lectura.');
    $blocked = false;
    try { InstallerService::encodeEnvValue("valor\nDB_USER=intruso"); } catch (RuntimeException) { $blocked = true; }
    $assert($blocked);
});

$test('Política de contraseña exige longitud, mayúscula, número y carácter especial', static function () use ($assert): void {
    $assert(PasswordPolicy::isValid('Ab1!xy'));
    $assert(PasswordPolicy::isValid('Ña1!xy'));
    $assert(!PasswordPolicy::isValid('A1!xy'));
    $assert(!PasswordPolicy::isValid('ab1!xy'));
    $assert(!PasswordPolicy::isValid('Abc!xy'));
    $assert(!PasswordPolicy::isValid('Ab12xy'));
});

$test('OAuth no se habilita sin credenciales de aplicación completas', static function () use ($assert): void {
    putenv('MELI_CLIENT_ID=');
    putenv('MELI_CLIENT_SECRET=');
    putenv('MELI_REDIRECT_URI=');
    $assert(!OAuthService::isConfigured());
    $blocked = false;
    try { OAuthService::assertConfigured(); } catch (RuntimeException) { $blocked = true; }
    $assert($blocked);
});

$test('Contraseña automática temporal cumple política fuerte', static function () use ($assert): void {
    $password = TemporaryAccessService::generatePassword();
    $assert(PasswordPolicy::isValid($password), 'La contraseña temporal generada debe cumplir la política.');
});

$test('Validación de acceso temporal detecta expiración y revocación', static function () use ($assert): void {
    $assert(TemporaryAccessService::temporaryAccessError(['is_temporary' => 0]) === null);
    $assert(TemporaryAccessService::temporaryAccessError(['is_temporary' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'revoked_at' => null]) === null);
    $assert(TemporaryAccessService::temporaryAccessError(['is_temporary' => 1, 'expires_at' => date('Y-m-d H:i:s', time() - 60), 'revoked_at' => null]) !== null);
    $assert(TemporaryAccessService::temporaryAccessError(['is_temporary' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'revoked_at' => date('Y-m-d H:i:s')]) !== null);
});

$test('SyncLockService genera llave determinística por cuenta tipo y rango', static function () use ($assert): void {
    $from = new DateTimeImmutable('2026-01-01 00:00:00');
    $to = new DateTimeImmutable('2026-01-31 23:59:59');
    $one = SyncLockService::makeKey(1, 'orders', $from, $to);
    $two = SyncLockService::makeKey(1, 'orders', $from, $to);
    $three = SyncLockService::makeKey(2, 'orders', $from, $to);
    $assert($one === $two && $one !== $three);
});

$test('Política de pago clasifica 404 como detalle no disponible', static function () use ($assert): void {
    $assert(PaymentDetailPolicy::statusFromException(new MeliApiException('no encontrado', 404)) === 'unavailable');
    $assert(PaymentDetailPolicy::statusFromException(new MeliApiException('rate limit', 429)) === 'error');
});

$test('Reporte mensual no reutiliza placeholder net en cierre de totales', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/Services/MonthlyReportService.php');
    $assert(is_string($source) && str_contains($source, 'estimated_net=:estimated_net,manual_base=:manual_base'));
    $assert(!str_contains($source, 'estimated_net=:net,manual_base=:net'));
});

$test('Configuración de sync usa valores seguros por defecto', static function () use ($assert): void {
    $settings = new SyncSettingsService();
    $assert($settings->maxManualRangeDays() >= 1);
    $assert($settings->pageLimit() <= 100);
    $assert($settings->maxOrdersPerRun() >= 1);
});

$test('ERP Meli 2.1 registra endpoints de lectura nuevos y bloquea rutas no mapeadas', static function () use ($assert): void {
    MeliEndpointRegistry::assertDocumented('GET', '/users/123/items/search');
    MeliEndpointRegistry::assertDocumented('GET', '/items/MCO123456789');
    MeliEndpointRegistry::assertDocumented('GET', '/post-purchase/v1/claims/search');
    MeliEndpointRegistry::assertDocumented('GET', '/questions/search');
    $blocked = false;
    try { MeliEndpointRegistry::assertDocumented('GET', '/post-purchase/v1/claims/123/detail'); }
    catch (RuntimeException) { $blocked = true; }
    $assert($blocked, 'Un endpoint ausente del mapa local debe fallar cerrado.');
});

$test('Migraciones 2.1 a 2.4.6 existen y mantienen entrega versionada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach (range(10, 28) as $number) {
        $matches = glob($root . '/database/migrations/' . sprintf('%03d', $number) . '_*.sql');
        $assert($matches !== false && count($matches) === 1, 'Falta migración ' . $number);
    }
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.4.6', '>='));
});

$test('2.1.2 corrige placeholders repetidos de Empresas y agrega facturación por fechas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $companyController = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
    $assert(str_contains($companyController, 'issuer_company_id=:issuer_id OR customer_company_id=:customer_id'));
    $assert(!str_contains($companyController, 'issuer_company_id=:id OR customer_company_id=:id'));
    $assert(is_file($root . '/app/Views/billing/date/index.php'));
    $assert(is_file($root . '/app/Views/billing/date/show.php'));
    $migration = (string) file_get_contents($root . '/database/migrations/016_date_billing_2_1_2.sql');
    $assert(str_contains($migration, 'issuer_company_id'));
    $assert(str_contains($migration, 'external_invoice_reference'));
});

$test('2.1 incluye vistas y controladores principales sin llamadas de escritura Mercado Libre', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Controllers/MeliProductController.php',
        '/app/Controllers/InternalProductController.php',
        '/app/Controllers/ProductLinkController.php',
        '/app/Controllers/DateReportController.php',
        '/app/Controllers/ClaimController.php',
        '/app/Views/products/unlinked/index.php',
        '/app/Views/billing/date/index.php',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $source = file_get_contents($root . '/app/Services/MeliApiClient.php');
    $assert(is_string($source) && str_contains($source, 'WriteGuard::assertAllowed($mutation)'));
});

$test('2.3/2.5 divide meses con rangos semiabiertos sin solapar', static function () use ($assert): void {
    $service = new SyncCenterService();
    $weekly = $service->rangesForMonth(2024, 2, 'weekly', 0);
    $parts = $service->rangesForMonth(2026, 1, 'parts', 10);
    $daily = $service->rangesForMonth(2026, 1, 'daily', 0);
    $assert($weekly[0][0]->format('Y-m-d') === '2024-02-01');
    $assert($weekly[count($weekly) - 1][1]->format('Y-m-d') === '2024-03-01');
    $assert(count($parts) === 10);
    $assert($parts[0][0]->format('Y-m-d') === '2026-01-01');
    $assert($parts[count($parts) - 1][1]->format('Y-m-d') === '2026-02-01');
    $assert(count($daily) === 31 && $daily[0][1]->format('Y-m-d') === '2026-01-02');
    for ($i = 1; $i < count($parts); $i++) {
        $assert($parts[$i][0] >= $parts[$i - 1][1], 'Los rangos por partes no deben solaparse.');
    }
});

$test('2.3 agrega centro de sincronización, cola, cobertura e importaciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Controllers/SyncController.php',
        '/app/Controllers/ProductImportController.php',
        '/app/Services/SyncQueueService.php',
        '/app/Services/SyncCoverageService.php',
        '/app/Services/ProductImportService.php',
        '/jobs/process_sync_queue.php',
        '/app/Views/sync/index.php',
        '/app/Views/products/imports/index.php',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/017_sync_center_imports_2_3.sql');
    foreach (['sync_batches', 'sync_batch_chunks', 'sync_diagnostics', 'meli_sync_coverage', 'product_import_sources'] as $table) {
        $assert(str_contains($migration, $table), 'Falta tabla ' . $table);
    }
});

$test('2.3.1 agrega gestión de sync, cron, timezone y filtros operativos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Services/CronHealthService.php',
        '/app/Services/DateTimePresenter.php',
        '/app/Views/settings/cron.php',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/018_sync_management_cron_timezone.sql');
    foreach (['cron_health_checks', 'app.timezone', 'sync.monitor_refresh_seconds'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/sync/chunk/cancel', '/sync/status', '/settings/cron/test'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta ' . $route);
    }
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $assert(str_contains($cronView, 'data-cron-test-form') && str_contains($cronView, 'data-cron-test-status'), 'Falta preloader de prueba cron en vista');
    $assert(str_contains($settingsController, 'application/json') && str_contains($settingsController, "'manual_web'") && str_contains($settingsController, "query('SELECT 1')"), 'Prueba cron debe responder JSON sin procesar la cola automática');
    $payments = (string) file_get_contents($root . '/app/Views/sales/payments/index.php');
    $claims = (string) file_get_contents($root . '/app/Views/sales/claims/index.php');
    $assert(str_contains($payments, 'payment_method_id') && str_contains($payments, 'detail_status'));
    $assert(str_contains($claims, 'Cuenta Mercado Libre') && !str_contains($claims, 'Cuenta ID'));
});

$test('2.4 agrega guardia API, preguntas, agenda cron, logs y mejoras de ventas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Services/ApiGuardService.php',
        '/app/Services/QuestionSyncService.php',
        '/app/Services/LogQueryService.php',
        '/app/Controllers/QuestionController.php',
        '/app/Views/sales/questions/index.php',
        '/app/Views/sync/schedule.php',
        '/app/Views/sync/guardrails.php',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $migration19 = (string) file_get_contents($root . '/database/migrations/019_api_guardrails_2_4.sql');
    $migration20 = (string) file_get_contents($root . '/database/migrations/020_questions_2_4.sql');
    foreach (['api_request_logs', 'api_circuit_breakers', 'api.guard.cooldown_minutes'] as $needle) {
        $assert(str_contains($migration19, $needle), 'Falta ' . $needle);
    }
    foreach (['meli_questions', 'question_notifications', 'questions.sync_enabled'] as $needle) {
        $assert(str_contains($migration20, $needle), 'Falta ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/sync/schedule', '/sync/process-now.json', '/sync/guardrails', '/questions', '/shipments/export'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta ' . $route);
    }
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $assert(str_contains($client, 'ApiGuardService'));
    $assert(str_contains($client, 'retryDelaySeconds'));
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $navigation = (string) file_get_contents($root . '/app/Repositories/NavigationRepository.php');
    $assert(str_contains($layout . $navigation, '/questions'));
    $assert(
        str_contains($layout, 'AssetVersionService::fingerprint')
        && str_contains($layout, "View::asset(\$base, 'app.css')"),
        'Los assets deben usar fingerprint y el despachador de la release activa.'
    );
    $shipments = (string) file_get_contents($root . '/app/Views/sales/shipments/index.php')
        . (string) file_get_contents($root . '/app/Views/sales/shipments/_table.php');
    $assert(str_contains($shipments, 'logistic_type') && str_contains($shipments, 'return_to='));
});

$test('2.4.1 agrega búsqueda avanzada, detalle de publicaciones, miniaturas y envíos pendientes', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Views/products/meli/show.php',
        '/database/migrations/023_search_products_shipments_labels_2_4_1.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/products/meli/show'), 'Falta ruta detalle Productos ML');
    $productController = (string) file_get_contents($root . '/app/Controllers/MeliProductController.php');
    $assert(str_contains($productController, 'link_counts'), 'Productos ML debe usar conteo agregado sin GROUP BY i.*');
    $assert(str_contains($productController, 'Logger::write'), 'Productos ML debe registrar errores técnicos');
    $productIndex = (string) file_get_contents($root . '/app/Views/products/meli/index.php')
        . (string) file_get_contents($root . '/app/Views/products/meli/_table.php');
    $assert(str_contains($productIndex, 'product-thumb'), 'Productos ML debe mostrar miniatura');
    $saleReader = $root . '/app/Services/SaleReadService.php';
    $orderController = (string) file_get_contents(
        is_file($saleReader) ? $saleReader : $root . '/app/Controllers/OrderController.php'
    );
    $advancedNeedles = is_file($saleReader)
        ? ['external_pack_id', 'si.title LIKE', 'buyer_nickname LIKE', 'EXISTS (']
        : ['search_type', 'soi.title LIKE', 'buyer_nickname LIKE', 'EXISTS ('];
    foreach ($advancedNeedles as $needle) {
        $assert(str_contains($orderController, $needle), 'Falta búsqueda avanzada: ' . $needle);
    }
    $orderShow = (string) file_get_contents(
        is_file($root . '/app/Views/sales/show.php')
            ? $root . '/app/Views/sales/show.php'
            : $root . '/app/Views/orders/show.php'
    );
    $assert(str_contains($orderShow, 'mercadolibre.com.co/ventas/'), 'Falta enlace externo a Mercado Libre');
    $shipmentService = (string) file_get_contents($root . '/app/Services/ShipmentQueryService.php');
    foreach (['pending_today', 'logisticsGroups', 'fulfillment', 'self_service', 'cross_docking', 'xd_drop_off', 'drop_off'] as $needle) {
        $assert(str_contains($shipmentService, $needle), 'Falta filtro/envío: ' . $needle);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/023_search_products_shipments_labels_2_4_1.sql');
    foreach (['meli_shipment_labels', 'shipping_labels.enabled', 'idx_order_items_title_2_4_1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.4.1: ' . $needle);
    }
    if (is_file($root . '/docs/mercadolibre_api_map.md')) {
        $map = (string) file_get_contents($root . '/docs/mercadolibre_api_map.md');
        $assert(str_contains($map, 'Etiquetas de envío para Colombia')
            && str_contains($map, 'sin habilitar'));
    }
});

$test('2.4.2 estabiliza Pagos con diagnóstico y fuente orders payments', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Services/SchemaInspectorService.php',
        '/app/Services/ModuleHealthService.php',
        '/database/migrations/024_payments_audit_stability_2_4_2.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $paymentService = (string) file_get_contents($root . '/app/Services/PaymentSummaryService.php');
    $assert(str_contains($paymentService, 'diagnostics('));
    $assert(str_contains($paymentService, "'summary'"));
    $assert(str_contains($paymentService, 'missing_detail'));
    $paymentPolicy = (string) file_get_contents($root . '/app/Services/PaymentDetailPolicy.php');
    $assert(str_contains($paymentPolicy, 'payments.expand_details_enabled'));
    $sync = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $assert(str_contains($sync, 'SchemaInspectorService'));
    $paymentsView = (string) file_get_contents($root . '/app/Views/sales/payments/index.php');
    $assert(str_contains($paymentsView, 'Diagnóstico de pagos'));
    $settingsDiagnostics = (string) file_get_contents($root . '/app/Views/settings/diagnostics.php');
    $assert(str_contains($settingsDiagnostics, 'Módulos principales'));
    $migration = (string) file_get_contents($root . '/database/migrations/024_payments_audit_stability_2_4_2.sql');
    foreach (['payments.expand_details_enabled', 'payments.source', 'module_health_checks', '2.4.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.4.2: ' . $needle);
    }
    if (is_file($root . '/docs/mercadolibre_api_map.md')) {
        $map = (string) file_get_contents($root . '/docs/mercadolibre_api_map.md');
        foreach (['orders[].payments[]', 'GET /payments/{payment_id}', 'permanece bloqueado'] as $needle) {
            $assert(str_contains($map, $needle), 'Falta documentación de pagos: ' . $needle);
        }
    }
});

$test('2.4.3 agrega programación flexible, agenda local y preloader manual', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/sync/chunk/reschedule'), 'Falta ruta para reprogramar bloques');
    $syncController = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    foreach (['scheduleFromPost', 'schedule_delay_minutes', 'schedule_at', 'rescheduleChunk', 'processOne((int) ($_POST[\'chunk_id\'] ?? 0) ?: null, (int) ($_POST[\'account_id\'] ?? 0))'] as $needle) {
        $assert(str_contains($syncController, $needle), 'Falta controlador sync 2.4.3: ' . $needle);
    }
    $syncService = (string) file_get_contents($root . '/app/Services/SyncCenterService.php');
    foreach (['enqueueChunk(int $chunkId, ?DateTimeImmutable $runAt = null)', 'enqueueMonth(int $batchId, ?DateTimeImmutable $runAt = null)', 'dbQueueDate', 'UTC'] as $needle) {
        $assert(str_contains($syncService, $needle), 'Falta servicio sync 2.4.3: ' . $needle);
    }
    $queue = (string) file_get_contents($root . '/app/Services/SyncQueueService.php');
    $assert(str_contains($queue, 'UTC_TIMESTAMP()'), 'La cola debe comparar contra UTC_TIMESTAMP');
    $presenter = (string) file_get_contents($root . '/app/Services/DateTimePresenter.php');
    $assert(str_contains($presenter, 'formatQueue'), 'Falta DateTimePresenter::formatQueue');
    $index = (string) file_get_contents($root . '/app/Views/sync/index.php');
    $account = (string) file_get_contents($root . '/app/Views/sync/account.php');
    $schedule = (string) file_get_contents($root . '/app/Views/sync/schedule.php');
    foreach (['/settings/manual-processing?scope=sales', 'Configuración de sincronización', 'DateTimePresenter::formatQueue'] as $needle) {
        $assert(str_contains($index, $needle), 'Falta vista sync index: ' . $needle);
    }
    foreach (['Solo crear bloques, sin encolar', 'Encolar en', 'datetime-local', 'chunk-schedule-form', 'sync-actions-cell', 'sync-secondary-actions'] as $needle) {
        $assert(str_contains($account, $needle), 'Falta vista sync account: ' . $needle);
    }
    foreach (['/sync/chunk/reschedule', 'DateTimePresenter::formatQueue', 'datetime-local'] as $needle) {
        $assert(str_contains($schedule, $needle), 'Falta agenda sync: ' . $needle);
    }
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $assert(str_contains($js, 'showProcessOverlay') && str_contains($js, 'process-overlay'));
    $assert(str_contains($js, 'updateScheduleControls') && str_contains($js, 'schedule_at'));
    $assert(str_contains($js, 'data-cron-test-form') && str_contains($js, 'Comprobando instalación'));
    $assert(str_contains($css, '.process-overlay') && str_contains($css, '@keyframes syncIndeterminate'));
    $assert(str_contains($css, '.sync-actions-cell') && str_contains($css, '.sync-schedule-form'));
    $migration = (string) file_get_contents($root . '/database/migrations/025_sync_scheduling_ui_2_4_3.sql');
    foreach (['sync.default_enqueue_delay_minutes', 'sync.allow_custom_schedule', 'sync.manual_overlay_enabled', '2.4.3'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.4.3: ' . $needle);
    }
});

$test('2.4.4 agrega diagnóstico horario, errores visibles y modo asistido', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/026_sync_timezone_error_visibility_2_4_4.sql');
    foreach (['error_type', 'error_http_status', 'error_recommendation', 'sync.assisted_mode_enabled', '2.4.4'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.4.4: ' . $needle);
    }
    $cron = (string) file_get_contents($root . '/app/Services/CronHealthService.php');
    $lock = (string) file_get_contents($root . '/app/Services/SyncLockService.php');
    $queue = (string) file_get_contents($root . '/app/Services/SyncQueueService.php');
    $assert(str_contains($cron, 'UTC_TIMESTAMP()') && str_contains($cron, 'withTimePayload'));
    $assert(str_contains($lock, 'UTC_TIMESTAMP()') && str_contains($lock, 'gmdate'));
    foreach (['processReady', 'SyncErrorClassifier::classify', 'error_recommendation', 'UTC_TIMESTAMP()'] as $needle) {
        $assert(str_contains($queue, $needle), 'Falta cola 2.4.4: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/sync/assisted-step.json'));
    $settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $assert(str_contains($settingsController, 'TimeDiagnosticsService'));
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $assert(str_contains($cronView, 'Diagnóstico horario') && str_contains($cronView, 'DateTimePresenter::formatQueue'));
    $syncIndex = (string) file_get_contents($root . '/app/Views/sync/index.php');
    $assert(
        (str_contains($syncIndex, 'data-assisted-sync-form') || str_contains($syncIndex, '/settings/manual-processing?scope=sales'))
        && str_contains($syncIndex, 'Bloques atrasados/listos')
    );
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $syncController = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $assert(!str_contains($js, 'data-assisted-sync-form')
        && str_contains($syncController, 'public function assistedStepJson')
        && str_contains(
            substr($syncController, strpos($syncController, 'public function assistedStepJson'), 900),
            'http_response_code(410)'
        ), 'La compatibilidad histórica debe quedar retirada y no ejecutar desde el navegador.');
});

$test('2.4.5 agrega modo asistido avanzado y recuperacion de vencidos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/027_sync_assisted_cron_recovery_2_4_5.sql');
    foreach (['sync.assisted_details_enabled', 'sync.overdue_reschedule_default_minutes', 'sync.default_enqueue_delay_minutes', '2.4.5'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.4.5: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/sync/overdue/reschedule', '/settings/cron/reschedule-overdue'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta 2.4.5: ' . $route);
    }
    $syncSettings = (string) file_get_contents($root . '/app/Services/SyncSettingsService.php');
    foreach (['overdueRescheduleDefaultMinutes', 'assistedDetailsEnabled', '[0, 5, 30, 60]'] as $needle) {
        $assert(str_contains($syncSettings, $needle), 'Falta setting 2.4.5: ' . $needle);
    }
    $syncService = (string) file_get_contents($root . '/app/Services/SyncCenterService.php');
    $assert(str_contains($syncService, 'reprogramOverdue'), 'Falta reprogramacion de vencidos');
    $queue = (string) file_get_contents($root . '/app/Services/SyncQueueService.php');
    foreach (['account_name', 'period_month', 'progressInfo', 'remaining_blocks_in_month', 'nextQueuedChunk', 'includeScheduled'] as $needle) {
        $assert(str_contains($queue, $needle), 'Falta detalle asistido en cola: ' . $needle);
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $assert(!str_contains(
        substr($controller, strpos($controller, 'public function assistedStepJson'), 900),
        'processReady('
    ), 'El modo asistido retirado no debe procesar bloques desde HTTP.');
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $assert(str_contains($cronView, 'Recuperar bloques vencidos') && str_contains($cronView, 'Reprogramar bloques vencidos'));
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $assert(!str_contains($js, 'setProcessDetails')
        && !str_contains($js, 'data-process-continue'),
        'Los controles asistidos heredados deben desaparecer del JavaScript.');
});

$test('2.4.6 agrega auditoria de ventas, sync diario y corrige confirm en selectores', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Services/SalesAuditService.php',
        '/app/Services/RecurringSyncService.php',
        '/app/Views/sync/audit.php',
        '/app/Views/sync/recurring.php',
        '/database/migrations/028_sync_audit_recurring_2_4_6.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/028_sync_audit_recurring_2_4_6.sql');
    foreach (['sync_sales_audits', 'sync_sales_audit_days', 'sync_sales_audit_missing_orders', 'sync_recurring_rules', 'sync.daily_enabled', 'questions.frequency_minutes', '2.4.6'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.4.6: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/sync/audit', '/sync/audit/run', '/sync/audit/day', '/sync/audit/enqueue-missing', '/sync/recurring', '/sync/recurring/save'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta 2.4.6: ' . $route);
    }
    $auditService = (string) file_get_contents($root . '/app/Services/SalesAuditService.php');
    foreach (["'limit' => 1", 'paging', 'compareDayIds', 'enqueueDay', '/orders/search'] as $needle) {
        $assert(str_contains($auditService, $needle), 'Falta auditoria liviana: ' . $needle);
    }
    $recurring = (string) file_get_contents($root . '/app/Services/RecurringSyncService.php');
    foreach (['processDue', 'sync.daily_enabled', 'frequency_minutes', 'overlap_hours', 'enqueueRange'] as $needle) {
        $assert(str_contains($recurring, $needle), 'Falta sync diario: ' . $needle);
    }
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($cron, 'RecurringSyncService') && str_contains($cron, 'recurring_enqueued'));
    $questions = (string) file_get_contents($root . '/app/Services/QuestionSyncService.php');
    $assert(str_contains($questions, 'questions.frequency_minutes') && str_contains($questions, 'question_sync_account_state'));
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $assert(str_contains($js, "closest('form[data-confirm]')") && str_contains($js, "closest?.('select,input,textarea,option,label')"));
    $dashboard = (string) file_get_contents($root . '/app/Views/dashboard/index.php')
        . (string) file_get_contents($root . '/app/Views/dashboard/_operations.php')
        . (string) file_get_contents($root . '/app/Repositories/NavigationRepository.php');
    $assert(str_contains($dashboard, 'Auditor') && str_contains($dashboard, '/sync/recurring'));
    if (is_file($root . '/docs/mercadolibre_api_map.md')) {
        $map = (string) file_get_contents($root . '/docs/mercadolibre_api_map.md');
        $assert(str_contains($map, 'rangos UTC semiabiertos')
            && str_contains($map, 'offset')
            && str_contains($map, 'huella de IDs'));
    }
});

$test('2.5 agrega integridad de ventas, normalizador horario y facturación segura', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $normalizer = new MeliDateTimeNormalizer();
    $normalized = $normalizer->normalize('2026-01-01T00:30:00.000-04:00', 'orders.date_created');
    $assert($normalized['status'] === 'ok' && $normalized['utc'] === '2026-01-01 04:30:00');
    foreach ([
        '/app/Services/MeliDateTimeNormalizer.php',
        '/app/Services/MeliDateRangeService.php',
        '/app/Services/BillingSafetyService.php',
        '/app/Services/OrderDateRepairService.php',
        '/app/Services/SalesRepairService.php',
        '/database/migrations/029_sales_integrity_billing_safety_2_5.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/029_sales_integrity_billing_safety_2_5.sql');
    foreach (['date_created_local_date', 'date_approved_local_date', 'timezone_diagnostics', 'sync_sales_repair_jobs', 'sync_data_purge_requests', 'coverage_snapshot_json', '2.5.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.5: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/sync/audit/compare-month', '/sync/audit/repair-month', '/sync/audit/recalculate-dates', '/sync/audit/diagnose-dates'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta 2.5: ' . $route);
    }
    $audit = (string) file_get_contents($root . '/app/Services/SalesAuditService.php');
    foreach (['MeliDateRangeService', 'date_created_local', 'compareMonthIds', 'repairMonth', 'timezone_suspect'] as $needle) {
        $assert(str_contains($audit, $needle), 'Falta auditoría 2.5: ' . $needle);
    }
    $billing = (string) file_get_contents($root . '/app/Services/DateReportService.php');
    $assert(str_contains($billing, 'BillingSafetyService') && str_contains($billing, 'date_approved_local'));
});

$test('2.5.1 agrega protección anti-bloqueo Mercado Libre avanzada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Services/ApiErrorClassifier.php',
        '/app/Services/ApiHealthService.php',
        '/app/Views/settings/api_health.php',
        '/database/migrations/030_api_block_protection_2_5_1.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta archivo 2.5.1: ' . $path);
    }
    $classifier = (string) file_get_contents($root . '/app/Services/ApiErrorClassifier.php');
    foreach (['bad_request', 'unauthorized', 'forbidden', 'missing_permission', 'rate_limited', 'resource_not_found', 'temporary_server_error', 'network_timeout', 'app_blocked', 'token_invalid', 'token_expired', 'refresh_token_invalid', 'unknown'] as $needle) {
        $assert(str_contains($classifier, $needle), 'Falta clasificación: ' . $needle);
    }
    $guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
    foreach (['error_type', 'is_app_blocked_signal', 'closeCircuit', 'pauseAccount', 'reactivateAccount', 'recordAdminAction', 'app_blocked'] as $needle) {
        $assert(str_contains($guard, $needle), 'Falta guardia 2.5.1: ' . $needle);
    }
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    foreach (['ApiErrorClassifier::classify', 'afterFailure($this->accountId', 'assertAllowed($this->accountId, $method, $path)'] as $needle) {
        $assert(str_contains($client, $needle), 'Falta cliente API 2.5.1: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/settings/api-health', '/settings/api-health/circuit/close', '/settings/api-health/account/pause', '/settings/api-health/account/reactivate', '/settings/api-health/export'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta salud API: ' . $route);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/030_api_block_protection_2_5_1.sql');
    foreach (['api_guard_admin_actions', 'api.logs.request_retention_days', 'api.guard.app_blocked_cooldown_minutes', '2.5.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.5.1: ' . $needle);
    }
    $dashboard = (string) file_get_contents($root . '/app/Views/dashboard/index.php')
        . (string) file_get_contents($root . '/app/Views/dashboard/_health.php');
    $assert((str_contains($dashboard, 'Salud API ML') || str_contains($dashboard, 'Salud API Mercado Libre')) && str_contains($dashboard, '/settings/api-health'));
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($cron, 'skipped_by_api_guard') && str_contains($cron, 'app_blocked'));
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.5.1', '>='));
});

$test('2.6.0 agrega centro de notificaciones orientado por eventos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/app/Controllers/NotificationController.php',
        '/app/Services/NotificationCoalescerService.php',
        '/app/Services/MissedFeedService.php',
        '/app/Views/notifications/index.php',
        '/app/Views/notifications/events.php',
        '/app/Views/notifications/missed.php',
        '/jobs/process_notifications.php',
        '/database/migrations/031_notification_center_2_6.sql',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta archivo 2.6.0: ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/031_notification_center_2_6.sql');
    foreach (['meli_notification_events', 'app_notifications', 'notification_rules', 'missed_feed_runs', 'notifications.enabled', '2.6.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.6.0: ' . $needle);
    }
    $webhook = (string) file_get_contents($root . '/app/Services/WebhookService.php');
    foreach (['payload_hash', 'unknown_topic', 'erp_received_at', 'source_ip', 'user_agent'] as $needle) {
        $assert(str_contains($webhook, $needle), 'Webhook no guarda campo: ' . $needle);
    }
    $coalescer = (string) file_get_contents($root . '/app/Services/NotificationCoalescerService.php');
    foreach (['processDue', 'syncOrderById', 'syncClaimById', 'questions.endpoint_confirmed', 'circuit_breaker_wait'] as $needle) {
        $assert(str_contains($coalescer, $needle), 'Falta procesamiento notificaciones: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/notifications', '/notifications/events', '/notifications/missed', '/notifications/events/retry'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta notificaciones: ' . $route);
    }
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(
        str_contains($layout, 'Notificaciones')
        && str_contains($layout, "View::asset(\$base, 'app.js')")
        && str_contains($layout, 'AssetVersionService::fingerprint'),
        'Notificaciones debe cargar el JavaScript de la misma release activa.'
    );
    $syncJob = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($syncJob, 'NotificationCoalescerService') && str_contains($syncJob, 'notification_events_processed'));
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $assert(str_contains($registry, '/missed_feeds'));
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.6.0', '>='));
});

$test('2.6.1 agrega auditoria exacta de ventas y reparacion confiable', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/032_sales_audit_reconciliation_2_6_1.sql');
    foreach (['sync_sales_audit_remote_ids', 'remote_ids_checked_at', 'local_shifted_count', 'audit_consistency_status', '2.6.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migración 2.6.1: ' . $needle);
    }
    $audit = (string) file_get_contents($root . '/app/Services/SalesAuditService.php');
    foreach (['compareMonthIdsSystem', 'remoteOrderRows', 'localRowsByExternalIds', 'missing_remote', 'local_shifted_date', 'updateAuditRollup'] as $needle) {
        $assert(str_contains($audit, $needle), 'Falta auditoría exacta 2.6.1: ' . $needle);
    }
    $repair = (string) file_get_contents($root . '/app/Services/SalesRepairService.php');
    $assert(
        str_contains($repair, 'classification="missing_remote"')
        && str_contains($repair, 'SalesAuditRunService())->createExactMonth'),
        'La reparación debe conservar el alcance exacto y encolar su comprobación moderna.'
    );
    $orders = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $assert(str_contains($orders, '$processedFromPage') && str_contains($orders, '$currentOffset += $count >= $maxOrders ? $processedFromPage : count($results);'));
    $view = (string) file_get_contents($root . '/app/Views/sync/audit.php');
    foreach (['Auditoría de ventas', 'Diferencias individuales de la auditoría exacta', 'Acción recomendada'] as $needle) {
        $assert(str_contains($view, $needle), 'Falta UI de auditoría equivalente: ' . $needle);
    }
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.6.1', '>='));
});

$test('2.6.2 corrige auditoria shifted y notificaciones pendientes', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(is_file($root . '/database/migrations/033_audit_notifications_hotfix_2_6_2.sql'), 'Falta migracion 2.6.2');
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.6.2', '>='));
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(
        str_contains($layout, "View::asset(\$base, 'app.css')")
        && str_contains($layout, "View::asset(\$base, 'app.js')")
        && str_contains($layout, 'AssetVersionService::fingerprint'),
        'CSS y JavaScript deben compartir el puntero atómico de release.'
    );
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/notifications/process'), 'Falta ruta para procesar notificaciones');
    $controller = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
    $assert(str_contains($controller, 'processPending') && str_contains($controller, 'Sincronización por eventos ejecutada'));
    $notifications = (string) file_get_contents($root . '/app/Views/notifications/index.php');
    $assert(str_contains($notifications, 'Procesar siguiente lote') && str_contains($notifications, 'Informativos ignorados'));
    $events = (string) file_get_contents($root . '/app/Views/notifications/events.php');
    $assert(str_contains($events, 'Eventos técnicos') && str_contains($events, 'Diagnóstico'));
    $auditView = (string) file_get_contents($root . '/app/Views/sync/audit.php');
    $assert(str_contains($auditView, 'classification') && str_contains($auditView, 'Existen en otro día'));
    $audit = (string) file_get_contents($root . '/app/Services/SalesAuditService.php');
    $assert(str_contains($audit, '$exactMissing === 0 && $exactExtra === 0 && $exactShifted > 0') && str_contains($audit, "\$status = 'complete';"));
});

$test('2.6.3 estabiliza auditoria exacta, envios y assets', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.6.3', '>='));
    $assert(is_file($root . '/database/migrations/034_stability_cron_audit_shipments_2_6_3.sql'), 'Falta migracion 2.6.3');
    $audit = (string) file_get_contents($root . '/app/Services/SalesAuditService.php');
    $assert(str_contains($audit, 'diagnostic_message') && str_contains($audit, 'local_meli_account_id'), 'Auditoria exacta debe guardar diagnostico local seguro');
    $shipments = (string) file_get_contents($root . '/app/Services/ShipmentQueryService.php');
    foreach (['hasUserFilter', 'distinctRecent', 'INTERVAL 30 DAY', 's.synced_at DESC'] as $needle) {
        $assert(str_contains($shipments, $needle), 'Falta optimizacion envios 2.6.3: ' . $needle);
    }
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(
        str_contains($layout, "View::asset(\$base, 'app.css')")
        && str_contains($layout, "View::asset(\$base, 'app.js')")
        && str_contains($layout, 'AssetVersionService::fingerprint'),
        'CSS y JavaScript deben compartir el puntero atómico de release.'
    );
    $auditView = (string) file_get_contents($root . '/app/Views/sync/audit.php');
    $assert(str_contains($auditView, 'Auditoría de ventas') && str_contains($auditView, 'Diferencias individuales de la auditoría exacta'));
});

$test('2.7.3 muestra cola financiera inteligente con billing automatico', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.7.4', '>='));
    $migration = (string) file_get_contents($root . '/database/migrations/035_order_financial_reconciliation_2_7.sql');
    foreach (['meli_order_financials', 'meli_order_billing_details', 'missing_cost_items_count', '2.7.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.7: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/orders/financial/queue', '/orders/financial/recalculate', '/orders/financial/manual-review', '/products/links/factor'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta 2.7: ' . $route);
    }
    $orderView = (string) file_get_contents($root . '/app/Views/orders/show.php');
    $assert(!str_contains($orderView, '<span>Pagado</span>'), 'La orden no debe mostrar Pagado ambiguo.');
    foreach (['Total cobrado al comprador', 'Neto local parcial', 'Neto ML conciliado', 'Utilidad real ERP', 'Recalcular financiero'] as $needle) {
        $assert(str_contains($orderView, $needle), 'Falta orden enriquecida: ' . $needle);
    }
    $financial = (string) file_get_contents($root . '/app/Services/OrderFinancialService.php');
    foreach (['buyer_shipping_paid', 'ml_shipping_charge', 'missing_cost_items_count', 'processPending'] as $needle) {
        $assert(str_contains($financial, $needle), 'Falta servicio financiero: ' . $needle);
    }
    $dateReport = (string) file_get_contents($root . '/app/Services/DateReportService.php');
    foreach (['net_without_shipping', 'net_after_shipping', 'ml_shipping_charge', 'date_report_item_orders'] as $needle) {
        $assert(str_contains($dateReport, $needle), 'Falta facturacion 2.7.1: ' . $needle);
    }
    $migration271 = (string) file_get_contents($root . '/database/migrations/036_financial_billing_audit_sync_2_7_1.sql');
    foreach (['date_report_item_orders', 'order_financial_recalc_jobs', 'diagnostic_message', '2.7.1'] as $needle) {
        $assert(str_contains($migration271, $needle), 'Falta migracion 2.7.1: ' . $needle);
    }
    $routes271 = (string) file_get_contents($root . '/public/index.php');
    foreach (['/billing/date/item-orders', '/billing/date/recalculate', '/sync/audit/reclassify-existing', '/sync/assisted/retry-failed'] as $route) {
        $assert(str_contains($routes271, $route), 'Falta ruta 2.7.1: ' . $route);
    }
    foreach (['/financial-recalc', '/financial-recalc/process-now', '/financial-recalc/assisted-step', '/financial-recalc/retry-failed'] as $route) {
        $assert(str_contains($routes271, $route), 'Falta ruta 2.7.2: ' . $route);
    }
    $migration272 = (string) file_get_contents($root . '/database/migrations/037_financial_recalc_queue_ui_2_7_2.sql');
    foreach (['cancelled_at', 'last_error_message', 'financial_recalc.batch_limit', '2.7.2'] as $needle) {
        $assert(str_contains($migration272, $needle), 'Falta migracion 2.7.2: ' . $needle);
    }
    $migration273 = (string) file_get_contents($root . '/database/migrations/038_financial_recalc_smart_billing_2_7_3.sql');
    foreach (['current_phase', 'billing_reconciled_net_amount', 'financial_recalc.use_billing_order_details', '2.7.3'] as $needle) {
        $assert(str_contains($migration273, $needle), 'Falta migracion 2.7.3: ' . $needle);
    }
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $assert(str_contains($registry, '/billing/integration/group/ML/order/details'), 'Falta registrar endpoint billing 2.7.3');
    $billingImporter = (string) file_get_contents($root . '/app/Services/OrderBillingImportService.php');
    foreach (['importForOrderIds', 'billing_reconciled_net_amount', 'tax_reteiva', 'buyer_shipping_paid'] as $needle) {
        $assert(str_contains($billingImporter, $needle), 'Falta importador billing 2.7.3: ' . $needle);
    }
    $financialQueue = (string) file_get_contents($root . '/app/Services/OrderFinancialRecalcJobService.php');
    foreach (['processBillingPhase', 'billing_import', 'SaleFinancialStateService', 'No hay recalculos financieros pendientes'] as $needle) {
        $assert(str_contains($financialQueue, $needle), 'Falta servicio cola financiera 2.7.3: ' . $needle);
    }
    $syncView = (string) file_get_contents($root . '/app/Views/sync/index.php');
    $assert(str_contains($syncView, 'Cola financiera') && str_contains($syncView, '/settings/manual-processing?scope=finance'));
    $links = (string) file_get_contents($root . '/app/Views/products/links/index.php');
    $assert(str_contains($links, 'Cantidad de bodega que descuenta cada venta') && str_contains($links, 'Seleccione publicación'));
    $sync = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $assert(!str_contains($sync, "\$this->api->get('/payments/") && !str_contains($sync, 'payments.expand_details_enabled=true'));
    $database = (string) file_get_contents($root . '/app/Core/Database.php');
    foreach (['connectionFresh', 'executeWithReconnect', 'isLostConnection', 'reconnectCount'] as $needle) {
        $assert(str_contains($database, $needle), 'Falta resiliencia DB 2.7.4: ' . $needle);
    }
    $migration274 = (string) file_get_contents($root . '/database/migrations/039_financial_recalc_mysql_resilience_2_7_4.sql');
    foreach (['last_db_reconnect_at', 'db_reconnect_count', 'financial_recalc.reconnect_between_steps', '2.7.4'] as $needle) {
        $assert(str_contains($migration274, $needle), 'Falta migracion 2.7.4: ' . $needle);
    }
    foreach (['recordDbReconnectIfNeeded', 'financial_recalc.orders_per_run', '10', 'Database::executeWithReconnect'] as $needle) {
        $assert(str_contains($financialQueue, $needle), 'Falta cola resiliente 2.7.4: ' . $needle);
    }
});

$test('2.8.0 agrega catalogo publico y privado sin API en vivo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.0', '>='), 'VERSION debe ser 2.8.0 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/040_catalogs_2_8.sql');
    foreach (['CREATE TABLE IF NOT EXISTS catalogs', 'CREATE TABLE IF NOT EXISTS catalog_items', 'access_token_hash', 'password_hash', 'catalog.tracking_enabled', 'uq_catalog_item_publication', '2.8.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion catalogos 2.8: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/catalogs/{id}', '/catalogs/{id}/refresh', '/catalogo/{slug}', '/catalogo/{slug}/producto/{itemId}'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta catalogo: ' . $route);
    }
    $router = (string) file_get_contents($root . '/app/Core/Router.php');
    foreach (['dynamicRoutes', 'compile', 'preg_match'] as $needle) {
        $assert(str_contains($router, $needle), 'Router debe soportar rutas dinamicas: ' . $needle);
    }
    foreach (['CatalogController.php', 'PublicCatalogController.php'] as $file) {
        $assert(is_file($root . '/app/Controllers/' . $file), 'Falta controlador ' . $file);
    }
    foreach (['CatalogService.php', 'CatalogSnapshotService.php', 'CatalogQueryService.php', 'CatalogAccessService.php', 'CatalogExportService.php', 'CatalogViewTrackerService.php'] as $file) {
        $assert(is_file($root . '/app/Services/' . $file), 'Falta servicio ' . $file);
    }
    $publicCode = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php')
        . (string) file_get_contents($root . '/app/Services/CatalogQueryService.php')
        . (string) file_get_contents($root . '/app/Views/public_catalog/show.php')
        . (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $assert(!str_contains($publicCode, 'MeliApiClient'), 'El catalogo publico no debe instanciar MeliApiClient');
    foreach (['cost', 'utilidad', 'raw_json', 'commission'] as $secretish) {
        $assert(!str_contains(strtolower($publicCode), $secretish), 'El catalogo publico no debe exponer dato interno: ' . $secretish);
    }
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    $assert(str_contains($query, 'array_key_exists(\'per_page\', $filters) ? 5000 : 96'), 'Impresion/exportacion de catalogos no debe quedar limitada a 96');
});

$test('2.8.2 permite copiar enlace privado sin guardar token plano', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.2', '>='), 'VERSION debe ser 2.8.2 o superior');
    $service = (string) file_get_contents($root . '/app/Services/CatalogService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $show = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    $form = (string) file_get_contents($root . '/app/Views/catalogs/form.php');
    $index = (string) file_get_contents($root . '/app/Views/catalogs/index.php');
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $assert(str_contains($service, "'slug' => \$slug") && str_contains($service, 'access_token_hash'), 'CatalogService debe devolver slug y guardar solo hash');
    $assert(str_contains($controller, 'privateCatalogUrl') && str_contains($controller, "Session::flash('private_link'"), 'CatalogController debe generar enlace privado copiable');
    $assert(str_contains($show, 'Generar enlace privado') && str_contains($form, 'Regenerar y copiar enlace privado'), 'UI debe ofrecer enlace privado');
    $assert(str_contains($show . $form, 'data-copy-text') && str_contains($js, '[data-copy-text]'), 'Debe existir botón copiar enlace');
    $assert(str_contains($index, 'private_token') && str_contains($index, 'Enlace privado'), 'Listado debe diferenciar catálogos privados del enlace público sin token');
    $assert(str_contains($css, 'catalog-private-link-box') && str_contains($css, 'copy-link-row'), 'Debe existir estilo para enlace privado copiable');
});

$test('2.8.3 agrega categorias ML reales y catalogos por fuente', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.3', '>='), 'VERSION debe ser 2.8.3 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/041_catalog_categories_sources_access_2_8_3.sql');
    foreach (['meli_categories', 'catalog_internal_items', 'source_type', 'combined', '2.8.3'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.8.3: ' . $needle);
    }
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $assert(str_contains($registry, '/categories/'), 'Debe registrarse GET /categories/{id}');
    $categoryService = (string) file_get_contents($root . '/app/Services/MeliCategoryService.php');
    foreach (['MeliApiClient', 'meli_categories', 'resolveMany', 'path_from_root_json'] as $needle) {
        $assert(str_contains($categoryService, $needle), 'Falta servicio categorias ML: ' . $needle);
    }
    $snapshot = (string) file_get_contents($root . '/app/Services/CatalogSnapshotService.php');
    foreach (['resolveCategoriesForCatalog', 'sourceInternalItems', 'MeliCategoryService', 'Sin categoría', 'active_link.active_link_id IS NULL'] as $needle) {
        $assert(str_contains($snapshot, $needle), 'Falta snapshot catalogo 2.8.3: ' . $needle);
    }
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    foreach (['catalog_internal_items', 'source_type', 'public_item_key', 'Bodega interna'] as $needle) {
        $assert(str_contains($query, $needle), 'Falta consulta catalogo 2.8.3: ' . $needle);
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($controller, 'resolveCategories') && str_contains($routes, '/catalogs/{id}/categories/resolve'), 'Falta accion admin para actualizar categorias ML');
    $form = (string) file_get_contents($root . '/app/Views/catalogs/form.php');
    $show = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    foreach (['Clave para empleados', 'Bodega interna', 'Combinado'] as $needle) {
        $assert(str_contains($form . $show, $needle), 'Falta UI catalogo 2.8.3: ' . $needle);
    }
    $publicCode = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php')
        . (string) file_get_contents($root . '/app/Services/CatalogQueryService.php')
        . (string) file_get_contents($root . '/app/Views/public_catalog/show.php')
        . (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $assert(!str_contains($publicCode, 'MeliApiClient'), 'La vista publica no debe consultar API en vivo');
});

$test('2.8.4 mantiene navegacion privada por token y filtros publicos seguros', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.4', '>='), 'VERSION debe ser 2.8.4 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/042_catalog_private_navigation_public_filters_2_8_4.sql');
    foreach (['show_public_advanced_filters', '2.8.4'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.8.4: ' . $needle);
    }
    $access = (string) file_get_contents($root . '/app/Services/CatalogAccessService.php');
    foreach (['catalog_token_access_', 'TOKEN_SESSION_SECONDS', 'tokenAccessStatus', 'hasTokenSession'] as $needle) {
        $assert(str_contains($access, $needle), 'Falta sesion temporal por token: ' . $needle);
    }
    $service = (string) file_get_contents($root . '/app/Services/CatalogService.php');
    $form = (string) file_get_contents($root . '/app/Views/catalogs/form.php');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    foreach (['show_public_advanced_filters', 'Mostrar filtros avanzados en catálogo público'] as $needle) {
        $assert(str_contains($service . $form, $needle), 'Falta configuracion filtros publicos: ' . $needle);
    }
    foreach (['catalog-public-search-advanced', 'source_type', 'company_id', 'data_quality', 'missing_sku', 'missing_image'] as $needle) {
        $assert(str_contains($public . $controller, $needle), 'Falta filtro publico seguro: ' . $needle);
    }
    $assert(!str_contains($public, 'link_state') && !str_contains($public, 'Vínculo'), 'Vista publica no debe mostrar filtro de vinculo bodega');
    $assert(str_contains($public, 'categoryUrl') && !str_contains($public, 'token='), 'Links publicos no deben arrastrar token plano');
});

$test('2.8.5 aclara conteos de catalogo y mejora navegacion movil', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.5', '>='), 'VERSION debe ser 2.8.5 o superior');
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    $show = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(str_contains($controller, "'public_visible'") && str_contains($show, 'Visible público'), 'El panel debe mostrar conteo visible publico separado del total interno');
    $assert(str_contains($show, 'Total interno') && str_contains($show, 'snapshots pausados'), 'El panel debe explicar diferencia entre total interno y publico');
    $assert(str_contains($public, 'catalog-mobile-actions') && str_contains($public, 'Filtros') && str_contains($public, 'Categorías'), 'La vista publica debe tener controles moviles plegables');
    $assert(str_contains($css, '.catalog-mobile-panel') && str_contains($css, '.catalog-public-sidebar{display:none}'), 'CSS debe ocultar sidebar largo en movil y usar paneles');
    $assert(str_contains($query, 'public_status_') && str_contains($query, 'ci.status IN ('), 'La consulta publica debe soportar estados publicos con placeholders unicos');
    $assert(
        str_contains($layout, "View::asset(\$base, 'app.css')")
        && str_contains($layout, "View::asset(\$base, 'app.js')")
        && str_contains($layout, 'AssetVersionService::fingerprint'),
        'Assets deben usar fingerprint y el despachador de la release activa.'
    );
});

$test('2.8.6 agrega estados publicos configurables y badges avanzados', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.6', '>='), 'VERSION debe ser 2.8.6 o superior');
    $service = (string) file_get_contents($root . '/app/Services/CatalogService.php');
    $form = (string) file_get_contents($root . '/app/Views/catalogs/form.php');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $product = (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    foreach (['publicStatusesFromData', 'public_statuses_json=:statuses', 'paused', 'under_review'] as $needle) {
        $assert(str_contains($service, $needle), 'Falta soporte de estados publicos: ' . $needle);
    }
    foreach (['Estados visibles en el catálogo público', 'public_statuses[]', 'Mostrar estados avanzados en tarjetas públicas'] as $needle) {
        $assert(str_contains($form, $needle), 'Falta UI estados publicos: ' . $needle);
    }
    foreach (['catalog-public-badges', 'statusLabel', 'logisticLabel', 'Logística no identificada'] as $needle) {
        $assert(str_contains($public . $product . $css, $needle), 'Faltan badges publicos avanzados: ' . $needle);
    }
});

$test('2.8.7 respeta estados publicos aunque el snapshot no sea visible por defecto', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.7', '>='), 'VERSION debe ser 2.8.7 o superior');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    $assert(str_contains($query, 'COALESCE(ci.visibility_override,"")<>"hidden"'), 'La consulta publica no debe depender de is_visible=1 para estados habilitados');
    $assert(str_contains($query, 'publicCategories') && str_contains($controller, 'publicCategories($catalog)'), 'Categorias publicas deben contarse con los mismos filtros publicos');
    $assert(!str_contains($query, '$where[] = \'ci.is_visible=1\';'), 'El filtro publico antiguo bloqueaba pausados/finalizados aunque estuvieran habilitados');
});

$test('2.8.8 corrige ficha publica de catalogo con parametros seguros', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.8', '>='), 'VERSION debe ser 2.8.8 o superior');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    $posParams = strpos($query, '$params = $this->meliParams($catalog, [], true)');
    $posSql = strpos($query, 'SELECT * FROM (\' . $this->meliSelectSql(true)');
    $assert($posParams !== false && $posSql !== false && $posParams < $posSql, 'findPublicItem debe preparar filtros/params antes de construir SQL ML');
    $assert(str_contains($query, '$params = $this->internalParams($catalog, [], true)'), 'findPublicItem debe preparar filtros/params antes de construir SQL interno');
    $assert(str_contains($controller, 'Error cargando ficha pública de catálogo.') && str_contains($controller, "View::render('public_catalog/unavailable'"), 'Ficha pública debe registrar error técnico y mostrar error controlado');
});

$test('2.8.9 agrega stock por origen y metodos de envio en catalogos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.9', '>='), 'VERSION debe ser 2.8.9 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/044_catalog_stock_shipping_2_8_9.sql');
    foreach (['meli_item_stock_locations', 'user_product_id', 'stock_full', 'stock_non_full', 'shipping_methods_json', 'show_stock_breakdown', 'show_shipping_methods', 'show_stock_detail_public', '2.8.9'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta migracion 2.8.9: ' . $needle);
    }
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $assert(str_contains($registry, '/user-products/') && str_contains($registry, '/stock'), 'Debe registrarse endpoint seguro de stock multi-origen');
    $sync = (string) file_get_contents($root . '/app/Services/MeliItemSyncService.php');
    foreach (['user_product_id', 'logistic_type', 'shipping_mode', 'userProductId'] as $needle) {
        $assert(str_contains($sync, $needle), 'Sync productos debe guardar dato local: ' . $needle);
    }
    $stockService = (string) file_get_contents($root . '/app/Services/MeliItemStockService.php');
    foreach (['/user-products/', 'MeliApiClient', 'meli_item_stock_locations', 'No modifica Mercado Libre'] as $needle) {
        $assert(str_contains($stockService, $needle), 'Falta servicio stock multi-origen: ' . $needle);
    }
    $snapshot = (string) file_get_contents($root . '/app/Services/CatalogSnapshotService.php');
    foreach (['stockBreakdown', 'shippingMethods', 'fulfillment', 'self_service', 'cross_docking', 'xd_drop_off', 'drop_off'] as $needle) {
        $assert(str_contains($snapshot, $needle), 'Snapshot debe calcular stock/logistica: ' . $needle);
    }
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    foreach (['shipping_method', 'stock_origin', 'stock_full', 'stock_non_full', 'stock_detail_status'] as $needle) {
        $assert(str_contains($query, $needle), 'Consultas deben filtrar/seleccionar: ' . $needle);
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($controller, 'resolveStock') && str_contains($routes, '/catalogs/{id}/stock/resolve'), 'Debe existir acción admin para cachear stock por origen');
    $form = (string) file_get_contents($root . '/app/Views/catalogs/form.php');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php') . (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $private = (string) file_get_contents($root . '/app/Views/catalogs/show.php') . (string) file_get_contents($root . '/app/Views/catalogs/private.php');
    foreach (['Mostrar desglose de stock', 'Mostrar métodos de envío', 'Mostrar detalle de stock en público'] as $needle) {
        $assert(str_contains($form, $needle), 'Formulario catalogo debe tener opcion: ' . $needle);
    }
    foreach (['Métodos disponibles', 'Origen stock', 'Stock FULL', 'Stock no FULL/local'] as $needle) {
        $assert(str_contains($public . $private, $needle), 'UI catalogo debe mostrar/filtar: ' . $needle);
    }
    $publicCode = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php') . $public;
    $assert(!str_contains($publicCode, 'MeliApiClient'), 'El catálogo público no debe consultar Mercado Libre en vivo');
});

$test('2.8.10 corrige filtros dinamicos de metodos de envio en catalogos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.10', '>='), 'VERSION debe ser 2.8.10 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/045_catalog_shipping_filter_options_2_8_10.sql');
    $assert(str_contains($migration, '2.8.10') && str_contains($migration, 'app_versions'), 'Migracion 045 debe registrar version 2.8.10');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    foreach (['shippingMethodOptions', 'shippingMethodNeedles', 'shipping_mode=:', 'shipping_methods_json LIKE', 'Flex'] as $needle) {
        $assert(str_contains($query, $needle), 'Filtro de envio debe ser dinamico/tolerante: ' . $needle);
    }
    $publicController = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    $catalogController = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $assert(str_contains($publicController, 'shippingMethodOptions($catalog, $filters, true)'), 'PublicCatalogController debe enviar opciones dinamicas publicas');
    $assert(str_contains($catalogController, 'shippingMethodOptions($catalog, $filters, false)'), 'CatalogController debe enviar opciones dinamicas privadas');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $private = (string) file_get_contents($root . '/app/Views/catalogs/show.php') . (string) file_get_contents($root . '/app/Views/catalogs/private.php');
    $assert(str_contains($public . $private, 'renderShippingOptions'), 'Vistas deben pintar opciones dinamicas con conteo');
});

$test('2.8.11 detecta multiples metodos de envio desde raw shipping local', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.11', '>='), 'VERSION debe ser 2.8.11 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/046_catalog_shipping_methods_multivalue_2_8_11.sql');
    $assert(str_contains($migration, '2.8.11') && str_contains($migration, 'app_versions'), 'Migracion 046 debe registrar version 2.8.11');
    $snapshot = (string) file_get_contents($root . '/app/Services/CatalogSnapshotService.php');
    foreach (['shippingSignals', 'shippingCode', 'Confirmado por API local', 'self_service', 'cross_docking', 'xd_drop_off', 'drop_off'] as $needle) {
        $assert(str_contains($snapshot, $needle), 'Snapshot debe detectar multiples metodos: ' . $needle);
    }
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $private = (string) file_get_contents($root . '/app/Views/catalogs/show.php') . (string) file_get_contents($root . '/app/Views/catalogs/private.php');
    $assert(str_contains($public, 'Método de envío') && str_contains($public, "'shipping_method', \$shippingOptions"), 'Catalogo publico debe mostrar métodos de envío disponibles');
    foreach (['Principal', 'Disponibles', 'Confianza envío', 'Confirmado por API local'] as $needle) {
        $assert(str_contains($private, $needle), 'Catalogo privado debe auditar logistica: ' . $needle);
    }
    $publicCode = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php') . $public;
    $assert(!str_contains($publicCode, 'MeliApiClient'), 'El catálogo público no debe consultar Mercado Libre en vivo');
});

$test('2.8.12 evita 500 por esquema parcial en catalogos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.12', '>='), 'VERSION debe ser 2.8.12 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/047_catalog_schema_guard_2_8_12.sql');
    foreach (['show_shipping_methods', 'public_statuses_json', 'stock_full', 'shipping_methods_json', '2.8.12'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 047 debe asegurar: ' . $needle);
    }
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    foreach (['selectColumn', 'hasColumn', 'hasTable', 'shippingParts !== []', 'stock_detail_status'] as $needle) {
        $assert(str_contains($query, $needle), 'CatalogQueryService debe tolerar esquema parcial: ' . $needle);
    }
    $catalogController = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $publicController = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    foreach (['Error cargando administración de catálogo', 'schemaWarning'] as $needle) {
        $assert(str_contains($catalogController, $needle), 'Administrar catalogo debe manejar error seguro: ' . $needle);
    }
    foreach (['Error cargando catálogo público', 'actualización incompleta', 'public_catalog/unavailable'] as $needle) {
        $assert(str_contains($publicController, $needle), 'Catalogo publico debe manejar error seguro: ' . $needle);
    }
    $unavailable = (string) file_get_contents($root . '/app/Views/public_catalog/unavailable.php');
    $assert(str_contains($unavailable, '$message ??'), 'Vista no disponible debe aceptar mensaje seguro');
});

$test('2.8.13 corrige y diagnostica enlace privado de catalogo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.13', '>='), 'VERSION debe ser 2.8.13 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/048_catalog_private_access_diagnostics_2_8_13.sql');
    foreach (['last_private_access_error', 'last_token_regenerated_at', '2.8.13'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 048 debe agregar diagnostico privado: ' . $needle);
    }
    $access = (string) file_get_contents($root . '/app/Services/CatalogAccessService.php');
    foreach (['publicAccessStatus', 'testToken', 'normalizeToken', 'tokenShapeIsValid', 'last_private_access_error'] as $needle) {
        $assert(str_contains($access, $needle), 'CatalogAccessService debe diagnosticar token: ' . $needle);
    }
    $service = (string) file_get_contents($root . '/app/Services/CatalogService.php');
    foreach (['is_enabled=1', 'expires_at=NULL', 'last_token_regenerated_at=NOW()', 'privateAccessDiagnostics'] as $needle) {
        $assert(str_contains($service, $needle), 'Regenerar token debe dejar catalogo usable: ' . $needle);
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php') . (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($controller, 'testToken') && str_contains($controller, '/catalogs/{id}/token/test'), 'Debe existir prueba admin de token');
    $publicController = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    $assert(str_contains($publicController, 'Acceso a catálogo público rechazado') && str_contains($publicController, 'Diagnóstico interno'), 'Rechazos publicos deben registrarse y ser diagnosticables para admin');
    $show = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    foreach (['Diagnóstico del enlace privado', 'Probar token', 'Tiene token guardado'] as $needle) {
        $assert(str_contains($show, $needle), 'Administrar catalogo debe mostrar diagnostico privado: ' . $needle);
    }
});

$test('2.8.14 acepta tokens heredados y corrige almacenamiento truncado', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.14', '>='), 'VERSION debe ser 2.8.14 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/049_catalog_private_token_storage_2_8_14.sql');
    foreach (['MODIFY COLUMN access_token_hash VARCHAR(128)', '2.8.14', 'app_versions'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 049 debe blindar token privado: ' . $needle);
    }
    $access = (string) file_get_contents($root . '/app/Services/CatalogAccessService.php');
    foreach (['sha256_truncated', 'legacy_plain_token', 'legacy_password_hash', 'upgradeTokenHash', '{32,128}'] as $needle) {
        $assert(str_contains($access, $needle), 'CatalogAccessService debe aceptar/normalizar tokens heredados: ' . $needle);
    }
});

$test('2.8.15 corrige HY093 en filtros de logística de catálogos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.15', '>='), 'VERSION debe ser 2.8.15 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/050_catalog_shipping_placeholders_2_8_15.sql');
    $assert(str_contains($migration, '2.8.15') && str_contains($migration, 'app_versions'), 'Migracion 050 debe registrar version 2.8.15');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    foreach ([
        '$logisticKey = $prefix . \'_shipping_method_logistic_\' . $index',
        '$modeKey = $prefix . \'_shipping_method_mode_\' . $index',
        '$likeKey = $prefix . \'_shipping_method_like_\' . $index',
    ] as $needle) {
        $assert(str_contains($query, $needle), 'Filtro de envio debe usar placeholder unico: ' . $needle);
    }
    $assert(!str_contains($query, '$key = $prefix . \'_shipping_method_\' . $index'), 'No debe reutilizarse el mismo placeholder para logistic_type y shipping_mode');
});

$test('2.8.16 oculta empresas eliminadas/inactivas en selectores y diagnostica duplicados', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.16', '>='), 'VERSION debe ser 2.8.16 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/051_company_selector_dedup_2_8_16.sql');
    $assert(str_contains($migration, '2.8.16') && str_contains($migration, 'app_versions'), 'Migracion 051 debe registrar version 2.8.16');
    $service = (string) file_get_contents($root . '/app/Services/CompanyOptionService.php');
    foreach (['deleted_at IS NULL AND status=1', 'duplicateActiveNames', 'GROUP_CONCAT(id ORDER BY id'] as $needle) {
        $assert(str_contains($service, $needle), 'CompanyOptionService debe filtrar/diagnosticar empresas: ' . $needle);
    }
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $catalogService = (string) file_get_contents($root . '/app/Services/CatalogService.php');
    foreach (['CompanyOptionService', '->active()'] as $needle) {
        $assert(str_contains($layout . $catalogService, $needle), 'Selectores principales deben usar CompanyOptionService: ' . $needle);
    }
    $assert(!str_contains($layout, 'SELECT id,name FROM companies WHERE status=1 ORDER BY name'), 'Selector superior no debe omitir deleted_at');
    $assert(!str_contains($catalogService, 'SELECT id,name FROM companies ORDER BY name'), 'CatalogService::companies no debe listar eliminadas/inactivas');
    $companiesView = (string) file_get_contents($root . '/app/Views/companies/index.php');
    foreach (['Hay empresas duplicadas por nombre', 'ID #', 'Nombre duplicado'] as $needle) {
        $assert(str_contains($companiesView, $needle), 'Modulo Empresas debe distinguir duplicados: ' . $needle);
    }
});

$test('2.8.18 agrega zoom y descripciones cacheadas en ficha publica de catalogo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.18', '>='), 'VERSION debe ser 2.8.18 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/052_catalog_product_gallery_2_8_17.sql');
    $assert(str_contains($migration, '2.8.17') && str_contains($migration, 'app_versions'), 'Migracion 052 debe registrar version 2.8.17');
    $migration53 = (string) file_get_contents($root . '/database/migrations/053_meli_item_descriptions_catalog_2_8_18.sql');
    foreach (['meli_item_descriptions', 'items.sync_descriptions_enabled', '2.8.18'] as $needle) {
        $assert(str_contains($migration53, $needle), 'Migracion 053 debe preparar descripciones cacheadas: ' . $needle);
    }
    $product = (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    foreach (['data-catalog-main-image', 'data-catalog-gallery', 'data-catalog-gallery-image', 'data-catalog-lightbox-open', 'Descripción', "View::asset(\$base, 'app.js')", 'v=2.11.2'] as $needle) {
        $assert(str_contains($product, $needle), 'Ficha publica debe exponer galeria clicable: ' . $needle);
    }
    $assert(!str_contains($product, 'array_unshift($pictureUrls'), 'La ficha pública no debe anteponer thumbnail_url sobre fotos grandes.');
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    foreach (['[data-catalog-gallery]', '[data-catalog-main-image]', 'dataset.catalogGalleryImage', 'aria-pressed', 'openCatalogLightbox', 'catalog-lightbox', 'Escape'] as $needle) {
        $assert(str_contains($js, $needle), 'app.js debe cambiar imagen principal y abrir zoom: ' . $needle);
    }
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    foreach (['.catalog-gallery-thumb', '.catalog-gallery-thumb.active', 'focus-visible', '.catalog-lightbox', '.catalog-product-description'] as $needle) {
        $assert(str_contains($css, $needle), 'CSS debe mostrar miniaturas, zoom y descripcion: ' . $needle);
    }
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $descriptionService = (string) file_get_contents($root . '/app/Services/MeliItemDescriptionService.php');
    foreach (['/description', 'fetchRemoteDescription', 'cachedForItem'] as $needle) {
        $assert(str_contains($registry . $descriptionService, $needle), 'Debe existir soporte solo lectura de descripcion: ' . $needle);
    }
});

$test('2.8.19 corrige imagen principal y agrega actualizacion admin de descripciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.19', '>='), 'VERSION debe ser 2.8.19 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/054_catalog_image_description_admin_2_8_19.sql');
    foreach (['catalog.description_batch_limit', 'last_description_sync_at', '2.8.19', 'setting_key'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 054 debe preparar control de descripciones: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/catalogs/{id}/descriptions/sync'), 'Falta ruta admin para descripciones de catálogo');
    $controller = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $service = (string) file_get_contents($root . '/app/Services/CatalogDescriptionSyncService.php');
    foreach (['CatalogDescriptionSyncService', 'syncDescriptions', 'retry_errors', 'force_refresh'] as $needle) {
        $assert(str_contains($controller . $service, $needle), 'Falta flujo de sincronización de descripciones: ' . $needle);
    }
    $show = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    foreach (['Descripciones Mercado Libre', 'Con descripción', 'Actualizar nuevamente todas las existentes'] as $needle) {
        $assert(str_contains($show, $needle), 'Administración de catálogo debe mostrar control de descripciones: ' . $needle);
    }
    $product = (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $assert(str_contains($product, 'if (!$pictureUrls && !empty($item[\'thumbnail_url\']))'), 'thumbnail_url debe ser fallback si no hay galería grande');
    $assert(!str_contains($product, 'array_unshift($pictureUrls'), 'No se debe convertir thumbnail_url en imagen principal si existen fotos grandes');
});

$test('2.8.20 declara compatibilidad PHP 8.3 a 8.5 y limpia deprecaciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.20', '>='), 'VERSION debe ser 2.8.20 o superior');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $assert(is_array($composer) && ($composer['require']['php'] ?? '') === '>=8.3 <8.6', 'composer.json debe soportar PHP >=8.3 <8.6');
    $migration = (string) file_get_contents($root . '/database/migrations/055_php_83_85_compatibility_2_8_20.sql');
    foreach (['app.min_php_version', 'app.max_tested_php_version', 'app.php_compatibility_range', 'setting_key', '2.8.20'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 055 debe registrar compatibilidad: ' . $needle);
    }
    $diagnostic = (string) file_get_contents($root . '/app/Services/DiagnosticService.php')
        . (string) file_get_contents($root . '/app/Core/RuntimeCompatibility.php');
    foreach (['RuntimeCompatibility', 'runtime', 'missing_required_extensions', 'composer_constraint'] as $needle) {
        $assert(str_contains($diagnostic, $needle), 'Diagnostico debe mostrar runtime PHP: ' . $needle);
    }
    $updateView = (string) file_get_contents($root . '/app/Views/settings/update.php');
    $diagnosticsView = (string) file_get_contents($root . '/app/Views/settings/diagnostics.php');
    $assert(str_contains($updateView . $diagnosticsView, 'Compatibilidad PHP') || str_contains($diagnosticsView, 'PHP 8.3–8.5'), 'Actualizador/diagnóstico debe mostrar compatibilidad PHP');
    $compat = (string) file_get_contents($root . '/bin/php_compat.php');
    $assert(str_contains($compat, 'php_compat_ok') && str_contains($compat, '>=8.3 <8.6'), 'Debe existir checker PHP compatible');
    foreach ([
        '/app/Controllers/ShipmentController.php',
        '/app/Controllers/SettingsController.php',
        '/app/Services/ExportService.php',
        '/app/Services/CatalogExportService.php',
        '/app/Controllers/MonthlyReportController.php',
    ] as $path) {
        $contents = (string) file_get_contents($root . $path);
        $assert(str_contains($contents, ', \'\\"\', \'\', "\\n")') || str_contains($contents, ', \'"\', \'\', "\\n")'), 'fputcsv debe usar escape explicito en ' . $path);
    }
    $syncOrders = (string) file_get_contents($root . '/jobs/sync_orders.php');
    $assert(
        str_contains($syncOrders, "erp_retired_job('sync_orders')")
        || (!str_contains($syncOrders, 'use DateTimeImmutable;') && str_contains($syncOrders, 'new \\DateTimeImmutable')),
        'sync_orders no debe generar warnings de use global ni continuar como worker independiente'
    );
});

$test('2.8.21 rediseña filtros públicos y estabiliza galería de catálogo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.21', '>='), 'VERSION debe ser 2.8.21 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/056_catalog_filter_ux_condition_gallery_2_8_21.sql');
    foreach (['condition_snapshot', '2.8.21', 'app_versions'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 056 debe preparar condición de catálogo: ' . $needle);
    }
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    foreach (['Marketplace', 'Tienda', 'Estado de publicación', 'Método de envío', 'Origen del stock', 'Calidad de datos', 'Estado del producto', 'Todos', 'data_quality', 'condition'] as $needle) {
        $assert(str_contains($public, $needle), 'Vista pública debe exponer filtros UX 2.8.21: ' . $needle);
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/PublicCatalogController.php');
    $assert(str_contains($controller, "\$filters['shipping_method'] = 'fulfillment'"), 'full=yes antiguo debe mapear a shipping_method=fulfillment');
    $query = (string) file_get_contents($root . '/app/Services/CatalogQueryService.php');
    $snapshot = (string) file_get_contents($root . '/app/Services/CatalogSnapshotService.php');
    foreach (['condition_snapshot', '$filters[\'condition\']', ':condition'] as $needle) {
        $assert(str_contains($query . $snapshot, $needle), 'Consulta/snapshot deben soportar condición: ' . $needle);
    }
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    foreach (['ERP Meli 2.8.21', '.catalog-filter-chip', '.catalog-active-filters', 'aspect-ratio:1/1', 'overflow:hidden', '.catalog-main-image-button img,.catalog-main-image-button img[data-catalog-main-image]', 'object-fit:contain'] as $needle) {
        $assert(str_contains($css, $needle), 'CSS debe estabilizar filtros y galería: ' . $needle);
    }
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $product = (string) file_get_contents($root . '/app/Views/public_catalog/product.php');
    $assert(
        str_contains($layout, "View::asset(\$base, 'app.css')")
        && str_contains($layout, 'AssetVersionService::fingerprint')
        && str_contains($product, "View::asset(\$base, 'app.js')")
        && str_contains($product, 'v=2.11.2'),
        'Assets deben quedar vinculados a la release activa y versionados.'
    );
});

$test('2.8.22 certifica runtime PHP 8.3 a 8.5 y contratos operativos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.22', '>='), 'VERSION debe ser 2.8.22 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/057_php_compatibility_audit_2_8_22.sql');
    foreach (['app.php_compatibility_audit_version', 'setting_key', '2.8.22', 'app_versions'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 057 debe registrar auditoría PHP: ' . $needle);
    }
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    foreach (['ext-curl', 'ext-iconv', 'ext-json', 'ext-mbstring', 'ext-openssl', 'ext-pdo', 'ext-pdo_mysql', 'ext-session'] as $extension) {
        $assert(isset($composer['require'][$extension]), 'Falta extensión obligatoria en composer: ' . $extension);
    }
    $assert(isset($composer['require-dev']['phpstan/phpstan']), 'PHPStan debe ser reproducible desde Composer.');
    $assert(is_file($root . '/composer.lock') && is_file($root . '/phpstan.neon'), 'Deben existir composer.lock y phpstan.neon.');
    $assert(is_file($root . '/tests/route_contract.php'), 'Debe existir prueba de contratos de rutas.');

    $apiClient = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $oauth = (string) file_get_contents($root . '/app/Services/OAuthService.php');
    $curlTransport = (string) file_get_contents($root . '/app/Services/CurlMeliHttpTransport.php');
    $assert(!str_contains($apiClient . $oauth . $curlTransport, 'curl_close('), 'PHP 8.5 no debe ejecutar curl_close().');
    $assert(str_contains($curlTransport, 'curl_init') && str_contains($curlTransport, '=== false'), 'cURL debe validar el handle.');

    $lost = new PDOException('The client was disconnected by the server because of inactivity');
    $lost->errorInfo = ['HY000', 4031, 'wait_timeout'];
    $assert(\App\Core\Database::isLostConnection($lost), 'La reconexión debe reconocer MySQL 4031.');
    $runtime = \App\Core\RuntimeCompatibility::snapshot();
    $assert(($runtime['status'] ?? '') === 'ok', 'El runtime de pruebas debe ser compatible: ' . (string) ($runtime['message'] ?? ''));
    $assert(($runtime['supported_range'] ?? '') === '>=8.3 <8.6');

    $cron = (string) file_get_contents($root . '/app/Services/CronHealthService.php');
    $assert(str_contains($cron, "'_runtime'") && str_contains($cron, 'runtime_matches_web'), 'Cron debe registrar y comparar su runtime PHP.');
    $compat = (string) file_get_contents($root . '/bin/php_compat.php');
    foreach (['curl_close', 'implicit_nullable', 'fputcsv_args', 'php_compat_ok'] as $needle) {
        $assert(str_contains($compat, $needle), 'El checker de compatibilidad debe revisar: ' . $needle);
    }
});

$test('2.8.23 aisla galeria y agrega cola resiliente de descripciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.8.23', '>='), 'VERSION debe ser 2.8.23 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/058_catalog_description_jobs_2_8_23.sql');
    foreach (['catalog_description_jobs', 'catalog_description_job_items', 'next_run_at', 'lock_expires_at', 'catalog.description_job_pause_seconds', '2.8.23'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 058 debe incluir: ' . $needle);
    }
    $service = (string) file_get_contents($root . '/app/Services/CatalogDescriptionJobService.php');
    foreach (['function create(', 'function processDue(', 'function pause(', 'function resume(', 'function cancel(', 'function reopen(', 'function retryErrors(', 'lock_token', 'dedupe_key'] as $needle) {
        $assert(str_contains($service, $needle), 'Servicio de cola debe implementar: ' . $needle);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/catalogs/{id}/description-jobs', '/catalogs/description-jobs/{jobId}/status.json', '/catalogs/description-jobs/{jobId}/step', '/catalogs/description-jobs/{jobId}/pause', '/catalogs/description-jobs/{jobId}/reopen'] as $needle) {
        $assert(str_contains($routes, $needle), 'Falta ruta de cola de descripciones: ' . $needle);
    }
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($cron, 'CatalogDescriptionJobService') && str_contains($cron, 'catalog_description_processed'), 'Cron debe procesar y reportar descripciones.');
    $catalogCss = (string) file_get_contents($root . '/public/assets/catalog.css');
    foreach (['.catalog-main-image-button', '.catalog-product-gallery', '.catalog-gallery-thumb img', 'object-fit:contain!important', '.catalog-admin-toolbar', '.catalog-more-filters'] as $needle) {
        $assert(str_contains($catalogCss, $needle), 'CSS aislado de catálogo debe incluir: ' . $needle);
    }
    $catalogJs = (string) file_get_contents($root . '/public/assets/catalog.js');
    foreach (['data-catalog-gallery', 'catalog-lightbox'] as $needle) {
        $assert(str_contains($catalogJs, $needle), 'JS de catálogo debe incluir: ' . $needle);
    }
    $descriptionController = (string) file_get_contents($root . '/app/Controllers/CatalogDescriptionJobController.php');
    $stepMethod = substr(
        $descriptionController,
        strpos($descriptionController, 'public function step'),
        700
    );
    $assert(!str_contains($catalogJs, 'data-catalog-description-monitor')
        && !str_contains($catalogJs, 'stepUrl')
        && str_contains($stepMethod, '410')
        && !str_contains($stepMethod, 'processDue('),
        'La galería permanece activa, pero el ejecutor web de descripciones debe quedar retirado.');
    $public = (string) file_get_contents($root . '/app/Views/public_catalog/show.php');
    $assert(str_contains($public, 'Más filtros') && !str_contains($public, 'catalog-filter-chip'), 'Filtros públicos deben ser sobrios y no usar píldoras antiguas.');
});

$test('2.9.0 agrega motor seguro de releases, migraciones y rescate', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach ([
        '/database/migrations/059_secure_update_engine_2_9.sql',
        '/app/Services/SecureUpdateEngineService.php',
        '/app/Services/UpdateManifestService.php',
        '/app/Services/UpdateReleaseService.php',
        '/app/Services/UpdateBackupService.php',
        '/launcher/web.php',
        '/launcher/cron.php',
        '/launcher/rescue.php',
        '/jobs/process_updates.php',
        '/public/assets/update.js',
        '/public/assets/update.css',
    ] as $path) {
        $assert(is_file($root . $path), 'Falta componente 2.9.0: ' . $path);
    }
    $migration = (string) file_get_contents($root . '/database/migrations/059_secure_update_engine_2_9.sql');
    foreach (['system_update_releases', 'system_update_runs', 'system_update_run_steps', 'system_update_migrations', 'system_update_backups', 'system_update_locks', 'system_update_trusted_keys'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 059 debe crear ' . $needle);
    }
    $engine = (string) file_get_contents($root . '/app/Services/SecureUpdateEngineService.php');
    foreach (['createRun(', 'process(', 'processDue(', 'rollback(', 'known_drifted', 'ACTUALIZAR SIN RESPALDO'] as $needle) {
        $assert(str_contains($engine, $needle), 'Motor seguro debe incluir ' . $needle);
    }
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    $assert(str_contains($migrator, 'GET_LOCK') && str_contains($migrator, 'checksum_sha256') && str_contains($migrator, "'adopted'"), 'Migrador debe usar lock, checksum y adopción segura.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/settings/update/package', '/settings/update/run/process', '/settings/update/status.json', '/settings/update/run/rollback'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta del motor seguro: ' . $route);
    }
});

$test('2.9.1 aplica correcciones API desde documentación materializada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.9.1', '>='), 'VERSION debe ser 2.9.1 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/060_api_audit_corrections_2_9_1.sql');
    foreach (['meli_api_capabilities', 'questions.api_version', 'claims.use_player_filters', 'items.scan_enabled', 'payments.expand_details_enabled', '2.9.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 060 debe incluir: ' . $needle);
    }
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    foreach (['disabled', 'investigating', '/payments/', 'actions-history|status-history|affects-reputation', 'contracts()'] as $needle) {
        $assert(str_contains($registry, $needle), 'Registro API debe controlar: ' . $needle);
    }
    $questions = (string) file_get_contents($root . '/app/Services/QuestionSyncService.php');
    $assert(str_contains($questions, "'api_version'") && str_contains($questions, "questions.api_version"), 'Preguntas deben enviar api_version configurable.');
    $claims = (string) file_get_contents($root . '/app/Services/ClaimSyncService.php');
    $assert(str_contains($claims, "'players.user_id'") && str_contains($claims, "'players.role'") && str_contains($claims, 'claims.use_player_filters'), 'Reclamos deben usar filtros de participante configurables.');
    $items = (string) file_get_contents($root . '/app/Services/MeliItemSyncService.php') . (string) file_get_contents($root . '/app/Services/MeliProductUpdateReviewService.php');
    $assert(str_contains($items, "'search_type' => 'scan'") && str_contains($items, "'scroll_id'") && str_contains($items, "'offset' => \$offset"), 'Productos deben soportar offset y scan sin mezclar scroll_id con offset.');
    $billing = (string) file_get_contents($root . '/app/Services/OrderBillingImportService.php');
    $assert(str_contains($billing, "'other' => 0.0") && str_contains($billing, 'revision_manual'), 'Billing ambiguo debe quedar en revisión manual.');
    $stock = (string) file_get_contents($root . '/app/Services/MeliItemStockService.php');
    $assert(str_contains($stock, 'permission_required') && str_contains($stock, 'Detalle no disponible') === false && str_contains($stock, 'MeliApiCapabilityService'), 'Stock 403 debe registrar capacidad y no inventar stock.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/settings/api-docs', '/settings/api-docs/endpoint', '/settings/api-docs/regenerate'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta documentación API: ' . $route);
    }
    foreach (['endpoints.json', 'fields.json', 'erp-usage.json', 'coverage.json', 'risks.json'] as $json) {
        $assert(is_file($root . '/resources/mercadolibre-api/generated/' . $json), 'Falta contrato compacto: ' . $json);
    }
});

$test('2.9.2 agrega presupuesto API preventivo anti-bloqueo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.9.2', '>='), 'VERSION debe ser 2.9.2 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/061_api_budget_blocking_2_9_2.sql');
    foreach (['api_budget_windows', 'api_workload_estimates', 'api.budget.enabled', 'api.guard.max_400_per_window', 'items.sync_descriptions_inline_enabled', 'sales_audit.exact_queue_required_threshold_days', '2.9.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 061 debe incluir: ' . $needle);
    }
    $budget = (string) file_get_contents($root . '/app/Services/ApiBudgetService.php');
    foreach (['function reserve(', 'function recordResult(', 'function canRunJobType(', 'api_budget_windows', 'web_request_api_limit', '/payments/{id}'] as $needle) {
        $assert(str_contains($budget, $needle), 'ApiBudgetService debe controlar: ' . $needle);
    }
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    foreach (['ApiBudgetService', 'reserve(', 'recordResult(', 'api_budget_exhausted'] as $needle) {
        $assert(str_contains($client, $needle), 'MeliApiClient debe reservar presupuesto antes de cURL: ' . $needle);
    }
    $assert(str_contains($budget, 'Presupuesto API ML agotado'), 'ApiBudgetService debe exponer mensaje seguro de presupuesto agotado.');
    $classifier = (string) file_get_contents($root . '/app/Services/ApiErrorClassifier.php');
    $guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
    foreach (['unauthorized_scopes', 'excessive_api_call', 'bad_request_token_owner'] as $needle) {
        $assert(str_contains($classifier, $needle), 'Clasificador debe detectar bloqueo: ' . $needle);
    }
    foreach (['bad_request_400_repeated', 'api.guard.max_400_per_window', 'api.guard.unauthorized_scopes_global_pause_minutes'] as $needle) {
        $assert(str_contains($guard, $needle), 'Guardia debe abrir circuitos preventivos: ' . $needle);
    }
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    foreach (['canRunJobType', 'orders_sync', 'description_job', 'billing', 'questions', 'api_budget', 'api_priority_budget_enabled'] as $needle) {
        $assert(str_contains($cron, $needle), 'Cron debe respetar presupuesto por prioridad: ' . $needle);
    }
    $payments = (string) file_get_contents($root . '/app/Services/PaymentDetailPolicy.php');
    $assert(str_contains($payments, 'return false;') && str_contains($payments, '/payments/{id}'), 'Detalle /payments/{id} debe quedar bloqueado por defecto.');
    $settings = (string) file_get_contents($root . '/app/Views/settings/index.php') . (string) file_get_contents($root . '/app/Views/settings/api_health.php');
    foreach (['Presupuesto preventivo', 'Enviadas a Mercado Libre', 'Incidentes activos', 'Salud de la integración'] as $needle) {
        $assert(str_contains($settings, $needle), 'UI debe mostrar presupuesto API: ' . $needle);
    }
    $rulesFile = $root . '/resources/mercadolibre-api/generated/blocking-rules.json';
    $assert(is_file($rulesFile), 'Debe existir contrato compacto blocking-rules.json');
    $rules = (string) file_get_contents($rulesFile);
    foreach (['EXCESSIVE_API_CALL', 'unauthorized_scopes', 'Retry-After', 'public_catalog_api_usage'] as $needle) {
        $assert(str_contains($rules, $needle), 'blocking-rules debe documentar regla: ' . $needle);
    }
});

$test('2.9.3 corrige migrador para instalaciones con collations mixtas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.9.3', '>='), 'VERSION debe ser 2.9.3 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/062_migrator_collation_fix_2_9_3.sql');
    foreach (['2.9.3', 'Illegal mix of collations', 'app_versions'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 062 debe registrar correctivo: ' . $needle);
    }
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    foreach (['$started = $state ===', '$finished = in_array'] as $needle) {
        $assert(str_contains($migrator, $needle), 'Migrador debe calcular estados en PHP: ' . $needle);
    }
    foreach ([":state_started='running'", ":state_finished IN ('applied','adopted','failed','drifted')", "VALUES(state)='running'", "VALUES(state) IN ('applied','adopted','failed','drifted')"] as $bad) {
        $assert(!str_contains($migrator, $bad), 'Migrador no debe conservar comparación textual propensa a collation: ' . $bad);
    }
});

$test('2.9.4 corrige HY093 y agrega trazabilidad segura de migraciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.9.4', '>='), 'VERSION debe ser 2.9.4 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/063_migrator_parameter_trace_2_9_4.sql');
    foreach (['system_update_migration_events', 'sql_state', 'update.migration_debug_enabled', '2.9.4', 'app_versions'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 063 debe incluir: ' . $needle);
    }
    $assert(!str_contains($migration, "\n    sqlstate VARCHAR"), 'La tabla no debe usar sqlstate como columna reservada en MariaDB');
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    foreach (['MigrationTraceService', 'MigrationExecutionException', 'SELECT id FROM system_update_migrations WHERE migration_key=?', 'sql_execution_started'] as $needle) {
        $assert(str_contains($migrator, $needle), 'Migrador 2.9.4 debe incluir: ' . $needle);
    }
    foreach ([':state_started', ':state_finished', 'ON DUPLICATE KEY UPDATE
               checksum_sha256'] as $bad) {
        $assert(!str_contains($migrator, $bad), 'Migrador no debe conservar upsert HY093: ' . $bad);
    }
    foreach ([
        '/app/Services/MigrationTraceService.php',
        '/app/Services/MigrationDiagnosticService.php',
        '/app/Services/MigrationExecutionException.php',
        '/app/Views/settings/migration_diagnostics.php',
        '/tests/migrator_mysql_integration.php',
    ] as $file) {
        $assert(is_file($root . $file), 'Falta componente de diagnóstico: ' . $file);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/settings/diagnostics/migrations', '/settings/diagnostics/migrations/export', '/settings/diagnostics/migrations/status.json'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta diagnóstica: ' . $route);
    }
    $trace = (string) file_get_contents($root . '/app/Services/MigrationTraceService.php');
    foreach (['[REDACTED]', 'sql_included', 'parameters_included'] as $needle) {
        $haystack = in_array($needle, ['sql_included', 'parameters_included'], true)
            ? (string) file_get_contents($root . '/app/Services/MigrationDiagnosticService.php')
            : $trace;
        $assert(str_contains($haystack, $needle), 'Diagnóstico debe proteger: ' . $needle);
    }
});

$test('2.9.5 mantiene visible la acción guiada y completa migraciones seguras', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/064_updater_guided_completion_2_9_5.sql');
    foreach (['ui.update.guided_mode', 'ui.update.advanced_collapsed', '2.9.5'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 064 debe incluir: ' . $needle);
    }
    $view = (string) file_get_contents($root . '/app/Views/settings/update.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
    $assert(str_contains($view, 'Completar actualización'), 'El actualizador debe mantener una acción principal humana.');
    $assert(str_contains($view, 'Opciones avanzadas'), 'Las herramientas técnicas deben quedar disponibles en modo avanzado.');
    $assert(str_contains($controller, 'array_merge($results, $updateService->runPendingMigrations())'), 'Después de activar 059 debe continuar el resto de migraciones.');
});

$test('2.10.0 separa configuración, centraliza navegación y guía vistas técnicas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.10.0', '>='), 'VERSION debe ser 2.10.0 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/065_human_configuration_ux_2_10_0.sql');
    foreach (['ui.navigation.task_oriented', 'ui.settings.section_saves', 'ui.accessibility.minimum_target_px', '2.10.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 065 debe incluir: ' . $needle);
    }
    foreach ([
        '/app/Repositories/NavigationRepository.php',
        '/app/Repositories/SettingsDefinitionRepository.php',
        '/app/Services/SettingsSectionService.php',
        '/app/Controllers/SettingsSectionController.php',
        '/app/Views/settings/section.php',
        '/public/assets/ux.css',
        '/public/assets/ux.js',
    ] as $file) {
        $assert(is_file($root . $file), 'Falta componente UX 2.10.0: ' . $file);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, "'/settings/{section}'"), 'Configuración debe tener rutas independientes por sección.');
    $settingsService = (string) file_get_contents($root . '/app/Services/SettingsSectionService.php');
    $assert(str_contains($settingsService, 'beginTransaction') && str_contains($settingsService, 'update_settings_section'), 'El guardado por sección debe ser transaccional y auditado.');
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(
        str_contains($layout, 'NavigationRepository')
        && str_contains($layout, "View::asset(\$base, 'ux.css')")
        && str_contains($layout, 'uxCssFingerprint'),
        'El layout debe usar navegación central y assets visuales de la release activa.'
    );
    $health = (string) file_get_contents($root . '/app/Views/settings/api_health.php')
        . (string) file_get_contents($root . '/app/Services/ApiHealthStatusPresenter.php');
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $diagnostics = (string) file_get_contents($root . '/app/Views/settings/diagnostics.php');
    $cron = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    foreach (['Salud de la integración', 'Estado por cuenta', 'Detalles técnicos'] as $needle) {
        $assert(str_contains($health, $needle), 'Salud API debe incluir: ' . $needle);
    }
    $assert(str_contains($diagnostics, 'Qué debe resolver') && str_contains($diagnostics, 'Detalles técnicos del sistema'), 'Diagnóstico debe priorizar acciones humanas.');
    $assert(str_contains($cron, 'Recuperar bloques vencidos') && str_contains($cron, 'Opciones avanzadas y Diagnóstico horario'), 'Cron debe separar operación y detalle técnico.');
});

$test('2.10.1 estabiliza colas, elimina pagos expandidos y coordina el cron', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.10.1', '>='), 'VERSION debe ser 2.10.1 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/066_system_stability_process_efficiency_2_10_1.sql');
    foreach (['order_resource_enrichment_jobs', 'order_resource_enrichment_job_orders', 'meli_item_sync_jobs', 'system_cron_run_steps', '2.10.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 066 debe incluir: ' . $needle);
    }
    $orders = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $assert(!str_contains($orders, "\$this->api->get('/payments/"), 'OrderSyncService no debe consultar /payments/{id}.');
    $enrichment = (string) file_get_contents($root . '/app/Services/OrderEnrichmentService.php');
    foreach (['order_resource_enrichment_job_orders', 'cachedResource', 'processDue'] as $needle) {
        $haystack = $needle === 'cachedResource' ? $orders : $enrichment;
        $assert(str_contains($haystack, $needle), 'Falta deduplicación de recursos: ' . $needle);
    }
    $items = (string) file_get_contents($root . '/app/Services/MeliItemSyncJobService.php');
    $assert(str_contains($items, 'phase') && str_contains($items, 'discoverPage') && str_contains($items, 'details'), 'Productos debe separar descubrimiento y detalle.');
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($cron, 'CronWorkCoordinator') && str_contains($cron, 'OrderEnrichmentService') && str_contains($cron, 'MeliItemSyncJobService'), 'Cron debe coordinar colas aisladas.');
    $health = (string) file_get_contents($root . '/app/Services/CronHealthService.php');
    foreach (['MAX_PAYLOAD_BYTES', 'encodedPayload', 'compactCoordinator', 'detail_table'] as $needle) {
        $assert(str_contains($health, $needle), 'cron_health_checks debe guardar payload acotado y dejar detalle en system_cron_run_steps: ' . $needle);
    }
    $session = (string) file_get_contents($root . '/app/Core/Session.php');
    $assert(str_contains($session, 'looksTechnical') && str_contains($session, 'Código de diagnóstico'), 'Mensajes técnicos deben ocultarse al usuario.');
});

$test('Cron conserva un resumen acotado aunque existan muchos pasos', static function () use ($assert): void {
    $steps = [];
    for ($index = 1; $index <= 100; $index++) {
        $steps['task_' . $index] = [
            'status' => 'completed',
            'processed' => 1,
            'errors' => $index % 20 === 0 ? 1 : 0,
            'duration_ms' => 10 + $index,
            'stop_reason' => '',
            'message' => str_repeat('detalle ', 50),
        ];
    }
    $service = new \App\Services\CronHealthService();
    $method = new ReflectionMethod($service, 'encodedPayload');
    $json = (string) $method->invoke($service, [
        'processed' => 100,
        'errors' => 5,
        'end_reason' => 'work_completed',
        'coordinator' => [
            'budget_ms' => 40000,
            'used_ms' => 12000,
            'remaining_ms' => 28000,
            'steps' => $steps,
        ],
    ]);
    $decoded = json_decode($json, true);
    $assert(strlen($json) <= 2048, 'El resumen de Cron debe permanecer por debajo de 2 KiB.');
    $assert(is_array($decoded)
        && (int) ($decoded['processed'] ?? 0) === 100
        && (int) ($decoded['errors'] ?? 0) === 5
        && (int) ($decoded['coordinator']['step_count'] ?? 0) === 100
        && (int) ($decoded['coordinator']['processed'] ?? 0) === 100,
        'La compactación debe conservar contadores y cantidad de pasos.');
    $assert(count((array) ($decoded['coordinator']['last_steps'] ?? [])) <= 5,
        'El payload debe conservar únicamente una cola corta de pasos recientes.');
});

$test('2.11.0 agrega arquitectura gradual, rangos UTC y observabilidad verificable', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.0', '>='), 'VERSION debe ser 2.11.0 o posterior');
    $migration = (string) file_get_contents($root . '/database/migrations/067_architecture_performance_observability_2_11_0.sql');
    foreach (['system_process_metrics', 'system_query_plan_audits', 'dates.range_contract', '2.11.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 067 debe incluir: ' . $needle);
    }
    foreach ([
        '/app/Core/Container.php',
        '/app/Repositories/RouteMetadataRepository.php',
        '/app/Services/RouteCatalogService.php',
        '/app/Services/Clock.php',
        '/app/ValueObjects/DateRange.php',
        '/app/Contracts/JobHandler.php',
        '/app/ValueObjects/JobLease.php',
        '/app/ValueObjects/JobResult.php',
        '/app/ValueObjects/RequestContext.php',
        '/app/Services/SchedulerService.php',
        '/app/Services/DashboardReadModelService.php',
        '/app/Services/QueryPlanAuditService.php',
        '/bin/db_explain_audit.php',
    ] as $file) {
        $assert(is_file($root . $file), 'Falta componente 2.11.0: ' . $file);
    }
    $router = (string) file_get_contents($root . '/app/Core/Router.php');
    $assert(str_contains($router, 'RouteMetadataRepository') && str_contains($router, 'definitions()'), 'Router debe conservar catálogo y metadata central.');
    $dateRange = (string) file_get_contents($root . '/app/ValueObjects/DateRange.php');
    $assert(str_contains($dateRange, "modify('+1 day')") && str_contains($dateRange, 'localToUtc'), 'Rangos de fecha deben ser locales, half-open y almacenados en UTC.');
    $dashboard = (string) file_get_contents($root . '/app/Services/DashboardReadModelService.php');
    $assert(str_contains($dashboard, 'requestCache') && str_contains($dashboard, 'SELECT o.id,o.external_order_id'), 'Dashboard debe usar read model cacheado sin SELECT *.');
});

$test('2.11.1 agrega notificaciones humanas y sincronizacion Webhook-First', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.1', '>='), 'VERSION debe ser 2.11.1 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/068_webhook_first_notifications_2_11_1.sql');
    foreach (['meli_notification_work_items', 'meli_notification_backfill_runs', 'notifications.webhook_first_enabled', 'notifications.target_sla_seconds', '2.11.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 068 debe incluir: ' . $needle);
    }
    foreach ([
        '/app/Services/MeliNotificationTopicRegistry.php',
        '/app/Services/NotificationWorkItemService.php',
        '/app/Services/NotificationBackfillService.php',
        '/app/Services/NotificationReconciliationService.php',
        '/app/Services/WebhookSpoolService.php',
        '/resources/mercadolibre-api/generated/notification-topics.json',
    ] as $file) {
        $assert(is_file($root . $file), 'Falta componente Webhook-First: ' . $file);
    }
    $receiver = (string) file_get_contents($root . '/app/Services/WebhookService.php');
    foreach (['connectionFresh', 'WebhookSpoolService', 'MeliNotificationTopicRegistry', 'NotificationWorkItemService', 'application_id'] as $needle) {
        $assert(str_contains($receiver, $needle), 'Receptor durable debe incluir: ' . $needle);
    }
    $worker = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    foreach (['rerun_requested', 'orders_event_sync', 'syncQuestionById', 'syncShipmentById', 'createReviewForItem'] as $needle) {
        $assert(str_contains($worker, $needle), 'Worker canónico debe incluir: ' . $needle);
    }
    $assert(!str_contains($worker, "get('/payments/"), 'Worker de notificaciones no debe consultar /payments/{id}.');
    $registry = new \App\Services\MeliNotificationTopicRegistry();
    $order = $registry->classify('orders_v2', '/orders/2000012345678901');
    $assert($order['valid'] === true && $order['resource_id'] === '2000012345678901' && $order['endpoint'] === '/orders/{id}', 'orders_v2 debe construir un recurso exacto registrado.');
    $manipulated = $registry->classify('orders_v2', 'https://example.invalid/orders/2000012345678901');
    $assert($manipulated['valid'] === false, 'Una URL manipulada no debe convertirse en llamada API.');
    $payment = $registry->classify('payments', '/payments/123');
    $assert($payment['valid'] === true && $payment['actionable'] === false && $payment['endpoint'] === null, 'Pagos debe reconocerse sin consultar /payments/{id}.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/notifications/activity', '/notifications/health', '/notifications/status.json', '/notifications/technical/events', '/notifications/backfill/analyze'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta Webhook-First: ' . $route);
    }
    $view = (string) file_get_contents($root . '/app/Views/notifications/index.php')
        . (string) file_get_contents($root . '/app/Repositories/NavigationRepository.php');
    foreach (['Necesitan atención', 'Actividad reciente', 'Salud y recuperación', 'Sincronización automática funcionando'] as $needle) {
        $assert(str_contains($view, $needle), 'Centro humano debe incluir: ' . $needle);
    }
});

$test('2.11.2 agrega carga progresiva, worker guiado y medición sanitizada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.2', '>='), 'VERSION debe ser 2.11.2 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/069_progressive_performance_worker_2_11_2.sql');
    foreach (['system_performance_metrics', 'performance.async_sections_enabled', 'notifications.assisted_worker_enabled', '2.11.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 069 debe incluir: ' . $needle);
    }
    foreach ([
        '/app/Services/AsyncSectionService.php',
        '/app/Services/ReadModelCacheService.php',
        '/app/Services/CacheInvalidationService.php',
        '/app/Services/PerformanceMonitorService.php',
        '/app/Services/AssetVersionService.php',
        '/app/Views/notifications/automation.php',
        '/public/assets/performance.css',
        '/public/assets/performance.js',
    ] as $file) {
        $assert(is_file($root . $file), 'Falta componente progresivo: ' . $file);
    }
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/orders/section.json', '/shipments/section.json', '/products/meli/section.json', '/dashboard/operations.json', '/notifications/automation/assisted-step', '/performance/metrics'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta progresiva: ' . $route);
    }
    $orders = (string) file_get_contents($root . '/app/Controllers/OrderController.php');
    $saleReader = is_file($root . '/app/Services/SaleReadService.php')
        ? (string) file_get_contents($root . '/app/Services/SaleReadService.php')
        : '';
    $legacyProgressive = str_contains($orders, "LIMIT ' . \$params['per_page'] . ' OFFSET ' . \$offset")
        && str_contains($orders, 'AsyncSectionService');
    $groupedSales = str_contains($orders, "\$this->redirect('/sales")
        && str_contains($saleReader, 'LIMIT \' . $perPage . \' OFFSET \' . $offset');
    $assert($legacyProgressive || $groupedSales, 'Órdenes o Ventas debe conservar paginación server-side.');
    $javascript = (string) file_get_contents($root . '/public/assets/performance.js');
    foreach (['AbortController', 'aria-busy', 'PerformanceObserver', 'data-async-section', 'history.pushState'] as $needle) {
        $assert(str_contains($javascript, $needle), 'Loader progresivo debe incluir: ' . $needle);
    }
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(str_contains($layout, 'performance.css') && str_contains($layout, 'performance.js') && str_contains($layout, 'AssetVersionService::fingerprint'));
});

$test('2.11.3 corrige coherencia visual, etiquetas humanas y consultas del Panel general', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.3', '>='), 'VERSION debe ser 2.11.3 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/070_ui_dashboard_consistency_2_11_3.sql');
    foreach (['dashboard.mobile_cards_enabled', 'products.internal_page_size', 'ui.minimum_touch_target_px', '2.11.3'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 070 debe incluir: ' . $needle);
    }
    $readModel = (string) file_get_contents($root . '/app/Services/DashboardReadModelService.php');
    foreach (['orders_period_total', 'financial_complete_count', 'financial_pending_count', 'billing_reconciled_net_amount', 'net_is_partial'] as $needle) {
        $assert(str_contains($readModel, $needle), 'Read model del Dashboard debe incluir: ' . $needle);
    }
    $assert(!str_contains($readModel, 'DATE_FORMAT(UTC_TIMESTAMP(),"%Y-%m-01")'), 'El neto del Dashboard no debe ignorar el rango seleccionado.');
    $dashboard = (string) file_get_contents($root . '/app/Views/dashboard/index.php');
    $assert(str_contains($dashboard, 'Órdenes del periodo') && str_contains($dashboard, 'No hay órdenes dentro del rango seleccionado.'), 'Dashboard debe distinguir métricas y estados vacíos.');
    $css = (string) file_get_contents($root . '/public/assets/performance.css');
    $assert(str_contains($css, '.dashboard-operation-list') && str_contains($css, 'grid-template-columns: minmax(0, 1fr)'), 'Dashboard progresivo debe usar flujo vertical y listas legibles.');
    $presenter = new \App\Services\UiLabelPresenter();
    $assert($presenter::syncType('orders') === 'Órdenes');
    $assert($presenter::catalogVisibility('private_token') === 'Privado por enlace');
    $assert($presenter::financialPhase('local_recalc') === 'Cálculo local');
    $assert($presenter::auditDifference(-5) === '5 sobrantes');
    $productLinks = (string) file_get_contents($root . '/app/Services/ProductLinkService.php');
    $assert(!str_contains($productLinks, 'LIKE :q OR'), 'Vinculación no debe reutilizar placeholders nombrados con prepares nativos.');
    $accounts = (string) file_get_contents($root . '/app/Controllers/MeliAccountController.php');
    $assert(str_contains($accounts, 'MeliAccountOverviewService') && !str_contains($accounts, 'SELECT a.*'), 'Cuentas ML debe usar columnas explícitas y resumen cacheado.');
});

$test('2.11.4 separa cron automático, prueba manual y worker de notificaciones', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.4', '>='), 'VERSION debe ser 2.11.4 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/071_cron_execution_verification_2_11_4.sql');
    foreach (['execution_source', 'run_token', 'heartbeat_at', 'automatic_streak', 'cron.main_interval_minutes', '2.11.4'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 071 debe incluir: ' . $needle);
    }
    $health = (string) file_get_contents($root . '/app/Services/CronHealthService.php');
    foreach (['scheduled_cli', 'manual_web', 'notification_cli', 'Automatización verificada', 'No llegó una invocación automática', 'pending_verification'] as $needle) {
        $assert(str_contains($health, $needle), 'Salud cron verificable debe incluir: ' . $needle);
    }
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $assert(str_contains($settings, "'manual_web'") && str_contains($settings, "query('SELECT 1')"), 'La prueba web debe ser rápida y quedar separada.');
    $assert(!str_contains($settings, '(new SyncQueueService())->processDue(1)'), 'La prueba web no debe procesar una cola real.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/settings/cron/status.json'), 'Debe existir endpoint de estado cron.');
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($job, 'ERP_CRON_START') && str_contains($job, 'ERP_CRON_OK'),
        'El lanzador único debe emitir salida CLI compacta.');
    $view = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    foreach (['Última ejecución automática', 'Frecuencia observada', 'Comprobar instalación', 'Notificaciones Webhook‑First'] as $needle) {
        $assert(str_contains($view, $needle), 'Vista cron debe incluir: ' . $needle);
    }
});

$test('2.11.5 acota cron Hostinger, evita solapamientos y agrega probe CLI', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.11.5', '>='), 'VERSION debe ser 2.11.5 o superior');
    $migration = (string) file_get_contents($root . '/database/migrations/072_hostinger_bounded_cron_2_11_5.sql');
    foreach (['cron_task_state', 'cron.max_runtime_seconds', 'cron.max_api_tasks_per_run', 'current_step', 'end_reason', '2.11.5'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 072 debe incluir: ' . $needle);
    }
    $probe = (string) file_get_contents($root . '/jobs/cron_probe.php');
    foreach (['ERP_CRON_PROBE_START', 'ERP_CRON_PROBE_OK', 'hostinger_test', "query('SELECT 1')"] as $needle) {
        $assert(str_contains($probe, $needle), 'Probe CLI debe incluir: ' . $needle);
    }
    $assert(!str_contains($probe, 'MeliApiClient') && !str_contains($probe, 'processDue('), 'Probe no debe consultar Mercado Libre ni procesar colas.');
    $main = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    foreach (['ERP_CRON_BOOT', 'ERP_CRON_SKIP', 'cron_entry_early_lock()', 'CronDeadlineContext::start', 'CronTaskStateService', 'max_api_tasks_per_run'] as $needle) {
        $assert(str_contains($main, $needle), 'Cron general acotado debe incluir: ' . $needle);
    }
    $assert(!str_contains($main, 'SecureUpdateEngineService'), 'El motor de actualizaciones no debe ejecutarse dentro del cron operativo.');
    $worker = (string) file_get_contents($root . '/jobs/process_notifications.php');
    $assert(str_contains($worker, 'ERP_CRON_SKIP')
        && str_contains($worker, 'retired_single_launcher')
        && !str_contains($worker, "job_try_lock('process_notifications')"),
        'El worker dedicado debe quedar retirado sin adquirir locks.');
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $assert(str_contains($client, 'CronDeadlineContext::curlTimeouts()'), 'Cliente API debe respetar el deadline del cron.');
});

$test('El runtime detecta releases mezcladas y verifica workers antes de procesar', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $fileVersion = trim((string) file_get_contents($root . '/VERSION'));
    $assert(version_compare($fileVersion, '2.11.12', '>='), 'VERSION debe ser 2.11.12 o posterior.');
    $migration = (string) file_get_contents($root . '/database/migrations/073_cron_release_integrity_2_11_6.sql');
    foreach (['release_version', 'release_build_id', 'component_checksum', 'idx_cron_health_release_build', '2.11.6'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 073 debe incluir: ' . $needle);
    }

    $manifestPath = $root . '/resources/runtime-manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $assert(is_array($manifest), 'El manifiesto de runtime debe ser JSON válido.');
    $assert(($manifest['version'] ?? '') === $fileVersion, 'El manifiesto debe coincidir con VERSION.');
    $assert(is_string($manifest['minimum_migration'] ?? null) && ($manifest['minimum_migration'] ?? '') !== '', 'El manifiesto debe declarar su migración mínima.');
    foreach (['cron_probe', 'process_sync_queue'] as $component) {
        $definition = $manifest['components'][$component] ?? [];
        $path = (string) ($definition['path'] ?? '');
        $expected = (string) ($definition['sha256'] ?? '');
        $assert($path !== '' && is_file($root . '/' . $path), 'Debe existir el componente ' . $component . '.');
        $assert(
            preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, strtolower((string) hash_file('sha256', $root . '/' . $path))),
            'El checksum debe coincidir para ' . $component . '.'
        );
    }

    $service = (string) file_get_contents($root . '/app/Services/ReleaseIntegrityService.php');
    foreach (['component_mismatch', 'migration_pending', 'nested_release_detected', 'cron_task_state', 'assertReady'] as $needle) {
        $assert(str_contains($service, $needle), 'Integridad de release debe incluir: ' . $needle);
    }
    foreach (['cron_probe.php', 'process_sync_queue.php'] as $file) {
        $job = (string) file_get_contents($root . '/jobs/' . $file);
        $assert(str_contains($job, 'ReleaseIntegrityService'), $file . ' debe validar integridad antes de procesar.');
        $assert(str_contains($job, 'version=') && str_contains($job, 'build='), $file . ' debe informar versión y build.');
    }
    $probe = (string) file_get_contents($root . '/jobs/cron_probe.php');
    $assert(str_contains($probe, "migration='") && str_contains($probe, 'components=ok'), 'Probe debe verificar la migración requerida y los componentes.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/settings/cron/integrity.json'), 'Debe existir el diagnóstico de integridad de solo lectura.');
    $view = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $assert(str_contains($view, 'Instalación mezclada') && str_contains($view, 'Comprobar integridad'), 'Cron debe mostrar el estado de integridad.');
});

$test('2.11.7 diagnostica fallos del worker y usa el intervalo real de Hostinger', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/074_notification_worker_diagnostics_2_11_7.sql');
    foreach ([
        'last_error_diagnostic_id',
        'last_error_stage',
        'notifications.dedicated_cron_interval_minutes',
        'notifications.heartbeat_stale_seconds',
        '2.11.7',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 074 debe incluir: ' . $needle);
    }
    $worker = (string) file_get_contents($root . '/jobs/process_notifications.php');
    $assert(str_contains($worker, 'retired_single_launcher'),
        'El worker histórico debe dirigir al lanzador único.');
    $service = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    foreach (['reportWorkError', 'recentFailures', 'worker_clean_streak', 'last_error_diagnostic_id'] as $needle) {
        $assert(str_contains($service, $needle), 'Servicio del worker debe incluir diagnóstico: ' . $needle);
    }
    $health = (string) file_get_contents($root . '/app/Services/WebhookService.php');
    $assert(str_contains($health, 'cron.notifications_interval_minutes'), 'Salud debe usar el intervalo real configurado para Hostinger.');
});

$test('2.11.8 evita transacciones OAuth anidadas y recupera solo errores confirmados', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/075_notification_worker_transaction_recovery_2_11_8.sql');
    foreach ([
        'consecutive_failures',
        'last_success_at',
        'processing_event_id',
        'processing_token_hash',
        'oauth.refresh_lock_wait_seconds',
        '2.11.8',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 075 debe incluir: ' . $needle);
    }
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    foreach (['OAuthTokenRefreshService', 'MeliHttpTransportInterface', 'inTransaction()'] as $needle) {
        $assert(str_contains($client, $needle), 'Cliente API debe proteger el límite transaccional: ' . $needle);
    }
    $refresh = (string) file_get_contents($root . '/app/Services/OAuthTokenRefreshService.php');
    foreach (['GET_LOCK', 'RELEASE_LOCK', 'gmdate', "status='conectado'", 'invalid_grant'] as $needle) {
        $assert(str_contains($refresh, $needle), 'Renovación OAuth segura debe incluir: ' . $needle);
    }
    $budget = (string) file_get_contents($root . '/app/Services/ApiBudgetService.php');
    $assert(str_contains($budget, '$ownsTransaction'), 'Presupuesto API debe controlar la propiedad de la transacción.');
    $work = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    foreach (['consecutive_failures', 'processing_event_id', 'last_success_at', 'beginTransaction()'] as $needle) {
        $assert(str_contains($work, $needle), 'Cierre de notificaciones debe incluir: ' . $needle);
    }
    $recovery = (string) file_get_contents($root . '/app/Services/NotificationWorkerRecoveryService.php');
    foreach (['There is already an active transaction', 'last_error_diagnostic_id', 'processing_error', 'RECOVERY_VERSION'] as $needle) {
        $assert(str_contains($recovery, $needle), 'Recuperación selectiva debe validar: ' . $needle);
    }
    $mainWorker = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($mainWorker, 'NotificationCoalescerService')
        && str_contains($mainWorker, 'notification_fallback'),
        'Las advertencias de recursos deben procesarse dentro del lanzador único.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($routes, '/settings/cron/notifications/recover-known-errors'), 'Debe existir la recuperación administrativa con CSRF.');
});

$test('2.11.9 corrige collation, recupera por canarios y limita títulos extensos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/076_products_titles_collation_queue_2_11_9.sql');
    foreach ([
        'meli_notification_recovery_runs',
        'meli_notification_recovery_run_items',
        'utf8mb4_unicode_ci',
        'cron.backlog_aware_scheduler_enabled',
        'ui.products_title_clamp_enabled',
        '2.11.9',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 076 debe incluir: ' . $needle);
    }
    $database = (string) file_get_contents($root . '/app/Core/Database.php');
    $assert(str_contains($database, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Cada conexión debe fijar la collation Unicode.');
    $recovery = (string) file_get_contents($root . '/app/Services/NotificationCollationRecoveryService.php');
    foreach (['createCanary', 'satisfied_local', 'illegal mix of collations', 'utf8mb4_general_ci', 'utf8mb4_unicode_ci'] as $needle) {
        $assert(str_contains($recovery, $needle), 'Recuperación canaria debe validar: ' . $needle);
    }
    $scheduler = (string) file_get_contents($root . '/app/Services/CronWorkAvailabilityService.php');
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $assert(str_contains($scheduler, 'orders_sync') && str_contains($scheduler, 'notification_fallback'), 'Scheduler debe sondear órdenes y notificaciones.');
    $assert(str_contains($cron, 'work_availability') && str_contains($cron, 'no_due_work'), 'Cron debe distinguir trabajo real y cola vacía.');
    $table = (string) file_get_contents($root . '/app/Views/products/meli/_table.php');
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    foreach (['data-product-title-tooltip', 'data-tooltip-text', 'product-title-text'] as $needle) {
        $assert(str_contains($table, $needle), 'Tabla de productos debe incluir: ' . $needle);
    }
    foreach (['role', 'aria-describedby', 'Escape', 'scrollWidth', '300'] as $needle) {
        $assert(str_contains($js, $needle), 'Tooltip accesible debe incluir: ' . $needle);
    }
    $assert(str_contains($css, 'text-overflow:ellipsis') && str_contains($css, '-webkit-line-clamp:2'), 'CSS debe recortar a una línea en escritorio y dos en móvil.');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/recovery/canary', '/recovery/start', '/recovery/pause', '/recovery/status.json'] as $needle) {
        $assert(str_contains($routes, $needle), 'Debe existir la ruta protegida: ' . $needle);
    }
});

$test('2.11.10 muestra tooltip retardado solo en títulos recortados de Productos ML', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/077_product_title_tooltip_2_11_10.sql');
    foreach ([
        'ui.products_title_tooltip_enabled',
        'ui.products_title_tooltip_hover_delay_ms',
        'ui.products_title_tooltip_focus_delay_ms',
        '3000',
        '300',
        '2.11.10',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 077 debe incluir: ' . $needle);
    }
    $table = (string) file_get_contents($root . '/app/Views/products/meli/_table.php');
    foreach ([
        'data-product-title-tooltip',
        'data-tooltip-hover-delay',
        'data-tooltip-focus-delay',
        "max(500, min(10000",
    ] as $needle) {
        $assert(str_contains($table, $needle), 'Vista de Productos ML debe incluir: ' . $needle);
    }
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    foreach ([
        ".products-ml-table [data-product-title-tooltip]",
        "source === 'focus' ? 300 : 2000",
        'scheduledOwner',
        'hoveredOwner',
        'MutationObserver',
        'aria-describedby',
        'Escape',
        'scrollWidth',
    ] as $needle) {
        $assert(str_contains($js, $needle), 'Tooltip seguro debe incluir: ' . $needle);
    }
    $assert(!str_contains($table, ' title='), 'No debe usarse el tooltip nativo title.');
    $definitions = (string) file_get_contents($root . '/app/Repositories/SettingsDefinitionRepository.php');
    $assert(str_contains($definitions, 'ui.products_title_tooltip_enabled'), 'Configuración debe permitir desactivar el tooltip.');
});

$test('2.11.11 mide el texto visible y no el enlace exterior del tooltip', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/078_product_title_tooltip_overflow_fix_2_11_11.sql');
    foreach ([
        'cron.release_integrity_required_version',
        '078_product_title_tooltip_overflow_fix_2_11_11.sql',
        '2.11.11',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 078 debe incluir: ' . $needle);
    }
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    foreach ([
        'const overflowTarget',
        "element.querySelector('.product-title-text') || element",
        'target.scrollWidth > target.clientWidth + 1',
        'target.scrollHeight > target.clientHeight + 1',
    ] as $needle) {
        $assert(str_contains($js, $needle), 'Detección de recorte debe incluir: ' . $needle);
    }
    $assert(
        !str_contains($js, 'element.scrollWidth > element.clientWidth + 1 || element.scrollHeight > element.clientHeight + 1'),
        'La detección no debe volver a medir únicamente el enlace exterior.'
    );
});

$test('2.11.12 reduce a dos segundos el tooltip de Productos ML', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/079_product_title_tooltip_delay_2_11_12.sql');
    foreach ([
        'ui.products_title_tooltip_hover_delay_ms',
        "'2000'",
        '079_product_title_tooltip_delay_2_11_12.sql',
        '2.11.12',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 079 debe incluir: ' . $needle);
    }
    $table = (string) file_get_contents($root . '/app/Views/products/meli/_table.php');
    $definitions = (string) file_get_contents($root . '/app/Repositories/SettingsDefinitionRepository.php');
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $assert(str_contains($table, "tooltip_hover_delay_ms', 2000"), 'Vista debe usar 2000 ms como fallback.');
    $assert(str_contains($definitions, "tooltip_hover_delay_ms', 'Espera del nombre completo con mouse', 2000"), 'Configuración debe recomendar 2000 ms.');
    $assert(str_contains($js, "source === 'focus' ? 300 : 2000"), 'JavaScript debe usar 2000 ms como fallback.');
});

$test('2.12 a 2.16 aíslan módulos, API, datos y migraciones del núcleo', static function () use ($assert): void {
    $root = dirname(__DIR__);
    foreach (['080_module_runtime_2_12_0.sql','081_register_meli_growth_2_13_0.sql','082_register_meli_ads_2_14_0.sql','083_register_meli_postsale_2_15_0.sql','084_register_meli_logistics_2_16_0.sql'] as $migration) {
        $assert(is_file($root . '/database/migrations/' . $migration), 'Debe existir la migración central ' . $migration);
    }
    foreach (['meli-insights','meli-growth','meli-ads','meli-postsale','meli-logistics'] as $module) {
        $manifest = $root . '/resources/modules/' . $module . '/module.json';
        $assert(is_file($manifest), 'Debe existir el manifiesto de ' . $module);
        $decoded = json_decode((string) file_get_contents($manifest), true);
        $assert(is_array($decoded) && ($decoded['id'] ?? '') === $module, 'El manifiesto debe identificar ' . $module);
        $files = glob($root . '/database/modules/' . $module . '/*.sql') ?: [];
        $assert($files !== [], 'El módulo debe tener migraciones propias: ' . $module);
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            $assert(preg_match('/ALTER\s+TABLE\s+(meli_|catalogs|catalog_items|internal_products)/i', $sql) !== 1, 'El módulo no puede alterar tablas operativas.');
        }
    }
    $moduleFiles = glob($root . '/app/Modules/*/{Services,Controllers,Repositories}/*.php', GLOB_BRACE) ?: [];
    foreach ($moduleFiles as $file) {
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        if (str_contains($relative, '/Shared/')) {
            continue;
        }
        $code = (string) file_get_contents($file);
        $assert(!str_contains($code, 'new MeliApiClient'), 'Los módulos deben usar MeliReadGateway: ' . $relative);
        $assert(preg_match('/\b(FROM|JOIN)\s+(meli_orders|meli_items|meli_shipments|catalogs)\b/i', $code) !== 1, 'Los módulos no consultan tablas del núcleo directamente: ' . $relative);
    }
    $registry = (string) file_get_contents($root . '/app/Core/Modules/ModuleRegistry.php');
    foreach (['MeliInsightsProvider','MeliGrowthProvider','MeliAdsProvider','MeliPostSaleProvider','MeliLogisticsProvider'] as $provider) {
        $assert(str_contains($registry, $provider), 'El runtime debe declarar ' . $provider);
    }
});

$test('2.16.1 clasifica la salud API por resultado real e incidente', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/085_api_health_incidents_2_16_1.sql');
    foreach ([
        'outcome_class',
        'reached_remote',
        'actionable',
        'risk_signal',
        'incident_key',
        'already an active transaction',
        'expected_absence',
        '2.16.1',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 085 debe incluir: ' . $needle);
    }

    $success = \App\Services\ApiRequestOutcomeClassifier::classify('GET', '/orders/1', 200, false, null);
    $assert($success['outcome_class'] === 'success' && $success['reached_remote'] === 1, 'Una respuesta 2xx debe contar como consulta remota correcta.');

    $local = \App\Services\ApiRequestOutcomeClassifier::classify(
        'POST',
        '/oauth/token',
        null,
        false,
        'There is already an active transaction',
        ['type' => 'api_budget_infrastructure'],
        null,
    );
    $assert($local['outcome_class'] === 'local_failure', 'La transacción anidada debe clasificarse como fallo interno.');
    $assert($local['reached_remote'] === 0 && $local['risk_signal'] === 0, 'Un fallo interno no llegó a ML ni implica bloqueo.');

    $absence = \App\Services\ApiRequestOutcomeClassifier::classify('GET', '/items/MCO1/description', 404, false, 'Not found', ['type' => 'not_found']);
    $assert($absence['outcome_class'] === 'expected_absence' && $absence['actionable'] === 0, 'Descripción ausente debe ser un resultado esperado.');

    $limited = \App\Services\ApiRequestOutcomeClassifier::classify('GET', '/orders/search', 429, false, 'Too many requests', ['type' => 'rate_limit']);
    $assert($limited['outcome_class'] === 'remote_error' && $limited['reached_remote'] === 1 && $limited['risk_signal'] === 1, 'Un 429 debe ser error remoto y señal de riesgo.');

    $blocked = \App\Services\ApiRequestOutcomeClassifier::classify('GET', '/orders/search', 401, true, 'Unauthorized scopes', ['type' => 'oauth', 'is_app_blocked_signal' => true], 'unauthorized_scopes');
    $assert($blocked['outcome_class'] === 'blocked_signal' && $blocked['risk_signal'] === 1, 'unauthorized_scopes debe producir señal crítica.');

    $localAgain = \App\Services\ApiRequestOutcomeClassifier::classify(
        'POST',
        '/oauth/token',
        null,
        false,
        'There is already an active transaction',
        ['type' => 'api_budget_infrastructure'],
        null,
    );
    $assert($local['incident_key'] === $localAgain['incident_key'], 'Repeticiones equivalentes deben agruparse en un incidente estable.');

    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach ([
        '/settings/api-health/incidents',
        '/settings/api-health/incidents/show',
        '/settings/api-health/incidents/status.json',
    ] as $route) {
        $assert(str_contains($routes, $route), 'Debe existir la ruta de incidentes: ' . $route);
    }
    $healthView = (string) file_get_contents($root . '/app/Views/settings/api_health.php');
    foreach (['Incidentes activos', 'Incidentes recuperados', 'Enviadas a Mercado Libre'] as $needle) {
        $assert(str_contains($healthView, $needle), 'Salud API debe explicar: ' . $needle);
    }
});

$test('2.16.2 pausa salidas, explica logs y aísla módulos incompletos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/086_api_emergency_controls_logs_docs_2_16_2.sql');
    foreach (['api_manual_pauses', 'api_incident_acknowledgements', 'api.health.global_banner_enabled', '2.16.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 086 debe incluir: ' . $needle);
    }

    $manual = \App\Services\ApiRequestOutcomeClassifier::classify(
        'GET',
        '/orders/1',
        null,
        true,
        'Consultas pausadas manualmente por seguridad Mercado Libre',
        ['type' => 'api_manual_pause', 'is_retryable' => true]
    );
    $assert($manual['outcome_class'] === 'policy_delay', 'Una pausa manual debe aplazar, no fallar, el trabajo.');
    $assert($manual['reached_remote'] === 0 && $manual['risk_signal'] === 0, 'La pausa manual no llega a Mercado Libre ni implica bloqueo.');

    $presenter = new \App\Services\ApiLogRiskPresenter();
    $description = $presenter->present([
        'http_status' => 404,
        'endpoint_path' => '/items/{id}/description',
        'outcome_class' => 'expected_absence',
        'reached_remote' => 1,
    ]);
    $assert($description['risk'] === 'Ninguno' && $description['blocking'] === false, 'Un 404 de descripción debe mostrarse neutral.');
    $pdo = $presenter->present([
        'http_status' => null,
        'endpoint_path' => '/oauth/token',
        'outcome_class' => 'local_failure',
        'reached_remote' => 0,
    ]);
    $assert($pdo['reached_remote'] === false && $pdo['blocking'] === false, 'Un fallo local debe explicar que no llegó a Mercado Libre.');

    $guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
    $manualPosition = strpos($guard, 'ApiManualPauseService');
    $enabledPosition = strpos($guard, 'if (!$this->enabled())');
    $assert($manualPosition !== false && $enabledPosition !== false && $manualPosition < $enabledPosition, 'La pausa manual debe evaluarse antes del guard automático.');

    $docs = (string) file_get_contents($root . '/app/Views/settings/api_docs.php');
    $assert(!str_contains($docs, 'Csrf::field()'), 'Documentación API no debe llamar un método CSRF inexistente.');
    $assert(str_contains($docs, 'Csrf::token()'), 'Documentación API debe usar el mecanismo CSRF real.');

    $registry = (string) file_get_contents($root . '/app/Core/Modules/ModuleRegistry.php');
    $assert(str_contains($registry, 'class_exists($providerClass)'), 'El registro modular debe comprobar cada proveedor antes de instanciarlo.');
    $assert(!str_contains($registry, 'new MeliGrowthProvider()'), 'El registro modular no debe construir proveedores de forma anticipada.');
    $assert(str_contains($registry, 'ModuleMigrationRunner($this))->pending($moduleId)'), 'Un módulo con migraciones propias pendientes no debe registrar rutas, trabajos ni eventos.');

    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['/settings/api-health/pause', '/settings/api-health/resume', '/logs/api/incidents'] as $route) {
        $assert(str_contains($routes, $route), 'Debe existir la ruta 2.16.2: ' . $route);
    }
});

$test('2.17.0 corrige el gateway modular y hace funcional Meli Insights', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/087_module_runtime_jobs_hardening_2_17_0.sql');
    foreach (['dedupe_key', 'checkpoint_json', 'consecutive_failures', 'module.rollout.allowed_modules', '2.17.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 087 debe incluir: ' . $needle);
    }
    $moduleMigration = (string) file_get_contents($root . '/database/modules/meli-insights/005_functional_insights.sql');
    foreach (['snapshot_hash', 'uq_insights_history_hash', 'eligibility'] as $needle) {
        $assert(str_contains($moduleMigration, $needle), 'Migración funcional de Insights debe incluir: ' . $needle);
    }

    $calls = [];
    $gateway = new \App\Modules\Shared\Services\MeliReadGateway(
        static function (int $accountId) use (&$calls): \App\Services\MeliReadClientInterface {
            $calls[] = ['account_id' => $accountId];
            return new class($calls) implements \App\Services\MeliReadClientInterface {
                public function __construct(private array $seed)
                {
                }
                public function get(string $path, array $query = [], array $meta = []): array
                {
                    return ['path' => $path, 'query' => $query, 'meta' => $meta];
                }
            };
        }
    );
    $response = $gateway->get('meli-insights', 91, '/items/MCO123', ['quantity' => 1], 'module_insights');
    $assert(($calls[0]['account_id'] ?? 0) === 91, 'Gateway debe construir el cliente para la cuenta seleccionada.');
    $assert(($response['path'] ?? '') === '/items/MCO123', 'Gateway debe enviar el path como primer argumento de get().');
    $assert(($response['meta']['module_id'] ?? '') === 'meli-insights', 'Gateway debe identificar el presupuesto del módulo.');

    $provider = new \App\Modules\MeliInsights\ModuleProvider();
    $assert(in_array($provider->version(), ['1.1.0', '1.1.1'], true), 'Meli Insights debe publicar una versión funcional compatible.');
    $assert(in_array('event_sync', $provider->jobTypes(), true), 'Insights debe procesar eventos puntuales.');

    $controller = (string) file_get_contents($root . '/app/Modules/MeliInsights/Controllers/DashboardController.php');
    foreach (['performance()', 'pricing()', 'moderations()', 'capabilities()', 'jobAction()'] as $method) {
        $assert(str_contains($controller, $method), 'Insights debe separar la experiencia: ' . $method);
    }
    $sync = (string) file_get_contents($root . '/app/Modules/MeliInsights/Services/InsightsSyncService.php');
    foreach (['sale_price', 'performance', 'moderation', 'competition', 'reputation', 'price_to_win', 'isFresh'] as $stage) {
        $assert(str_contains($sync, $stage), 'Insights funcional debe implementar: ' . $stage);
    }
    $dispatcher = (string) file_get_contents($root . '/app/Core/Modules/ModuleEventDispatcher.php');
    $assert(str_contains($dispatcher, "in_array('event_sync'"), 'Los eventos deben crear event_sync, no una actualización completa.');
});

$test('2.17.0 mantiene aislados los módulos todavía no liberados', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $health = (string) file_get_contents($root . '/app/Core/Modules/ModuleHealthService.php');
    $admin = (string) file_get_contents($root . '/app/Controllers/ModuleAdminController.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/modules.php');
    $assert(str_contains($health, 'module.rollout.allowed_modules'), 'El estado modular debe respetar el rollout permitido.');
    $assert(str_contains($admin, 'rolloutAllowed'), 'No debe habilitarse un módulo incompleto por URL directa.');
    $assert(str_contains($view, 'Próximamente'), 'La interfaz debe explicar que los módulos restantes aún no están disponibles.');
    $availability = (string) file_get_contents($root . '/app/Services/CronWorkAvailabilityService.php');
    $assert(str_contains($availability, "'module_jobs'"), 'Cron debe comprobar si hay trabajos modulares antes de asignar turno.');
});

$test('2.17.1 recupera 087 de forma exacta y protege la cola modular', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration087 = (string) file_get_contents($root . '/database/migrations/087_module_runtime_jobs_hardening_2_17_0.sql');
    $migration088 = (string) file_get_contents($root . '/database/migrations/088_module_runtime_logs_recovery_2_17_1.sql');
    $replacement = json_decode((string) file_get_contents($root . '/resources/migration-replacements.json'), true);
    $rule = $replacement['replacements'][0] ?? [];

    $assert(($rule['old_checksum'] ?? '') === 'f2b58e5df23ff3c9c14534a449bb60b1853cada388eb89b55b65433445bd5046', 'La sustitución debe aceptar únicamente el checksum 087 fallido conocido.');
    $assert(($rule['new_checksum'] ?? '') === hash_file('sha256', $root . '/database/migrations/087_module_runtime_jobs_hardening_2_17_0.sql'), 'El checksum autorizado debe coincidir con la 087 entregada.');
    $assert(($rule['required_driver_code'] ?? '') === '1901', 'La sustitución debe limitarse al error MariaDB 1901.');
    $assert(!str_contains($migration087, 'GENERATED ALWAYS AS'), '087 no debe usar la columna generada incompatible.');
    $assert(str_contains($migration087, 'active_dedupe_key CHAR(64) NULL'), '087 debe usar una clave activa explícita.');
    foreach (['lease_generation', 'lease_heartbeat_at', 'superseded_by_job_id', '2.17.1'] as $needle) {
        $assert(str_contains($migration088, $needle), 'Migración 088 debe incluir: ' . $needle);
    }

    $runner = (string) file_get_contents($root . '/app/Core/Modules/ModuleJobRunner.php');
    foreach (['lease_generation=?', 'reconcilePendingEvents', 'active_dedupe_key=dedupe_key', 'lease_lost'] as $needle) {
        $assert(str_contains($runner, $needle), 'La cola modular debe proteger: ' . $needle);
    }
    $policy = (string) file_get_contents($root . '/app/Services/MigrationReplacementPolicy.php');
    foreach (['required_state', 'required_stage', 'required_driver_code', 'message_contains'] as $needle) {
        $assert(str_contains($policy, $needle), 'La política de sustitución debe validar: ' . $needle);
    }
});

$test('2.17.2 certifica el drift de 087 y bloquea módulos incompletos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration087 = $root . '/database/migrations/087_module_runtime_jobs_hardening_2_17_0.sql';
    $migration089 = (string) file_get_contents($root . '/database/migrations/089_migration_drift_recovery_2_17_2.sql');
    $policy = (string) file_get_contents($root . '/app/Services/MigrationReplacementPolicy.php');
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    $readiness = (string) file_get_contents($root . '/app/Core/Modules/ModuleRuntimeReadinessService.php');
    $integration = (string) file_get_contents($root . '/tests/module_migration_mysql_integration.php');

    $assert(hash_file('sha256', $migration087) === '3091de2752312006f59567effe7100d9993e2dde5f7260c967782c4afc958397', '087 debe permanecer congelada con el checksum aprobado.');
    foreach (['drift_attempt_missing', 'wrong_sql_state', 'message_signature_mismatch', 'corrected_sql_already_started', 'unexpected_schema', 'active_module_job'] as $reason) {
        $assert(str_contains($policy, "'{$reason}'"), 'La recuperación debe explicar: ' . $reason);
    }
    $assert(str_contains($policy, "str_replace(['`', '\"', \"'\"]"), 'La firma debe normalizar backticks y comillas.');
    $assert(str_contains($migrator, 'markDriftPreservingChecksum'), 'Un rechazo no debe sobrescribir el checksum registrado.');
    $assert(str_contains($migrator, "\$metadataState === 'drifted'"), 'Drifted debe requerir siempre una decisión explícita.');
    $assert(str_contains($readiness, '089_migration_drift_recovery_2_17_2.sql'), 'El runtime modular debe esperar hasta 089.');
    $assert(str_contains($migration089, "'2.17.2'"), '089 debe registrar la versión 2.17.2.');
    $assert(str_contains($integration, '`active_dedupe_key`'), 'La integración debe reproducir literalmente los backticks de producción.');
    $assert(str_contains($integration, "ERP_RELEASE_STRICT"), 'La certificación no debe permitir omitir MySQL/MariaDB real.');
});

$test('2.17.1 separa elegibilidad de Insights y presenta Logs humanos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $provider = new \App\Modules\MeliInsights\ModuleProvider();
    $assert($provider->version() === '1.1.1', 'Insights debe publicar versión 1.1.1.');
    $moduleMigration = (string) file_get_contents($root . '/database/modules/meli-insights/006_capability_scope_reconciliation.sql');
    $assert(str_contains($moduleMigration, 'ml_insights_item_capabilities'), 'Insights debe guardar elegibilidad por publicación.');

    $sync = (string) file_get_contents($root . '/app/Modules/MeliInsights/Services/InsightsSyncService.php');
    $assert(str_contains($sync, "'item_not_eligible'"), 'Un 404 competitivo debe quedar como publicación no elegible.');
    $assert(str_contains($sync, "'expected_absence'"), 'Una ausencia válida debe quedar separada de la capacidad de cuenta.');

    $view = (string) file_get_contents($root . '/app/Views/logs/index.php');
    foreach (['Actividad de Mercado Libre', 'Necesitan atención', 'Actividad normal', 'Historial técnico', 'Riesgo vigente'] as $needle) {
        $assert(str_contains($view, $needle), 'Logs debe presentar la experiencia humana: ' . $needle);
    }
    $query = (string) file_get_contents($root . '/app/Services/LogQueryService.php');
    $assert(str_contains($query, "l.created_at>=?") && str_contains($query, "l.created_at<?"), 'Logs API debe usar rangos UTC half-open.');
    $assert(str_contains($query, "\$technical = (\$filters['view'] ?? '') === 'technical'"), 'El detalle técnico costoso debe consultarse solo cuando se solicita.');
});

$test('2.17.3 presenta Salud Mercado Libre por niveles y sin cargar detalle técnico en Resumen', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/090_api_health_command_center_2_17_3.sql');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $overviewService = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
    $technicalService = (string) file_get_contents($root . '/app/Services/ApiHealthTechnicalService.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/api_health.php');
    $navigation = (string) file_get_contents($root . '/app/Views/settings/_api_health_nav.php');
    $pauseDialog = (string) file_get_contents($root . '/app/Views/settings/_api_health_pause_dialog.php');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $ux = (string) file_get_contents($root . '/public/assets/ux.js');

    $assert(version_compare($version, '2.17.3', '>='), 'VERSION debe incluir Salud Mercado Libre 2.17.3 o posterior.');
    $assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.17.3', '>='), 'El manifiesto runtime debe incluir 2.17.3 o posterior.');
    foreach ([
        'api.health.overview_cache_seconds',
        'api.health.overview_incident_limit',
        'api.health.recent_activity_limit',
        'api.health.technical_page_size',
        'api.health.default_period_hours',
        'api.health.progressive_sections_enabled',
        "'2.17.3'",
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 090 debe incluir: ' . $needle);
    }
    foreach ([
        '/settings/api-health/accounts',
        '/settings/api-health/protection',
        '/settings/api-health/technical',
    ] as $route) {
        $assert(str_contains($routes, $route), 'Salud API debe registrar la ruta: ' . $route);
    }
    foreach (['apiHealthAccounts()', 'apiHealthProtection()', 'apiHealthTechnical()', 'releaseReadOnlySession()'] as $method) {
        $assert(str_contains($controller, $method), 'El controlador debe implementar: ' . $method);
    }
    $assert(!str_contains($overviewService, 'ApiHealthTechnicalService'), 'Resumen no debe cargar el servicio técnico pesado.');
    $assert(!str_contains($overviewService, 'ApiBudgetService'), 'Resumen no debe cargar ventanas de presupuesto técnico.');
    $assert(str_contains($overviewService, "rememberArray('api-health-overview'"), 'Resumen debe usar caché aislada por contexto.');
    $assert(str_contains($technicalService, 'ApiBudgetService'), 'El presupuesto detallado debe permanecer en Detalles técnicos.');
    foreach (['Estado actual', 'Consultas correctas', 'Cuentas Mercado Libre', 'Necesitan atención', 'Actividad reciente', 'Historial recuperado'] as $label) {
        $assert(str_contains($view, $label), 'Resumen debe presentar la jerarquía humana: ' . $label);
    }
    foreach (['Resumen', 'Cuentas', 'Incidentes', 'Protección', 'Detalles técnicos'] as $tab) {
        $assert(str_contains($navigation, $tab), 'La navegación contextual debe incluir: ' . $tab);
    }
    foreach (['fieldset', 'legend', 'Confirmar pausa'] as $control) {
        $assert(str_contains($pauseDialog, $control), 'El diálogo de pausa debe incluir: ' . $control);
    }
    $assert(str_contains($css, '.api-command-status'), 'El sistema visual debe definir la tarjeta central.');
    $assert(str_contains($css, 'min-width:0') || str_contains($css, 'min-width: 0'), 'La composición debe prevenir desbordamiento de grids y flex.');
    $assert(str_contains($ux, 'dialogTriggers') && str_contains($ux, "addEventListener('close'"), 'Los diálogos deben restaurar el foco al control original.');
    $assert(str_contains($ux, "event.key !== 'Tab'") && str_contains($ux, 'event.preventDefault()'), 'Los diálogos deben contener el foco mediante teclado.');
});

$test('2.17.4 congela auditorías exactas y presenta errores seguros', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/091_sales_audit_integrity_safe_errors_2_17_4.sql');
    $service = (string) file_get_contents($root . '/app/Services/SalesAuditRunService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $view = (string) file_get_contents($root . '/app/Views/sync/audit.php');

    $assert(version_compare($version, '2.17.4', '>='), 'VERSION debe incluir 2.17.4 o posterior.');
    $assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.17.4', '>='), 'El manifiesto debe incluir 2.17.4 o posterior.');
    foreach (['sync_sales_audit_runs', 'sync_sales_audit_run_days', 'sync_sales_audit_run_orders', 'sync_sales_audit_jobs'] as $table) {
        $assert(str_contains($migration, $table), 'La migración 091 debe crear: ' . $table);
    }
    foreach (['$remoteUtc >= $utcTo', 'external_order_id', 'missing_normalized_date', 'shifted_date', 'other_account'] as $contract) {
        $assert(str_contains($service, $contract), 'La auditoría exacta debe implementar: ' . $contract);
    }
    $assert(str_contains($controller, 'createExactMonth'), 'La auditoría mensual debe crear un trabajo reanudable.');
    foreach (['Confiabilidad', 'Órdenes remotas únicas', 'Pendientes de normalización', 'Acción recomendada'] as $label) {
        $assert(str_contains($view, $label), 'La vista humana debe mostrar: ' . $label);
    }

    $controllers = glob($root . '/app/Controllers/*.php') ?: [];
    foreach ($controllers as $file) {
        $source = (string) file_get_contents($file);
        $assert(
            preg_match("/Session::flash\\(\\s*['\\\"]error['\\\"]\\s*,[^;]*getMessage\\(\\)/s", $source) !== 1,
            'Ningún controlador debe mostrar directamente getMessage(): ' . basename($file)
        );
    }
});

$test('2.18.0 proyecta todas las colas y explica la próxima ejecución', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/092_automation_control_center_2_18_0.sql');
    $registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
    $diagnostic = (string) file_get_contents($root . '/app/Services/DiagnosticService.php');
    $projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $scheduler = (string) file_get_contents($root . '/app/Services/WorkSchedulerService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');

    foreach (['system_work_queue_projection', 'system_work_queue_runs', 'system_work_queue_run_items'] as $table) {
        $assert(str_contains($migration, $table), 'Migración 092 debe crear: ' . $table);
    }
    foreach ([
        'operational_maintenance','notification_spool','notification_backfill','notification_fallback',
        'recurring_sync','orders_sync','order_enrichment','order_date_repair','questions','financial_recalc',
        'sales_repair','sales_audit','catalog_descriptions','items_sync','module_jobs',
    ] as $queue) {
        $assert(str_contains($registry, "'{$queue}'"), 'El registro debe inventariar: ' . $queue);
    }
    $assert(str_contains($projection, 'priority_tier ASC,p.created_at_source ASC'), 'La cola debe respetar prioridad y antigüedad.');
    $assert(
        str_contains($scheduler, '$apiCount >= $maxApiTasks')
        && str_contains($scheduler, 'count($selected) >= $maxTasks')
        && str_contains($scheduler, "cron.max_api_tasks_per_run")
        && str_contains($scheduler, "cron.max_tasks_per_run"),
        'La vista previa debe respetar los mismos límites configurados que el cron.'
    );
    foreach (['/settings/cron/next', '/settings/cron/queue', '/settings/cron/history', '/settings/cron/diagnostics'] as $route) {
        $assert(str_contains($routes, $route), 'Debe existir la ruta: ' . $route);
    }
    foreach (['Cola completa', 'Próxima oportunidad', 'Estimación', 'Esperando presupuesto'] as $label) {
        $assert(str_contains($queueView, $label), 'La cola humana debe mostrar: ' . $label);
    }
});

$test('2.18.1 agrega ayuda contextual, lenguaje humano y pagina vistas pesadas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/093_human_ux_context_help_2_18_1.sql');
    $repository = (string) file_get_contents($root . '/app/Services/ContextHelpRepository.php');
    $presenter = (string) file_get_contents($root . '/app/Services/ContextHelpPresenter.php');
    $glossary = (string) file_get_contents($root . '/app/Services/HumanGlossary.php');
    $ux = (string) file_get_contents($root . '/public/assets/ux.js');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $unlinked = (string) file_get_contents($root . '/app/Services/UnlinkedProductService.php');
    $alerts = (string) file_get_contents($root . '/app/Services/AlertService.php');
    $imports = (string) file_get_contents($root . '/app/Services/ProductImportService.php');

    foreach (['ui.context_help_enabled','ui.context_help_hover_delay_ms','3000','ui.context_help_focus_delay_ms','300','ui.default_page_size'] as $setting) {
        $assert(str_contains($migration, $setting), 'Migración 093 debe incluir: ' . $setting);
    }
    foreach (['qué hace','Cuándo usarlo','Mercado Libre','Impacto','Riesgo','Tiempo'] as $concept) {
        $haystack = strtolower($repository . $presenter);
        $assert(str_contains($haystack, strtolower($concept)), 'La ayuda debe explicar: ' . $concept);
    }
    foreach (['En espera','Programado para después','Se reintentará','Cálculo local','Disponible de nuevo','Reserva temporal del trabajo'] as $label) {
        $assert(str_contains($glossary, $label), 'El glosario debe traducir: ' . $label);
    }
    foreach (['helpHoverDelay = 3000', 'helpFocusDelay = 300', 'aria-describedby', "event.key === 'Escape'", "pointerType === 'touch'"] as $contract) {
        $assert(str_contains($ux, $contract), 'HelpPopover debe implementar: ' . $contract);
    }
    $assert(str_contains($css, '.context-help-popover') && str_contains($css, 'position:fixed'), 'La ayuda no debe modificar el layout.');
    $assert(str_contains($unlinked, 'paginate('), 'Productos sin vincular debe paginarse.');
    $assert(str_contains($alerts, 'paginateOpen'), 'Alertas debe paginarse.');
    $assert(str_contains($imports, 'paginateCandidates'), 'Importaciones debe paginarse.');
});

$test('2.19.0 implementa Growth aislado, por etapas y sin escrituras remotas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $central = (string) file_get_contents($root . '/database/migrations/094_meli_growth_functional_2_19_0.sql');
    $moduleMigration = (string) file_get_contents($root . '/database/modules/meli-growth/002_functional_growth.sql');
    $provider = (string) file_get_contents($root . '/app/Modules/MeliGrowth/ModuleProvider.php');
    $sync = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Services/GrowthSyncService.php');
    $dashboard = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Services/GrowthDashboardService.php');
    $view = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Views/index.php');
    $registry = (string) file_get_contents($root . '/app/Services/MeliEndpointRegistry.php');
    $topics = json_decode((string) file_get_contents($root . '/resources/mercadolibre-api/generated/notification-topics.json'), true);

    $assert(version_compare($version, '2.19.0', '>='), 'VERSION debe incluir 2.19.0 o posterior.');
    $assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.19.0', '>='), 'El manifiesto debe incluir 2.19.0 o posterior.');
    $assert(str_contains($central, "'module.meli_growth.enabled','0'"), 'Growth debe permanecer deshabilitado por defecto.');
    $assert(str_contains($central, 'meli-insights,meli-growth'), 'La release debe permitir instalar Growth sin habilitar los demás módulos.');
    foreach (['ml_growth_capabilities', 'ml_growth_account_visits_daily', 'ml_growth_candidates'] as $table) {
        $assert(str_contains($moduleMigration, $table), 'La migración interna debe crear: ' . $table);
    }
    foreach (["'promotions'", "'visits'", "'trends'", "'highlights'", "'conversion'"] as $stage) {
        $assert(str_contains($sync, $stage), 'La sincronización debe incluir la etapa: ' . $stage);
    }
    $assert(str_contains($sync, "'app_version' => 'v2'"), 'Promociones, candidatos y ofertas deben usar app_version=v2.');
    $assert(str_contains($sync, '/items_visits/time_window'), 'Growth debe usar visitas agregadas por cuenta.');
    $assert(!str_contains($sync, '/items/$ITEM_ID/visits'), 'Growth no debe hacer visitas masivas por publicación.');
    $assert(!str_contains($sync, 'new MeliApiClient'), 'El módulo no debe instanciar MeliApiClient directamente.');
    $assert(!preg_match('/->(post|put|delete)\\s*\\(/i', $sync), 'Growth no debe escribir hacia Mercado Libre.');
    foreach (['promotion_candidate', 'promotion_offer'] as $topic) {
        $assert(str_contains($provider, "'{$topic}'"), 'El proveedor debe suscribirse a: ' . $topic);
    }
    $canonicals = array_column((array) ($topics['rules'] ?? []), 'canonical');
    $assert(in_array('promotion_candidate', $canonicals, true), 'El registro de notificaciones debe reconocer candidatos.');
    $assert(in_array('promotion_offer', $canonicals, true), 'El registro de notificaciones debe reconocer ofertas.');
    foreach (['/seller-promotions/candidates/', '/seller-promotions/offers/', '/items_visits/time_window', '/trends/'] as $contract) {
        $assert(str_contains($registry, $contract), 'El registro de endpoints debe cubrir: ' . $contract);
    }
    foreach (['Promociones', 'Rendimiento comercial', 'Tendencias y oportunidades', 'No hay información comprobada todavía'] as $label) {
        $assert(str_contains($view, $label), 'La vista Growth debe explicar: ' . $label);
    }
    $assert(str_contains($dashboard, 'Sin comprobar'), 'Los ceros sin snapshot no deben presentarse como ausencia confirmada.');
});

$test('2.19.1 explica decisiones del cron y enlaza trabajos de forma segura', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $migration = (string) file_get_contents($root . '/database/migrations/095_automation_attention_explainable_2_19_1.sql');
    $registrySource = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
    $scheduler = (string) file_get_contents($root . '/app/Services/WorkSchedulerService.php');
    $projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
    $nextView = (string) file_get_contents($root . '/app/Views/settings/automation_next.php');
    $queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');
    $workView = (string) file_get_contents($root . '/app/Views/settings/automation_work.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $ux = (string) file_get_contents($root . '/public/assets/ux.js');

    $assert(version_compare($version, '2.19.1', '>='), 'VERSION debe incluir 2.19.1 o posterior.');
    foreach ([
        'automation.attention_tooltip_hover_delay_ms','2000',
        'automation.attention_tooltip_focus_delay_ms','300',
        'automation.attention_inline_reason_enabled','automation.work_detail_enabled',
    ] as $setting) {
        $assert(str_contains($migration, $setting), 'Migración 095 debe incluir: ' . $setting);
    }
    foreach ([
        'selected_by_priority','not_yet_eligible','cycle_capacity','api_slot_capacity',
        'waiting_budget','automatic_retry','paused','action_required',
    ] as $reason) {
        $assert(str_contains($scheduler, "'{$reason}'"), 'El planificador debe explicar: ' . $reason);
    }
    $assert(
        str_contains($projection, 'public function find(string $queueKey, string $sourceId')
        && str_contains($projection, 'LIMIT 1'),
        'El detalle debe localizar trabajos con una consulta limitada.'
    );
    $assert(str_contains($projection, 'isset($definitions[$queueKey])'), 'El detalle debe rechazar colas no registradas.');
    $assert(str_contains($routes, "'/settings/cron/work'"), 'Debe existir la ruta de detalle del trabajo.');
    foreach (['Qué ocurrió','Qué debe hacer','¿Llegó a Mercado Libre?','Riesgo de bloqueo','Ver detalles técnicos'] as $label) {
        $assert(str_contains($workView, $label), 'El detalle debe mostrar: ' . $label);
    }
    foreach (['work-inline-reason','data-work-attention','Ver qué ocurrió'] as $contract) {
        $assert(str_contains($nextView . $queueView, $contract), 'Las vistas deben incluir: ' . $contract);
    }
    foreach (['data-hover-delay','data-focus-delay','aria-describedby', "event.key === 'Escape'", "pointerType === 'touch'", '2000'] as $contract) {
        $assert(str_contains($ux . $nextView . $queueView, $contract), 'La ayuda de estados debe implementar: ' . $contract);
    }

    $registry = new \App\Services\WorkQueueRegistry();
    foreach ($registry->definitionsByKey() as $key => $definition) {
        foreach (['description','context_route','context_label','impact','automatic_behavior'] as $field) {
            $assert(trim((string) ($definition[$field] ?? '')) !== '', "La cola {$key} debe declarar {$field}.");
        }
        $assert(str_starts_with((string) $definition['context_route'], '/'), "La cola {$key} debe usar una ruta interna.");
        $assert(!str_contains((string) $definition['context_route'], '://'), "La cola {$key} no puede enlazar un host externo.");
    }

    $presenter = new \App\Services\WorkAttentionPresenter();
    $budget = $presenter->present([
        'queue_key' => 'orders_sync',
        'source_id' => '5',
        'display_status' => 'waiting_budget',
        'is_api_task' => 1,
    ], 'waiting_budget');
    $assert($budget['reached_remote'] === false, 'Presupuesto agotado debe indicar que no consultó Mercado Libre.');
    $assert($budget['blocking_risk'] === 'none', 'Presupuesto agotado no debe parecer riesgo de bloqueo.');

    $local = $presenter->present([
        'queue_key' => 'notification_fallback',
        'source_id' => '8',
        'display_status' => 'error',
        'safe_error_message' => 'SQLSTATE connection failed',
        'is_api_task' => 1,
    ], 'action_required');
    $assert($local['reached_remote'] === false, 'Un fallo PDO/MySQL debe identificarse como interno.');
    $assert($local['blocking_risk'] === 'none', 'Un fallo interno no debe indicar riesgo de bloqueo.');

    $critical = $presenter->present([
        'queue_key' => 'questions',
        'source_id' => '9',
        'display_status' => 'error',
        'safe_error_message' => '401 unauthorized_scopes',
        'is_api_task' => 1,
    ], 'action_required');
    $assert($critical['blocking_risk'] === 'critical', 'unauthorized_scopes debe mostrarse como crítico.');
    $detailPath = (string) (parse_url((string) $critical['detail_url'], PHP_URL_PATH) ?: '');
    $assert(str_ends_with($detailPath, '/settings/cron/work'), 'El detalle debe usar una ruta interna controlada y conservar el base path.');
    $assert(str_contains($registrySource, 'Un fallo del módulo no detiene órdenes ni webhooks.'), 'Los módulos deben explicar su aislamiento.');
});

$test('2.19.2 guía auditorías exactas, separa salud local y verifica adaptadores', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $migration = (string) file_get_contents($root . '/database/migrations/096_exact_audit_repair_health_ux_2_19_2.sql');
    $repair = (string) file_get_contents($root . '/app/Services/SalesAuditExactRepairService.php');
    $auditView = (string) file_get_contents($root . '/app/Views/sync/audit.php');
    $previewView = (string) file_get_contents($root . '/app/Views/sync/audit_repair_preview.php');
    $health = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
    $registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
    $adapter = (string) file_get_contents($root . '/app/Services/SqlWorkQueueAdapter.php');
    $routes = (string) file_get_contents($root . '/public/index.php');

    $assert(version_compare($version, '2.19.2', '>='), 'VERSION debe ser 2.19.2 o posterior.');
    foreach ([
        'sync_sales_audit_run_id','source_kind','lock_owner','heartbeat_at',
        'verification_audit_job_id','system_work_queue_adapter_health',
        'source_queue_key','source_work_id',
    ] as $contract) {
        $assert(str_contains($migration, $contract), 'Migración 096 debe incluir: ' . $contract);
    }
    $assert(str_contains($repair, 'WHERE r.sync_sales_audit_run_id=? AND r.classification="missing_remote"'), 'La reparación debe derivarse de faltantes exactos.');
    $assert(str_contains($repair, 'LIMIT 1 FOR UPDATE'), 'La adquisición del trabajo debe ser atómica.');
    $assert(str_contains($repair, 'syncOrderById'), 'El worker CLI debe consultar órdenes individuales.');
    $assert(str_contains($repair, 'createExactMonth'), 'La reparación debe programar verificación exacta.');
    $assert(str_contains($auditView, 'Resultado anterior') && str_contains($auditView, 'Solo consulta'), 'El formato heredado debe quedar separado.');
    $assert(str_contains($auditView, '/sync/audit/repair/preview?run_id='), 'La reparación debe pasar por previsualización.');
    $assert(str_contains($previewView, 'Cambios en Mercado Libre') && str_contains($previewView, 'Ninguno. Solo lectura.'), 'La confirmación debe explicar el impacto remoto.');
    $assert(str_contains($health, 'remoteActiveIncidents') && str_contains($health, 'erp_processing'), 'Salud API debe separar Mercado Libre de procesos internos.');
    $assert(str_contains($registry, "'financial_recalc'") && str_contains($registry, 'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status'), 'Financiero debe usar meli_account_id.');
    $assert(str_contains($registry, 'j.processed_pages progress_current'), 'Auditoría debe usar processed_pages.');
    $assert(str_contains($registry, 'j.current_account_id meli_account_id'), 'Descripciones debe usar current_account_id.');
    $assert(str_contains($adapter, 'WorkQueueAdapterHealthService'), 'Los adaptadores deben guardar salud persistente.');
    foreach (['/sync/audit/run','/sync/audit/repair/preview','/sync/audit/repair','/sync/audit/repair/pause','/sync/audit/repair/resume'] as $route) {
        $assert(str_contains($routes, "'{$route}'"), 'Debe existir la ruta: ' . $route);
    }
});

$test('2.19.3 mide carga real sin almacenar respuestas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/097_api_workload_intelligence_2_19_3.sql');
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $transport = (string) file_get_contents($root . '/app/Services/CurlMeliHttpTransport.php');
    $profiles = new \App\Services\MeliOperationProfileRegistry();
    $description = $profiles->resolve('GET', '/items/MCO123/description');
    $assert($description['key'] === 'item_description', 'La descripción debe tener un perfil propio.');
    $assert($description['initial_batch'] === 1 && $description['minimum_pause_seconds'] === 20, 'Descripción debe iniciar una por una y con pausa.');
    $billing = $profiles->resolve('GET', '/billing/integration/group/ML/order/details');
    $assert($billing['maximum_batch'] === 60, 'Billing no puede superar 60 órdenes.');
    foreach (['operation_key','wire_bytes','decoded_bytes','api_operation_metrics_hourly'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 097 debe incluir: ' . $needle);
    }
    $assert(str_contains($client, 'MeliOperationTelemetryService'), 'El cliente debe registrar telemetría.');
    $assert(str_contains($transport, "'wire_bytes'") && str_contains($transport, "'decoded_bytes'"), 'El transporte debe medir bytes.');
    $assert(!str_contains((string) file_get_contents($root . '/app/Services/MeliOperationTelemetryService.php'), 'response_json'), 'Telemetría no debe almacenar respuestas.');
});

$test('2.19.4 coordina cron y procesamiento manual sin API desde web', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/098_manual_processing_center_2_19_4.sql');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $cronState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
    $coordination = (string) file_get_contents($root . '/app/Services/ExecutionCoordinationService.php');
    $worker = (string) file_get_contents($root . '/jobs/process_manual_queue.php');
    foreach (['manual_processing_sessions','manual_processing_scopes','manual_processing_items','grace_until','lease_generation'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 098 debe incluir: ' . $needle);
    }
    foreach (['/settings/manual-processing/start','/settings/manual-processing/pause','/settings/manual-processing/resume','/settings/manual-processing/finish'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta del Centro: ' . $route);
    }
    $assert(
        str_contains($cronState, 'reservedQueueKeys')
        && str_contains($coordination, 'queueReservedForManual'),
        'Cron debe ceder las colas reclamadas mediante el coordinador compartido.'
    );
    $assert(str_contains($worker, "PHP_SAPI !== 'cli'"), 'El procesador debe ser exclusivamente CLI.');
    $assert(str_contains($worker, 'ManualQueueExecutionService')
        || str_contains($worker, 'reason=retired_interactive_web'),
        'El worker manual debe ejecutar fuera de la web o quedar retirado por el sucesor interactivo.');
    $manual = (string) file_get_contents($root . '/app/Services/ManualProcessingService.php');
    $assert(version_compare(trim((string) file_get_contents($root . '/VERSION')), '2.20.6', '>=')
        ? (str_contains($manual, "['notification_spool']") && str_contains($manual, "'notification_fallback'"))
        : str_contains($manual, "['notification_spool', 'notification_fallback']"),
        'La entrada temporal de webhooks debe quedar fuera; fallback solo puede entrar con adaptador exacto.');
    $sync = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $assert(str_contains($sync, "'scope' => 'sales'")
        && str_contains($sync, "'origin' => 'legacy_process_now'"),
        'El modo heredado debe conducir al Centro conservando el alcance.');
});

$test('2.19.5 cierra sesiones manuales sin falsos completados ni colas vacías', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/099_manual_processing_integrity_2_19_5.sql');
    $manual = (string) file_get_contents($root . '/app/Services/ManualProcessingService.php');
    $executor = (string) file_get_contents($root . '/app/Services/ManualQueueExecutionService.php');
    $projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
    $adapter = (string) file_get_contents($root . '/app/Services/SqlWorkQueueAdapter.php');
    $telemetry = (string) file_get_contents($root . '/app/Services/MeliOperationTelemetryService.php');
    $registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');

    foreach ([
        'completed_with_issues','worker_heartbeat_at','next_eligible_at',
        'api_operation_metric_samples','account_scope_key','uq_api_operation_hour_scope',
    ] as $contract) {
        $assert(str_contains($migration, $contract), 'Migración 099 debe incluir: ' . $contract);
    }
    $assert(version_compare($version, '2.19.5', '>='), 'VERSION debe incluir el correctivo 2.19.5.');
    $minimumMigration = (string) ($manifest['minimum_migration'] ?? '');
    $assert(($manifest['version'] ?? '') === $version
        && preg_match('/^(?:099|1\\d{2}|[2-9][0-9]{2})_/', $minimumMigration) === 1,
        'El manifiesto debe exigir 099 o una migración acumulativa posterior.');
    $assert(str_contains($manual, 'public function finalizeSessionIfDone'), 'La sesión debe cerrarse automáticamente.');
    $assert(str_contains($manual, 'lease_expires_at<UTC_TIMESTAMP()'), 'Un micro-lote abandonado debe ser recuperable.');
    $assert(str_contains($manual, "GET_LOCK('erp_manual_processing_start',3)"), 'Dos inicios simultáneos deben serializarse con lock MySQL.');
    $assert(str_contains($manual, 'El procesamiento se detuvo para revisar el último resultado.'), 'Un error debe detener la sesión y explicarlo.');
    $assert(str_contains($manual, "['operational_maintenance','order_date_repair','financial_recalc']"), 'Trabajo local no debe congelar entrada webhook.');
    $assert(str_contains($executor, "'reached_remote' => false")
        && str_contains($executor, 'ejecutor heredado está retirado')
        && !str_contains($executor, 'processDue(')
        && !str_contains($executor, 'processNext('),
        'El ejecutor heredado debe quedar cerrado; verificar el ID después de processDue era demasiado tarde.');
    $assert(str_contains($projection, 'public function all('), 'La simulación no debe truncarse silenciosamente a 100 trabajos.');
    $assert(str_contains($adapter, 'projectSucceeded'), 'Una falla de lectura debe distinguirse de una cola vacía.');
    $assert(str_contains($projection, 'if (!$adapter->projectSucceeded())'), 'La proyección anterior debe conservarse cuando falla el adaptador.');
    $assert(str_contains($telemetry, 'p95_duration_ms') && str_contains($telemetry, 'profileReadiness'), 'La activación debe usar carga p95 y perfiles seleccionados.');
    $assert(str_contains($registry, "'financial_recalc', 'order_financial_recalc_jobs', 'Recalcular financiero', 'Finanzas', 4, true"), 'Financiero debe reservar un turno API porque puede importar billing.');
});

$test('2.19.6 protege la transición manual y exige evidencia remota real', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/100_manual_processing_lifecycle_security_2_19_6.sql');
    $manual = (string) file_get_contents($root . '/app/Services/ManualProcessingService.php');
    $coordination = (string) file_get_contents($root . '/app/Services/ExecutionCoordinationService.php');
    $projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
    $telemetry = (string) file_get_contents($root . '/app/Services/MeliOperationTelemetryService.php');
    $policy = (string) file_get_contents($root . '/app/Services/MeliWorkloadPolicyService.php');
    $javascript = (string) file_get_contents($root . '/public/assets/app.js');

    $assert(version_compare($version, '2.19.6', '>='), 'VERSION debe incluir el correctivo 2.19.6.');
    $minimumMigration = (string) ($manifest['minimum_migration'] ?? '');
    $assert(($manifest['version'] ?? '') === $version
        && preg_match('/^(?:100|1\\d{2}|[2-9][0-9]{2})_/', $minimumMigration) === 1,
        'El manifiesto debe exigir 100 o una migración acumulativa posterior.');
    foreach (['idx_manual_item_eligible','visible_item_limit','require_remote_samples','2.19.6'] as $contract) {
        $assert(str_contains($migration, $contract), 'Migración 100 debe incluir: ' . $contract);
    }
    $assert(str_contains($manual, 'if ((string) $session->fetchColumn() !== \'active\')'),
        'El worker debe comprobar la sesión antes de reclamar otro micro-lote.');
    $assert(str_contains($manual, 'SET status="finishing"')
        && str_contains($manual, 'status IN ("pending","waiting","retry")'),
        'Finalizar debe esperar el micro-lote activo y devolver solo el trabajo no iniciado.');
    $assert(!str_contains($manual, "['id' => \$sessionId, 'session_token' => \$token]"),
        'La respuesta administrativa no debe exponer el token interno de sesión.');
    $assert(str_contains($manual, "unset(\$session['session_token'])")
        && str_contains($manual, "unset(\$row['lease_owner'])"),
        'La consulta de estado debe retirar credenciales internas y propietarios de lease.');
    $assert(str_contains($coordination, '"active","paused","finishing"'),
        'Cron debe respetar la reserva mientras termina el micro-lote.');
    $assert(str_contains($projection, "if (!empty(\$filters['queue_keys'])"),
        'La simulación debe filtrar las colas antes de truncar.');
    $assert(str_contains($telemetry, 'AND reached_remote=1')
        && substr_count($telemetry, "['remote_count']") >= 2
        && str_contains($policy, "['remote_count']"),
        'Solo observaciones que llegaron a Mercado Libre deben habilitar el ritmo adaptativo.');
    $assert(!str_contains($javascript, '/MeliApiClient')
        && str_contains($javascript, 'data-manual-controller="directed-cli-v1"')
        && !str_contains($javascript, '/interactive/step'),
        'El navegador solo debe observar estados y detenerse al terminar.');
});

$test('2.19.7 aísla la evidencia de carga por cuenta y permite canarios conservadores', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/101_account_scoped_workload_safety_2_19_7.sql');
    $telemetry = (string) file_get_contents($root . '/app/Services/MeliOperationTelemetryService.php');
    $policy = (string) file_get_contents($root . '/app/Services/MeliWorkloadPolicyService.php');
    $manual = (string) file_get_contents($root . '/app/Services/ManualProcessingService.php');
    $coordination = (string) file_get_contents($root . '/app/Services/ExecutionCoordinationService.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');

    $assert(version_compare($version, '2.19.7', '>='), 'VERSION debe conservar las protecciones introducidas en 2.19.7.');
    $assert(($manifest['version'] ?? '') === $version,
        'El manifiesto debe coincidir con VERSION.');
    foreach ([
        'idx_api_metric_scope_remote','account_scoped_profiles',
        'require_percentile_samples','block_batch_growth_on_local_failure',
        'conservative_bootstrap_enabled',
    ] as $contract) {
        $assert(str_contains($migration, $contract), 'Migración 101 debe incluir: ' . $contract);
    }
    $assert(str_contains($telemetry, 'account_scope_key=?')
        && str_contains($telemetry, 'ROW_NUMBER() OVER (PARTITION BY operation_key')
        && str_contains($telemetry, 'local_failure_count'),
        'Telemetría debe separar cuenta, percentiles y fallos internos.');
    $assert(str_contains($policy, '$localFailures > 0')
        && str_contains($policy, "\$percentile['samples']")
        && str_contains($policy, '$accountId'),
        'La política debe permanecer conservadora sin evidencia completa de la cuenta.');
    $assert(str_contains($manual, "'safe_mode_required' => \$safeModeRequired")
        && str_contains($manual, "\$mode !== 'conservative'"),
        'Sin muestra suficiente solo debe permitirse el canario conservador.');
    $assert(str_contains($coordination, '? IS NULL OR s.meli_account_id IS NULL OR s.meli_account_id=?'),
        'Cron global debe respetar una reserva manual limitada por cuenta.');
    $assert((str_contains($view, 'modo seguro') || str_contains($view, 'adaptador exacto'))
        && (str_contains($view, 'excluded_jobs') || str_contains($view, 'unknown_size_jobs')),
        'La simulación debe explicar límites y evidencia en lenguaje humano.');
});

$test('2.19.8 hace visible Procesar ahora y permite el inicio conservador inmediato', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/102_manual_processing_immediate_ux_2_19_8.sql');
    $manual = (string) file_get_contents($root . '/app/Services/ManualProcessingService.php');
    $engine = (string) file_get_contents($root . '/app/Services/ManualProcessingEngineService.php');
    $worker = (string) file_get_contents($root . '/jobs/process_manual_queue.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
    $navigation = (string) file_get_contents($root . '/app/Repositories/NavigationRepository.php');
    $routes = (string) file_get_contents($root . '/public/index.php');

    $assert(version_compare($version, '2.19.8', '>='), 'VERSION debe conservar 2.19.8.');
    $assert(($manifest['version'] ?? '') === $version
        && preg_match('/^(?:10[2-9]|1[1-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir 102 o una migración acumulativa posterior.');
    foreach ([
        'manual_processing_engine_health',
        'conservative_immediate_enabled',
        'default_scope',
        'descriptions_require_confirmation',
    ] as $contract) {
        $assert(str_contains($migration, $contract), 'Migración 102 debe incluir: ' . $contract);
    }
    $assert(str_contains($manual, "'recommended' =>")
        && !str_contains($manual, "&& (\$localOnly || !empty(\$observation['time_complete']))"),
        'Las 24 horas de observación no deben bloquear el modo conservador.');
    $assert(str_contains($manual, 'ManualProcessingEngineService')
        && str_contains($manual, 'confirmDelicate'),
        'El inicio debe exigir motor listo y confirmación de descripciones.');
    $assert(str_contains($engine, 'No fue posible comprobar el motor manual')
        && (str_contains($worker, "\$engine->heartbeat('success'")
          || str_contains($worker, 'reason=retired_interactive_web')),
        'El motor heredado debe informar salud o quedar retirado explícitamente.');
    $assert(str_contains($view, 'Procesar ahora')
        && (str_contains($view, 'Comenzar en modo seguro')
          || str_contains($view, 'Comenzar campaña')
          || str_contains($view, 'Comenzar procesamiento manual')
          || str_contains($view, 'Procesar un trabajo'))
        && !str_contains($view, 'Faltan aproximadamente'),
        'La interfaz debe ofrecer una siguiente acción humana inmediata.');
    $assert(str_contains($navigation, "'Procesar ahora'")
        && str_contains($routes, '/settings/manual-processing/setup')
        && str_contains($routes, '/settings/manual-processing/check-engine'),
        'Procesar ahora debe ser visible y tener diagnóstico propio.');
});

$test('2.19.9 certifica el motor y prohíbe procesamiento manual inexacto', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/103_manual_engine_certification_2_19_9.sql');
    $probe = (string) file_get_contents($root . '/jobs/manual_engine_probe.php');
    $contract = (string) file_get_contents($root . '/app/Services/ManualCampaignAdapter.php');
    $adapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
    $registry = (string) file_get_contents($root . '/app/Services/ManualCampaignAdapterRegistry.php');

    $assert(version_compare($version, '2.19.9', '>='), 'VERSION debe incluir 2.19.9.');
    foreach (['manual_engine_probe_runs','manual_campaign_adapter_health','manual_operation_profiles'] as $table) {
        $assert(str_contains($migration, $table), 'Migración 103 debe incluir ' . $table . '.');
    }
    $probeRetired = str_contains($probe, 'retired_single_launcher');
    if (!$probeRetired) {
        foreach (['10 => 30','30 => 55','sleep(','hrtime(true)','ERP_MANUAL_PROBE_OK'] as $needle) {
            $assert(str_contains($probe, $needle), 'Probe debe certificar tiempo real: ' . $needle);
        }
        $assert(str_contains($probe, 'ManualCampaignAdapterRegistry')
            && str_contains($probe, 'manual_operation_profiles')
            && str_contains($probe, 'overlapProbe'),
            'El probe debe verificar adaptadores, perfiles y solapamiento real.');
    }
    $assert(str_contains($contract, 'processExact(') && str_contains($contract, 'inspect('),
        'Cada adaptador debe inspeccionar y procesar un recurso exacto.');
    $assert(str_contains($adapter, "processOne(\$id")
        && str_contains($adapter, "processJob(")
        && (str_contains($adapter, "processDue(\n                \$id")
            || str_contains($adapter, 'processExactItem(')),
        'Órdenes, productos y descripciones deben usar su ID congelado.');
    $assert(str_contains($registry, "'exact'") || str_contains($registry, '$exact'),
        'El registro debe distinguir adaptadores exactos.');
    $minimumMigrationMatch = [];
    $hasRetiredManualWorkerMigration = preg_match(
        '/^(\d{3})_/',
        (string) ($manifest['minimum_migration'] ?? ''),
        $minimumMigrationMatch
    ) === 1 && (int) ($minimumMigrationMatch[1] ?? 0) >= 117;
    $assert(isset($manifest['components']['manual_engine_probe'])
        || ($hasRetiredManualWorkerMigration
            && !isset($manifest['components']['process_manual_campaign'])),
        'El probe debe estar protegido o retirado junto con los workers manuales.');
});

$test('2.20.0 crea campañas persistentes y una cabina basada en eventos reales', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/104_manual_campaigns_visual_control_2_20_0.sql');
    $service = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $worker = (string) file_get_contents($root . '/jobs/process_manual_campaign.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $javascript = (string) file_get_contents($root . '/public/assets/app.js');

    $assert(version_compare($version, '2.20.0', '>='), 'VERSION debe conservar 2.20.0.');
    $assert(preg_match('/^(?:10[4-9]|1[1-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1
        && (isset($manifest['components']['process_manual_campaign'])
            || isset($manifest['components']['process_sync_queue'])),
        'El manifiesto debe exigir 104 o posterior y verificar el orquestador vigente.');
    foreach (['manual_campaigns','manual_campaign_operations','manual_campaign_items','manual_campaign_events','manual_campaign_workers'] as $table) {
        $assert(str_contains($migration, $table), 'Migración 104 debe incluir ' . $table . '.');
    }
    $assert(str_contains($service, 'next_action_at')
        && str_contains($service, 'lease_generation')
        && str_contains($service, 'ManualCampaignAdapterRegistry')
        && str_contains($service, 'max_duration_seconds')
        && str_contains($service, 'max_blocks'),
        'La campaña debe persistir reloj, fencing, límites y adaptador exacto.');
    $assert((str_contains($worker, 'usleep(')
          && str_contains($worker, 'inTransaction()')
          && str_contains($worker, 'processExact('))
        || str_contains($worker, 'reason=retired_interactive_web'),
        'El worker debe dormir sin busy-loop y procesar fuera de transacciones.');
    $assert(str_contains($view, 'role="progressbar"')
        && str_contains($view, 'data-manual-countdown')
        && (str_contains($view, 'data-engine-live') || str_contains($view, 'data-browser-live'))
        && (str_contains($view, 'Actividad reciente') || str_contains($view, '>Actividad<')),
        'La cabina debe mostrar progreso, reloj y actividad confirmada por heartbeat.');
    $assert(str_contains($javascript, 'after_event_id=')
        && str_contains($javascript, 'known_version=')
        && str_contains($javascript, 'document.hidden'),
        'La interfaz debe pedir cambios incrementales y reducir actividad en segundo plano.');
});

$test('2.20.1 mide llamadas reales y respeta el intervalo por operación', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/105_manual_campaign_exact_pacing_2_20_1.sql');
    $adapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
    $orders = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $worker = (string) file_get_contents($root . '/jobs/process_manual_campaign.php');
    $counter = (string) file_get_contents($root . '/app/Services/ManualCampaignCallCounter.php');
    $api = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $engine = (string) file_get_contents($root . '/app/Services/ManualProcessingEngineService.php');

    $assert(version_compare($version, '2.20.1', '>='), 'VERSION debe incluir el parche 2.20.1 o uno posterior.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '105_manual_campaign_exact_pacing_2_20_1.sql',
        '106_manual_engine_setup_recovery_2_20_2.sql',
        '107_manual_processing_monitor_unification_2_20_3.sql',
        '108_interactive_manual_processing_2_20_4.sql',
        '109_manual_campaign_rhythm_monitor_2_20_5.sql',
        '110_manual_campaign_preview_exact_notifications_2_20_6.sql',
        '111_sales_audit_forensic_hardening_2_20_7.sql',
        '112_annual_sales_fiscal_control_2_21_0.sql',
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración de ritmo exacto o su correctivo posterior.');
    foreach (['current_operation_id','exact_remote_pacing_enabled','real_call_counter_enabled','2.20.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 105 debe incluir ' . $needle . '.');
    }
    $assert(str_contains($adapter, 'processOne($id, $accountId, 1, false)')
        && preg_match('/processJob\\(\\s*\\$id,\\s*1,/', $adapter) === 1,
        'Órdenes y productos deben limitarse a una salida remota por pulso.');
    $assert(str_contains($orders, '$pages < $maxApiPages')
        && str_contains($orders, '$allowInlineEnrichment'),
        'La búsqueda de órdenes debe limitar páginas y evitar fan-out inline en campaña.');
    $assert((str_contains($worker, 'ApiExecutionMetadataContext::run')
          && str_contains($worker, 'ManualCampaignCallCounter')
          || str_contains($worker, 'reason=retired_interactive_web'))
        && str_contains($counter, 'reached_remote=1'),
        'El worker debe contar llamadas observadas, no inferirlas por filas.');
    $assert(str_contains($api, 'ApiExecutionMetadataContext::current()'),
        'El cliente API debe propagar la correlación segura de campaña.');
    $assert(str_contains($engine, "=== 'certified'\n                ||")
        && str_contains($migration, 'requested_window_seconds IN (10,30,55)'),
        'Una prueba adicional no debe revocar una certificación ya aprobada.');
});

$test('2.20.2 permite activación conservadora y prioriza la actualización pendiente', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/106_manual_engine_setup_recovery_2_20_2.sql');
    $engine = (string) file_get_contents($root . '/app/Services/ManualProcessingEngineService.php');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $worker = (string) file_get_contents($root . '/jobs/process_manual_campaign.php');
    $legacyWorker = (string) file_get_contents($root . '/jobs/process_manual_queue.php');
    $setup = (string) file_get_contents($root . '/app/Views/settings/manual_processing_setup.php');
    $main = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');

    $assert(version_compare($version, '2.20.2', '>='), 'VERSION debe incluir el correctivo 2.20.2 o uno posterior.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '106_manual_engine_setup_recovery_2_20_2.sql',
        '107_manual_processing_monitor_unification_2_20_3.sql',
        '108_interactive_manual_processing_2_20_4.sql',
        '109_manual_campaign_rhythm_monitor_2_20_5.sql',
        '110_manual_campaign_preview_exact_notifications_2_20_6.sql',
        '111_sales_audit_forensic_hardening_2_20_7.sql',
        '112_annual_sales_fiscal_control_2_21_0.sql',
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe obtener el requisito mínimo desde la migración 106 o su sucesora.');
    foreach ([
        'manual_campaign.certification_required',
        'manual_campaign.conservative_runtime_seconds',
        'manual_campaign.long_runtime_requires_certification',
        'manual_campaign.legacy_worker_retired',
        '2.20.2',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 106 debe incluir ' . $needle . '.');
    }
    $assert(!str_contains($engine, 'REQUIRED_MIGRATION')
        && str_contains($engine, "new ReleaseIntegrityService())->inspect(true)")
        && str_contains($engine, "'state' => \$databaseUnavailable ? 'installation_incomplete' : 'update_required'")
        && str_contains($engine, "'ready_conservative'"),
        'La preparación debe usar el manifiesto activo y distinguir actualización de modo conservador.');
    $assert(!str_contains($campaign, "empty(\$engine['certified'])"),
        'La certificación larga no debe bloquear la creación de campañas conservadoras.');
    $assert((str_contains($worker, 'ERP_CAMPAIGN_SKIP reason=migration_required')
          || str_contains($worker, 'reason=retired_interactive_web'))
        && ((str_contains($legacyWorker, "'process_manual_queue_legacy'")
          && str_contains($legacyWorker, 'ERP_MANUAL_SKIP reason=migration_required'))
          || str_contains($legacyWorker, 'reason=retired_interactive_web')),
        'Los workers deben omitir migraciones pendientes sin contaminar el heartbeat principal.');
    $interactiveReplacement = version_compare($version, '2.20.4', '>=');
    $assert($interactiveReplacement
        ? (str_contains($setup, 'Campañas dirigidas')
            && str_contains($setup, 'lanzador normal del ERP')
            && str_contains($setup, 'No necesita instalar nada')
            && !str_contains($setup, 'Certificar motor'))
        : (str_contains($setup, 'Completar actualización')
            && str_contains($setup, 'Certificación opcional')
            && str_contains($setup, 'process_manual_queue.php')
            && str_contains($setup, 'Hostinger solo despierta el motor')),
        'La configuración debe explicar el modo vigente con una sola acción humana.');
    $assert((str_contains($main, "\$campaignReady && \$engineReady")
          || str_contains($main, "\$campaignReady && !\$activeSession")
          || str_contains($main, "\$campaignReady && empty(\$emergencyStop) && !\$activeSession"))
        && !str_contains($main, "\$campaignReady && \$engineReady && \$certified"),
        'Procesar ahora debe permitir el modo conservador sin exigir certificación, salvo durante una parada de emergencia.');
});

$test('2.20.3 unifica el monitor asistido con progreso y heartbeat verificables', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/107_manual_processing_monitor_unification_2_20_3.sql');
    $service = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $syncController = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $css = (string) file_get_contents($root . '/public/assets/app.css');
    $javascript = (string) file_get_contents($root . '/public/assets/app.js');

    $assert(version_compare($version, '2.20.3', '>='), 'VERSION debe conservar 2.20.3.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '107_manual_processing_monitor_unification_2_20_3.sql',
        '108_interactive_manual_processing_2_20_4.sql',
        '109_manual_campaign_rhythm_monitor_2_20_5.sql',
        '110_manual_campaign_preview_exact_notifications_2_20_6.sql',
        '111_sales_audit_forensic_hardening_2_20_7.sql',
        '112_annual_sales_fiscal_control_2_21_0.sql',
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 107 o su sucesora.');
    foreach (['origin_key','origin_context_json','returned_items','last_engine_state','engine_live_seconds','2.20.3'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 107 debe incluir ' . $needle . '.');
    }
    $assert(str_contains($service, 'SUM(status="running")')
        && (str_contains($service, '$openItems > 0 ? min(99.9')
          || str_contains($service, '$progressOpen > 0 ? min(99.9'))
        && (str_contains($service, 'worker_heartbeat_at')
          || str_contains($service, 'control_heartbeat_at'))
        && str_contains($service, "'display_state'"),
        'El monitor debe reconstruir contadores y exigir heartbeat de la campaña.');
    $assert(str_contains($controller, 'ManualSingleStepService())->execute')
        && str_contains($controller, "trim((string) (\$_POST['preview_token']"),
        'Procesar ahora debe conservar el calculo exacto y ejecutar un solo paso.');
    $assert(!str_contains($syncController, 'resumePending($accountId)')
        && !str_contains($syncController, 'retryFailed($accountId)'),
        'Las rutas asistidas heredadas no deben mutar colas antes de redirigir.');
    $assert(str_contains($view, 'manual-countdown-panel')
        && (str_contains($view, 'manual-stat-strip') || str_contains($view, 'manual-stat-line'))
        && str_contains($view, 'data-manual-next-label')
        && !str_contains($view, 'manual-countdown"'),
        'La cabina debe usar reloj rectangular, resumen compacto y siguiente trabajo.');
    $assert(str_contains($javascript, 'engineLive = Boolean(session.engine_live)')
        && str_contains($javascript, 'if (payload.ok && payload.session)')
        && str_contains($javascript, 'manual-time-progress'),
        'La animación debe depender de actividad confirmada y respuestas diferenciales.');
    $assert(str_contains($css, '.manual-countdown-panel')
        && str_contains($css, '@media(prefers-reduced-motion:reduce)')
        && (str_contains($css, '.manual-monitor.is-engine-live')
          || str_contains($css, '.manual-monitor.is-browser-live')),
        'El diseño debe ser responsive y respetar movimiento reducido.');
});

$test('2.20.4 procesa un paso web idempotente sin depender de cron manual', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/108_interactive_manual_processing_2_20_4.sql');
    $interactive = (string) file_get_contents($root . '/app/Services/InteractiveManualProcessingService.php');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
    $session = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $javascript = (string) file_get_contents($root . '/public/assets/app.js');
    $legacyCampaign = (string) file_get_contents($root . '/jobs/process_manual_campaign.php');
    $legacyQueue = (string) file_get_contents($root . '/jobs/process_manual_queue.php');
    $syncQueue = (string) file_get_contents($root . '/app/Services/SyncQueueService.php');
    $itemQueue = (string) file_get_contents($root . '/app/Services/MeliItemSyncJobService.php');
    $descriptionQueue = (string) file_get_contents($root . '/app/Services/CatalogDescriptionJobService.php');
    $notificationQueue = (string) file_get_contents($root . '/app/Services/NotificationBackfillService.php');

    $assert(version_compare($version, '2.20.4', '>='), 'VERSION debe conservar 2.20.4 o una versión posterior.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '108_interactive_manual_processing_2_20_4.sql',
        '109_manual_campaign_rhythm_monitor_2_20_5.sql',
        '110_manual_campaign_preview_exact_notifications_2_20_6.sql',
        '111_sales_audit_forensic_hardening_2_20_7.sql',
        '112_annual_sales_fiscal_control_2_21_0.sql',
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 108 o su sucesora.');
    foreach ([
        'manual_campaign_reservations','manual_campaign_steps','control_owner',
        'control_expires_at','step_sequence','interactive_web','2.20.4',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 108 debe incluir ' . $needle . '.');
    }
    $assert(!str_contains($interactive, 'manual_campaign_steps')
        && !str_contains($interactive, 'processStep(')
        && !str_contains($interactive, 'Database::')
        && str_contains($interactive, 'lanzador CLI'),
        'La compatibilidad interactiva retirada no debe abrir base, adquirir recursos ni procesar adaptadores.');
    $assert(str_contains($campaign, 'execution_mode="directed_cli"')
        && str_contains($campaign, 'manual_campaign_reservations')
        && str_contains($campaign, 'total_units'),
        'Las campañas dirigidas deben congelar reservas y progreso por unidades.');
    $settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $assert(!str_contains($routes, '/settings/manual-processing/interactive/start')
        && str_contains($routes, '/settings/manual-processing/interactive/step')
        && str_contains($routes, '/settings/manual-processing/interactive/heartbeat')
        && str_contains($settingsController, 'public function manualProcessingInteractiveStep')
        && str_contains($settingsController, 'public function manualProcessingInteractiveHeartbeat')
        && str_contains($settingsController, 'ManualSingleStepService())->execute')
        && str_contains($settingsController, "'state' => 'not_processed'"),
        'La excepcion web autorizada debe ejecutar un paso exacto y responder sin proceso persistente.');
    $assert(str_contains($view, 'Procesamiento manual listo')
        && str_contains($view, 'Mantenga abierta esta pagina')
        && !str_contains($view, 'Comprobar motor'),
        'La pantalla inicial debe explicar el plano CLI recuperable.');
    $assert(!str_contains($session, 'data-step-url')
        && !str_contains($session, 'data-heartbeat-url')
        && !str_contains($session, 'Procesar siguiente paso'),
        'El monitor no debe ejecutar API desde el navegador.');
    $assert(!str_contains($settingsController, 'manualProcessingInteractiveStep')
        || !str_contains(
            substr($settingsController, strpos($settingsController, 'manualProcessingInteractiveStep'), 500),
            'processStep('
        ),
        'La compatibilidad HTTP no debe ejecutar un paso de trabajo.');
    $assert(str_contains($legacyCampaign, 'reason=retired_interactive_web')
        && str_contains($legacyQueue, 'reason=retired_interactive_web'),
        'Los workers manuales heredados deben finalizar sin adquirir trabajo.');
    foreach ([$syncQueue, $itemQueue, $descriptionQueue, $notificationQueue] as $queueSource) {
        $assert(str_contains($queueSource, "hasTable('manual_campaign_reservations')"),
            'Las colas normales deben seguir operativas antes de aplicar la migración 108.');
    }
});

$test('2.20.5 aplica ritmo global, límites reales y monitor compacto', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/109_manual_campaign_rhythm_monitor_2_20_5.sql');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $interactive = (string) file_get_contents($root . '/app/Services/InteractiveManualProcessingService.php');
    $campaignWorker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
    $registry = (string) file_get_contents($root . '/app/Services/ManualCampaignAdapterRegistry.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $javascript = (string) file_get_contents($root . '/public/assets/app.js');
    $routes = (string) file_get_contents($root . '/public/index.php');

    $assert(version_compare($version, '2.20.5', '>='), 'VERSION debe conservar 2.20.5 o una corrección posterior.');
    $assert(is_string($manifest['minimum_migration'] ?? null),
        'El manifiesto debe declarar una migración mínima.');
    foreach (['outbound_calls','block_outbound_calls','completed_blocks','limit_reason','source_state','2.20.5'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 109 debe incluir ' . $needle . '.');
    }
    $rhythm = new \App\Services\ManualCampaignRhythmService();
    $after29 = $rhythm->afterStep(29, 0, 1, 30, 2000, 30000, true);
    $assert($after29['block_ended'] && $after29['delay_ms'] === 30000
        && $after29['block_calls'] === 0 && $after29['completed_blocks'] === 1,
        'La consulta global 30 debe cerrar el bloque y aplicar 30 segundos.');
    $afterLocal = $rhythm->afterStep(17, 0, 0, 30, 2000, 30000, true);
    $assert(!$afterLocal['block_ended'] && $afterLocal['block_calls'] === 17 && $afterLocal['delay_ms'] === 0,
        'El trabajo local no debe reiniciar ni consumir el bloque remoto.');
    $assert($rhythm->limitReason([
        'configuration' => ['max_blocks' => 5, 'max_duration_seconds' => 0],
        'completed_blocks' => 5,
    ]) === 'max_blocks', 'El máximo de bloques debe detener la campaña.');
    $assert(!str_contains($campaign, 'current_operation_id<>?,0,calls_in_block')
        && str_contains($campaign, 'block_outbound_calls')
        && !str_contains($interactive, 'ManualCampaignSourceInspector')
        && str_contains($campaignWorker, 'ManualCampaignSourceInspector'),
        'El contador no debe reiniciarse y solo el worker CLI debe comprobar y procesar la fuente.');
    $assert(str_contains($registry, "'notification_backfill'")
        && str_contains($registry, "'notification-backfill', 'notification_backfill', 'local_maintenance', 'Recuperación de notificaciones', false"),
        'El backfill sin aislamiento exacto no debe procesarse interactivamente.');
    foreach (['manual-ops-main','manual-log-tabs','data-manual-work-progress','data-manual-attention'] as $needle) {
        $assert(str_contains($view, $needle), 'El monitor compacto debe incluir ' . $needle . '.');
    }
    $assert(str_contains($javascript, 'data-manual-controller="directed-cli-v1"')
        && str_contains($javascript, 'statusInFlight')
        && !str_contains($javascript, 'heartbeatInFlight')
        && !str_contains($javascript, 'stepInFlight')
        && !str_contains($javascript, '/interactive/step'),
        'Un solo controlador de lectura debe impedir consultas concurrentes y no ejecutar pasos.');
    $assert(str_contains($routes, '/settings/manual-processing/session/items')
        && str_contains($routes, '/settings/manual-processing/session/events'),
        'La campaña debe tener listados propios de trabajos y eventos.');
});

$test('2.20.6 congela el cálculo visible y procesa notificaciones exactas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/110_manual_campaign_preview_exact_notifications_2_20_6.sql');
    $controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $preview = (string) file_get_contents($root . '/app/Services/ManualCampaignPreviewService.php');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $notification = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    $registry = (string) file_get_contents($root . '/app/Services/ManualCampaignAdapterRegistry.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $apiContext = (string) file_get_contents($root . '/app/Services/ApiExecutionMetadataContext.php');

    $assert(version_compare($version, '2.20.6', '>='), 'VERSION debe identificar 2.20.6 o una sucesora.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '110_manual_campaign_preview_exact_notifications_2_20_6.sql',
        '111_sales_audit_forensic_hardening_2_20_7.sql',
        '112_annual_sales_fiscal_control_2_21_0.sql',
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 110 o una sucesora.');
    foreach (['manual_campaign_previews','manual_campaign_preview_items','preview_ttl_seconds','2.20.6'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 110 debe incluir ' . $needle . '.');
    }
    $assert(str_contains($routes, "post('/settings/manual-processing/preview'")
        && str_contains($controller, 'manualProcessingPreview')
        && str_contains($controller, '#resultado-calculo'),
        'El cálculo debe usar POST, PRG y ancla visible.');
    $assert(str_contains($preview, 'configuration_hash')
        && str_contains($preview, 'created_by_user_id')
        && str_contains($preview, 'manual_campaign_preview_items'),
        'La previsualización debe quedar congelada y pertenecer al usuario.');
    $assert(str_contains($campaign, 'revalidateFrozenPreview')
        && str_contains($campaign, 'previewService->consume'),
        'El inicio debe revalidar y consumir la selección revisada.');
    $assert(str_contains($registry, "'notification_fallback'")
        && str_contains($notification, 'inspectExact')
        && str_contains($notification, 'processExact')
        && str_contains($notification, 'manual_campaign_reservations'),
        'Notificaciones debe tener adaptador exacto y coordinación con automatización.');
    $assert(str_contains($notification, 'Necesita completar primero la orden asociada'),
        'Un envío que podría generar dos consultas debe quedar fuera.');
    $assert(str_contains($apiContext, 'claimRemoteCall')
        && str_contains($view, 'Calcular trabajos disponibles')
        && str_contains($view, 'preview_token'),
        'Cada paso debe limitar salidas y la vista debe iniciar desde el cálculo congelado.');
});

$test('2.20.7 y 2.21.0 separan auditoría, evidencia y preparación fiscal segura', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $migration111 = (string) file_get_contents($root . '/database/migrations/111_sales_audit_forensic_hardening_2_20_7.sql');
    $migration112 = (string) file_get_contents($root . '/database/migrations/112_annual_sales_fiscal_control_2_21_0.sql');
    $control = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    $fiscal = (string) file_get_contents($root . '/app/Services/SalesFiscalPreparationService.php');
    $gateway = (string) file_get_contents($root . '/app/Services/SalesAuditAccessGateway.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $view = (string) file_get_contents($root . '/app/Views/sales_control/index.php');

    $assert(version_compare($version, '2.21.0', '>='), 'VERSION debe incluir Control anual de ventas.');
    foreach (['lease_generation','snapshot_hash','sync_sales_audit_run_pages','company_id','2.20.7'] as $needle) {
        $assert(str_contains($migration111, $needle), 'Migración 111 debe incluir ' . $needle . '.');
    }
    foreach (['sales_control_closes','sales_control_reopenings','sales_control_fiscal_snapshots',
              'encrypted_payload','sales_control_access_audit','2.21.0'] as $needle) {
        $assert(str_contains($migration112, $needle), 'Migración 112 debe incluir ' . $needle . '.');
    }
    $assert(str_contains($gateway, 'a.company_id')
        && str_contains($gateway, 'meli_account_id')
        && str_contains($control, 'count($captures) < 2')
        && str_contains($control, 'snapshot_hash'),
        'Cuenta, empresa y doble captura deben proteger el cierre.');
    $assert(str_contains($fiscal, 'Crypto::encrypt')
        && str_contains($fiscal, '/orders/billing-info/')
        && !str_contains($fiscal, '/payments/'),
        'La preparación fiscal debe cifrar y usar solo el endpoint confirmado.');
    foreach (['/sales-control/check-year','/sales-control/check-month','/sales-control/close','/sales-control/reopen'] as $route) {
        $assert(str_contains($routes, $route), 'Falta la ruta ' . $route . '.');
    }
    $assert(str_contains($view, 'Qué hacer ahora')
        && str_contains($view, 'Sin información comprobada')
        && !str_contains($view, 'SQLSTATE'),
        'La vista anual debe priorizar conclusión y no fingir ceros.');
});

$test('2.21.1 corrige el contrato real de cuentas y la proyección de auditorías', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/113_sales_control_schema_compatibility_2_21_1.sql');
    $gateway = (string) file_get_contents($root . '/app/Services/SalesAuditAccessGateway.php');
    $control = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    $contract = (string) file_get_contents($root . '/app/Services/SalesControlSchemaContractService.php');
    $registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
    $diagnostic = (string) file_get_contents($root . '/app/Services/DiagnosticService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SalesControlController.php');
    $view = (string) file_get_contents($root . '/app/Views/sales_control/index.php');
    $integration = (string) file_get_contents($root . '/tests/sales_control_2211_mysql_integration.php');

    $assert(version_compare($version, '2.21.1', '>='), 'VERSION debe incluir el correctivo 2.21.1.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '113_sales_control_schema_compatibility_2_21_1.sql',
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 113 o una sucesora.');
    $assert(str_contains($migration, 'sales_control.schema_contract_enabled')
        && str_contains($migration, '2.21.1')
        && !preg_match('/ALTER\\s+TABLE\\s+meli_accounts/i', $migration),
        'La migración 113 debe registrar el contrato sin inventar deleted_at.');
    $assert(!str_contains($gateway, 'a.deleted_at')
        && str_contains($gateway, 'c.deleted_at IS NULL')
        && str_contains($gateway, 'a.company_id=?'),
        'El gateway debe aislar empresa y usar únicamente columnas existentes.');
    $assert(str_contains($control, 'SalesAuditAccessGateway')
        && !str_contains($control, 'FROM meli_accounts'),
        'Control de ventas debe centralizar la lectura de cuentas.');
    $assert(str_contains($contract, "'sync_sales_audit_jobs'")
        && str_contains($contract, "'sales_control_years'")
        && str_contains($contract, "'sales_control_closes'"),
        'El contrato debe validar el esquema completo antes de construir la pantalla.');
    $assert(preg_match(
        '/FROM sync_sales_audit_jobs j.*?j\\.started_at started_at_source.*?WHERE j\\.status IN/s',
        $registry
    ) === 1,
        'La cola sales_audit debe proyectar started_at, no una columna locked_at inexistente.');
    $assert(str_contains($controller, "compact('accounts', 'accountId', 'year', 'overview', 'schemaStatus')")
        && str_contains($view, 'Falta completar la actualización')
        && str_contains($view, '/settings/update'),
        'Un esquema incompleto debe degradar a una acción humana, no a error 500.');
    $assert(str_contains($diagnostic, 'sales_control_schema_compatibility')
        && str_contains($diagnostic, "'occurrences' => 1")
        && str_contains($diagnostic, "'recoverable' => true"),
        'Los fallos de esquema repetidos deben aparecer como un incidente recuperable.');
    $assert(str_contains($integration, "ERP_RELEASE_STRICT")
        && str_contains($integration, "TABLE_NAME='meli_accounts' AND COLUMN_NAME='deleted_at'")
        && str_contains($integration, 'WorkQueueRegistry')
        && str_contains($integration, 'checkMonth('),
        'La certificación MariaDB debe ejecutar realmente cuentas, resumen, cola e idempotencia.');
});

$test('2.21.2 a 2.22.1 recuperan ejecuciones y presentan cobertura honesta', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $notifications = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    $release = (string) file_get_contents($root . '/app/Services/ReleaseIntegrityService.php');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
    $worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $sales = (string) file_get_contents($root . '/app/Services/SalesControlService.php')
        . (string) file_get_contents($root . '/app/Views/sales_control/index.php')
        . (string) file_get_contents($root . '/app/Views/sales_control/month.php');
    $nav = (string) file_get_contents($root . '/app/Views/sales_control/_nav.php');
    $scopeAudit = (string) file_get_contents($root . '/app/Services/BusinessScopeAuditService.php');
    $queueAdapter = (string) file_get_contents($root . '/app/Services/SqlWorkQueueAdapter.php');

    $assert(version_compare($version, '2.22.1', '>='), 'VERSION debe registrar 2.22.1.');
    $assert(in_array(($manifest['minimum_migration'] ?? ''), [
        '117_operational_ux_contracts_2_22_1.sql',
        '118_runtime_journal_health_scheduler_2_22_2.sql',
        '119_verified_sales_capture_2_23_0.sql',
        '120_operational_consolidation_ux_2_23_1.sql',
        '121_sale_pack_identity_integrity_2_23_2.sql',
        '122_sale_financial_reconciliation_2_24_0.sql',
        '123_manual_processing_exclusion_navigation_2_24_1.sql',
        '124_runtime_collation_scheduler_recovery_2_24_2.sql',
        '125_directed_campaign_sales_workflow_2_25_0.sql',
        '126_manual_campaign_scheduler_recovery_2_25_1.sql',
    ], true) || preg_match('/^(?:12[7-9]|1[3-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El runtime debe exigir la migración final o una sucesora.');
    foreach (['114_operational_integrity_scope_2_21_2.sql','115_resumable_execution_journal_2_21_3.sql',
              '116_sales_evidence_control_2_22_0.sql','117_operational_ux_contracts_2_22_1.sql'] as $migration) {
        $assert(is_file($root . '/database/migrations/' . $migration), 'Falta la migración ' . $migration . '.');
    }
    $assert(str_contains($notifications, 'completeExpectedAbsence')
        && str_contains($notifications, 'question_not_available')
        && str_contains($notifications, 'random_int'),
        'Una pregunta 404 debe cerrarse y los errores temporales usar backoff con jitter.');
    $assert(str_contains($release, 'ComponentSchemaContractService')
        && str_contains($release, '$this->inspect(false)'),
        'La integridad debe bloquear por componente, no por metadata global.');
    $assert(str_contains($campaign, 'execution_mode="directed_cli"')
        && !str_contains($campaign, 'WHERE execution_mode="interactive_web" AND status="active"'),
        'El plano de campaña activo debe ser exclusivamente CLI.');
    $assert(str_contains($settings, 'ManualSingleStepService())->execute')
        && str_contains($settings, "'state' => 'not_processed'"),
        'El unico endpoint web ejecutable debe limitarse a un paso exacto.');
    $assert(str_contains($journal, 'remote_dispatched')
        && str_contains($journal, 'response_received')
        && str_contains($journal, 'approved')
        && str_contains($journal, 'last_approved_step')
        && str_contains($worker, 'observed_safe_window_ms'),
        'El diario debe conservar salida, respuesta, aprobación y ventana observada.');
    $assert(str_contains($sales, 'enqueueNextYearMonth')
        && str_contains($sales, 'captureCountForPeriod')
        && str_contains($sales, 'cancellation_coverage="limited"')
        && str_contains($sales, "'fiscal_missing_total' => \$control && \$control['fiscal_missing_total'] !== null"),
        'Ventas debe coordinar meses, exigir doble captura y conservar desconocidos.');
    $assert(str_contains($nav, '$salesNavQuery') && !str_contains($nav, '$query ='),
        'La navegación no debe sobrescribir la consulta del detalle mensual.');
    $assert(str_contains($scopeAudit, 'BusinessScopeContext')
        && str_contains($scopeAudit, 'user_company_access')
        && str_contains($scopeAudit, 'meli_account_id'),
        'Diagnóstico debe comprobar el contrato empresa/cuenta en caminos críticos.');
    $assert(str_contains($queueAdapter, "\$display === 'running'")
        && str_contains($queueAdapter, 'time() - 300')
        && str_contains($queueAdapter, 'reserva anterior dejó de enviar señales'),
        'Automatización no debe presentar como activo un trabajo sin señal reciente.');
});

$test('2.22.2 unifica el lanzador, el diario y la elegibilidad', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/118_runtime_journal_health_scheduler_2_22_2.sql');
    $journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
    $scheduler = (string) file_get_contents($root . '/app/Services/WorkSchedulerService.php');
    $launcher = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $retired = (string) file_get_contents($root . '/jobs/process_notifications.php');
    foreach (['remote_dispatched','response_received','result_applied','approved','observed_interval_seconds'] as $needle) {
        $assert(str_contains($migration . $journal, $needle), 'Falta contrato recuperable: ' . $needle);
    }
    $assert(str_contains($launcher, "'notification_fallback'")
        && str_contains($retired, 'retired_single_launcher'),
        'Webhook-First debe ejecutarse solo dentro del lanzador principal.');
    $assert(str_contains($scheduler, 'unavailableQueueCount')
        && str_contains($scheduler, 'No es seguro afirmar que no exista trabajo'),
        'El planificador no debe confundir una cola ilegible con una cola vacía.');
});

$test('2.23.0 valida capturas y cierres independientes', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/119_verified_sales_capture_2_23_0.sql');
    $verified = (string) file_get_contents($root . '/app/Services/VerifiedSalesCaptureService.php');
    $control = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    foreach (['capture_role','verification_not_before','coverage_validation_state','sync_sales_capture_validations'] as $needle) {
        $assert(str_contains($migration, $needle), 'Falta evidencia 2.23.0: ' . $needle);
    }
    foreach (['hueco de paginación','página de IDs repetida','total informado por Mercado Libre cambió',
              '$httpStatus !== 200'] as $needle) {
        $assert(str_contains($verified, $needle), 'Falta validación estricta: ' . $needle);
    }
    $assert(str_contains($control, 'verification_min_delay_minutes')
        && str_contains($control, "validation_state'] ?? '') !== 'valid'")
        && str_contains($control, 'Las dos comprobaciones todavía no son independientes'),
        'Un cierre debe exigir capturas válidas, distintas y separadas.');
});

$test('2.23.1 retira disparadores web y exige MariaDB para release', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root . '/public/index.php');
    $docs = (string) file_get_contents($root . '/docs/mercadolibre_api_map.md');
    $cronDocs = (string) file_get_contents($root . '/docs/cron_jobs.md');
    $notifications = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
    $orders = (string) file_get_contents($root . '/app/Controllers/OrderController.php');
    $products = (string) file_get_contents($root . '/app/Controllers/MeliProductController.php');
    $claims = (string) file_get_contents($root . '/app/Controllers/ClaimController.php');
    $questions = (string) file_get_contents($root . '/app/Controllers/QuestionController.php');
    $catalogs = (string) file_get_contents($root . '/app/Controllers/CatalogController.php');
    $manualView = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $retiredStep = substr(
        $settingsController,
        (int) strpos($settingsController, 'public function manualProcessingInteractiveStep'),
        500
    );
    $assert(str_contains($routes, '/settings/manual-processing/interactive/step')
        && str_contains($retiredStep, 'ManualSingleStepService())->execute')
        && !str_contains($retiredStep, 'processStep('),
        'La ruta web permitida debe ejecutar solo el paso exacto V3.');
    $webControllers = $notifications . $orders . $products . $claims . $questions . $catalogs;
    foreach (['->processDue(', '->processRun(', '->syncRange(', '->syncOrderById(', '->syncOpened(',
              '->syncAccount(', '->syncAllActive(', '->syncItem('] as $forbiddenCall) {
        $assert(!str_contains($webControllers, $forbiddenCall),
            'Los controladores web no deben ejecutar transporte o trabajo remoto: ' . $forbiddenCall);
    }
    $assert(str_contains($manualView, "/settings/manual-processing/start")
        && !str_contains($manualView, "/settings/manual-processing/interactive/start"),
        'El formulario manual debe crear una campaña CLI mediante la ruta vigente.');
    $assert(substr_count($cronDocs, 'process_sync_queue.php') >= 1
        && !str_contains($cronDocs, '* * * * * php /home/USUARIO/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/process_notifications.php'),
        'La guía debe configurar un solo lanzador.');
    $assert(!str_contains($docs, 'operaciÃ')
        && str_contains($docs, 'GET /questions/{question_id}')
        && str_contains($docs, 'HTTP 206'),
        'El mapa API debe estar legible y actualizado.');
    $assert(isset($composer['scripts']['qa:release'])
        && is_file($root . '/tests/release_gate.php'),
        'La certificación de release debe exigir una base real.');
});

$test('2.23.2 reconstruye la identidad visible por pack sin inventar una orden principal', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/121_sale_pack_identity_integrity_2_23_2.sql');
    $reader = (string) file_get_contents($root . '/app/Services/SaleReadService.php');
    $reconciliation = (string) file_get_contents($root . '/app/Services/HistoricalPackReconciliationService.php');
    $enrichment = (string) file_get_contents($root . '/app/Services/OrderEnrichmentService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $assert(str_contains($reader, 'COALESCE(o.external_pack_id,o.external_order_id)')
        && str_contains($reader, 'external_pack_id=? OR external_order_id=?'),
        'La búsqueda debe resolver pack y orden hija a la misma venta.');
    $assert(str_contains($reconciliation, '/packs/')
        && str_contains($reconciliation, 'recover_order')
        && str_contains($reconciliation, 'enqueueRebuild'),
        'La reparación histórica debe ser local primero y recuperar solo hijas faltantes.');
    $assert(!str_contains($enrichment, 'SET meli_pack_id=')
        && str_contains($enrichment, 'meli_pack_orders'),
        'El enriquecimiento no puede escribir la columna inexistente meli_orders.meli_pack_id.');
    foreach (['/sales', '/sales/show', '/sales/integrity', 'sale_pack_rebuild_runs'] as $needle) {
        $assert(str_contains($routes . $migration, $needle), 'Falta contrato de venta agrupada: ' . $needle);
    }
});

$test('2.24.0 concilia el caso real por pack y distribuye sin perder centavos', static function () use ($assert): void {
    $parser = new \App\Services\SaleBillingParser();
    $lines = $parser->parse([
        'details' => [
            [
                'order_id' => '2000017629499388',
                'detail_id' => 'fee-1',
                'detail_type' => 'SALE_FEE',
                'transaction_detail' => 'Cargo por venta',
                'detail_amount' => 10533,
            ],
            [
                'order_id' => '2000017629499386',
                'detail_id' => 'fee-2',
                'detail_type' => 'SALE_FEE',
                'transaction_detail' => 'Cargo por venta',
                'detail_amount' => 8746,
            ],
            [
                'detail_id' => 'shipping-pack',
                'detail_type' => 'SHIPPING',
                'detail_sub_type' => 'seller_cost',
                'transaction_detail' => 'Cargo neto por envíos de Mercado Libre',
                'detail_amount' => 25600,
            ],
            [
                'detail_id' => 'tax-pack',
                'detail_type' => 'WITHHOLDING',
                'transaction_detail' => 'Impuestos y retenciones',
                'detail_amount' => 4027,
            ],
            [
                'detail_id' => 'shipping-pack',
                'detail_type' => 'SHIPPING',
                'transaction_detail' => 'Cargo duplicado en otra sección del documento',
                'detail_amount' => 25600,
            ],
        ],
    ], ['2000017629499388', '2000017629499386']);
    $assert(count($lines) === 4, 'El parser debe deduplicar conceptos oficiales repetidos.');
    $result = (new \App\Services\SaleFinancialService())->calculate([
        ['id' => 1, 'gross' => 60191, 'units' => 1],
        ['id' => 2, 'gross' => 49980, 'units' => 2],
    ], $lines);
    $assert((float) $result['products_amount'] === 110171.0, 'El bruto del pack es incorrecto.');
    $assert((float) $result['sale_fee_amount'] === 19279.0, 'Los cargos por venta no sumaron las dos órdenes.');
    $assert((float) $result['shipping_charge_amount'] === 25600.0, 'El envío compartido se duplicó.');
    $assert((float) $result['taxes_amount'] === 4027.0, 'Los impuestos oficiales son incorrectos.');
    $assert((float) $result['net_amount'] === 61265.0, 'El neto esperado del caso aportado debe ser $61.265.');
    $allocated = array_sum(array_map(
        static fn(array $row): float => (float) $row['net_allocated'],
        $result['allocations']
    ));
    $assert(abs($allocated - 61265.0) < 0.001, 'La distribución interna debe cerrar contra el neto oficial.');
});

$test('2.24.0 descarta agregados y cargos anulados antes de conciliar', static function () use ($assert): void {
    $lines = (new \App\Services\SaleBillingParser())->parse([
        'shipping_info' => [
            'detail_type' => 'SHIPPING',
            'total_amount' => 51200,
            'details' => [
                [
                    'detail_id' => 'shipping-active',
                    'detail_sub_type' => 'seller_cost',
                    'detail_amount' => 25600,
                    'status' => 'approved',
                ],
                [
                    'detail_id' => 'shipping-cancelled',
                    'detail_sub_type' => 'seller_cost',
                    'detail_amount' => 25600,
                    'status' => 'cancelled',
                ],
            ],
        ],
    ], ['2000017629499388']);
    $assert(count($lines) === 1, 'El parser no debe sumar el agregado padre ni un cargo anulado.');
    $assert((float) $lines[0]['amount'] === 25600.0 && $lines[0]['line_group'] === 'shipping',
        'Debe conservarse únicamente el detalle vigente con la clasificación heredada.');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/OrderController.php');
    $assert(!str_contains($controller, 'new OrderFinancialService')
        && str_contains($controller, 'new SaleFinancialService'),
        'Las rutas heredadas deben conducir a la conciliación agrupada y aislada.');
});

$test('2.24.1 explica y enlaza todos los trabajos excluidos de Procesar ahora', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/123_manual_processing_exclusion_navigation_2_24_1.sql');
    $campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
    $preview = (string) file_get_contents($root . '/app/Services/ManualCampaignPreviewService.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
    $detail = (string) file_get_contents($root . '/app/Views/settings/manual_processing_excluded.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach ([
        'action_required',
        'future',
        'running',
        'paused',
        'completed',
        'automatic_only',
    ] as $state) {
        $assert(str_contains($campaign, "'{$state}'"), "Falta clasificar el estado {$state} sin analizar textos humanos.");
    }
    $assert(str_contains($preview, 'loadForReview')
        && str_contains($preview, 'created_by_user_id=?'),
        'El detalle debe conservar evidencia de solo lectura y aislarla por usuario.');
    $assert(str_contains($view, 'manual-preview-group')
        && str_contains($view, 'Procesar rápido no significa saltar protecciones.'),
        'El resumen debe convertir cada contador en un destino explicado.');
    $assert(str_contains($detail, 'Abrir trabajo')
        && str_contains($detail, 'Ir al módulo')
        && str_contains($detail, '<caption>'),
        'La lista excluida debe ofrecer destino operativo y tabla accesible.');
    $assert(str_contains($routes, '/settings/manual-processing/excluded')
        && str_contains($migration, '2.24.1')
        && str_contains($migration, 'exclusion_reason_mode'),
        'La release debe registrar ruta, defaults y versión 2.24.1.');
});

$test('2.24.2 recupera el lanzador antes de abrir colas y conserva resultados tipados', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/124_runtime_collation_scheduler_recovery_2_24_2.sql');
    $contract = (string) file_get_contents($root . '/app/Services/ComponentSchemaContractService.php');
    $coordinator = (string) file_get_contents($root . '/app/Services/CronWorkCoordinator.php');
    $worker = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $probe = (string) file_get_contents($root . '/jobs/manual_engine_probe.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach (['system_cron_boot_attempts','utf8mb4_unicode_ci','normalized_error_code','2.24.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 124 debe incluir ' . $needle);
    }
    $assert(
        !str_contains($contract, 'LEFT JOIN schema_migrations')
        && str_contains($contract, 'SELECT version FROM schema_migrations'),
        'Los contratos deben compararse en PHP sin unir columnas con collations distintas.'
    );
    $assert(
        str_contains($coordinator, 'CronWorkOutcome::normalize')
        && str_contains($worker, 'CronBootstrapJournalService'),
        'El lanzador debe conservar resultados tipados y registrar el arranque antes de las colas.'
    );
    $assert(
        str_contains($probe, 'retired_single_launcher')
        && !str_contains($probe, 'sleep('),
        'El probe heredado debe quedar inactivo y no consumir una ventana de Hostinger.'
    );
    $assert(
        str_contains($routes, '/settings/cron/attention')
        && str_contains($routes, '/settings/cron/attention/remediate'),
        'La intervención guiada debe tener rutas de lectura y mutación protegida.'
    );
});

$test('2.25.0 dirige recursos exactos y coordina el año sin duplicar auditorías', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/125_directed_campaign_sales_workflow_2_25_0.sql');
    $registry = (string) file_get_contents($root . '/app/Services/ManualCampaignAdapterRegistry.php');
    $adapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
    $inspector = (string) file_get_contents($root . '/app/Services/ManualCampaignSourceInspector.php');
    $worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
    $sales = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    $monthView = (string) file_get_contents($root . '/app/Views/sales_control/month.php');
    foreach ([
        'sales_control_year_runs',
        'reservation_ttl_seconds',
        'primary_lane_first',
        '2.25.0',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 125 debe incluir ' . $needle);
    }
    foreach ([
        'sales_audit',
        'sales_repair',
        'financial_recalc',
        'order_enrichment',
        'sale_pack_reconciliation',
        'sale_financial_reconciliation',
        'module_jobs',
    ] as $queue) {
        $assert(str_contains($registry, "'{$queue}'") && str_contains($adapter, "'{$queue}'"),
            'Falta un adaptador dirigido para ' . $queue);
        $assert(str_contains($inspector, "'{$queue}'"),
            'Falta verificar la fuente exacta de ' . $queue);
    }
    $assert(str_contains($worker, "'source' => 'manual_campaign'")
        && str_contains($worker, 'renewed_at=UTC_TIMESTAMP'),
        'El worker debe limitar cada salida remota y renovar reservas adaptativas.');
    $assert(str_contains($sales, 'ensureAnnualRun')
        && str_contains($sales, 'capture_role="primary"')
        && str_contains($sales, 'primaryCaptureRunId'),
        'El control anual debe reutilizar un coordinador y terminar primarias antes de verificaciones futuras.');
    $assert(str_contains($monthView, 'Por comprobar')
        && !str_contains($monthView, "require __DIR__ . '/_nav.php'"),
        'Un mes activo no debe mostrar ceros ni duplicar su navegación.');
});

$test('2.25.1 reserva un carril de campaña y mantiene el monitor coherente', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $selector = new \App\Services\CronTaskLaneSelector();
    $selected = $selector->select([
        ['key' => 'notification_fallback', 'api' => true, 'lane' => 'urgent'],
        ['key' => 'order_enrichment', 'api' => true, 'lane' => 'normal'],
        ['key' => 'manual_campaign', 'api' => true, 'lane' => 'directed'],
        ['key' => 'operational_maintenance', 'api' => false, 'lane' => 'local'],
    ], 3, 1);
    $keys = array_column($selected, 'key');
    $assert($keys === ['manual_campaign', 'operational_maintenance'],
        'El cupo API global debe permitir solo la campaña y conservar trabajo local.');
    $minimal = $selector->select([
        ['key' => 'orders_sync', 'api' => true, 'lane' => 'urgent'],
        ['key' => 'manual_campaign', 'api' => true, 'lane' => 'directed'],
    ], 1, 1);
    $assert(array_column($minimal, 'key') === ['manual_campaign'],
        'Una configuración de un solo trabajo y una sola API no puede exceder ninguno de los límites.');

    $withoutCampaign = $selector->select([
        ['key' => 'orders_sync', 'api' => true, 'lane' => 'urgent'],
        ['key' => 'order_enrichment', 'api' => true, 'lane' => 'normal'],
        ['key' => 'operational_maintenance', 'api' => false, 'lane' => 'local'],
    ], 3, 1);
    $assert(array_column($withoutCampaign, 'key') === ['orders_sync', 'operational_maintenance'],
        'Sin campaña, el límite API global debe seguir siendo uno y conservar trabajo local.');

    $migration = (string) file_get_contents(
        $root . '/database/migrations/126_manual_campaign_scheduler_recovery_2_25_1.sql'
    );
    $controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $script = (string) file_get_contents($root . '/public/assets/app.js');
    $style = (string) file_get_contents($root . '/public/assets/app.css');
    $view = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    foreach (['scheduler_recovered', 'INTERVAL 32 MINUTE', '2.25.1'] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 126 debe conservar ' . $needle);
    }
    $assert(!str_contains($controller, "'session' => \$delta"),
        'El JSON no debe reemplazar el resumen completo por un delta sin contadores.');
    $assert(str_contains($script, 'engineLive = Boolean(session.engine_live)')
        && str_contains($script, 'event.display_time'),
        'El monitor solo debe animarse con actividad real y presentar la hora local.');
    $assert(str_contains($style, '[hidden]{display:none!important}')
        && str_contains($view, 'data-manual-outbound'),
        'Los errores vacíos deben permanecer ocultos y las consultas deben verse por separado.');
});

$test('2.25.2 comercializa la importacion anual de ventas sin romper evidencia avanzada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root . '/public/index.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/SaleController.php');
    $status = (string) file_get_contents($root . '/app/Services/SalesImportStatusService.php');
    $sales = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    $view = (string) file_get_contents($root . '/app/Views/sales/index.php');
    $advanced = (string) file_get_contents($root . '/app/Views/sales_control/index.php');
    $migration = (string) file_get_contents($root . '/database/migrations/127_sales_import_commercial_flow_2_25_2.sql');

    $assert(str_contains($routes, "/sales/import-year")
        && str_contains($controller, 'function importYear')
        && str_contains($controller, 'Auth::requireRole(\'admin\', \'operador\')')
        && str_contains($controller, 'SalesControlService())->checkYear'),
        'La entrada comercial debe ser una ruta POST protegida que delega en el control anual existente.');
    $assert(str_contains($status, 'final class SalesImportStatusService')
        && str_contains($status, 'No consulta Mercado Libre')
        && !str_contains($status, 'MeliApiClient'),
        'El estado comercial debe ser un read model sin llamadas remotas.');
    $assert(str_contains($view, 'Importar ventas del año')
        && str_contains($view, 'sales-import-panel')
        && str_contains($view, 'Ver evidencia avanzada'),
        'Ventas debe mostrar la accion anual simple y mantener salida a evidencia avanzada.');
    $assert(str_contains($advanced, 'Evidencia avanzada de ventas')
        && str_contains($advanced, 'Datos fiscales pendientes')
        && !str_contains($advanced, '<h1>Control de ventas</h1>'),
        'Control de ventas debe presentarse como evidencia avanzada sin duplicar el enfoque comercial.');
    $assert(str_contains($sales, 'enqueueSafeRemediation')
        && str_contains($sales, 'SalesAuditExactRepairService')
        && str_contains($sales, 'OrderDateRepairService')
        && str_contains($sales, "capture_role'] ?? 'primary') !== 'primary'"),
        'Las reparaciones automaticas deben existir y limitarse a capturas primarias.');
    foreach (['sales_import.auto_repair_missing', 'sales_import.auto_repair_dates', '2.25.2'] as $needle) {
        $assert(str_contains($migration, $needle), 'La migracion 127 debe registrar ' . $needle);
    }
    // Los paquetes de runtime excluyen deliberadamente documentación y
    // auditorías. Si el árbol de desarrollo las conserva, se valida su
    // contenido; su ausencia no puede invalidar el código desplegable.
    $planPath = $root . '/docs/PLAN_TRABAJO_COMERCIAL_VENTAS_ML_2_25_1.md';
    $auditPath = $root . '/audits/AUDITORIA_FLUJO_COMERCIAL_VENTAS_ML_2_25_1.md';
    if (is_file($planPath)) {
        $plan = (string) file_get_contents($planPath);
        $assert(str_contains($plan, '127_sales_import_commercial_flow_2_25_2.sql'),
            'El plan disponible debe explicar la migración 127.');
    }
    if (is_file($auditPath)) {
        $audit = (string) file_get_contents($auditPath);
        $assert(str_contains($audit, 'Version implementada: `2.25.2`'),
            'La auditoría disponible debe registrar 2.25.2.');
    }
});

$test('2.25.4 observa el arranque, evita collation 1267 y muestra la campaña real', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $entry = (string) file_get_contents($root . '/jobs/_cron_entry_state.php');
    $gateway = (string) file_get_contents($root . '/app/Services/InformationSchemaGateway.php');
    $schema = (string) file_get_contents($root . '/app/Services/SchemaInspectorService.php');
    $runtime = (string) file_get_contents($root . '/app/Services/AutomationRuntimeStatusService.php');
    $scheduler = (string) file_get_contents($root . '/app/Services/WorkSchedulerService.php');
    $nextView = (string) file_get_contents($root . '/app/Views/settings/automation_next.php');
    $campaignView = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $migration = (string) file_get_contents(
        $root . '/database/migrations/129_cron_entry_collation_campaign_recovery_2_25_4.sql'
    );

    $assert(strpos($job, "cron_entry_early_lock()") < strpos($job, "require __DIR__ . '/_bootstrap.php'"),
        'El lock temprano debe adquirirse antes del bootstrap.');
    foreach (['php_opened', 'bootstrap_loaded', 'database_connected', 'queues_prepared', 'work_selected', 'finished'] as $stage) {
        $assert(str_contains($job . $entry, "'{$stage}'"),
            'Falta observar la etapa temprana ' . $stage);
    }
    $assert(str_contains($gateway, 'BINARY TABLE_SCHEMA=BINARY :schema')
        && str_contains($gateway, 'BINARY TABLE_NAME=BINARY :table_name')
        && !str_contains($schema, 'TABLE_SCHEMA=DATABASE()'),
        'La inspección debe comparar information_schema de forma binaria y centralizada.');
    $assert(str_contains($runtime, 'latestAutomaticAnyBuild')
        && str_contains($runtime, 'entry_is_current_build'),
        'El estado debe separar el build actual del historial anterior.');
    $assert(str_contains($scheduler, 'CronTaskDefinitionRegistry')
        && str_contains($scheduler, 'CronTaskStateService())->preview')
        && str_contains($nextView, 'Selección real del próximo ciclo')
        && str_contains($nextView, 'Turno garantizado'),
        'Próxima ejecución debe reutilizar el selector real y explicar el carril dirigido.');
    $assert(str_contains($campaignView, 'Esperando que Hostinger inicie el ERP')
        && str_contains($campaignView, 'Todavía no se puede estimar'),
        'El monitor no debe inventar ETA ni actividad sin señal.');
    $assert(!str_contains($cronView, 'Worker de notificaciones · cada 5 minutos')
        && str_contains($cronView, 'Único lanzador del ERP'),
        'Cron debe instruir un solo lanzador.');
    foreach (['system_cron_entry_states', 'last_scheduler_selected_at', '2.25.4'] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 129 debe incluir ' . $needle);
    }
});

$test('2.25.6 evita que Cron bloquee toda la sesión web', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $dashboard = (string) file_get_contents($root . '/app/Controllers/DashboardController.php');
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $updates = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
    $projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $entry = (string) file_get_contents($root . '/jobs/_cron_entry_state.php');
    $migration = (string) file_get_contents(
        $root . '/database/migrations/131_web_session_projection_hotfix_2_25_6.sql'
    );

    $assert(str_contains($dashboard, 'Session::closeReadOnly();'),
        'El panel debe liberar la sesión antes de sus lecturas pesadas.');
    $assert(substr_count($updates, 'Session::closeReadOnly();') >= 2,
        'Actualizaciones y su estado deben liberar la sesión antes de inspecciones pesadas.');
    $cronMethod = substr(
        $settings,
        (int) strpos($settings, 'public function cron(): void'),
        (int) strpos($settings, 'public function automationNext(): void')
            - (int) strpos($settings, 'public function cron(): void')
    );
    $assert(str_contains($cronMethod, '$this->releaseReadOnlySession();')
        && str_contains($settings, "'details_included' => false")
        && str_contains($settings, "\$_GET['details']"),
        'Cron debe liberar la sesión y cargar diagnósticos pesados solo bajo solicitud.');
    $assert(str_contains($projection, 'summary(bool $refreshIfStale = false)')
        && str_contains($projection, 'page(array $filters = [], bool $refreshIfStale = false)')
        && str_contains($projection, 'if ($refreshIfStale)')
        && str_contains($projection, 'function refreshNextQueue()'),
        'Una lectura web no debe reconstruir las quince colas automáticamente.');
    $finishPosition = strpos($job, "\$persistEntry('finished'");
    $projectionPosition = strpos($job, '->refreshNextQueue()');
    $assert(
        $finishPosition !== false
        && $projectionPosition !== false
        && $projectionPosition > $finishPosition
        && !str_contains($job, 'WorkQueueProjectionService())->refresh();'),
        'El cron debe aprobar su resultado antes de actualizar una sola proyección secundaria.'
    );
    $assert(str_contains($entry, "defined('ERP_SHARED_ROOT')")
        && str_contains($entry, "basename(dirname(\$releaseRoot)) === 'releases'"),
        'El marcador temprano debe usar el storage compartido también desde una release administrada.');
    foreach (['automation.web_projection_refresh_enabled', 'release_session_before_heavy_get', '2.25.6'] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 131 debe registrar ' . $needle);
    }
});

$test('2.25.7 bloquea toda salida Mercado Libre antes del transporte', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $stop = new \App\Services\MeliEmergencyStopService();
    $assert($stop->active(), 'El archivo PAUSE_MELI_API debe mantener activa la parada de emergencia.');

    $transport = new class implements \App\Services\MeliHttpTransportInterface {
        public bool $called = false;

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            $this->called = true;
            throw new RuntimeException('El transporte no debe alcanzarse durante la parada.');
        }
    };

    $blocked = false;
    try {
        (new \App\Services\MeliApiClient(1, $transport))->get('/users/me');
    } catch (\App\Services\ApiManualPauseException $error) {
        $blocked = str_contains($error->getMessage(), 'bloqueadas por mantenimiento');
    }
    $assert($blocked, 'La lectura debe detenerse con una explicación humana.');
    $assert(!$transport->called, 'La parada debe actuar antes de abrir el transporte HTTP.');

    $oauth = (string) file_get_contents($root . '/app/Services/OAuthService.php');
    $guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
    $health = (string) file_get_contents($root . '/app/Views/settings/api_health.php')
        . (string) file_get_contents($root . '/app/Services/ApiHealthStatusPresenter.php');
    $job = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $htaccess = (string) file_get_contents($root . '/.htaccess');
    $migration = (string) file_get_contents($root . '/database/migrations/132_emergency_api_stop_2_25_7.sql');

    $assert(str_contains($oauth, 'MeliEmergencyStopService())->assertAllowed()'),
        'OAuth debe respetar la parada antes de abrir el transporte.');
    $assert(strpos($guard, 'MeliEmergencyStopService())->assertAllowed()') < strpos($guard, 'ApiManualPauseService())->assertAllowed('),
        'La parada independiente debe evaluarse antes de consultar la base.');
    $assert(str_contains($health, 'Consultas a Mercado Libre bloqueadas por mantenimiento'),
        'Salud API debe explicar el bloqueo de emergencia.');
    $assert(strpos($job, '_automation_emergency_stop.php') < strpos($job, "_bootstrap.php")
        && str_contains($job, 'reason=manual_automation_stop remote=false database=false')
        && str_contains($job, '$localOnlyKeys')
        && str_contains($job, "'operational_maintenance'"),
        'Automatización apagada debe detenerse antes del bootstrap; API apagada solo admite trabajo local certificado.');
    $assert(str_contains($htaccess, 'PAUSE_MELI_API'),
        'El archivo de parada no debe servirse públicamente.');
    $assert(str_contains($migration, '2.25.7'),
        'La migración debe registrar la versión de emergencia.');
});

$test('2.26.2 mantiene saneamiento legacy explícito y 2.28.39 retiene solo telemetría verificada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $maintenance = (string) file_get_contents($root . '/app/Services/DatabaseMaintenanceService.php');
    $operational = (string) file_get_contents($root . '/app/Services/OperationalMaintenanceService.php');
    $migration = (string) file_get_contents($root . '/database/migrations/149_forensic_noise_sanitation_2_26_2.sql');

    $assert(str_contains($maintenance, "'legacy_notifications'")
        && str_contains($maintenance, 'NotificationLegacyNormalizationService())->normalizeBatch')
        && str_contains($maintenance, 'compactMessageBatch')
        && str_contains($maintenance, 'sin replay automático')
        && str_contains($maintenance, "? 'legacy_notifications'")
        && str_contains($maintenance, "\$phase = 'retention';"),
        'La normalización y compactación legacy deben ser fases explícitas del Centro.');
    $normalizer = (string) file_get_contents(
        $root . '/app/Services/NotificationLegacyNormalizationService.php'
    );
    $assert(str_contains($normalizer, '"queued","unknown_topic","waiting_retry"'),
        'El saneamiento debe cerrar todo estado legacy elegible sin dejar reintentos huérfanos.');
    $assert(!str_contains($operational, 'NotificationLegacyNormalizationService())->normalizeBatch'),
        'OperationalMaintenanceService no debe normalizar legacy en el cron local silencioso.');
    $retention = (string) file_get_contents($root . '/app/Services/TechnicalRetentionCliService.php');
    $assert(!str_contains($operational, 'StorageMaintenanceService())->run')
        && !str_contains($operational, 'SET payload_json="{}"')
        && str_contains($operational, 'TechnicalRetentionCliService')
        && str_contains($retention, 'RetentionPolicyService())->runDatasetStep')
        && str_contains($retention, 'min(500, $limit)'),
        'El cron solo puede retirar telemetría mediante archivo, checksum, rollup y lote cercado.');
    $assert(!str_contains($operational, 'MaintenanceStepCompactionService')
        && str_contains($maintenance, 'compactTerminalSteps'),
        'La bitácora debe compactarse al cerrar su sesión, nunca desde un cron silencioso.');
    $launcher = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $registry = (string) file_get_contents($root . '/app/Services/CronTaskDefinitionRegistry.php');
    $executor = (string) file_get_contents($root . '/app/Services/ManualQueueExecutionService.php');
    $journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
    $assert(!str_contains($launcher, "'catalog_description_cleanup'")
        && !str_contains($registry, "'catalog_description_cleanup'")
        && !str_contains($executor, "'catalog_description_cleanup'"),
        'La limpieza histórica de descripciones debe ejecutarse únicamente desde Saneamiento.');
    $assert(str_contains($journal, 'WHERE system_execution_run_id=? AND state="response_received"')
        && str_contains($journal, 'SET state="uncertain"')
        && !str_contains($journal, 'La respuesta fue recibida. Falta confirmar el cierre local.'),
        'Una respuesta no persistida no puede declararse aplicada durante la recuperación.');
    $assert(str_contains($maintenance, "'cold_archives'")
        && str_contains($maintenance, 'purgeExpired')
        && str_contains($maintenance, 'compactTerminalSteps'),
        'Archivos fríos vencidos y pasos técnicos deben sanearse dentro del flujo explícito.');
    $physical = (string) file_get_contents(
        $root . '/app/Services/PhysicalTableRecoveryService.php'
    );
    $assert(str_contains($physical, 'bulkRewriteRequiresRebuild')
        && str_contains($physical, 'payloadRewriteRequiresRebuild')
        && str_contains($physical, 'fragmentationRequiresRebuild')
        && str_contains($physical, 'legacy_notifications')
        && str_contains($physical, 'legacy_messages')
        && str_contains($physical, 'MAX(linked_at)')
        && str_contains($physical, 'system_table_maintenance_history'),
        'Cada reescritura masiva debe habilitar una sola reconstrucción física comprobable.');
    $assert(!str_contains($migration, "('notifications.legacy_normalization_auto_enabled'")
        && !str_contains($migration, "('database_maintenance.step_compaction_auto_enabled'")
        && str_contains($migration, "DELETE FROM app_settings"),
        'La migración local debe retirar interruptores automáticos obsoletos, no conservar ruido.');
});

$test('2.26.2 cierra ejecutores genéricos y conserva selección exacta solo en CLI', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $legacy = new \App\Services\ManualQueueExecutionService();
    $closed = $legacy->execute([
        'queue_key' => 'orders_sync',
        'source_id' => '987654',
        'meli_account_id' => 321,
    ], microtime(true) + 30);
    $assert(($closed['status'] ?? '') === 'action_required'
        && ($closed['processed'] ?? -1) === 0
        && ($closed['reached_remote'] ?? true) === false,
        'El ejecutor heredado debe fallar cerrado sin tocar una cola ni transporte.');

    $legacySource = (string) file_get_contents($root . '/app/Services/ManualQueueExecutionService.php');
    $worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
    $adapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
    $saleFinancial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    $moduleRunner = (string) file_get_contents($root . '/app/Core/Modules/ModuleJobRunner.php');
    $assert(!str_contains($legacySource, '->processDue(')
        && !str_contains($legacySource, '->processNext(')
        && !str_contains($legacySource, '->syncNextActive('),
        'Ninguna compatibilidad manual puede elegir el siguiente recurso genérico.');
    $assert(str_contains($worker, '->forQueue((string) $item[\'queue_key\'])')
        && str_contains($worker, '$adapter->processExact(')
        && str_contains($worker, '(string) $item[\'source_id\']')
        && !str_contains($worker, 'ManualQueueExecutionService'),
        'El worker debe resolver el adaptador por queue_key y entregar exactamente el source_id congelado.');
    $assert(!str_contains($adapter, 'processDue(')
        && !str_contains($adapter, 'processNext(')
        && str_contains($adapter, "'orders_sync' => (new SyncQueueService())->processOne(\$id, \$accountId, 1, false)")
        && str_contains($adapter, "'catalog_descriptions' => (new CatalogDescriptionJobService())->processExactItem("),
        'Los adaptadores dirigidos deben llamar exclusivamente métodos exactos.');
    $assert(!str_contains($saleFinancial, 'enqueueDailyDue')
        && str_contains($saleFinancial, 'input_version')
        && str_contains($saleFinancial, 'count($externalOrderIds) > 60'),
        'Una conciliacion financiera exacta debe estar versionada y no recapturarse por antiguedad.');
    $assert(str_contains($moduleRunner, 'return $jobId === null ? $this->claim($pdo, $registry) : null;'),
        'Un módulo exacto deshabilitado no puede caer al selector genérico de otro job.');

    $registry = new \App\Services\ManualCampaignAdapterRegistry();
    $unsupported = $registry->forQueue('questions');
    $assert($unsupported instanceof \App\Services\ManualCampaignAdapter
        && !$unsupported->supportsExact(),
        'Una cola sin identidad aislada debe permanecer fuera de campañas dirigidas.');
    $invalid = $registry->forQueue('orders_sync');
    $assert($invalid instanceof \App\Services\ManualCampaignAdapter
        && !$invalid->inspect('otro-recurso', 0)->eligible,
        'Un source_id no certificado debe rechazarse antes de abrir la base o una cola.');

    $retiredMethods = [
        [\App\Controllers\SyncController::class, 'assistedStepJson'],
        [\App\Controllers\FinancialRecalcController::class, 'assistedStep'],
        [\App\Controllers\NotificationController::class, 'automationAssistedStep'],
    ];
    foreach ($retiredMethods as [$class, $method]) {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
        $methodSource = implode("\n", array_slice(
            is_array($lines) ? $lines : [],
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
        $assert(str_contains($methodSource, '410')
            && !str_contains($methodSource, 'MeliApiClient')
            && !str_contains($methodSource, 'processDue(')
            && !str_contains($methodSource, 'processExact('),
            $class . '::' . $method . ' debe permanecer retirado y sin transporte.');
    }
});

$test('2.26.2 genera documentación API con la versión instalada', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $knowledge = (string) file_get_contents(
        $root . '/app/Services/MeliApiKnowledgeService.php'
    );
    $questions = (string) file_get_contents(
        $root . '/app/Views/sales/questions/index.php'
    );

    $assert(!str_contains($knowledge, 'ERP Meli 2.9.0')
        && str_contains($knowledge, 'renderEndpointMatrix($version')
        && str_contains($knowledge, 'renderChecklist($version'),
        'Los reportes API no deben rotular una versión histórica fija.');
    $assert(!str_contains($questions, 'ERP Meli 2.4'),
        'Las ayudas operativas no deben conservar números de versión obsoletos.');
});

$test('2.25.5 automatiza ventas finanzas y publicaciones con ritmo persistente', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/130_commercial_ml_data_pipeline_2_25_5.sql');
    $pacing = (string) file_get_contents($root . '/app/Services/ApiPacingService.php');
    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $financial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    $notifications = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
    $repair = (string) file_get_contents($root . '/app/Services/SalesAuditExactRepairService.php');
    $items = (string) file_get_contents($root . '/app/Services/MeliItemSyncJobService.php');
    $reviews = (string) file_get_contents($root . '/app/Services/MeliProductUpdateReviewService.php');
    $reviewController = (string) file_get_contents($root . '/app/Controllers/MeliProductReviewController.php');
    $reviewListView = (string) file_get_contents($root . '/app/Views/products/meli/reviews.php');
    $reviewDetailView = (string) file_get_contents($root . '/app/Views/products/meli/review_show.php');
    $availability = (string) file_get_contents($root . '/app/Services/CronWorkAvailabilityService.php');
    $salesController = (string) file_get_contents($root . '/app/Controllers/SaleController.php');
    $salesStatus = (string) file_get_contents($root . '/app/Services/SalesImportStatusService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $salesView = (string) file_get_contents($root . '/app/Views/sales/index.php');

    foreach ([
        'awaiting_remote',
        'remote_pending_since',
        'retry_until',
        'api_request_pacing_state',
        'cursor_expires_at',
        'cursor_restart_count',
        'company_id BIGINT UNSIGNED NULL',
        'items.hybrid_bulk_updates_enabled',
        'api.pacing.ceiling_rpm',
        'sales_financial.remote_retry_days',
        '2.25.5',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migracion 130 debe incluir ' . $needle);
    }
    $assert(str_contains($client, '(new ApiPacingService())->reserve')
        && strpos($client, '(new ApiPacingService())->reserve') < strpos($client, '$budget->reserve'),
        'MeliApiClient debe obtener pacing antes de consumir presupuesto preventivo.');
    $assert(str_contains($pacing, 'max(1, min(59')
        && str_contains($pacing, 'FOR UPDATE')
        && str_contains($pacing, 'CronDeadlineContext::remainingSeconds')
        && str_contains($pacing, "\$pdo->rollBack();\n                return ['wait_us' => \$waitUs, 'reserved' => false]"),
        'El pacing debe limitar 1-59, persistir turnos y no mover un turno que no cabe en la ventana CLI.');
    $assert(str_contains($financial, 'function queueFromOrderId')
        && str_contains($financial, 'function queueFromOrderIds')
        && str_contains($financial, 'input_version')
        && str_contains($financial, 'SaleFinancialStateService')
        && !str_contains($financial, 'enqueueDailyDue'),
        'La conciliacion comercial debe converger por venta y version sin recaptura diaria.');
    $assert(str_contains($notifications, 'queueFromOrderId(')
        && str_contains($notifications, "'notification'")
        && str_contains($repair, "queueFromOrderIds("),
        'Notificaciones y reparaciones deben converger en SaleFinancialService.');
    $assert(str_contains($availability, '"pending","retry","awaiting_remote"'),
        'El CRON debe reconocer conciliaciones en espera remota.');
    $assert(str_contains($items, 'cursor_expires_at')
        && str_contains($items, 'cursor_restart_count')
        && str_contains($items, 'scroll_cursor_ttl_seconds')
        && str_contains($items, 'items.hybrid_bulk_updates_enabled')
        && str_contains($items, 'ingestDiscoveredSnapshot')
        && strpos($items, 'ingestDiscoveredSnapshot') < strpos($items, '->persistApprovedItem($detail'),
        'La importacion de publicaciones debe recuperar cursores vencidos y proteger identidad sensible bajo feature flag.');
    $assert(str_contains($reviews, 'items.hybrid_notification_updates_enabled')
        && str_contains($reviews, 'applySafeNotificationReview')
        && str_contains($reviews, 'ingestDiscoveredSnapshot')
        && str_contains($reviews, 'catalog_product_id')
        && str_contains($reviews, 'r.company_id'),
        'Las notificaciones de publicaciones deben ser hibridas y aislar revisiones por empresa.');
    $assert(str_contains($routes, '/products/meli/reviews')
        && str_contains($routes, '/products/meli/reviews/approve')
        && str_contains($routes, '/products/meli/reviews/reject')
        && str_contains($routes, '/products/meli/reviews/apply')
        && str_contains($reviewController, "Auth::requireRole('admin', 'operador')")
        && str_contains($reviewController, 'Csrf::validate')
        && str_contains($reviewController, 'MeliProductUpdateReviewService')
        && str_contains($reviewListView, 'Cambios de identidad comercial')
        && str_contains($reviewDetailView, 'Aplicar aprobadas'),
        'La revision hibrida debe ser visible, autenticada, protegida por CSRF y aislada por empresa/cuenta.');
    $assert(str_contains($salesController, '$service->checkMonth(')
        && str_contains($salesStatus, 'date_created_local_date')
        && str_contains($salesStatus, 'JOIN meli_accounts stage_account'),
        'La ventana disponible debe preparar meses exactos y medir por fecha local y empresa derivada de la cuenta.');
    $assert(str_contains($routes, '/sales/import-available')
        && str_contains($salesView, 'Importar todo lo disponible')
        && str_contains($salesView, 'financial_status')
        && str_contains($salesView, '$visiblePages'),
        'Ventas debe ofrecer ventana disponible, filtro financiero y paginacion compacta.');
});

$test('2.25.10 certifica la recuperación sin bucles ni pérdida concurrente de webhooks', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/133_safe_boot_direct_updater_2_25_8.sql');
    $hardeningMigration = (string) file_get_contents($root . '/database/migrations/134_recovery_hardening_2_25_9.sql');
    $certificationMigration = (string) file_get_contents($root . '/database/migrations/135_recovery_certification_2_25_10.sql');
    $bootstrap = (string) file_get_contents($root . '/bootstrap.php');
    $database = (string) file_get_contents($root . '/app/Core/Database.php');
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    $frontController = (string) file_get_contents($root . '/public/index.php');
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $webhook = (string) file_get_contents($root . '/app/Services/WebhookService.php');
    $webhookSpool = (string) file_get_contents($root . '/app/Services/WebhookSpoolService.php');
    $transport = (string) file_get_contents($root . '/app/Services/CurlMeliHttpTransport.php');
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $manualView = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');

    $assert(version_compare($version, '2.25.10', '>=') && ($manifest['version'] ?? '') === $version, 'VERSION y manifiesto deben coincidir.');
    $assert(preg_match('/^(?:135|13[6-9]|1[4-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1, 'El manifiesto debe exigir al menos la migración 135.');
    foreach (['runtime.web_connect_timeout_seconds', 'runtime.direct_updater_required', 'runtime.nonblocking_file_telemetry', '2.25.8'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 133 debe registrar: ' . $needle);
    }
    foreach (['runtime.recovery_reauthentication_seconds', 'runtime.pending_update_gate_enabled', 'runtime.legacy_jobs_emergency_guard', '2.25.9'] as $needle) {
        $assert(str_contains($hardeningMigration, $needle), 'Migración 134 debe registrar: ' . $needle);
    }
    foreach (['runtime.recovery_stop_on_migration_error', 'notifications.spool_atomic_replay', 'runtime.update_json_conflict_enabled', '2.25.10'] as $needle) {
        $assert(str_contains($certificationMigration, $needle), 'Migración 135 debe registrar: ' . $needle);
    }
    foreach (['index.php', 'login.php', 'actualizar.php', '.htaccess', 'PAUSE_MELI_API'] as $file) {
        $assert(is_file($root . '/' . $file), 'La recuperación directa requiere ' . $file);
    }
    $rootHtaccess = (string) file_get_contents($root . '/.htaccess');
    $assert(str_contains($rootHtaccess, 'DirectoryIndex index.php')
        && str_contains($rootHtaccess, 'bootstrap\\.php')
        && str_contains($rootHtaccess, 'resources|storage'),
        'La raíz debe abrir index.php y negar bootstrap, recursos internos y storage.');
    $assert(!str_contains($bootstrap, 'AppSettingsService') && !str_contains($bootstrap, 'PerformanceMonitorService'),
        'Bootstrap no debe consultar settings ni escribir métricas en MariaDB.');
    $assert(str_contains($database, 'DB_WEB_CONNECT_TIMEOUT_SECONDS') && str_contains($database, 'PDO::ATTR_TIMEOUT'),
        'La conexión web debe fallar dentro de un tiempo acotado.');
    $assert(str_contains($database, 'PDO::ATTR_PERSISTENT => false'),
        'La recuperación no debe heredar conexiones persistentes atascadas.');
    foreach (['RequestContextService', 'WebhookService', 'ApiHealthAlertService'] as $service) {
        $assert(!str_contains($layout, 'new ' . $service), 'El layout no debe ejecutar ' . $service . ' de forma síncrona.');
    }
    $assert(str_contains($recovery, 'new Migrator') && str_contains($recovery, '->run(1)')
        && str_contains($recovery, 'InstalledVersionMarkerService')
        && str_contains($recovery, 'authorizeMigration')
        && str_contains($recovery, 'recoveryLoginRateLimited')
        && str_contains($recovery, 'missing_origin')
        && str_contains($recovery, "Session::forget('_recovery_authorized_at')")
        && str_contains($recovery, "Session::closeReadOnly();")
        && str_contains($recovery, 'backup_choice')
        && str_contains($recovery, 'Continuar sin respaldo interno')
        && str_contains($recovery, 'BackupCenterService')
        && str_contains($recovery, 'check_backup')
        && str_contains($recovery, 'recover_backup')
        && str_contains($recovery, 'cancel_backup')
        && str_contains($recovery, 'cleanup_backup')
        && str_contains($recovery, 'backupFailureMessage')
        && str_contains($recovery, 'restart_login')
        && str_contains($recovery, 'administrator_session_stale')
        && !str_contains($recovery, 'UpdateBackupService')
        && !str_contains($recovery, 'createDirect()')
        && !str_contains($recovery, 'MeliApiClient'),
        'El actualizador directo debe revalidar, soltar la sesión, preparar/recuperar/cancelar respaldo reanudable por CLI e instalar un paso sin transporte remoto.');
    $assert(str_contains($migrator, 'SELECT * FROM system_update_migrations')
        && substr_count($migrator, "trace?->event('migration_selected'") === 1
        && !str_contains($migrator, "trace?->event('migration_skipped'"),
        'El migrador debe precargar metadata y no registrar cientos de migraciones ya aplicadas.');
    $assert(str_contains($frontController, 'InstalledVersionMarkerService')
        && str_contains($frontController, 'update_required')
        && str_contains($frontController, '/actualizar.php'),
        'Toda sesión autenticada debe pasar por la puerta ligera de actualización.');
    $assert(strpos($cron, '_automation_emergency_stop.php') < strpos($cron, "require __DIR__ . '/_bootstrap.php'"),
        'Cron debe evaluar la parada de automatización antes del bootstrap y PDO.');
    $assert(str_contains($cron, 'remote=false database=false'), 'La parada de automatización debe confirmar que no abrió la base.');
    $assert(str_contains($webhook, "Env::get('WEBHOOK_MAX_PAYLOAD_BYTES'")
        && str_contains($webhook, "Env::bool('WEBHOOK_SPOOL_ENABLED'"),
        'El webhook debe decidir el spool sin consultar app_settings.');
    $assert(str_contains($webhookSpool, "Env::get('WEBHOOK_SPOOL_MAX_BYTES'")
        && str_contains($webhookSpool, "Env::get('WEBHOOK_SPOOL_FILE_MAX_BYTES'")
        && str_contains($webhookSpool, "flock(\$lock, LOCK_EX)")
        && str_contains($webhookSpool, 'processing-')
        && str_contains($webhookSpool, 'recoverAbandonedClaims')
        && str_contains($webhookSpool, 'restoreRemaining'),
        'El spool degradado debe tener límites, claim atómico, recuperación y restauración sin sobrescribir entradas concurrentes.');
    $assert(strpos($transport, 'MeliEmergencyStopService') < strpos($transport, 'curl_init()'),
        'El transporte debe aplicar una última parada de emergencia antes de abrir cURL.');
    $assert(str_contains($settings, "\$session['display_state'] = 'maintenance'")
        && str_contains($settings, 'No se iniciarán consultas y no se perdió progreso.')
        && str_contains($manualView, 'Procesamiento en mantenimiento')
        && str_contains($manualView, 'empty($emergencyStop)'),
        'Las campañas deben conservarse sin ofrecer inicio, progreso o ETA durante la parada.');
    foreach (['refresh_meli_tokens.php', 'sync_orders.php', 'sync_shipments.php', 'sync_payments.php', 'process_order_financials.php'] as $jobFile) {
        $job = (string) file_get_contents($root . '/jobs/' . $jobFile);
        $retired = str_contains($job, '_retired_job.php') && str_contains($job, 'erp_retired_job(');
        $guarded = strpos($job, '_meli_emergency_stop.php') !== false
            && strpos($job, "_bootstrap.php") !== false
            && strpos($job, '_meli_emergency_stop.php') < strpos($job, "_bootstrap.php");
        $assert($retired || $guarded,
            $jobFile . ' debe detenerse antes de cargar bootstrap o MariaDB.');
    }
    foreach (['cron_probe.php', 'cleanup_raw.php', 'sync_monthly_report.php', 'process_updates.php'] as $jobFile) {
        $job = (string) file_get_contents($root . '/jobs/' . $jobFile);
        $bootstrapAt = strpos($job, 'bootstrap.php');
        $guardAt = strpos($job, '_meli_emergency_stop.php');
        $retired = str_contains($job, '_retired_job.php') && str_contains($job, 'erp_retired_job(');
        $assert($retired || ($guardAt !== false && $bootstrapAt !== false && $guardAt < $bootstrapAt),
            $jobFile . ' debe permanecer inactivo antes de abrir el ERP durante la emergencia.');
    }
});

$test('2.25.11 recupera navegación y evita ráfagas de lecturas web', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents($root . '/database/migrations/136_web_navigation_recovery_2_25_11.sql');
    $schema = (string) file_get_contents($root . '/app/Services/SchemaInspectorService.php');
    $schemaGateway = (string) file_get_contents($root . '/app/Services/InformationSchemaGateway.php');
    $salesContract = (string) file_get_contents($root . '/app/Services/SalesControlSchemaContractService.php');
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $dashboard = (string) file_get_contents($root . '/app/Controllers/DashboardController.php');
    $performance = (string) file_get_contents($root . '/app/Controllers/PerformanceController.php');
    $shell = (string) file_get_contents($root . '/app/Controllers/ShellController.php');
    $appJs = (string) file_get_contents($root . '/public/assets/app.js');
    $performanceJs = (string) file_get_contents($root . '/public/assets/performance.js');

    $assert(version_compare($version, '2.25.11', '>=')
        && ($manifest['version'] ?? '') === $version,
        'La versión instalada debe conservar 2.25.11 o una sucesora y coincidir con el manifiesto.');
    $assert(preg_match('/^(?:13[6-9]|1[4-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 136 o una sucesora.');
    foreach (['runtime.shell_requests_sequential', 'runtime.async_sections_sequential', 'runtime.performance_metrics_file_only', '2.25.11'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 136 debe registrar: ' . $needle);
    }
    $assert(str_contains($schemaGateway, 'columnNamesForTables')
        && str_contains($schema, 'missingRequirements')
        && str_contains($salesContract, 'missingRequirements(self::REQUIRED)'),
        'Los contratos de esquema deben resolverse en una lectura por lote.');
    $assert(!str_contains(substr($settings, strpos($settings, 'public function index(): void'), 1400), 'DiagnosticService())->summary()')
        && str_contains($settings, 'Estado disponible en Automatización'),
        'El Centro de configuración no debe ejecutar el diagnóstico profundo antes de renderizar.');
    $assert(str_contains($dashboard, 'SystemSafetyStatusService')
        && str_contains($dashboard, "'maintenance' => true"),
        'Salud del Panel debe degradar de inmediato durante la parada API.');
    $assert(str_contains($performance, 'RequestPerformanceFileLogger')
        && !str_contains($performance, 'PerformanceMonitorService'),
        'La telemetría del navegador no debe insertar métricas en MariaDB.');
    $assert(str_contains($shell, 'SystemSafetyStatusService')
        && str_contains($shell, 'Las consultas remotas están bloqueadas.'),
        'El estado del shell debe responder desde la parada local sin consultar Salud API ni notificaciones.');
    $assert((str_contains($appJs, 'loadShellContext().finally(loadShellStatus)')
            || str_contains($appJs, 'loadShellSnapshot()'))
        && str_contains($performanceJs, 'Math.min(2, initialSections.length)')
        && str_contains($performanceJs, 'Promise.all'),
        'Las lecturas auxiliares deben ser estables y las secciones progresivas usar máximo dos solicitudes.');
});

$test('2.25.14 recupera el freno de mano y unifica los estados detenidos', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $kernel = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $stop = (string) file_get_contents($root . '/stop.php');
    $health = (string) file_get_contents($root . '/app/Views/settings/api_health.php')
        . (string) file_get_contents($root . '/app/Services/ApiHealthStatusPresenter.php');
    $sales = (string) file_get_contents($root . '/app/Services/SalesControlService.php')
        . (string) file_get_contents($root . '/app/Views/sales_control/index.php')
        . (string) file_get_contents($root . '/app/Views/sales_control/month.php');
    $migration = (string) file_get_contents(
        $root . '/database/migrations/139_emergency_panel_state_consistency_2_25_14.sql'
    );

    $assert(str_contains($kernel, 'ENT_QUOTES | ENT_SUBSTITUTE')
        && str_contains($kernel, 'htmlspecialchars'),
        'El panel de emergencia debe disponer de un escape HTML único y tolerante.');
    $assert((str_contains($stop, 'catch (Throwable') || str_contains($stop, 'catch (\\Throwable'))
        && str_contains($stop, 'PAUSE_MELI_API')
        && str_contains($stop, 'PAUSE_ERP_AUTOMATION')
        && !str_contains($stop, 'Database::'),
        'El capturador superior debe funcionar sin base y mostrar las dos paradas.');
    $assert(str_contains($health, 'Mercado Libre bloqueado por mantenimiento')
        && str_contains($health, 'Abrir freno de mano')
        && str_contains($health, 'if (!$apiStopped)'),
        'Salud API no debe ofrecer pausar ni declarar habilitada una API ya detenida.');
    $assert(str_contains($sales, "'waiting_automation'")
        && str_contains($sales, "'Por comprobar'")
        && str_contains($sales, "'missing_confirmed'"),
        'Control de ventas debe distinguir trabajo preparado y métricas confirmadas.');
    $assert(str_contains($migration, '2.25.14')
        && str_contains($migration, 'sales_control.unknown_values_as_zero'),
        'La migración 139 debe registrar los defaults de consistencia.');
});

$test('2.25.15 pagina ventas y difiere lecturas operativas pesadas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $schema = (string) file_get_contents($root . '/app/Services/InformationSchemaGateway.php');
    $diagnostic = (string) file_get_contents($root . '/app/Services/MigrationDiagnosticService.php');
    $modules = (string) file_get_contents($root . '/app/Core/Modules/ModuleHealthService.php');
    $moduleMigrations = (string) file_get_contents($root . '/app/Core/Modules/ModuleMigrationRunner.php');
    $sales = (string) file_get_contents($root . '/app/Services/SaleReadService.php');
    $settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $routes = (string) file_get_contents($root . '/public/index.php');

    $assert(version_compare($version, '2.25.15', '>=')
        && ($manifest['version'] ?? '') === $version
        && preg_match('/^(?:14[0-9]|1[5-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'Versión, manifiesto y migración mínima deben coincidir.');
    $assert(str_contains($schema, 'tablesExist')
        && str_contains($schema, 'indexesForTables')
        && substr_count($schema, 'public function tableCollations') === 1,
        'InformationSchemaGateway debe ofrecer lecturas agrupadas por categoría.');
    $assert(str_contains($diagnostic, '$schema = new InformationSchemaGateway($pdo)')
        && str_contains($diagnostic, 'indexesForTables')
        && !str_contains($diagnostic, 'private function tableExists'),
        'El diagnóstico debe reutilizar un gateway y eliminar el patrón N+1.');
    $assert(str_contains($moduleMigrations, 'pendingAll')
        && str_contains($modules, 'pendingAll')
        && str_contains($modules, 'getMany')
        && str_contains($modules, 'includeLiveChecks'),
        'Módulos debe cargar estados, settings y migraciones una vez.');
    $assert(str_contains($sales, 'Primera fase: paginar identidades de venta')
        && str_contains($sales, '$identityPredicates')
        && str_contains($sales, 'sales-list-count')
        && strpos($sales, 'LIMIT \' . $perPage') < strpos($sales, 'LEFT JOIN meli_order_items'),
        'Ventas debe limitar identidades antes de agregar ítems.');
    foreach ([
        '/settings/cron/section.json',
        '/settings/diagnostics/section.json',
        '/sales/import-status.json',
    ] as $route) {
        $assert(str_contains($routes, $route), 'Falta la ruta progresiva ' . $route . '.');
    }
    $assert(str_contains($settings, "View::render('settings/cron_shell'")
        && str_contains($settings, "View::render('settings/diagnostics_shell'")
        && str_contains($settings, "'system-diagnostic'")
        && str_contains($settings, '60,'),
        'Cron y Diagnóstico deben renderizar primero shells ligeros y cachear el diagnóstico.');
});

$test('2.25.16 crea copias cifradas por CLI sin transporte remoto', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/141_backup_recovery_center_2_25_16.sql');
    $archive = (string) file_get_contents($root . '/app/Services/BackupArchiveService.php');
    $center = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/BackupController.php');
    $worker = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    foreach (['system_backup_archives','system_backup_jobs','system_backup_table_checks','system_backup_download_grants','2.25.16'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 141 incompleta: ' . $needle . '.');
    }
    $assert(str_contains($archive, 'ERP-MELI-BACKUP-2')
        && str_contains($archive, 'sodium_crypto_secretstream_xchacha20poly1305')
        && str_contains($archive, 'manifest_sha256')
        && str_contains($archive, 'database-snapshot-active.json'),
        'La copia v2 debe quedar cifrada, autenticada y protegida por snapshot.');
    $assert(str_contains($center, 'processRequested')
        && str_contains($center, 'queueVerification')
        && str_contains($controller, 'BackupCenterService())->enqueue(')
        && !str_contains($controller, 'BackupArchiveService())->create'),
        'El navegador debe encolar y el servicio CLI debe procesar la copia.');
    $assert(str_contains($worker, 'ERP_LOCAL_MAINTENANCE_ONLY')
        && str_contains($worker, 'remote=false'),
        'El lanzador único debe ofrecer un carril estrictamente local.');
});

$test('2.26.9 recupera, cancela y vincula copias sin desbloquear transporte', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents(
        $root . '/database/migrations/157_backup_lifecycle_sanitation_bridge_2_26_9.sql'
    );
    $center = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
    $coordinator = (string) file_get_contents(
        $root . '/app/Services/LocalMaintenanceCoordinator.php'
    );
    $maintenance = (string) file_get_contents(
        $root . '/app/Services/DatabaseMaintenanceService.php'
    );
    $launcher = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    foreach ([
        "'cleanup'",
        'delete_requested_at',
        'context_type',
        'idx_backup_jobs_available',
        'idx_backup_archives_context',
        "'2.26.9'",
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 157 incompleta: ' . $needle);
    }
    $assert(str_contains($center, 'deleteBeforeLifecycleMigration')
        && str_contains($center, 'processDeletionCleanup')
        && str_contains($center, 'bindMaintenanceContext')
        && str_contains($center, "status='cancelled'")
        && str_contains($center, "job_type='cleanup'"),
        'La copia debe cancelarse, cercarse, limpiarse por CLI y fijarse a saneamiento.');
    $assert(str_contains($coordinator, 'local-maintenance-state.json')
        && str_contains($coordinator, "'deleting'")
        && str_contains($coordinator, 'backup_cleanup_pending')
        && str_contains($launcher, 'reconcileBackup'),
        'El coordinador único debe recuperar también el cleanup y liberar solo su marcador.');
    $assert(str_contains($maintenance, 'bindVerifiedBackup')
        && str_contains($maintenance, 'backup_binding')
        && str_contains($maintenance, 'La copia vinculada cambió'),
        'Saneamiento debe conservar exactamente la copia verificada de su análisis.');
    $assert(str_contains($routes, '/settings/backups/recover')
        && str_contains($routes, '/settings/backups/cancel')
        && str_contains($launcher, 'remote=false'),
        'Recuperar y cancelar deben ser controles web; todo lote continuará por CLI local.');
});

$test('2.25.17 restaura solo en base nueva y sanitiza el clon diagnóstico', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/142_safe_restore_diagnostic_clone_2_25_17.sql');
    $restore = (string) file_get_contents($root . '/app/Services/RestoreService.php');
    $switch = (string) file_get_contents($root . '/app/Services/RestoreConfigSwitchService.php');
    $recovery = (string) file_get_contents($root . '/app/Recovery/RestoreRecoveryKernel.php');
    foreach (['system_restore_plans','system_restore_checks','system_restore_switches','diagnostic_clone','2.25.17'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 142 incompleta: ' . $needle . '.');
    }
    $assert(str_contains($restore, 'La restauración nunca puede usar la base activa')
        && str_contains($restore, "status='desconectado'")
        && str_contains($restore, "'meli_tokens', 'meli_oauth_states'")
        && str_contains($restore, 'gzip_offset')
        && str_contains($restore, 'targetDataHash')
        && str_contains($restore, 'archive_verified')
        && str_contains($restore, 'Migrator('),
        'La restauración debe ser reanudable, verificar hashes, migrar una base nueva e invalidar OAuth en clones.');
    $assert(str_contains($switch, 'config.env.next') || str_contains($switch, "\$configPath . '.next'")
        && str_contains($switch, 'Crypto::encrypt')
        && str_contains($switch, 'rollbackLatest'),
        'El cambio debe ser atómico, conservar configuración cifrada y admitir rollback.');
    $assert(str_contains($recovery, 'EmergencyControlService')
        && str_contains($recovery, 'RestoreConfigSwitchService')
        && !str_contains($recovery, 'DashboardController')
        && !str_contains($recovery, 'ModuleKernel')
        && !str_contains($recovery, 'Database::connection'),
        'recuperar.php debe usar un kernel mínimo independiente.');
});

$test('2.25.18 mide crecimiento, productores y percentiles sin conservar SQL', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/143_database_growth_observability_2_25_18.sql');
    $collector = (string) file_get_contents($root . '/app/Services/FileQueryPerformanceCollector.php');
    $report = (string) file_get_contents($root . '/app/Services/QueryPerformanceReportService.php');
    $inventory = (string) file_get_contents($root . '/app/Services/RuntimeProcessInventoryService.php');
    foreach (['system_database_growth_snapshots','system_storage_producer_catalog','system_query_performance_rollups','2.25.18'] as $needle) {
        $assert(str_contains($migration, $needle), 'La observabilidad debe incluir ' . $needle . '.');
    }
    $assert(str_contains($collector, 'SHOW SESSION STATUS')
        && str_contains($collector, 'route_hash')
        && !str_contains($collector, "'sql'")
        && !str_contains($collector, "'parameters'"),
        'El perfilador debe conservar métricas sanitizadas, no consultas ni parámetros.');
    foreach (['p50_ms','p95_ms','p99_ms','rows_read','disk_temporary_tables'] as $needle) {
        $assert(str_contains($report, $needle), 'El reporte reproducible debe incluir ' . $needle . '.');
    }
    $assert(str_contains($inventory, 'active_launcher_count')
        && str_contains($inventory, 'legacy_assisted'),
        'El inventario debe localizar lanzadores y navegadores asistidos residuales.');
});

$test('2.25.19 archiva antes de retener y externaliza un solo payload privado', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/144_storage_retention_payload_archive_2_25_19.sql');
    $retention = (string) file_get_contents($root . '/app/Services/RetentionPolicyService.php');
    $archive = (string) file_get_contents($root . '/app/Services/ColdArchiveService.php');
    $payloads = (string) file_get_contents($root . '/app/Services/FileRemotePayloadStore.php');
    $webhook = (string) file_get_contents($root . '/public/webhook_mercadolibre.php');
    foreach (['system_cold_archives','remote_payload_objects','remote_payload_references','retention.success_days','retention.incident_days','2.25.19'] as $needle) {
        $assert(str_contains($migration, $needle), 'La retención debe incluir ' . $needle . '.');
    }
    $assert(str_contains($retention, 'verified_at IS NOT NULL')
        && str_contains($retention, 'rollup_verified_at IS NOT NULL')
        && str_contains($retention, 'oldestImmutableFinancialMonth'),
        'Una fila solo puede retirarse después de archivo y resumen, y los jobs deben ser inmutables.');
    $assert(str_contains($archive, 'PrivateArchiveCipher')
        && str_contains($archive, 'content_sha256')
        && str_contains($payloads, 'reference_count')
        && str_contains($payloads, 'purgeOrphans'),
        'Los archivos deben cifrarse/verificarse y los payloads deduplicados no deben quedar huérfanos.');
    $assert(str_contains($webhook, 'WebhookSpoolService')
        && !str_contains($webhook, 'Database::')
        && !str_contains($webhook, 'WebhookService'),
        'El receptor público debe confirmar el spool antes de abrir MariaDB.');
});

$test('2.25.20 pagina ventas antes de agregar y retira ejecutores web', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/145_web_sql_runtime_consolidation_2_25_20.sql');
    $sales = (string) file_get_contents($root . '/app/Services/SaleReadService.php');
    $appJs = (string) file_get_contents($root . '/public/assets/app.js');
    $catalogJs = (string) file_get_contents($root . '/public/assets/catalog.js');
    $catalogController = (string) file_get_contents($root . '/app/Controllers/CatalogDescriptionJobController.php');
    $assert(str_contains($migration, 'sale_identity') && str_contains($migration, '2.25.20'),
        'La migración debe indexar la identidad visible de venta.');
    $assert(str_contains($sales, 'Primera fase: paginar identidades de venta')
        && strpos($sales, 'LIMIT \' . $perPage') < strpos($sales, 'LEFT JOIN meli_order_items')
        && !str_contains($sales, 'raw_json'),
        'Ventas debe limitar identidades antes de leer sus relaciones y evitar payloads crudos.');
    $assert(!str_contains($appJs, 'new Worker')
        && !str_contains($appJs, '/interactive/step')
        && !str_contains($catalogJs, 'stepUrl')
        && !str_contains($catalogJs, 'data-catalog-description-monitor'),
        'El navegador no debe mantener ningún ejecutor asistido.');
    $step = substr($catalogController, strpos($catalogController, 'public function step'), 700);
    $assert(str_contains($step, '410') && !str_contains($step, 'processDue('),
        'La ruta histórica de descripciones debe quedar retirada.');
});

$test('2.26.0 deja un lanzador, checkpoints y recuperación física explícita', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/146_runtime_consolidation_physical_recovery_2_26_0.sql');
    $preserver = (string) file_get_contents($root . '/app/Services/HistoricalMigrationDataPreserver.php');
    $recovery = (string) file_get_contents($root . '/app/Services/PhysicalTableRecoveryService.php');
    $inventory = (new \App\Services\RuntimeProcessInventoryService())->inspect();
    foreach (['system_table_maintenance_history','financial_job_items','single_launcher','2.26.0'] as $needle) {
        $assert(str_contains($migration, $needle), 'La consolidación debe incluir ' . $needle . '.');
    }
    $assert(
        hash_equals(
            '1d4afd69e8253debfd634c6875cf5b2c71df1d459f45563ec6374219f1d7a312',
            hash('sha256', $migration)
        )
        && str_contains($preserver, 'system_retention_runs_preserved_2265')
        && str_contains($preserver, 'RENAME TABLE')
        && str_contains($preserver, 'INSERT IGNORE INTO'),
        'La consolidación debe conservar la migración publicada y preservar sus datos alrededor de ella.'
    );
    $assert(($inventory['active_launcher_count'] ?? -1) === 3
        && ($inventory['review_required_count'] ?? -1) === 0,
        'Durante rollback deben existir V2 y los dos lanzadores independientes V3.');
    $assert(str_contains($recovery, 'MeliEmergencyStopService')
        && str_contains($recovery, 'automationStopped')
        && str_contains($recovery, 'disk_free_space')
        && str_contains($recovery, 'payloads_externalized')
        && str_contains($recovery, 'OPTIMIZE TABLE'),
        'La reconstrucción debe ser explícita, detenida y comprobar espacio y payloads trasladados.');
});

$test('2.26.1 separa retención terminal e incidentes sin bloquear colas activas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $migration = (string) file_get_contents(
        $root . '/database/migrations/147_notification_retention_partition_2_26_1.sql'
    );
    $retention = (string) file_get_contents($root . '/app/Services/RetentionPolicyService.php');
    $archive = (string) file_get_contents($root . '/app/Services/ColdArchiveService.php');
    foreach (['notification_success', 'notification_incidents'] as $needle) {
        $assert(
            str_contains($migration, $needle)
            && str_contains($retention, $needle)
            && str_contains($archive, $needle),
            'La retención 2.26.1 debe declarar y usar ' . $needle . '.'
        );
    }
    $assert(str_contains($migration, '2.26.1'), 'La migración debe registrar la versión 2.26.1.');
    $assert(
        str_contains($retention, '"received","queued","processing"') === false,
        'Los estados activos no deben formar parte de un archivo terminal.'
    );
});

$test('2.26.2 endurece contrato web, origin y lanzadores de release', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $router = (string) file_get_contents($root . '/app/Core/Router.php');
    $auth = (string) file_get_contents($root . '/app/Core/Auth.php');
    $cron = (string) file_get_contents($root . '/launcher/cron.php');
    $webhook = (string) file_get_contents($root . '/launcher/webhook.php');
    $maintenanceDirect = (string) file_get_contents(
        $root . '/app/Recovery/DatabaseMaintenanceRecoveryKernel.php'
    );

    foreach (['authorize($method, $metadata)', 'assertSameOrigin()', 'expectedOrigin()', 'Csrf::validate', 'Auth::requireLogin', 'permanent_admin'] as $needle) {
        $assert(str_contains($router, $needle), 'Router debe aplicar defensa central: ' . $needle . '.');
    }
    $assert(strpos($router, '$metadata = (array) ($route[\'metadata\'] ?? [])') !== false,
        'Las rutas dinámicas también deben conservar metadata de seguridad.');
    $assert(str_contains($auth, 'return $role === \'operator\' ? \'operador\' : $role;'),
        'Auth debe normalizar operator/operador para contratos modulares heredados.');
    foreach ([$cron, $webhook] as $launcher) {
        $assert(str_contains($launcher, "str_replace('\\\\', '/', (string) (\$pointer['path'] ?? ''))")
            && str_contains($launcher, "!in_array('..', explode('/', \$relative), true)")
            && str_contains($launcher, "realpath(\$installationRoot . '/releases')")
            && str_contains($launcher, 'str_starts_with(str_replace'),
            'Cada lanzador administrado debe confinar el puntero a releases/.');
    }
    $assert(str_contains($maintenanceDirect, 'name="idempotency_key"')
        && str_contains($maintenanceDirect, 'validateStepIdempotencyKey')
        && str_contains($maintenanceDirect, 'rotateStepIdempotencyKey')
        && !str_contains($maintenanceDirect, "'direct-' . bin2hex(random_bytes(16))\n                    );"),
        'El mantenimiento directo debe reenviar micro-pasos con una clave idempotente del formulario.');
});

$test('2.26.2 mantiene idempotencia de doble envio en mantenimiento directo', static function () use ($assert): void {
    $ref = new ReflectionClass(\App\Recovery\DatabaseMaintenanceRecoveryKernel::class);
    $kernel = $ref->newInstanceWithoutConstructor();
    $current = $ref->getMethod('currentStepIdempotencyKey');
    $validate = $ref->getMethod('validateStepIdempotencyKey');
    $rotate = $ref->getMethod('rotateStepIdempotencyKey');
    $previousSession = $_SESSION ?? [];
    try {
        unset(
            $_SESSION['maintenance_direct_step_key'],
            $_SESSION['maintenance_direct_previous_step_key']
        );
        $first = (string) $current->invoke($kernel);
        $assert(preg_match('/^direct-[A-Fa-f0-9]{32}$/', $first) === 1,
            'La clave directa debe tener formato acotado.');
        $assert((string) $current->invoke($kernel) === $first,
            'El formulario directo debe conservar la clave hasta aprobar el paso.');
        $assert((string) $validate->invoke($kernel, $first) === $first,
            'La clave actual debe ser aceptada.');
        $rotate->invoke($kernel, $first);
        $second = (string) $current->invoke($kernel);
        $assert($second !== $first, 'Después de aprobar, el siguiente paso necesita nueva clave.');
        $assert((string) $validate->invoke($kernel, $first) === $first,
            'El doble envio inmediato debe poder reusar la clave anterior.');
        $rotate->invoke($kernel, $first);
        $assert((string) $current->invoke($kernel) === $second,
            'Repetir la clave anterior no debe consumir la clave vigente.');
        $rotate->invoke($kernel, $second);
        $staleRejected = false;
        try {
            $validate->invoke($kernel, $first);
        } catch (ReflectionException $error) {
            throw $error;
        } catch (Throwable) {
            $staleRejected = true;
        }
        $assert($staleRejected, 'Una clave de dos pasos atras debe rechazarse.');
    } finally {
        $_SESSION = $previousSession;
    }
});

$test('2.26.2 separa el mes abierto y oculta reconstrucciones innecesarias', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $service = (string) file_get_contents(
        $root . '/app/Services/DatabaseMaintenanceService.php'
    );
    $view = (string) file_get_contents(
        $root . '/app/Views/settings/database_maintenance.php'
    );
    $assert(
        str_contains($service, "'open_period_deferred' => \$openPeriodDeferred")
        && str_contains($service, "'actionable_total' => \$actionableTotal")
        && str_contains(
            $service,
            'UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY'
        ),
        'El análisis debe separar filas del mes abierto de las que puede retirar ahora.'
    );
    $assert(
        str_contains($view, 'Filas listas ahora')
        && str_contains($view, 'se conservan hasta su cierre')
        && str_contains($view, '$eligibleRecoveryTables')
        && str_contains($view, 'No hay tablas que requieran reconstrucción.'),
        'La interfaz debe explicar la retención y ocultar reconstrucciones no elegibles.'
    );
});

$test('2.26.14 habilita saneamiento local por navegador sin Mercado Libre', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $controller = (string) file_get_contents(
        $root . '/app/Controllers/DatabaseMaintenanceController.php'
    );
    $service = (string) file_get_contents(
        $root . '/app/Services/DatabaseMaintenanceService.php'
    );
    $view = (string) file_get_contents(
        $root . '/app/Views/settings/database_maintenance.php'
    );
    $script = (string) file_get_contents($root . '/public/assets/performance.js');
    $maintenanceScript = substr(
        $script,
        (int) strpos($script, "document.querySelector('[data-database-maintenance]')")
    );
    $routes = (string) file_get_contents($root . '/public/index.php');

    $assert(
        str_contains($controller, 'runInteractiveStep')
        && !str_contains($controller, 'El saneamiento ya no modifica datos desde el navegador')
        && !str_contains($controller, 'http_response_code(410)'),
        'El saneamiento lógico debe volver a micro-pasos web locales y no responder 410.'
    );
    $assert(
        str_contains($service, 'public function runInteractiveStep')
        && str_contains($service, 'MaintenanceExecutionLock')
        && str_contains($service, 'claimCliStep')
        && str_contains($service, "'web-"),
        'El micro-paso web debe reutilizar el mismo lock, lease y fencing del motor local.'
    );
    $interactiveOffset = (int) strpos($service, 'public function runInteractiveStep');
    $interactiveMethod = substr($service, $interactiveOffset, 1800);
    $assert(
        !str_contains($interactiveMethod, "PHP_SAPI !== 'cli'")
        && !str_contains($interactiveMethod, 'El navegador no puede ejecutar saneamiento destructivo'),
        'El método interactivo no puede conservar un gate duro que impida los micro-lotes del navegador.'
    );
    $assert(
        str_contains($view, 'data-step-url')
        && str_contains($view, '/settings/database-maintenance/step')
        && str_contains($view, 'Mantenga esta pestaña abierta')
        && !str_contains($view, 'php jobs/process_sync_queue.php'),
        'La interfaz debe dirigir el saneamiento lógico por pestaña, sin comando cron en el flujo principal.'
    );
    $assert(
        str_contains($maintenanceScript, 'const stepUrl')
        && str_contains($maintenanceScript, "fetch(stepUrl")
        && str_contains($maintenanceScript, "method: 'POST'")
        && str_contains($maintenanceScript, "status.session"),
        'El monitor debe ejecutar un paso corto y refrescar el estado real.'
    );
    $assert(
        str_contains($routes, "/settings/database-maintenance/step")
        && str_contains($routes, '$maintenanceAnalyzeContinuation')
        && str_contains($routes, "\$pathEndsWith('/settings/database-maintenance/step')")
        && str_contains($routes, '&& !$maintenanceContinuation'),
        'El gate de snapshot debe permitir analizar y continuar saneamiento propietario.'
    );
});

$test('2.26.3 restablece datos importados sin ejecutar borrados desde HTTP', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $controller = (string) file_get_contents(
        $root . '/app/Controllers/ImportedMeliDataResetController.php'
    );
    $service = (string) file_get_contents(
        $root . '/app/Services/ImportedMeliDataResetService.php'
    );
    $job = (string) file_get_contents($root . '/jobs/reset_imported_meli_data.php');
    $launcher = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $freeze = (string) file_get_contents(
        $root . '/app/Services/DatabaseMutationFreezeService.php'
    );
    $metadata = (string) file_get_contents(
        $root . '/app/Repositories/RouteMetadataRepository.php'
    );
    foreach ([
        '/settings/imported-data-reset',
        '/settings/imported-data-reset/analyze',
        '/settings/imported-data-reset/authorize',
        '/settings/imported-data-reset/pause',
    ] as $route) {
        $assert(str_contains($routes, $route), 'Falta la ruta segura ' . $route . '.');
    }
    $assert(
        !str_contains($controller, 'DELETE FROM')
        && !str_contains($controller, 'TRUNCATE')
        && !str_contains($controller, 'MeliApiClient'),
        'El controlador web no puede borrar ni transportar datos.'
    );
    foreach ([
        'Csrf::validate',
        'verifyPassword',
        'SameOriginGuard::assertRequest(true)',
        'Auth::isTemporary',
        "'backup_id'",
        "'account_count'",
    ] as $needle) {
        $assert(str_contains($controller, $needle), 'Falta protección web: ' . $needle . '.');
    }
    $assert(
        str_contains($service, 'stopAll(')
        && !str_contains($service, 'ELIMINAR DATOS DE MERCADO LIBRE')
        && str_contains($service, 'apiStopped()')
        && str_contains($service, 'automationStopped()')
        && str_contains($service, 'freshVerifiedBackup')
        && str_contains($service, 'lease_generation')
        && str_contains($service, 'BackupArchiveService())->verify')
        && str_contains($service, "hash_file('sha256'")
        && str_contains($service, 'DatabaseMutationFreezeService')
        && str_contains($service, 'assertClassifiedInventory')
        && str_contains($service, 'hash_init'),
        'La autorización debe enlazar copia verificada, ambas paradas, freeze, inventario, huella y fencing.'
    );
    $assert(
        str_contains($job, "_retired_job.php")
        && !str_contains($job, 'ImportedMeliDataResetService')
        && !str_contains($job, 'cron_entry_early_lock()')
        && str_contains($launcher, 'ImportedMeliDataResetService')
        && str_contains($launcher, 'runNext(')
        && !str_contains($job, 'MeliApiClient'),
        'El reset debe ejecutarse solo en el lanzador único y el job antiguo debe ser un stub.'
    );
    $assert(
        str_contains($service, "'sync_batch_chunks'")
        && str_contains($service, 'assertNoActiveWork')
        && str_contains($service, 'assertCandidatePlanUnchanged')
        && str_contains($service, 'assertAuthorizedScope')
        && str_contains($service, 'FOR UPDATE'),
        'El reset debe cerrar cambios de candidatos, scope, trabajos activos y pérdida del lease.'
    );
    $assert(
        str_contains($freeze, "\$existing['context']")
        && str_contains($metadata, "'/settings/imported-data-reset'")
        && str_contains($routes, "\$mutationFreeze['purpose'] ?? '') === 'database_sanitation'")
        && str_contains($routes, "\$mutationFreeze['purpose'] ?? '') === 'imported_data_reset'"),
        'El heartbeat debe conservar contexto y cada mantenimiento solo puede continuar su propio freeze.'
    );
});

$test('2.26.3 aplica una política conservadora de identidad y evidencia', static function () use ($assert): void {
    $policy = new \App\Services\ImportedMeliDataResetPolicy();
    $operations = $policy->operations();
    $tables = array_column($operations, 'table');
    foreach ([
        'companies','company_settings','users','user_company_access',
        'meli_accounts','meli_tokens','meli_oauth_states','app_settings',
        'schema_migrations','internal_products','product_meli_links',
        'catalogs','catalog_items','monthly_reports','monthly_report_orders',
        'sales_control_closes','system_backup_archives',
    ] as $protected) {
        $assert(
            !in_array($protected, $tables, true),
            'La política no puede eliminar la tabla protegida ' . $protected . '.'
        );
    }
    $order = null;
    $item = null;
    foreach ($operations as $operation) {
        if ($operation['table'] === 'meli_orders') {
            $order = $operation;
        }
        if ($operation['table'] === 'meli_items') {
            $item = $operation;
        }
    }
    $assert(
        is_array($order)
        && str_contains($order['extra'], 'monthly_report_orders')
        && str_contains($order['extra'], 'date_report_orders')
        && str_contains($order['extra'], 'sales_control_fiscal_job_items'),
        'Las órdenes usadas como evidencia deben quedar excluidas.'
    );
    $assert(
        is_array($item)
        && str_contains($item['extra'], 'product_meli_links')
        && str_contains($item['extra'], 'catalog_items'),
        'Las publicaciones con decisiones humanas deben conservarse.'
    );
    $scoped = [];
    foreach ($operations as $operation) {
        $scoped[$operation['table']] = (string) ($operation['scope'] ?? 'direct');
    }
    $assert(($scoped['question_notifications'] ?? '') === 'question_parent'
        && ($scoped['catalog_description_jobs'] ?? '') === 'catalog_job',
        'Las tablas sin meli_account_id deben usar su relación real para aislar la cuenta.');
    $migration = (string) file_get_contents(
        dirname(__DIR__) . '/database/migrations/150_imported_meli_data_reset_2_26_3.sql'
    );
    $assert(
        str_contains($migration, 'imported_data_reset_table_policy')
        && str_contains($migration, 'information_schema.TABLES')
        && str_contains($migration, "action ENUM('preserve','delete','reset','ignore')"),
        'Cada tabla instalada debe quedar clasificada y las futuras deben bloquear el análisis.'
    );
});

$test('2.26.3 evita sondeos ajenos y ciclos terminales en Cron', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $availability = (string) file_get_contents(
        $root . '/app/Services/CronWorkAvailabilityService.php'
    );
    $items = (string) file_get_contents(
        $root . '/app/Services/MeliItemSyncJobService.php'
    );
    $performanceController = (string) file_get_contents(
        $root . '/app/Controllers/PerformanceController.php'
    );
    $performanceJs = (string) file_get_contents(
        $root . '/public/assets/performance.js'
    );
    $dateRepair = (string) file_get_contents(
        $root . '/app/Services/OrderDateRepairService.php'
    );
    $salesRepair = (string) file_get_contents(
        $root . '/app/Services/SalesRepairService.php'
    );

    $assert(
        str_contains($availability, '$probes = [')
        && str_contains($availability, '$snapshot[$key] = $probe();')
        && strpos($availability, '$selected = $onlyKeys') < strpos($availability, '$snapshot[$key] = $probe();'),
        'Las colas deben sondearse después de aplicar el subconjunto permitido.'
    );
    $assert(
        str_contains($availability, 'WHERE status IN ("queued","waiting","running")')
        && str_contains($availability, 'lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP()'),
        'La disponibilidad de descripciones debe coincidir con el selector que reclama el trabajo.'
    );
    $assert(
        str_contains($items, ') retryable_count')
        && str_contains($items, "\$phase = \$retryable > 0 ? 'details' : (\$errors > 0 ? 'error' : 'complete')")
        && str_contains($items, "\$terminal = in_array(\$phase, ['complete', 'error'], true)"),
        'Un job sin elementos recuperables debe quedar terminal y salir de Cron.'
    );
    $tryOffset = strpos($performanceController, 'try {');
    $authOffset = strpos($performanceController, 'Auth::requireLogin();');
    $assert(
        $tryOffset !== false && $authOffset !== false && $tryOffset < $authOffset,
        'Una telemetría con sesión vencida debe descartarse sin crear incidentes en MariaDB.'
    );
    $assert(
        str_contains($performanceJs, 'metricLastSentAt')
        && str_contains($performanceJs, '< 30000'),
        'El navegador debe limitar muestras repetidas del mismo indicador.'
    );
    foreach ([$dateRepair, $salesRepair] as $repairService) {
        $assert(
            str_contains($repairService, 'SalesAuditRunService())->createExactMonth')
            && !str_contains($repairService, 'runMonthSystem(')
            && !str_contains($repairService, 'compareMonthIdsSystem('),
            'Una reparación debe encolar la auditoría exacta y no descargar un mes completo dentro de su turno.'
        );
    }
});

$test('2.26.4 recupera freno y mantenimiento desde releases administradas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $paths = (string) file_get_contents(
        $root . '/jobs/_prebootstrap_runtime_paths.php'
    );
    $automation = (string) file_get_contents(
        $root . '/jobs/_automation_emergency_stop.php'
    );
    $meli = (string) file_get_contents(
        $root . '/jobs/_meli_emergency_stop.php'
    );
    $cron = (string) file_get_contents(
        $root . '/jobs/process_sync_queue.php'
    );
    $panel = (string) file_get_contents(
        $root . '/app/Recovery/EmergencyControlKernel.php'
    );
    $backupSignal = (string) file_get_contents(
        $root . '/app/Services/BackupMaintenanceRequestService.php'
    );
    $migration = (string) file_get_contents(
        $root . '/database/migrations/152_managed_maintenance_emergency_recovery_2_26_4.sql'
    );

    foreach ([
        "defined('ERP_INSTALLATION_ROOT')",
        "defined('ERP_SHARED_ROOT')",
        "basename(dirname(\$runtimeRoot)) === 'releases'",
        'erp_prebootstrap_marker_exists',
    ] as $needle) {
        $assert(
            str_contains($paths, $needle),
            'El resolver temprano debe cubrir instalaciones clásicas y administradas.'
        );
    }
    $assert(
        str_contains($automation, "erp_prebootstrap_pause_marker_exists('PAUSE_ERP_AUTOMATION')")
        && str_contains($meli, "erp_prebootstrap_pause_marker_exists('PAUSE_MELI_API')"),
        'Ambos frenos deben usar la misma autoridad previa al bootstrap.'
    );
    foreach ([
        'storage/cache/backup-maintenance-request.json',
        'storage/cache/restore-maintenance-request.json',
        'storage/cache/database-mutation-freeze.json',
    ] as $marker) {
        $assert(
            str_contains($paths, $marker),
            'El resolver temprano debe centralizar el marcador compartido: ' . $marker
        );
    }
    $assert(
        str_contains($cron, 'erp_prebootstrap_runtime_state()')
        && str_contains($cron, 'erp_prebootstrap_runtime_mode($earlyRuntimeState)'),
        'Cron debe consumir una sola instantánea tipada del estado previo al bootstrap.'
    );
    $assert(
        str_contains($panel, '$status = $this->control->status();')
        && !str_contains($panel, '(new SystemSafetyStatusService())->status();'),
        'El panel autenticado debe reutilizar la autoridad que validó la sesión.'
    );
    $assert(
        str_contains($backupSignal, 'time() + 604800')
        && str_contains($backupSignal, '$issuedAt < $now - 604800')
        && str_contains($backupSignal, '$issuedAt > $now + 300')
        && str_contains($backupSignal, '$expiresAt > $issuedAt + 604800'),
        'La copia pendiente debe sobrevivir la actualización sin perder autenticidad.'
    );
    foreach ([
        'backup.maintenance.shared_root_resolver',
        'emergency.panel.single_filesystem_authority',
        '2.26.4',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 152 debe registrar ' . $needle);
    }
});

$test('2.26.10 cerca copias y exige vínculo exacto para sanear', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $coordinator = (string) file_get_contents(
        $root . '/app/Services/LocalMaintenanceCoordinator.php'
    );
    $backup = (string) file_get_contents(
        $root . '/app/Services/BackupCenterService.php'
    );
    $maintenance = (string) file_get_contents(
        $root . '/app/Services/DatabaseMaintenanceService.php'
    );
    $restore = (string) file_get_contents(
        $root . '/app/Services/RestoreService.php'
    );
    $migration = (string) file_get_contents(
        $root . '/database/migrations/158_local_maintenance_fencing_exact_backup_2_26_10.sql'
    );

    foreach ([
        "'owner' =>",
        "'generation' =>",
        "'freeze_active' =>",
        "'coordinator_expired'",
        "'coordinator_guided_repair_required'",
        'clearCoordinatorIfMatches',
    ] as $needle) {
        $assert(str_contains($coordinator, $needle), 'El coordinador debe conservar ' . $needle);
    }
    foreach ([
        "'cancel_requested'",
        'cleanupExecutionArtifacts',
        "\$checkpoint['_execution_tag']",
        'checkpointArtifactsAvailable',
        'supportsSafeCancellationLifecycle',
    ] as $needle) {
        $assert(str_contains($backup, $needle), 'El ciclo de copias debe implementar ' . $needle);
    }
    $assert(
        !str_contains($backup, 'new BackupMaintenanceRequestService())->publish')
        && !str_contains($coordinator, '$legacyService->publish'),
        'Los marcadores heredados solo deben importarse; no pueden seguir siendo autoridad de escritura.'
    );
    $assert(
        str_contains($maintenance, "'database_sanitation'")
        && str_contains($maintenance, 'integrity_before_json')
        && !str_contains($maintenance, 'latestVerifiedArchive'),
        'Saneamiento debe exigir la copia exacta y no la última copia global.'
    );
    $assert(
        str_contains($restore, "'cancel_requested'")
        && str_contains($restore, "'ready_pending_release'")
        && str_contains($restore, "'deleting'"),
        'Restauración debe normalizar los estados activos de 2.26.10.'
    );
    foreach ([
        'cancel_requested_at',
        'control_generation',
        'maintenance_phase',
        'backup.local_coordinator_version',
        '2.26.10',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'La migración 158 debe registrar ' . $needle);
    }
});

$test('2.26.20 muestra progreso real de copias por navegador', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
    $backup = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
    $backupController = (string) file_get_contents($root . '/app/Controllers/BackupController.php');
    $maintenanceController = (string) file_get_contents($root . '/app/Controllers/DatabaseMaintenanceController.php');
    $migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
    $frontController = (string) file_get_contents($root . '/public/index.php');
    $backupView = (string) file_get_contents($root . '/app/Views/settings/backups.php');
    $maintenanceView = (string) file_get_contents($root . '/app/Views/settings/database_maintenance.php');
    $script = (string) file_get_contents($root . '/public/assets/performance.js');
    $migration160 = (string) file_get_contents(
        $root . '/database/migrations/160_recovery_updater_external_backup_choice_2_26_12.sql'
    );
    $migration161 = (string) file_get_contents(
        $root . '/database/migrations/161_recovery_updater_no_cron_migration_drain_2_26_13.sql'
    );
    $migration162 = (string) file_get_contents(
        $root . '/database/migrations/162_recovery_no_cron_backup_sanitation_2_26_14.sql'
    );
    $migration163 = (string) file_get_contents(
        $root . '/database/migrations/163_backup_browser_reconciliation_2_26_15.sql'
    );
    $migration164 = (string) file_get_contents(
        $root . '/database/migrations/164_browser_backup_finalizer_2_26_16.sql'
    );
    $migration165 = (string) file_get_contents(
        $root . '/database/migrations/165_backup_request_normalizer_2_26_17.sql'
    );
    $migration166 = (string) file_get_contents(
        $root . '/database/migrations/166_browser_only_backup_external_update_2_26_18.sql'
    );
    $migration167 = (string) file_get_contents(
        $root . '/database/migrations/167_browser_backup_visible_no_launcher_2_26_19.sql'
    );
    $migration168 = (string) file_get_contents(
        $root . '/database/migrations/168_backup_browser_progress_monitor_2_26_20.sql'
    );

    $assert(version_compare($version, '2.26.20', '>=') && ($manifest['version'] ?? '') === $version, 'VERSION y manifiesto deben declarar una versión igual o posterior a 2.26.20.');
    $assert(
        preg_match('/^(?:168|169|17[0-9]|1[8-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir al menos la migración 168.'
    );
    foreach ([
        'recovery_external_backup_choice',
        'RESPALDO EXTERNO CONFIRMADO',
        'direct_update_secure_optional',
        '2.26.12',
    ] as $needle) {
        $assert(str_contains($migration160, $needle), 'La migración 160 debe registrar ' . $needle);
    }
    foreach ([
        'migrator_drain_result_sets',
        'RESPALDO EXTERNO CONFIRMADO',
        '2.26.13',
    ] as $needle) {
        $assert(str_contains($migration161, $needle), 'La migración 161 debe registrar ' . $needle);
    }
    foreach ([
        'recovery_external_ignores_internal_backup',
        'backup.browser_interactive_enabled',
        'backup.browser_step_row_limit',
        'database_maintenance.browser_interactive_enabled',
        'database_maintenance.browser_step_deadline_seconds',
        '2.26.14',
    ] as $needle) {
        $assert(str_contains($migration162, $needle), 'La migración 162 debe registrar ' . $needle);
    }
    $manualStep = new \ReflectionMethod(\App\Controllers\SettingsController::class, 'manualProcessingInteractiveStep');
    $manualLines = file($manualStep->getFileName(), FILE_IGNORE_NEW_LINES);
    $manualSource = implode("\n", array_slice(
        is_array($manualLines) ? $manualLines : [],
        $manualStep->getStartLine() - 1,
        $manualStep->getEndLine() - $manualStep->getStartLine() + 1
    ));
    $assert(str_contains($manualSource, 'ManualSingleStepService')
        && !str_contains($manualSource, 'MeliApiClient')
        && !str_contains($manualSource, 'processDue('),
        'Procesar ahora debe usar exclusivamente el ejecutor exacto de un paso.');
    foreach ([
        'backup.browser_reconcile_missing_final_state',
        'backup.browser_failed_without_verified_file',
        '2.26.15',
    ] as $needle) {
        $assert(str_contains($migration163, $needle), 'La migración 163 debe registrar ' . $needle);
    }
    foreach ([
        'backup.browser_partial_releases_lease',
        'backup.browser_chunk_failure_is_recoverable',
        '2.26.16',
    ] as $needle) {
        $assert(str_contains($migration164, $needle), 'La migración 164 debe registrar ' . $needle);
    }
    foreach ([
        'backup.accept_legacy_forced_request_id',
        'backup.browser_request_normalizer_version',
        'backup.browser_lost_lease_is_deferred',
        'backup.browser_retry_from_last_approved_checkpoint',
        'backup.request_normalizer_ignores_deleted_active_rows',
        '2.26.17',
    ] as $needle) {
        $assert(str_contains($migration165, $needle), 'La migración 165 debe registrar ' . $needle);
    }
    foreach ([
        'backup.browser_mode_without_cli_signal',
        'backup.direct_update_external_choice_visible_with_pending_internal',
        'backup.default_creation_mode',
        '2.26.18',
    ] as $needle) {
        $assert(str_contains($migration166, $needle), 'La migración 166 debe registrar ' . $needle);
    }
    foreach ([
        'backup.browser_status_without_launcher',
        'backup.browser_first_step_immediate',
        'backup.hide_launcher_wording_for_browser_backups',
        '2.26.19',
    ] as $needle) {
        $assert(str_contains($migration167, $needle), 'La migración 167 debe registrar ' . $needle);
    }
    foreach ([
        'backup.browser_progress_monitor_enabled',
        'backup.browser_progress_mode',
        'backup.browser_eta_minimum_chunks',
        'backup.browser_stale_after_seconds',
        '2.26.20',
    ] as $needle) {
        $assert(str_contains($migration168, $needle), 'La migración 168 debe registrar ' . $needle);
    }
    $assert(
        !str_contains($recovery, "!hash_equals('CONTINUAR SIN RESPALDO',")
        && !str_contains($recovery, "!hash_equals('RESPALDO EXTERNO CONFIRMADO',")
        && str_contains($recovery, 'backup_choice')
        && str_contains($recovery, 'Continuar sin respaldo interno')
        && str_contains($recovery, "Session::put('_recovery_backup_decision', 'skipped')"),
        'El actualizador debe aceptar respaldo externo o continuar sin respaldo con contraseña y botón explícito, sin frases largas.'
    );
    $assert(
        str_contains($recovery, "if (\$backupDecision === 'skipped')")
        && str_contains($recovery, "\$backup = \$this->inspectSecureBackup();")
        && str_contains($recovery, "\$backupDecision !== 'skipped' ? \$this->inspectBackupForDisplay()"),
        'Una copia interna pendiente no debe ser inspeccionada ni bloquear cuando la decisión sea skipped.'
    );
    $assert(
        !str_contains($recovery, 'La identidad se confirmó, pero el respaldo necesita revisión. No se modificó la base de datos.')
        && str_contains($recovery, 'Usar respaldo externo y continuar')
        && str_contains($recovery, 'La copia interna pendiente quedará como limpieza pendiente; no bloqueará la migración.'),
        'El actualizador debe separar identidad confirmada de problemas reales de respaldo.'
    );
    $assert(
        str_contains($backup, 'processInteractiveStep')
        && str_contains($backup, "string \$executionMode = 'browser'")
        && str_contains($backup, "\$executionMode === 'cli'")
        && str_contains($backup, 'reconcileForcedRequestWithoutJob')
        && str_contains($backup, 'failRunningBackupJob')
        && str_contains($backup, "\$request['backup_id'] = \$backupId")
        && str_contains($backup, "\$request['public_id'] = \$publicId")
        && str_contains($backup, 'El micro-lote perdió su lease antes de aprobar el checkpoint')
        && str_contains($backup, 'Se limpiaron los artefactos temporales')
        && str_contains($backup, 'processRequested(?array $forcedRequest = null, array $limits = [])')
        && str_contains($backup, "'row_limit' => 500")
        && str_contains($backup, "'deadline_seconds' => 2")
        && str_contains($backup, "'lease_seconds' => 30"),
        'BackupCenterService debe ofrecer pasos interactivos cortos, lease breve y reconciliables sin depender del cron.'
    );
    $assert(
        str_contains($backup, 'progressPresenter')
        && str_contains($backup, "'progress' => \$progress")
        && (
            str_contains($backup, "'mode' => 'browser'")
            || (
                str_contains($backup, "'mode' =>")
                && str_contains($backup, "\$active['execution_mode'] ?? 'browser'")
            )
        )
        && str_contains($backup, "'eta_seconds_min'")
        && str_contains($backup, "'is_stale'")
        && str_contains($backup, "'browser_mode'"),
        'El estado de copias por navegador debe reportar progreso, ETA y modo browser.'
    );
    $assert(
        str_contains($backupController, 'interactiveStart')
        && str_contains($backupController, 'interactiveStep')
        && str_contains($backupController, 'interactiveCancel')
        && str_contains($frontController, '/settings/backups/interactive/start')
        && str_contains($frontController, '/settings/backups/interactive/step')
        && str_contains($frontController, '/settings/backups/interactive/status.json'),
        'Copias debe exponer endpoints interactivos seguros.'
    );
    $assert(
        str_contains($backupView, '/settings/backups/interactive/start')
        && str_contains($backupView, 'No se consultará Mercado Libre')
        && str_contains($backupView, 'Esta pestaña procesará micro-lotes locales')
        && str_contains($backupView, 'data-backup-progressbar')
        && str_contains($backupView, 'data-backup-eta')
        && str_contains($backupView, 'data-backup-activity')
        && !str_contains($backupView, 'Esperando lanzador')
        && !str_contains($backupView, 'worker')
        && !str_contains($backupView, 'cron')
        && !str_contains($backupView, 'en cola')
        && str_contains($script, 'processOneStep')
        && str_contains($script, 'mergeProgress')
        && str_contains($script, 'Procesando lote local')
        && str_contains($script, 'Pausado por pestaña en segundo plano')
        && str_contains($script, "fetch(stepUrl"),
        'La UI de copias debe avanzar por navegador, mostrar barra/ETA y conservar progreso real.'
    );
    $assert(
        str_contains($script, 'window.setTimeout(refresh, 100)'),
        'La UI de copias debe iniciar el primer micro-lote de navegador sin esperar cron.'
    );
    $assert(
        str_contains($maintenanceController, 'runInteractiveStep')
        && str_contains($maintenanceView, 'data-step-url')
        && str_contains($frontController, '$maintenanceAnalyzeContinuation')
        && str_contains($frontController, "\$pathEndsWith('/settings/database-maintenance/step')")
        && str_contains($frontController, '&& !$maintenanceContinuation'),
        'Saneamiento debe poder analizar y avanzar micro-lotes propietarios aunque haya snapshot recuperable.'
    );
    $assert(
        str_contains($migrator, 'executeMigrationSql')
        && str_contains($migrator, 'nextRowset')
        && str_contains($migrator, 'closeCursor'),
        'El migrador debe drenar result sets antes de registrar schema_migrations.'
    );
    $assert(
        !str_contains($recovery, 'UpdateBackupService')
        && !str_contains($recovery, 'createDirect()')
        && !str_contains($recovery, 'MeliApiClient'),
        'El actualizador directo no puede usar respaldo monolítico ni transporte Mercado Libre.'
    );
});

$test('2.27.0 unifica mantenimiento local sin cron ni frases largas', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents(
        $root . '/database/migrations/170_local_maintenance_unified_flow_2_27_0.sql'
    );
    $maintenanceService = (string) file_get_contents($root . '/app/Services/DatabaseMaintenanceService.php');
    $maintenanceController = (string) file_get_contents($root . '/app/Controllers/DatabaseMaintenanceController.php');
    $maintenanceView = (string) file_get_contents($root . '/app/Views/settings/database_maintenance.php');
    $backupView = (string) file_get_contents($root . '/app/Views/settings/backups.php');
    $recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $updateController = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
    $resetService = (string) file_get_contents($root . '/app/Services/ImportedMeliDataResetService.php');
    $resetView = (string) file_get_contents($root . '/app/Views/settings/imported_data_reset.php');

    $assert(
        version_compare($version, '2.27.0', '>=')
        && version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.27.0', '>='),
        'VERSION y manifiesto deben declarar una versión igual o superior a 2.27.0.'
    );
    $assert(
        preg_match('/^(?:170|171|17[2-9]|1[8-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 170 o una posterior.'
    );
    foreach ([
        'protection_mode',
        'protection_status',
        'canary_status',
        'database_maintenance.unified_local_flow',
        'database_maintenance.allow_external_backup',
        'database_maintenance.allow_backup_waiver',
        '2.27.0',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 170 debe registrar ' . $needle . '.');
    }
    $assert(
        str_contains($maintenanceService, 'setProtection(')
        && str_contains($maintenanceService, "PROTECTION_EXTERNAL = 'external_backup'")
        && str_contains($maintenanceService, "PROTECTION_WAIVED = 'waived'")
        && str_contains($maintenanceService, 'canary_status="passed"'),
        'Saneamiento debe aceptar copia ERP, respaldo externo o renuncia con canario.'
    );
    $assert(
        str_contains($maintenanceController, 'protect()')
        && str_contains($routes, '/settings/database-maintenance/protect')
        && str_contains($maintenanceController, 'verifyPassword'),
        'La protección del saneamiento debe tener ruta POST con contraseña administrativa.'
    );
    foreach ([
        'Usar respaldo externo',
        'Continuar sin respaldo interno',
        'Crear copia local',
        'Iniciar lote canario',
    ] as $needle) {
        $assert(str_contains($maintenanceView, $needle), 'La vista de saneamiento debe mostrar acción: ' . $needle);
    }
    foreach ([
        'CONTINUAR SIN RESPALDO',
        'RESPALDO EXTERNO CONFIRMADO',
        'ELIMINAR COPIA',
        'CANCELAR COPIA',
        'RECUPERAR COPIA',
        'RECUPERAR ESPACIO',
        'ELIMINAR DATOS DE MERCADO LIBRE',
    ] as $forbidden) {
        $assert(!str_contains($maintenanceView, $forbidden), 'Saneamiento no debe exigir frase larga: ' . $forbidden);
        $assert(!str_contains($backupView, $forbidden), 'Copias no debe exigir frase larga: ' . $forbidden);
        $assert(!str_contains($recovery, $forbidden), 'Actualizador no debe exigir frase larga: ' . $forbidden);
        $assert(!str_contains($resetView, $forbidden), 'Reset importado no debe exigir frase larga: ' . $forbidden);
    }
    $assert(
        str_contains($recovery, 'Usar respaldo externo y continuar')
        && str_contains($recovery, 'Continuar sin respaldo interno')
        && !str_contains($recovery, "hash_equals('RESPALDO EXTERNO CONFIRMADO'")
        && !str_contains($recovery, "hash_equals('CONTINUAR SIN RESPALDO'"),
        'El actualizador directo debe avanzar con botón explícito y contraseña, no con frase manual.'
    );
    $assert(
        str_contains($updateController, 'ACTUALIZAR SIN RESPALDO')
        && !str_contains($resetService, 'ELIMINAR DATOS DE MERCADO LIBRE'),
        'Los flujos sensibles deben mantener intención interna sin pedir frases al administrador.'
    );
});

$test('2.27.1 desbloquea protect y aísla copias por navegador del cron', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents(
        $root . '/database/migrations/171_browser_backup_state_isolation_2_27_1.sql'
    );
    $front = (string) file_get_contents($root . '/public/index.php');
    $backupService = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
    $backupController = (string) file_get_contents($root . '/app/Controllers/BackupController.php');
    $backupJs = (string) file_get_contents($root . '/public/assets/performance.js');

    $assert(
        version_compare($version, '2.27.1', '>=')
        && version_compare((string) ($manifest['version'] ?? ''), '2.27.1', '>='),
        'VERSION y manifiesto deben declarar 2.27.1 o superior.'
    );
    $assert(
        preg_match('/^(?:171|172|17[3-9]|1[8-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 171 o una posterior compatible.'
    );
    foreach ([
        'execution_mode ENUM',
        "'browser','cli'",
        'backup.browser_execution_mode',
        'backup.browser_never_requires_cron',
        'database_maintenance.protect_allowed_during_snapshot',
        '2.27.1',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 171 debe registrar ' . $needle . '.');
    }
    $assert(
        str_contains($front, '$maintenanceProtectionContinuation')
        && str_contains($front, 'database-snapshot-active.json')
        && str_contains($front, '&& !$maintenanceProtectionContinuation'),
        'El gate global debe permitir protect aun con snapshot físico activo.'
    );
    $assert(
        str_contains($backupService, 'execution_mode')
        && str_contains($backupService, 'isBrowserBackup(')
        && str_contains($backupService, 'publishBackupIfCli(')
        && str_contains($backupService, 'Esta copia se continúa desde la pestaña del navegador.'),
        'Copias por navegador deben persistir modo y no publicar coordinación CLI.'
    );
    $assert(
        str_contains($backupService, "['prepared', 'queued', 'creating'")
        && str_contains($backupService, 'snapshotConflict('),
        'El paso interactivo debe aceptar copias heredadas preparadas y reportar snapshot ajeno.'
    );
    $assert(
        str_contains($backupController, 'overview(max(0, (int) ($_GET[\'backup\'] ?? 0)))')
        && str_contains($backupController, 'backup_interactive_step_failed')
        && str_contains($backupController, 'safeErrorMessage'),
        'El controlador debe usar la copia solicitada y devolver JSON humano en errores de lote.'
    );
    $assert(
        str_contains($backupJs, "['prepared', 'queued'")
        && str_contains($backupJs, "payload.message || ''")
        && str_contains($backupJs, "payload.action || ''"),
        'El monitor debe avanzar copias prepared y mostrar causa/acción de errores JSON.'
    );
});

$test('2.27.2 evita pantallas muertas de mantenimiento y conserva protect accionable', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $migration = (string) file_get_contents(
        $root . '/database/migrations/172_operational_audit_surface_consistency_2_27_2.sql'
    );
    $front = (string) file_get_contents($root . '/public/index.php');
    $maintenanceView = (string) file_get_contents($root . '/app/Views/errors/maintenance.php');

    $assert(
        version_compare($version, '2.27.2', '>=')
        && version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.27.2', '>='),
        'VERSION y manifiesto deben declarar 2.27.2 o superior.'
    );
    $assert(
        preg_match('/^(?:172|17[3-9]|1[8-9][0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'El manifiesto debe exigir la migración 172 o una posterior compatible.'
    );
    foreach ([
        'maintenance.blocked_screen_actions',
        'database_maintenance.protect_allowed_during_freeze',
        'backup.browser_dead_end_prevention',
        '2.27.2',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 172 debe registrar ' . $needle . '.');
    }
    $assert(
        str_contains($front, "\$router->get('/settings/database-maintenance/protect'")
        && str_contains($front, "\$router->get('/settings/backups/recover'")
        && str_contains($front, '&& !$maintenanceProtectionContinuation')
        && str_contains($front, "'actions' => ["),
        'El front controller debe enrutar accesos accidentales y permitir protect durante freeze.'
    );
    $assert(
        substr_count($front, "\$router->get('/settings/database-maintenance/protect'") === 1,
        'La ruta GET /settings/database-maintenance/protect no puede registrarse dos veces porque el Router pisa la primera definición.'
    );
    $assert(
        str_contains($maintenanceView, 'Acciones de recuperación')
        && str_contains($maintenanceView, 'No se consultó Mercado Libre')
        && str_contains($maintenanceView, '.btn.primary'),
        'La vista de mantenimiento debe ser accionable y no una página muerta.'
    );
    $backupView = (string) file_get_contents($root . '/app/Views/settings/backups.php');
    $assert(
        str_contains($backupView, 'Continuar limpieza')
        && str_contains($backupView, '?backup=<?= (int) $archive[\'id\'] ?>'),
        'Una copia cancelada o en limpieza debe ofrecer un camino visible para continuar desde la pestaña.'
    );
});

$test('2.28.4 certifica fase 1 comercial, Cron visible y freno humano', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);

    $assert(
        version_compare($version, '2.28.4', '>=')
        && ($manifest['version'] ?? '') === $version
        && preg_match('/^(?:18[4-9]|19[0-9]|[2-9][0-9]{2})_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
        'VERSION, manifiesto y migración mínima deben declarar 2.28.4 o una versión posterior coherente.'
    );

    foreach ([
        '173_cron_contract_language_audit_2_27_3.sql',
        '174_browser_backup_freeze_scope_2_27_4.sql',
        '175_exact_sanitation_protection_2_27_5.sql',
        '176_operational_certification_matrix_2_27_6.sql',
        '177_emergency_handbrake_unified_control_2_27_7.sql',
        '178_stable_root_handbrake_control_2_27_8.sql',
        '179_shared_config_handbrake_readiness_2_27_9.sql',
        '180_commercial_path_foundation_2_28_0.sql',
        '181_sales_temporal_coverage_contract_2_28_1.sql',
        '182_operational_audit_polish_2_28_2.sql',
        '183_cron_fast_canary_visibility_2_28_3.sql',
        '184_human_emergency_control_2_28_4.sql',
    ] as $migration) {
        $assert(is_file($root . '/database/migrations/' . $migration), 'Falta migración ' . $migration);
    }
    $phaseOneMigrations = glob($root . '/database/migrations/181_*_2_28_1.sql') ?: [];
    $assert(count($phaseOneMigrations) === 1, 'Debe existir una sola migración 181 de cobertura temporal para evitar contratos divergentes.');
    $assert(
        is_file($root . '/app/Services/SalesAuditTemporalCoverageService.php')
        && !is_file($root . '/app/Services/CommercialTemporalCoverageService.php'),
        'La cobertura temporal debe tener una sola autoridad de cierre y no conservar servicios comerciales duplicados.'
    );
    $assert(is_file($root . '/app/Services/SalesTemporalCoverageService.php'), 'El read model comercial necesita presentador anual de cobertura temporal.');

    $language = (string) file_get_contents($root . '/app/Services/RuntimeLanguagePresenter.php');
    $cronOutcome = (string) file_get_contents($root . '/app/Services/CronWorkOutcome.php');
    $syncJob = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $backupService = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
    $maintenanceService = (string) file_get_contents($root . '/app/Services/DatabaseMaintenanceService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $syncController = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
    $financialController = (string) file_get_contents($root . '/app/Controllers/FinancialRecalcController.php');
    $notificationController = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
    $settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    $backupView = (string) file_get_contents($root . '/app/Views/settings/backups.php');
    $restoreView = (string) file_get_contents($root . '/app/Views/settings/backup_restore.php');
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $notificationsView = (string) file_get_contents($root . '/app/Views/notifications/index.php');
    $notificationsAutomation = (string) file_get_contents($root . '/app/Views/notifications/automation.php');
    $recurringView = (string) file_get_contents($root . '/app/Views/sync/recurring.php');
    $syncAuditView = (string) file_get_contents($root . '/app/Views/sync/audit.php');
    $syncAuditRunView = (string) file_get_contents($root . '/app/Views/sync/audit_run.php');
    $catalogView = (string) file_get_contents($root . '/app/Views/catalogs/show.php');
    $manualSessionView = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
    $appJs = (string) file_get_contents($root . '/public/assets/app.js');
    $emergencyKernel = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $salesAuditTemporal = (string) file_get_contents($root . '/app/Services/SalesAuditTemporalCoverageService.php');
    $salesControl = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
    $salesAuditRun = (string) file_get_contents($root . '/app/Services/SalesAuditRunService.php');

    $assert(
        str_contains($salesAuditTemporal, 'public const CONTRACT_VERSION = 2')
        && str_contains($salesAuditTemporal, 'canClose(array $run)')
        && str_contains($salesAuditRun, 'temporal_coverage_state')
        && str_contains($salesAuditRun, 'coverage_contract_version')
        && str_contains($salesControl, 'coverage_contract_version')
        && str_contains($salesControl, 'No se puede cerrar este mes porque la cobertura temporal no demuestra'),
        'Ventas debe materializar cobertura temporal y bloquear cierres nuevos sin contrato full.'
    );

    $assert(
        str_contains($emergencyKernel, 'new EmergencyControlService(AppPaths::installationRoot())')
        && str_contains($emergencyKernel, 'Env::load(AppPaths::configFile())')
        && str_contains($emergencyKernel, 'is_file(AppPaths::configFile())'),
        'El freno de mano debe usar raíz estable y config compartida en instalaciones administradas.'
    );
    $panelBody = substr($emergencyKernel, (int) strpos($emergencyKernel, 'private function renderPanel'), (int) strpos($emergencyKernel, 'private function document') - (int) strpos($emergencyKernel, 'private function renderPanel'));
    $reactivateBody = substr($emergencyKernel, (int) strpos($emergencyKernel, 'private function reactivate'), (int) strpos($emergencyKernel, 'private function readiness') - (int) strpos($emergencyKernel, 'private function reactivate'));
    $assert(
        str_contains($panelBody, 'data-confirm')
        && str_contains($panelBody, 'aria-pressed')
        && str_contains($panelBody, 'Activar freno de mano completo')
        && str_contains($panelBody, 'Detalle técnico')
        && !str_contains($panelBody, 'Contraseña de emergencia')
        && !str_contains($panelBody, 'name="password"')
        && !str_contains($panelBody, 'for="stop-reason"')
        && !str_contains($panelBody, 'Reactivar de forma controlada'),
        'El panel de emergencia debe ser accionable: interruptores con confirmación, sin motivo ni contraseña repetidos.'
    );
    $assert(
        !str_contains($reactivateBody, 'authenticate(')
        && !str_contains($reactivateBody, "POST['password']")
        && str_contains($reactivateBody, '$requireReadiness'),
        'Reactivar desde una sesión de emergencia válida no debe pedir contraseña de nuevo, pero sí conservar comprobaciones de readiness.'
    );
    $assert(
        str_contains($emergencyKernel, 'defaultReason(')
        && str_contains($emergencyKernel, 'Prueba canaria preparada desde freno de mano.')
        && str_contains($emergencyKernel, 'Mercado Libre activado después de canario exitoso.'),
        'Las auditorías del freno de mano deben generar motivos automáticos humanos.'
    );

    foreach ([
        'ready',
        'waiting_automation',
        'waiting_api',
        'waiting_budget',
        'waiting_lock',
        'waiting_schedule',
        'action_required',
        'empty',
        'failed',
    ] as $state) {
        $assert(str_contains($cronOutcome, "'{$state}'"), 'CronWorkOutcome debe declarar estado operacional ' . $state);
    }
    $assert(str_contains($cronOutcome, 'operationalState('), 'CronWorkOutcome debe exponer traducción operacional.');
    $assert(str_contains($language, 'browserForbiddenWords') && str_contains($language, 'operationalCronStates'), 'RuntimeLanguagePresenter debe centralizar lenguaje y estados.');

    foreach ([
        '/sync/assisted-step.json',
        '/financial-recalc/assisted-step',
        '/notifications/automation/assisted-step',
        '/notifications/process',
        '/notifications/work/process',
        '/settings/manual-processing/interactive/step',
    ] as $route) {
        $assert(str_contains($routes, $route), 'Falta contrato de ruta heredada: ' . $route);
    }
    foreach ([
        [$syncController, 'assistedStepJson'],
        [$notificationController, 'automationAssistedStep'],
        [$settingsController, 'manualProcessingInteractiveStep'],
    ] as [$source, $method]) {
        $methodBody = substr($source, strpos($source, 'function ' . $method), 900);
        $assert(
            $method === 'manualProcessingInteractiveStep'
                ? str_contains($methodBody, 'ManualSingleStepService')
                : str_contains($methodBody, 'http_response_code(410)'),
            'La ruta debe respetar su contrato web acotado: ' . $method
        );
        $assert(!str_contains($methodBody, 'processDue(') && !str_contains($methodBody, 'syncNextActive('), 'Ruta heredada no puede elegir colas genéricas: ' . $method);
    }
    $financialAssisted = substr($financialController, strpos($financialController, 'function assistedStep'), 900);
    $assert(
        str_contains($financialAssisted, '/settings/manual-processing')
        && !str_contains($financialAssisted, 'processDue(')
        && !str_contains($financialAssisted, 'syncNextActive('),
        'El recálculo financiero heredado debe redirigir a Procesar ahora sin ejecutar colas.'
    );
    $processBodies = [
        substr($notificationController, (int) strpos($notificationController, 'function processWork'), 900),
    ];
    foreach ($processBodies as $body) {
        $assert(!str_contains($body, 'processDue(') && !str_contains($body, 'syncNextActive('), 'Notificaciones web no puede procesar colas completas.');
    }

    foreach (['execution_mode', 'isBrowserBackup(', 'publishBackupIfCli(', "COALESCE(execution_mode, 'browser')", "b.execution_mode='cli'"] as $needle) {
        $assert(str_contains($backupService, $needle), 'Copia navegador debe ser independiente de Cron: falta ' . $needle);
    }
    foreach (['phase_label', 'can_step', 'can_cancel', 'can_cleanup', 'rows_processed_in_step', 'should_continue'] as $needle) {
        $assert(str_contains($backupService, $needle), 'Progreso extendido de copia debe exponer ' . $needle);
    }
    $publishBody = substr($backupService, strpos($backupService, 'function publishBackupIfCli'), 900);
    $assert(str_contains($publishBody, 'isBrowserBackup') && str_contains($publishBody, 'return'), 'Copias navegador no deben publicar señal CLI.');

    foreach (['Esperando lanzador', 'worker', 'cron', 'en cola'] as $forbidden) {
        $assert(!str_contains($backupView, $forbidden), 'Copias por navegador no debe mostrar texto heredado: ' . $forbidden);
    }
    $assert(!str_contains($restoreView, 'Esperando lanzador'), 'Restauración no debe decir Esperando lanzador.');
    foreach (['ERP_CRON_BOOT version=2.25.4', 'workers de notificaciones', 'Cron corre cada 5 minutos', 'cron puede continuarlo', 'cron continuará', 'continuará mediante cron', 'Esperando el lanzador'] as $forbidden) {
        $assert(!str_contains($cronView . $notificationsView . $notificationsAutomation . $recurringView . $syncAuditView . $syncAuditRunView . $catalogView . $manualSessionView . $appJs, $forbidden), 'Texto heredado visible detectado: ' . $forbidden);
    }

    $assert(
        str_contains($maintenanceService, 'setProtection(')
        && str_contains($maintenanceService, "PROTECTION_EXTERNAL = 'external_backup'")
        && str_contains($maintenanceService, "PROTECTION_WAIVED = 'waived'")
        && str_contains($maintenanceService, 'verifiedAt < $analyzedAt')
        && str_contains($maintenanceService, "!== 'database_sanitation'"),
        'Saneamiento debe exigir protección exacta por sesión y no usar última copia global.'
    );

    $assert(
        str_contains($syncJob, 'component=process_sync_queue')
        && str_contains($syncJob, "_meli_emergency_stop.php")
        && str_contains($syncJob, '$apiEmergencyStop')
        && str_contains($syncJob, '$localOnlyKeys')
        && str_contains($syncJob, 'remote=false'),
        'El lanzador central debe seguir bloqueando transporte remoto con freno API.'
    );
});

$test('Salud API usa parámetros PDO únicos y separa evidencia de Cron', static function () use ($assert): void {
    $root = dirname(__DIR__);
    $health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
    $overview = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');

    foreach (['active_minutes_all', 'active_minutes_remote', 'active_minutes_local'] as $placeholder) {
        $assert(
            substr_count($health, ':' . $placeholder . ' MINUTE') === 1,
            'Cada filtro de ventana activa debe tener una sola aparición SQL: ' . $placeholder
        );
        $assert(
            substr_count($health, "bindValue(':{$placeholder}'") === 1,
            'Cada filtro de ventana activa debe tener su binding PDO: ' . $placeholder
        );
    }
    $assert(
        !str_contains($health, 'INTERVAL :active_minutes MINUTE'),
        'PDO MySQL nativo no debe reutilizar :active_minutes en la consulta agregada.'
    );

    $evidenceAssignment = strpos($overview, "['automation_evidence'] = \$this->automationEvidence(") ?: -1;
    $cachedStatusBuild = strpos($overview, "new ApiHealthStatusPresenter") ?: PHP_INT_MAX;
    $assert($evidenceAssignment > -1, 'El resumen debe exponer evidencia independiente del último Cron finalizado.');
    $assert(
        str_contains($overview, 'WHERE latest.origin="scheduled_cli" AND latest.finished_at IS NOT NULL'),
        'La evidencia debe leer un ciclo CLI terminado, no el ciclo running más reciente.'
    );
    $assert(
        str_contains($overview, "'source' => 'latest_finished_scheduled_cli'")
        && str_contains($overview, "'remote_calls' => \$remoteCalls")
        && str_contains($overview, "'useful_activity' =>"),
        'La evidencia de automatización debe declarar fuente, llamadas remotas y actividad útil.'
    );
    $assert(
        $evidenceAssignment < $cachedStatusBuild,
        'La evidencia se agrega al sobre final y no se usa como señal de salud de Mercado Libre.'
    );
});

$failed = 0;
foreach ($tests as [$status, $name]) {
    echo strtoupper($status) . " {$name}\n";
    if ($status === 'fail') {
        $failed++;
    }
}
echo sprintf("%d tests, %d failures\n", count($tests), $failed);
exit($failed ? 1 : 0);

