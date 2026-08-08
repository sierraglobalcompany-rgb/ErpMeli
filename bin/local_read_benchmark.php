<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Session;
use App\Services\MigrationDiagnosticService;
use App\Services\RequestContextService;
use App\Services\SaleReadService;

Database::useProfile('diagnostic');
$pdo = Database::connection();
$admin = $pdo->query(
    'SELECT id,name,email,role,is_temporary,expires_at
     FROM users
     WHERE role="admin" AND status=1
     ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($admin)) {
    fwrite(STDERR, "No existe un administrador local habilitado.\n");
    exit(2);
}
Session::start();
Session::put('user', $admin);
Session::closeReadOnly();

$measure = static function (callable $callback): array {
    $beforeMemory = memory_get_usage(true);
    $started = hrtime(true);
    $value = $callback();
    return [
        'milliseconds' => round((hrtime(true) - $started) / 1_000_000, 2),
        'memory_bytes' => max(0, memory_get_usage(true) - $beforeMemory),
        'result_count' => is_array($value)
            ? count(is_array($value['items'] ?? null) ? $value['items'] : $value)
            : 0,
    ];
};

$context = (new RequestContextService())->options();
$accountId = (int) ($context['accounts'][0]['id'] ?? 0);
$companyId = (int) ($context['accounts'][0]['company_id'] ?? 0);
$results = [
    'context' => $measure(static fn(): array => (new RequestContextService())->options()),
    'sales_cold' => $measure(static fn(): array => (new SaleReadService())->list([
        'company_id' => $companyId,
        'account_id' => $accountId,
        'page' => 1,
        'per_page' => 50,
    ])),
    'sales_hot' => $measure(static fn(): array => (new SaleReadService())->list([
        'company_id' => $companyId,
        'account_id' => $accountId,
        'page' => 1,
        'per_page' => 50,
    ])),
    'sales_all_accounts' => $measure(static fn(): array => (new SaleReadService())->list([
        'company_id' => 0,
        'account_id' => 0,
        'page' => 1,
        'per_page' => 50,
    ])),
    'diagnostic' => $measure(static fn(): array => (new MigrationDiagnosticService())->summary()),
];

echo json_encode(
    $results,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
