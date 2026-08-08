<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/erp-read-performance-' . bin2hex(random_bytes(5));
mkdir($temporary . '/storage/cache/read-models', 0700, true);
define('ERP_SHARED_ROOT', $temporary);
define('ERP_RELEASE_ROOT', $root);
putenv('APP_KEY=read-performance-test-key-which-is-long-enough');
putenv('SESSION_SECURE=false');
require $root . '/vendor/autoload.php';

$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_read_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");
try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();

    $pdo->exec(
        "INSERT INTO users (name,email,password_hash,role,status)
         VALUES ('Administrador','read-test@example.invalid','irrelevant','admin',1)"
    );
    $userId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Empresa de prueba',1)");
    $companyId = (int) $pdo->lastInsertId();
    $access = $pdo->prepare(
        "INSERT INTO user_company_access (user_id,company_id,access_role,granted_by)
         VALUES (?,?,'admin',?)"
    );
    $access->execute([$userId, $companyId, $userId]);
    $account = $pdo->prepare(
        "INSERT INTO meli_accounts (company_id,account_name,meli_user_id,status)
         VALUES (?, 'Cuenta de prueba', 9900001, 'conectado')"
    );
    $account->execute([$companyId]);
    $accountId = (int) $pdo->lastInsertId();

    $order = $pdo->prepare(
        'INSERT INTO meli_orders
         (meli_account_id,external_order_id,date_created,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES (?,?,?,"paid",1000,1000,"COP",UTC_TIMESTAMP())'
    );
    $item = $pdo->prepare(
        'INSERT INTO meli_order_items
         (meli_order_id,meli_account_id,external_item_id,title,quantity,unit_price)
         VALUES (?, ?, ?, "Producto de prueba", 1, 1000)'
    );
    $pdo->beginTransaction();
    for ($index = 0; $index < 10000; $index++) {
        $externalId = 3000000000000000 + $index;
        $date = (new DateTimeImmutable('2026-01-01 00:00:00'))
            ->modify('+' . $index . ' seconds')
            ->format('Y-m-d H:i:s');
        $order->execute([$accountId, $externalId, $date]);
        $item->execute([(int) $pdo->lastInsertId(), $accountId, 'ITEM-' . $index]);
    }
    $pdo->commit();

    App\Core\Session::start();
    App\Core\Session::put('user', [
        'id' => $userId,
        'name' => 'Administrador',
        'email' => 'read-test@example.invalid',
        'role' => 'admin',
        'is_temporary' => 0,
        'expires_at' => null,
    ]);
    App\Core\Session::closeReadOnly();

    $startedAt = microtime(true);
    $result = (new App\Services\SaleReadService())->list([
        'company_id' => $companyId,
        'account_id' => $accountId,
        'page' => 1,
        'per_page' => 50,
    ]);
    $duration = microtime(true) - $startedAt;
    if ((int) $result['total'] !== 10000 || count($result['items']) !== 50) {
        throw new RuntimeException('La paginación en dos fases no devolvió 50 de 10.000 ventas.');
    }
    if (count(array_unique(array_column($result['items'], 'sale_id'))) !== 50) {
        throw new RuntimeException('La página contiene ventas duplicadas.');
    }
    if ($duration > 2.0) {
        throw new RuntimeException('El listado frío superó 2 segundos: ' . round($duration, 3));
    }

    $diagnosticStarted = microtime(true);
    $diagnostic = (new App\Services\MigrationDiagnosticService())->summary();
    $diagnosticDuration = microtime(true) - $diagnosticStarted;
    if ((int) ($diagnostic['pending_count'] ?? -1) !== 0) {
        throw new RuntimeException('El diagnóstico reportó migraciones pendientes después de aplicar 140.');
    }
    if ($diagnosticDuration > 2.0) {
        throw new RuntimeException('El diagnóstico agrupado superó 2 segundos: ' . round($diagnosticDuration, 3));
    }
    echo 'read_performance_22515_ok sales_ms=' . (int) round($duration * 1000)
        . ' diagnostic_ms=' . (int) round($diagnosticDuration * 1000) . PHP_EOL;
} finally {
    App\Core\Database::setConnection($admin);
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
