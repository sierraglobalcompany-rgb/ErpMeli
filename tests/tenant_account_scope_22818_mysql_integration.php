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
require $root . '/vendor/autoload.php';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_scope_22818_' . bin2hex(random_bytes(5));
$serverVersion = (string) $admin->query('SELECT VERSION()')->fetchColumn();
$databaseCollation = str_contains(strtolower($serverVersion), 'mariadb')
    ? 'utf8mb4_uca1400_ai_ci'
    : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$databaseCollation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    App\Services\SchemaInspectorService::clearCache();

    $pdo->exec(
        "INSERT INTO users (name,email,password_hash,role,status) VALUES
         ('Usuario parcial','partial@example.invalid','irrelevant','operador',1),
         ('Usuario sin alcance','closed@example.invalid','irrelevant','operador',1),
         ('Administrador','admin@example.invalid','irrelevant','admin',1)"
    );
    $partialUser = (int) $pdo->query("SELECT id FROM users WHERE email='partial@example.invalid'")->fetchColumn();
    $closedUser = (int) $pdo->query("SELECT id FROM users WHERE email='closed@example.invalid'")->fetchColumn();
    $adminUser = (int) $pdo->query("SELECT id FROM users WHERE email='admin@example.invalid'")->fetchColumn();

    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Empresa A',1),('Empresa B',1)");
    $companyA = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa A'")->fetchColumn();
    $companyB = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa B'")->fetchColumn();
    $grant = $pdo->prepare(
        "INSERT INTO user_company_access (user_id,company_id,access_role,granted_by)
         VALUES (?,?,'operador',?)"
    );
    $grant->execute([$partialUser, $companyA, $adminUser]);

    $account = $pdo->prepare(
        "INSERT INTO meli_accounts (company_id,account_name,meli_user_id,status)
         VALUES (?, ?, ?, 'conectado')"
    );
    $account->execute([$companyA, 'Cuenta A1', 991001]);
    $accountA1 = (int) $pdo->lastInsertId();
    $account->execute([$companyA, 'Cuenta A2', 991002]);
    $accountA2 = (int) $pdo->lastInsertId();
    $account->execute([$companyB, 'Cuenta B1', 992001]);
    $accountB1 = (int) $pdo->lastInsertId();

    $scope = new App\Services\AuthorizedBusinessScope();
    if ($scope->accountIds($partialUser) !== [$accountA1, $accountA2]) {
        throw new RuntimeException('El acceso empresarial heredado no incluyó sus cuentas antes de configurar ACL exacta.');
    }

    $acl = $pdo->prepare(
        "INSERT INTO user_meli_account_access (user_id,meli_account_id,access_role,granted_by)
         VALUES (?,?,'member',?)"
    );
    $acl->execute([$partialUser, $accountA2, $adminUser]);
    if ($scope->accountIds($partialUser) !== [$accountA2]) {
        throw new RuntimeException('La ACL exacta no redujo el alcance a una cuenta dentro de la empresa.');
    }
    if ($scope->accountIds($closedUser) !== []) {
        throw new RuntimeException('Un usuario sin empresa autorizada no falló cerrado.');
    }

    foreach ([$accountA1, $accountB1] as $foreignAccount) {
        try {
            $scope->account($foreignAccount, 0, $partialUser);
            throw new RuntimeException('Una cuenta no autorizada fue visible por acceso directo.');
        } catch (App\Core\HttpException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }
    if ((int) $scope->account($accountA2, 0, $partialUser)['id'] !== $accountA2) {
        throw new RuntimeException('La cuenta exacta autorizada no pudo consultarse.');
    }

    $generation = (new App\Services\SessionGenerationService())->current();
    App\Core\Session::put('user', [
        'id' => $partialUser,
        'name' => 'Usuario parcial',
        'email' => 'partial@example.invalid',
        'role' => 'operador',
        'is_temporary' => 0,
        'expires_at' => null,
        'session_generation' => $generation,
    ]);

    $payment = $pdo->prepare(
        'INSERT INTO meli_payments (meli_account_id,external_payment_id,transaction_amount,status,synced_at)
         VALUES (?, ?, ?, "approved", UTC_TIMESTAMP())'
    );
    $payment->execute([$accountA1, 81001, 10]);
    $payment->execute([$accountA2, 81002, 20]);
    $payment->execute([$accountB1, 81003, 30]);
    $paymentRows = (new App\Services\PaymentSummaryService())->list(['per_page' => 50]);
    if (count($paymentRows) !== 1 || (int) $paymentRows[0]['meli_account_id'] !== $accountA2) {
        throw new RuntimeException('Pagos expuso registros fuera de la ACL exacta de cuenta.');
    }
    $paymentTotals = (new App\Services\PaymentSummaryService())->totals([]);
    if ((int) $paymentTotals['count_rows'] !== 1 || (float) $paymentTotals['amount'] !== 20.0) {
        throw new RuntimeException('Los totales de pagos mezclaron cuentas no autorizadas.');
    }
    $paymentOptions = (new App\Services\PaymentSummaryService())->options();
    if (count($paymentOptions['accounts']) !== 1 || (int) $paymentOptions['accounts'][0]['id'] !== $accountA2) {
        throw new RuntimeException('El selector de pagos expuso cuentas no autorizadas.');
    }
    try {
        (new App\Services\PaymentSummaryService())->list(['account_id' => $accountA1]);
        throw new RuntimeException('Pagos aceptó una cuenta ajena por filtro directo.');
    } catch (App\Core\HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }

    $product = $pdo->prepare('INSERT INTO internal_products (company_id,internal_sku,name) VALUES (?,?,?)');
    $product->execute([$companyA, 'A-PRODUCT', 'Producto A']);
    $productA = (int) $pdo->lastInsertId();
    $product->execute([$companyB, 'B-PRODUCT', 'Producto B']);
    $item = $pdo->prepare(
        'INSERT INTO meli_items (meli_account_id,external_item_id,title,synced_at) VALUES (?,?,?,UTC_TIMESTAMP())'
    );
    $item->execute([$accountA1, 'MCO-A1', 'Item A1']);
    $itemA1 = (int) $pdo->lastInsertId();
    $item->execute([$accountA2, 'MCO-A2', 'Item A2']);
    $itemA2 = (int) $pdo->lastInsertId();
    $item->execute([$accountB1, 'MCO-B1', 'Item B1']);
    $linkService = new App\Services\ProductLinkService();
    $visibleItems = $linkService->meliItems([]);
    if (count($visibleItems) !== 1 || (int) $visibleItems[0]['id'] !== $itemA2) {
        throw new RuntimeException('Vinculación expuso publicaciones fuera de la ACL exacta.');
    }
    try {
        $linkService->link($productA, $itemA1);
        throw new RuntimeException('Vinculación aceptó una publicación de otra cuenta por ID directo.');
    } catch (App\Core\HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }
    $linkService->link($productA, $itemA2);
    $pdo->prepare(
        'INSERT INTO product_meli_links (internal_product_id,meli_account_id,meli_item_id,status) VALUES (?,?,?,"active")'
    )->execute([$productA, $accountA1, $itemA1]);
    $foreignLink = (int) $pdo->lastInsertId();
    try {
        $linkService->updateFactor($foreignLink, 2.0);
        throw new RuntimeException('Vinculación modificó un vínculo de otra cuenta por ID directo.');
    } catch (App\Core\HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }

    $run = $pdo->prepare(
        'INSERT INTO date_report_runs
         (company_id,issuer_company_id,customer_company_id,meli_account_id,date_from,date_to,status)
         VALUES (?,?,?,?,"2026-07-01","2026-07-31","borrador")'
    );
    $run->execute([$companyA, $companyA, $companyB, $accountA1]);
    $runA1 = (int) $pdo->lastInsertId();
    $run->execute([$companyA, $companyA, $companyB, $accountA2]);
    $runA2 = (int) $pdo->lastInsertId();
    $dateService = new App\Services\DateReportService();
    if (($dateService->findRun($runA1)['run'] ?? []) !== []) {
        throw new RuntimeException('Facturación por fechas expuso un run de otra cuenta por ID directo.');
    }
    if ((int) (($dateService->findRun($runA2)['run']['id'] ?? 0)) !== $runA2) {
        throw new RuntimeException('Facturación por fechas ocultó el run exacto autorizado.');
    }
    $visibleRuns = $dateService->listBillingRuns();
    if (count($visibleRuns) !== 1 || (int) $visibleRuns[0]['id'] !== $runA2) {
        throw new RuntimeException('El historial de facturación mezcló runs de cuentas no autorizadas.');
    }

    $catalog = $pdo->prepare(
        'INSERT INTO catalogs (name,slug,account_scope,meli_account_id,company_scope,company_id)
         VALUES (?,? ,"single_account",?,"single_company",?)'
    );
    $catalog->execute(['Catálogo A1', 'catalog-a1', $accountA1, $companyA]);
    $catalogA1 = (int) $pdo->lastInsertId();
    $catalog->execute(['Catálogo A2', 'catalog-a2', $accountA2, $companyA]);
    $catalogA2 = (int) $pdo->lastInsertId();
    $catalogItem = $pdo->prepare(
        'INSERT INTO catalog_items (catalog_id,meli_item_id,meli_account_id,external_item_id,title_snapshot)
         VALUES (?,?,?,?,?)'
    );
    $catalogItem->execute([$catalogA1, $itemA1, $accountA1, 'MCO-A1', 'Item A1']);
    $catalogItem->execute([$catalogA2, $itemA2, $accountA2, 'MCO-A2', 'Item A2']);
    $description = new App\Services\CatalogDescriptionJobService();
    try {
        $description->assertCatalogAuthorized($catalogA1);
        throw new RuntimeException('Descripciones aceptó un catálogo de otra cuenta.');
    } catch (App\Core\HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }
    $description->assertCatalogAuthorized($catalogA2);

    $pdo->prepare('INSERT INTO catalog_description_jobs (catalog_id,status) VALUES (?,"queued")')->execute([$catalogA1]);
    $jobA1 = (int) $pdo->lastInsertId();
    $catalogItemA1 = (int) $pdo->query('SELECT id FROM catalog_items WHERE catalog_id=' . $catalogA1)->fetchColumn();
    $pdo->prepare(
        'INSERT INTO catalog_description_job_items
         (catalog_description_job_id,catalog_item_id,meli_item_id,meli_account_id,external_item_id)
         VALUES (?,?,?,?,?)'
    )->execute([$jobA1, $catalogItemA1, $itemA1, $accountA1, 'MCO-A1']);
    try {
        $description->assertJobAuthorized($jobA1);
        throw new RuntimeException('Descripciones aceptó un job de otra cuenta por ID directo.');
    } catch (App\Core\HttpException $exception) {
        if ($exception->status !== 404) {
            throw $exception;
        }
    }

    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();

    echo "PASS tenant_account_scope_22818_mysql_integration\n";
} finally {
    App\Core\Database::setConnection($admin);
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
