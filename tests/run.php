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
    MeliEndpointRegistry::assertDocumented('GET', '/post-purchase/v1/claims/123/detail');
    MeliEndpointRegistry::assertDocumented('GET', '/questions/search');
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
    $assert(str_contains($layout, 'AssetVersionService::fingerprint') && str_contains($layout, '/assets/app.css?v='));
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
    $assert(str_contains($js, 'data-assisted-sync-form') && str_contains($js, 'continue_delay_seconds'));
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
    $assert(str_contains($controller, 'processReady((int) ($_POST[\'account_id\'] ?? 0), true)'), 'El modo asistido debe procesar bloques en cola aunque no estén vencidos.');
    $cronView = (string) file_get_contents($root . '/app/Views/settings/cron.php');
    $assert(str_contains($cronView, 'Recuperar bloques vencidos') && str_contains($cronView, 'Reprogramar bloques vencidos'));
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    foreach (['data-process-pause', 'data-process-continue', 'data-process-cancel', 'setProcessDetails'] as $needle) {
        $assert(str_contains($js, $needle), 'Falta JS asistido 2.4.5: ' . $needle);
    }
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
    $assert(str_contains($questions, 'questions.frequency_minutes') && str_contains($questions, 'questions.last_sync_at'));
    $js = (string) file_get_contents($root . '/public/assets/app.js');
    $assert(str_contains($js, "element.tagName === 'FORM'") && str_contains($js, "closest?.('select,input,textarea,option,label')"));
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
    $assert(str_contains($layout, 'Notificaciones') && str_contains($layout, '/assets/app.js?v=') && str_contains($layout, 'AssetVersionService::fingerprint'));
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
    $assert(str_contains($repair, 'classification="missing_remote"') && str_contains($repair, 'compareMonthIdsSystem'));
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
    $assert(str_contains($layout, '/assets/app.css?v=') && str_contains($layout, '/assets/app.js?v=') && str_contains($layout, 'AssetVersionService::fingerprint'));
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
    $assert(str_contains($layout, '/assets/app.css?v=') && str_contains($layout, '/assets/app.js?v=') && str_contains($layout, 'AssetVersionService::fingerprint'));
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
    foreach (['importForOrderIds', 'importación Billing agrupada fue retirada', 'un order_id por llamada física'] as $needle) {
        $assert(str_contains($billingImporter, $needle), 'Importador billing retirado debe conservar barrera: ' . $needle);
    }
    $saleFinancial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    foreach (['billingOrderIdsPerCallLimit', 'BILLING_ORDER_IDS_PER_CALL = 1', 'billing_order_v2'] as $needle) {
        $assert(str_contains($saleFinancial, $needle), 'Billing activo debe operar un order_id por GET: ' . $needle);
    }
    $financialQueue = (string) file_get_contents($root . '/app/Services/OrderFinancialRecalcJobService.php');
    foreach (['processBillingPhase', 'billing_import', 'auto_billing_for_missing', 'No hay recalculos financieros pendientes'] as $needle) {
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
    $assert(str_contains($layout, '/assets/app.css?v=') && str_contains($layout, '/assets/app.js?v=') && str_contains($layout, 'AssetVersionService::fingerprint'), 'Assets deben usar fingerprint de release y contenido');
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
    foreach (['data-catalog-main-image', 'data-catalog-gallery', 'data-catalog-gallery-image', 'data-catalog-lightbox-open', 'Descripción', 'app.js?v=2.11.2'] as $needle) {
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
    $assert(!str_contains($syncOrders, 'use DateTimeImmutable;') && str_contains($syncOrders, 'new \\DateTimeImmutable'), 'sync_orders no debe generar warnings de use global');
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
    $assert(str_contains($layout, '/assets/app.css?v=') && str_contains($layout, 'AssetVersionService::fingerprint') && str_contains($product, 'app.js?v=2.11.2'), 'Assets deben quedar versionados con fingerprint o versión de release');
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
    foreach (['data-catalog-gallery', 'data-catalog-description-monitor', 'Continuará en', 'data-description-start', 'catalog-lightbox'] as $needle) {
        $assert(str_contains($catalogJs, $needle), 'JS de catálogo debe incluir: ' . $needle);
    }
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
    $saleFinancial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    $assert(str_contains($billing, 'importación Billing agrupada fue retirada') && str_contains($saleFinancial, "'other' => 0.0") && str_contains($saleFinancial, 'review'), 'Billing ambiguo debe quedar en revisión y el importador agrupado retirado.');
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
    $assert(str_contains($layout, 'NavigationRepository') && str_contains($layout, '/assets/ux.css?v=') && str_contains($layout, 'uxCssFingerprint'), 'El layout debe usar navegación central y sistema visual.');
    $health = (string) file_get_contents($root . '/app/Views/settings/api_health.php');
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
    $session = (string) file_get_contents($root . '/app/Core/Session.php');
    $assert(str_contains($session, 'looksTechnical') && str_contains($session, 'Código de diagnóstico'), 'Mensajes técnicos deben ocultarse al usuario.');
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
    foreach (['scheduled_cli', 'manual_web', 'notification_cli', 'Automatización verificada', 'Solo prueba manual', 'pending_verification'] as $needle) {
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
    foreach (['ERP_CRON_BOOT', 'ERP_CRON_SKIP', "job_try_lock('process_sync_queue')", 'CronDeadlineContext::start', 'CronTaskStateService', 'max_api_tasks_per_run'] as $needle) {
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
    $response = $gateway->get('meli-insights', 91, '/items/MCO123/sale_price', ['quantity' => 1], 'module_insights');
    $assert(($calls[0]['account_id'] ?? 0) === 91, 'Gateway debe construir el cliente para la cuenta seleccionada.');
    $assert(($response['path'] ?? '') === '/items/MCO123/sale_price', 'Gateway debe enviar el path como primer argumento de get().');
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
    $scheduler = (string) file_get_contents($root . '/app/Services/WorkSchedulerService.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');

    foreach (['system_work_queue_projection', 'system_work_queue_runs', 'system_work_queue_run_items'] as $table) {
        $assert(str_contains($migration, $table), 'Migración 092 debe crear: ' . $table);
    }
    foreach ([
        'operational_maintenance','notification_spool','notification_backfill','notification_fallback',
        'recurring_sync','orders_sync','order_enrichment','order_date_repair','questions','financial_recalc',
        'sales_repair','sales_audit','catalog_descriptions','items_sync','catalog_description_cleanup','module_jobs',
    ] as $queue) {
        $assert(str_contains($registry, "'{$queue}'"), 'El registro debe inventariar: ' . $queue);
    }
    $assert(str_contains($projection, 'priority_tier ASC,p.created_at_source ASC'), 'La cola debe respetar prioridad y antigüedad.');
    $assert(str_contains($scheduler, '$apiCount >= 1') && str_contains($scheduler, 'count($selected) >= 3'), 'La vista previa debe respetar los límites del cron.');
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
    $assert(str_contains($projection, 'public function find(string $queueKey, string $sourceId)'), 'El detalle debe localizar trabajos con una consulta limitada.');
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
    $assert(str_starts_with((string) $critical['detail_url'], '/settings/cron/work?'), 'El detalle debe usar una ruta interna controlada.');
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
    $worker = (string) file_get_contents($root . '/jobs/process_manual_queue.php');
    foreach (['manual_processing_sessions','manual_processing_scopes','manual_processing_items','grace_until','lease_generation'] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 098 debe incluir: ' . $needle);
    }
    foreach (['/settings/manual-processing/start','/settings/manual-processing/pause','/settings/manual-processing/resume','/settings/manual-processing/finish'] as $route) {
        $assert(str_contains($routes, $route), 'Falta ruta del Centro: ' . $route);
    }
    $assert(str_contains($cronState, 'queueReservedForManual'), 'Cron debe ceder las colas reclamadas.');
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
        && preg_match('/^(?:099|1\\d{2})_/', $minimumMigration) === 1,
        'El manifiesto debe exigir 099 o una migración acumulativa posterior.');
    $assert(str_contains($manual, 'public function finalizeSessionIfDone'), 'La sesión debe cerrarse automáticamente.');
    $assert(str_contains($manual, 'lease_expires_at<UTC_TIMESTAMP()'), 'Un micro-lote abandonado debe ser recuperable.');
    $assert(str_contains($manual, "GET_LOCK('erp_manual_processing_start',3)"), 'Dos inicios simultáneos deben serializarse con lock MySQL.');
    $assert(str_contains($manual, 'El procesamiento se detuvo para revisar el último resultado.'), 'Un error debe detener la sesión y explicarlo.');
    $assert(str_contains($manual, "['operational_maintenance','order_date_repair','financial_recalc','catalog_description_cleanup']"), 'Trabajo local no debe congelar entrada webhook.');
    $assert(str_contains($executor, "'target_terminal'") && str_contains($executor, 'refreshQueue'), 'El worker debe verificar el trabajo congelado.');
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
        && preg_match('/^(?:100|1\\d{2})_/', $minimumMigration) === 1,
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
        && str_contains($javascript, 'terminalStatuses'),
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
        && preg_match('/^(?:10[2-9]|1[1-9][0-9])_/', (string) ($manifest['minimum_migration'] ?? '')) === 1,
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
          || str_contains($view, 'Comenzar procesamiento manual'))
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
    foreach (['10 => 30','30 => 55','sleep(','hrtime(true)','ERP_MANUAL_PROBE_OK'] as $needle) {
        $assert(str_contains($probe, $needle), 'Probe debe certificar tiempo real: ' . $needle);
    }
    $assert(str_contains($probe, 'ManualCampaignAdapterRegistry')
        && str_contains($probe, 'manual_operation_profiles')
        && str_contains($probe, 'overlapProbe'),
        'El probe debe verificar adaptadores, perfiles y solapamiento real.');
    $assert(str_contains($contract, 'processExact(') && str_contains($contract, 'inspect('),
        'Cada adaptador debe inspeccionar y procesar un recurso exacto.');
    $assert(str_contains($adapter, "processOne(\$id")
        && str_contains($adapter, "processJob(")
        && (str_contains($adapter, "processDue(\n                \$id")
            || str_contains($adapter, 'processExactItem(')),
        'Órdenes, productos y descripciones deben usar su ID congelado.');
    $assert(str_contains($registry, "'exact'") || str_contains($registry, '$exact'),
        'El registro debe distinguir adaptadores exactos.');
    $assert(isset($manifest['components']['manual_engine_probe'])
        || (preg_match('/^(?:11[7-9]|12[0-2])_/', (string) ($manifest['minimum_migration'] ?? '')) === 1
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
    $assert(preg_match('/^(?:10[4-9]|1[1-9][0-9])_/', (string) ($manifest['minimum_migration'] ?? '')) === 1
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
        '114_operational_integrity_scope_2_21_2.sql',
        '115_resumable_execution_journal_2_21_3.sql',
        '116_sales_evidence_control_2_22_0.sql',
        '117_operational_ux_contracts_2_22_1.sql',
    ], true), 'El manifiesto debe exigir la migración de ritmo exacto o su correctivo posterior.');
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
    ], true), 'El manifiesto debe obtener el requisito mínimo desde la migración 106 o su sucesora.');
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
          || str_contains($main, "\$campaignReady && !\$activeSession"))
        && !str_contains($main, "\$campaignReady && \$engineReady && \$certified"),
        'Procesar ahora debe permitir el modo conservador sin exigir certificación.');
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
    ], true), 'El manifiesto debe exigir la migración 107 o su sucesora.');
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
    $assert(str_contains($controller, "'changed' => \$changed")
        && str_contains($controller, '$origin,')
        && str_contains($controller, "'account_id' => \$accountId"),
        'El estado debe ser incremental y conservar el contexto permitido.');
    $assert(!str_contains($syncController, 'resumePending($accountId)')
        && !str_contains($syncController, 'retryFailed($accountId)'),
        'Las rutas asistidas heredadas no deben mutar colas antes de redirigir.');
    $assert(str_contains($view, 'manual-countdown-panel')
        && (str_contains($view, 'manual-stat-strip') || str_contains($view, 'manual-stat-line'))
        && str_contains($view, 'data-manual-next-label')
        && !str_contains($view, 'manual-countdown"'),
        'La cabina debe usar reloj rectangular, resumen compacto y siguiente trabajo.');
    $assert(str_contains($javascript, "displayState === 'processing'")
        && str_contains($javascript, "payload.changed")
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
    ], true), 'El manifiesto debe exigir la migración 108 o su sucesora.');
    foreach ([
        'manual_campaign_reservations','manual_campaign_steps','control_owner',
        'control_expires_at','step_sequence','interactive_web','2.20.4',
    ] as $needle) {
        $assert(str_contains($migration, $needle), 'Migración 108 debe incluir ' . $needle . '.');
    }
    $assert(str_contains($interactive, 'manual_campaign_steps')
        && str_contains($interactive, 'clientStepKey')
        && str_contains($interactive, 'processStep(')
        && str_contains($interactive, 'control_expires_at'),
        'El ejecutor debe validar control, idempotencia y procesar un recurso exacto.');
    $assert(str_contains($campaign, 'execution_mode="directed_cli"')
        && str_contains($campaign, 'manual_campaign_reservations')
        && str_contains($campaign, 'total_units'),
        'Las campañas dirigidas deben congelar reservas y progreso por unidades.');
    foreach ([
        '/settings/manual-processing/interactive/start',
        '/settings/manual-processing/interactive/step',
        '/settings/manual-processing/interactive/heartbeat',
    ] as $route) {
        $assert(!str_contains($routes, $route), 'La ruta web retirada no debe ejecutar trabajos: ' . $route . '.');
    }
    $assert(str_contains($view, 'Procesamiento manual listo')
        && str_contains($view, 'Puede cerrar esta página')
        && !str_contains($view, 'Comprobar motor'),
        'La pantalla inicial debe explicar el plano CLI recuperable.');
    $assert(!str_contains($session, 'data-step-url')
        && !str_contains($session, 'data-heartbeat-url')
        && !str_contains($session, 'Procesar siguiente paso'),
        'El monitor no debe ejecutar API desde el navegador.');
    $assert(!str_contains($routes, '/settings/manual-processing/interactive/step'),
        'El navegador no debe disponer de un disparador HTTP de trabajos.');
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
        && str_contains($interactive, 'ManualCampaignSourceInspector'),
        'El contador no debe reiniciarse al cambiar de operación y cada paso debe comprobar la fuente.');
    $assert(str_contains($registry, "'notification_backfill'")
        && str_contains($registry, "'notification-backfill', 'notification_backfill', 'local_maintenance', 'Recuperación de notificaciones', false"),
        'El backfill sin aislamiento exacto no debe procesarse interactivamente.');
    foreach (['manual-ops-main','manual-log-tabs','data-manual-work-progress','data-manual-attention'] as $needle) {
        $assert(str_contains($view, $needle), 'El monitor compacto debe incluir ' . $needle . '.');
    }
    $assert(str_contains($javascript, 'data-manual-controller="unified-v2"')
        && str_contains($javascript, 'heartbeatInFlight')
        && str_contains($javascript, 'stepInFlight')
        && str_contains($javascript, 'statusInFlight'),
        'Un solo controlador debe impedir peticiones concurrentes.');
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
    ], true), 'El manifiesto debe exigir la migración 110 o una sucesora.');
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
    ], true), 'El manifiesto debe exigir la migración 113 o una sucesora.');
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
    $sales = (string) file_get_contents($root . '/app/Services/SalesControlService.php');
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
    ], true), 'El runtime debe exigir la migración final o una sucesora.');
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
    $assert(str_contains($settings, 'http_response_code(410)')
        && str_contains($settings, 'las consultas de Mercado Libre solo se ejecutan desde el trabajo CLI'),
        'Los endpoints web heredados no deben ejecutar API.');
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
    $assert(!str_contains($routes, '/settings/manual-processing/interactive/step'),
        'No debe existir un disparador HTTP de trabajos.');
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

$failed = 0;
foreach ($tests as [$status, $name]) {
    echo strtoupper($status) . " {$name}\n";
    if ($status === 'fail') {
        $failed++;
    }
}
echo sprintf("%d tests, %d failures\n", count($tests), $failed);
exit($failed ? 1 : 0);
