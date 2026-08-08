<?php

declare(strict_types=1);

use App\Core\Env;

require_once dirname(__DIR__) . '/bootstrap.php';

$reference = (string) ($argv[1] ?? 'erpmeli_reference');
$candidate = (string) ($argv[2] ?? 'erpmeli_dev');
foreach ([$reference, $candidate] as $database) {
    if (preg_match('/^[a-zA-Z0-9_]+$/', $database) !== 1) {
        throw new RuntimeException('Nombre de clon no permitido.');
    }
}

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', '3306');
$user = (string) Env::get('DB_USER', '');
$password = (string) Env::get('DB_PASS', '');
$pdo = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$tables = [
    'meli_accounts',
    'meli_orders',
    'meli_order_items',
    'meli_packs',
    'meli_pack_orders',
    'meli_payments',
    'meli_shipments',
    'meli_order_billing_details',
    'meli_sale_financials',
    'meli_sale_financial_lines',
    'meli_sale_financial_allocations',
    'manual_campaigns',
    'sync_sales_audit_runs',
    'sales_control_closes',
];
$excluded = [
    'raw_json',
    'raw_path',
    'payload_json',
    'actions_json',
    // Contador operativo derivado de manual_campaign_items. La migración 190
    // lo reconcilia deliberadamente; no forma parte de la identidad comercial.
    'retry_items',
    'updated_at',
    'synced_at',
];
$excludedByTable = [
    // El clon diagnóstico invalida deliberadamente OAuth y desconecta las
    // cuentas. Ese estado operativo no es pérdida de identidad/configuración.
    'meli_accounts' => ['status'],
    // Los agregados y la versión de campaña se recalculan desde sus ítems en
    // migraciones de recuperación. La identidad, configuración y dimensiones
    // declaradas de la campaña sí continúan bajo comparación estricta.
    'manual_campaigns' => [
        'version_no',
        'completed_items',
        'failed_items',
        'skipped_items',
        'completed_units',
        'failed_units',
        'skipped_units',
        'primary_calls',
        'derived_calls',
        'outbound_calls',
    ],
];

/** @return list<string> */
$columns = static function (PDO $pdo, string $database, string $table) use ($excluded, $excludedByTable): array {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE BINARY TABLE_SCHEMA=BINARY :schema AND BINARY TABLE_NAME=BINARY :table
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute(['schema' => $database, 'table' => $table]);
    return array_values(array_filter(
        array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        static fn (string $column): bool => !in_array($column, $excluded, true)
            && !in_array($column, $excludedByTable[$table] ?? [], true)
    ));
};

/** @return array{rows:int,sha256:string} */
$digest = static function (
    PDO $pdo,
    string $database,
    string $table,
    array $selectedColumns
): array {
    if ($selectedColumns === []) {
        return ['rows' => 0, 'sha256' => hash('sha256', '')];
    }
    $quoted = implode(',', array_map(
        static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
        $selectedColumns
    ));
    $order = in_array('id', $selectedColumns, true) ? '`id`' : $quoted;
    $stmt = $pdo->query(
        'SELECT ' . $quoted . ' FROM `' . $database . '`.`' . $table . '` ORDER BY ' . $order
    );
    $hash = hash_init('sha256');
    $rows = 0;
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        hash_update(
            $hash,
            (json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            . PHP_EOL
        );
        ++$rows;
    }
    return ['rows' => $rows, 'sha256' => hash_final($hash)];
};

$failures = [];
$results = [];
foreach ($tables as $table) {
    $leftColumns = $columns($pdo, $reference, $table);
    $rightColumns = $columns($pdo, $candidate, $table);
    $common = array_values(array_intersect($leftColumns, $rightColumns));
    if ($common === []) {
        $failures[] = $table . ': sin contrato común';
        continue;
    }
    $left = $digest($pdo, $reference, $table, $common);
    $right = $digest($pdo, $candidate, $table, $common);
    $same = $left === $right;
    $results[$table] = [
        'same' => $same,
        'rows_reference' => $left['rows'],
        'rows_candidate' => $right['rows'],
        'columns_compared' => count($common),
    ];
    if (!$same) {
        $failures[] = $table;
    }
}

$sale = '2000014234269247';
$saleMetrics = [];
foreach ([$reference, $candidate] as $database) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT o.id) orders,
                COUNT(DISTINCT i.external_item_id) products,
                COALESCE(SUM(i.quantity),0) units
         FROM `' . $database . '`.meli_orders o
         INNER JOIN `' . $database . '`.meli_order_items i ON i.meli_order_id=o.id
         WHERE o.external_pack_id=:sale'
    );
    $stmt->execute(['sale' => $sale]);
    $saleMetrics[$database] = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}
if (
    ($saleMetrics[$reference] ?? []) !== ($saleMetrics[$candidate] ?? [])
    || ($saleMetrics[$candidate]['orders'] ?? 0) !== 2
    || ($saleMetrics[$candidate]['products'] ?? 0) !== 2
    || ($saleMetrics[$candidate]['units'] ?? 0) !== 3
) {
    $failures[] = 'venta ' . $sale;
}

echo json_encode([
    'ok' => $failures === [],
    'tables' => $results,
    'acceptance_sale' => $saleMetrics[$candidate] ?? [],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
