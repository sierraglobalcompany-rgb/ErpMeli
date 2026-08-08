<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$saleRead = (string) file_get_contents($root . '/app/Services/SaleReadService.php');
$salesShow = (string) file_get_contents($root . '/app/Views/sales/show.php');
$settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$diagnosticService = (string) file_get_contents($root . '/app/Services/SupportDiagnosticLookupService.php');
$migration = (string) file_get_contents($root . '/database/migrations/239_sales_detail_item_currency_recovery_2_28_59.sql');
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
$version = trim((string) file_get_contents($root . '/VERSION'));

$itemSelectForbidden = "seller_sku,quantity,unit_price,full_unit_price,currency_id'";
if (str_contains($saleRead, $itemSelectForbidden)) {
    fwrite(STDERR, "SaleReadService still selects missing meli_order_items.currency_id\n");
    exit(1);
}

foreach ([
    'o.currency_id' => 'Sale detail must use order currency.',
    '$currency = (string) ($sale[\'orders\'][0][\'currency_id\'] ?? \'COP\');' => 'Sale view must keep order currency fallback.',
    'SupportDiagnosticLookupService' => 'Safe support diagnostic service must exist.',
    '/settings/diagnostics/error' => 'Safe diagnostic route must exist.',
    'supportDiagnostic' => 'SettingsController must expose diagnostic lookup.',
    'sqlstate' => 'Diagnostic lookup must expose sanitized SQLSTATE only.',
] as $needle => $message) {
    $haystack = in_array($needle, ['SupportDiagnosticLookupService', 'sqlstate'], true)
        ? $diagnosticService . $settingsController
        : ($needle === '/settings/diagnostics/error' ? $routes : $saleRead . $salesShow . $settingsController);
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (!version_compare($version, '2.28.59', '>=')) {
    fwrite(STDERR, "VERSION must preserve 2.28.59 or later\n");
    exit(1);
}

if (($manifest['version'] ?? '') !== $version
    || (int) ($manifest['minimum_migration'] ?? '') < 239) {
    fwrite(STDERR, "Runtime manifest must preserve migration 239 or later\n");
    exit(1);
}

if (!str_contains($migration, "('app.version','2.28.59'")) {
    fwrite(STDERR, "Migration 239 must set app.version 2.28.59\n");
    exit(1);
}

echo "sales_detail_recovery_22859_ok\n";
