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
$temporary = sys_get_temp_dir() . '/erp-storage-retention-' . bin2hex(random_bytes(5));
$private = $temporary . '/private';
$shared = $temporary . '/shared';
mkdir($private, 0700, true);
mkdir($shared . '/storage', 0700, true);
if (!defined('ERP_SHARED_ROOT')) {
    define('ERP_SHARED_ROOT', $shared);
}
if (!defined('ERP_RELEASE_ROOT')) {
    define('ERP_RELEASE_ROOT', $root);
}
putenv('APP_KEY=storage-retention-test-key-which-is-long-enough');
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('ML_WRITE_ENABLED=false');
require $root . '/vendor/autoload.php';

$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_storage_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();

    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Empresa prueba',1)");
    $companyId = (int) $pdo->lastInsertId();
    $account = $pdo->prepare(
        "INSERT INTO meli_accounts (company_id,account_name,meli_user_id,status)
         VALUES (?,'Cuenta prueba',990001,'conectado')"
    );
    $account->execute([$companyId]);
    $accountId = (int) $pdo->lastInsertId();
    $old = (new DateTimeImmutable('first day of -2 months'))->format('Y-m-10 12:00:00');
    $orderPayload = json_encode([
        'id' => 2000000000000001,
        'date_created' => $old,
        'buyer' => ['billing_info' => ['id' => 'LOCAL-TEST']],
    ], JSON_THROW_ON_ERROR);
    $order = $pdo->prepare(
        'INSERT INTO meli_orders
         (meli_account_id,external_order_id,date_created,status,total_amount,paid_amount,
          currency_id,raw_json,synced_at)
         VALUES (?,?,?,"paid",1000,1000,"COP",?,?)'
    );
    $order->execute([$accountId, 2000000000000001, $old, $orderPayload, $old]);
    $orderId = (int) $pdo->lastInsertId();
    $itemPayload = json_encode(['item' => ['id' => 'ITEM-LOCAL'], 'quantity' => 2], JSON_THROW_ON_ERROR);
    $item = $pdo->prepare(
        'INSERT INTO meli_order_items
         (meli_order_id,meli_account_id,external_item_id,title,quantity,unit_price,raw_json,created_at)
         VALUES (?,?,"ITEM-LOCAL","Producto local",2,500,?,?)'
    );
    $item->execute([$orderId, $accountId, $itemPayload, $old]);
    $itemId = (int) $pdo->lastInsertId();

    $api = $pdo->prepare(
        'INSERT INTO api_request_logs
         (meli_account_id,method,endpoint_path,http_status,outcome_class,reached_remote,
          actionable,risk_signal,was_blocked,duration_ms,created_at)
         VALUES (?,"GET","/orders/test",200,"success",1,0,0,0,20,?)'
    );
    $api->execute([$accountId, $old]);
    $notificationPayload = json_encode(['topic' => 'orders_v2', 'resource' => '/orders/test'], JSON_THROW_ON_ERROR);
    $notification = $pdo->prepare(
        'INSERT INTO meli_notification_events
         (notification_id,meli_account_id,topic,canonical_topic,remote_resource_id,
          resource,payload_json,payload_hash,status,erp_received_at,created_at)
         VALUES ("LOCAL-NOTIFICATION",?,"orders_v2","orders","test","/orders/test",?,?,"processed",?,?)'
    );
    $notification->execute([
        $accountId,
        $notificationPayload,
        hash('sha256', $notificationPayload),
        $old,
        $old,
    ]);
    // La fila activa comparte mes con el éxito. Debe permanecer en la cola sin
    // impedir que el resultado terminal del mismo mes se archive y se retire.
    $activeMonth = $old;
    $activePayload = json_encode(
        ['topic' => 'orders_v2', 'resource' => '/orders/still-pending'],
        JSON_THROW_ON_ERROR
    );
    $activeNotification = $pdo->prepare(
        'INSERT INTO meli_notification_events
         (notification_id,meli_account_id,topic,canonical_topic,remote_resource_id,
          resource,payload_json,payload_hash,status,erp_received_at,created_at)
         VALUES ("LOCAL-NOTIFICATION-ACTIVE",?,"orders_v2","orders","still-pending",
                 "/orders/still-pending",?,?,"queued",?,?)'
    );
    $activeNotification->execute([
        $accountId,
        $activePayload,
        hash('sha256', $activePayload),
        $activeMonth,
        $activeMonth,
    ]);
    $incidentMonth = (new DateTimeImmutable('first day of -4 months'))->format('Y-m-10 13:00:00');
    $incidentPayload = json_encode(
        ['topic' => 'questions', 'resource' => '/questions/no-longer-available'],
        JSON_THROW_ON_ERROR
    );
    $incidentNotification = $pdo->prepare(
        'INSERT INTO meli_notification_events
         (notification_id,meli_account_id,topic,canonical_topic,remote_resource_id,
          resource,payload_json,payload_hash,status,erp_received_at,created_at,updated_at)
         VALUES ("LOCAL-NOTIFICATION-INCIDENT",?,"questions","questions","gone",
                 "/questions/no-longer-available",?,?,"unknown_topic",?,?,?)'
    );
    $incidentNotification->execute([
        $accountId,
        $incidentPayload,
        hash('sha256', $incidentPayload),
        $incidentMonth,
        $incidentMonth,
        $incidentMonth,
    ]);
    $cron = $pdo->prepare(
        'INSERT INTO cron_health_checks
         (job_name,execution_source,status,duration_ms,created_at)
         VALUES ("process_sync_queue","scheduled_cli","success",30,?)'
    );
    $cron->execute([$old]);
    $financialOld = (new DateTimeImmutable('first day of -4 months'))->format('Y-m-10 12:00:00');
    $financialJob = $pdo->prepare(
        'INSERT INTO order_financial_recalc_jobs
         (meli_account_id,status,total_items,processed_items,completed_at,created_at)
         VALUES (?,"complete",1,1,?,?)'
    );
    $financialJob->execute([$accountId, $financialOld, $financialOld]);
    $financialJobId = (int) $pdo->lastInsertId();
    $financialItem = $pdo->prepare(
        'INSERT INTO order_financial_recalc_job_items
         (order_financial_recalc_job_id,meli_order_id,external_order_id,status,
          financial_status,processed_at,created_at)
         VALUES (?,?,"2000000000000001","complete","complete",?,?)'
    );
    $financialItem->execute([$financialJobId, $orderId, $financialOld, $financialOld]);

    $payloadResult = (new App\Services\RemotePayloadMigrationService())->migrateBatch(20);
    if ((int) $payloadResult['processed'] !== 2 || (int) $payloadResult['errors'] !== 0) {
        throw new RuntimeException('La migración no aprobó exactamente los dos payloads de prueba.');
    }
    $savedOrder = $pdo->query(
        'SELECT id,meli_account_id,raw_json FROM meli_orders WHERE id=' . $orderId
    )->fetch(PDO::FETCH_ASSOC);
    $savedItem = $pdo->query(
        'SELECT id,meli_account_id,raw_json FROM meli_order_items WHERE id=' . $itemId
    )->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($savedOrder)
        || !is_array($savedItem)
        || $savedOrder['raw_json'] !== null
        || $savedItem['raw_json'] !== null
        || (new App\Services\RawPayloadReader())->decode($savedOrder, 'meli_orders') !== json_decode($orderPayload, true)
        || (new App\Services\RawPayloadReader())->decode($savedItem, 'meli_order_items') !== json_decode($itemPayload, true)
    ) {
        throw new RuntimeException('Los payloads privados no reconstruyen exactamente su entidad original.');
    }

    $retention = (new App\Services\RetentionPolicyService())->run(100);
    if (
        (int) $retention['errors'] !== 0
        || (int) $retention['archives'] !== 5
        || (int) $retention['deleted'] !== 5
    ) {
        throw new RuntimeException('Archivo, resumen y retención no se completaron en orden seguro.');
    }
    $archives = $pdo->query(
        'SELECT dataset_key,period_month,storage_name,row_count,verified_at,rollup_verified_at
         FROM system_cold_archives ORDER BY dataset_key'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($archives) !== 5) {
        throw new RuntimeException('No se crearon los cinco archivos fríos esperados.');
    }
    if (
        (int) $pdo->query(
            'SELECT COUNT(*) FROM meli_notification_events
             WHERE notification_id="LOCAL-NOTIFICATION-ACTIVE" AND status="queued"'
        )->fetchColumn() !== 1
        || (int) $pdo->query(
            'SELECT COUNT(*) FROM meli_notification_events
             WHERE notification_id="LOCAL-NOTIFICATION"'
        )->fetchColumn() !== 0
    ) {
        throw new RuntimeException('La retención no separó correctamente trabajo activo y resultados terminales.');
    }
    foreach ($archives as $archive) {
        if (
            empty($archive['verified_at'])
            || empty($archive['rollup_verified_at'])
            || (int) $archive['row_count'] !== 1
        ) {
            throw new RuntimeException('Un archivo frío quedó sin verificación completa.');
        }
        $verified = (new App\Services\ColdArchiveService())->verifyFile(
            $private . '/cold-archives/' . $archive['storage_name'],
            (string) $archive['dataset_key'],
            (string) $archive['period_month']
        );
        if ((int) $verified['rows'] !== 1) {
            throw new RuntimeException('El archivo frío no devolvió su fila original.');
        }
    }
    $second = (new App\Services\RetentionPolicyService())->run(100);
    if ((int) $second['deleted'] !== 0 || (int) $second['archives'] !== 0 || (int) $second['errors'] !== 0) {
        throw new RuntimeException('La segunda ejecución de retención no fue idempotente.');
    }
    if (
        (int) $pdo->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn() !== 1
        || (int) $pdo->query('SELECT COUNT(*) FROM meli_order_items')->fetchColumn() !== 1
    ) {
        throw new RuntimeException('La retención modificó información comercial.');
    }
    if (
        (int) $pdo->query(
            'SELECT COUNT(*) FROM order_financial_recalc_jobs WHERE id=' . $financialJobId
        )->fetchColumn() !== 1
        || (int) $pdo->query(
            'SELECT COUNT(*) FROM order_financial_recalc_job_items
             WHERE order_financial_recalc_job_id=' . $financialJobId
        )->fetchColumn() !== 0
    ) {
        throw new RuntimeException('El detalle financiero no se compactó conservando su resumen padre.');
    }

    // Las referencias genéricas no tienen FK a cinco tablas comerciales. Al
    // desaparecer una entidad se debe retirar la referencia obsoleta, pero un
    // objeto compartido por otra entidad viva debe conservarse.
    $orderObjectId = (int) $pdo->query(
        "SELECT payload_object_id FROM remote_payload_references
         WHERE entity_table='meli_orders' AND entity_id={$orderId}"
    )->fetchColumn();
    $itemObjectId = (int) $pdo->query(
        "SELECT payload_object_id FROM remote_payload_references
         WHERE entity_table='meli_order_items' AND entity_id={$itemId}"
    )->fetchColumn();
    $pdo->exec(
        "UPDATE remote_payload_references
         SET payload_object_id={$orderObjectId}
         WHERE entity_table='meli_order_items' AND entity_id={$itemId}"
    );
    $pdo->exec(
        "UPDATE remote_payload_objects SET reference_count=2 WHERE id={$orderObjectId}"
    );
    $pdo->exec(
        "UPDATE remote_payload_objects SET reference_count=0 WHERE id={$itemObjectId}"
    );
    $pdo->exec("DELETE FROM meli_order_items WHERE id={$itemId}");

    $payloadStore = new App\Services\FileRemotePayloadStore();
    $dangling = $payloadStore->purgeDanglingReferences(100);
    if ((int) $dangling['deleted'] !== 1) {
        throw new RuntimeException('La referencia huérfana no se retiró exactamente una vez.');
    }
    if (
        (int) $pdo->query(
            "SELECT reference_count FROM remote_payload_objects WHERE id={$orderObjectId}"
        )->fetchColumn() !== 1
    ) {
        throw new RuntimeException('El contador del payload compartido no se recalculó.');
    }
    $orphans = $payloadStore->purgeOrphans(100);
    if (
        (int) $orphans['deleted'] !== 1
        || (int) $pdo->query(
            "SELECT COUNT(*) FROM remote_payload_objects WHERE id={$orderObjectId}"
        )->fetchColumn() !== 1
    ) {
        throw new RuntimeException('La purga no protegió correctamente el payload compartido.');
    }

    echo 'storage_retention_22519_ok payloads=2 archives=5 deleted=5 '
        . 'active_preserved=1 dangling_refs=' . (int) $dangling['deleted']
        . ' shared_payload_refcount=1 orphan_objects=' . (int) $orphans['deleted']
        . PHP_EOL;
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
