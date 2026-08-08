<?php

declare(strict_types=1);

/**
 * Prueba conductual del restablecimiento sobre una base MariaDB desechable.
 * Nunca usa la base configurada del ERP ni realiza transporte HTTP.
 */

$host = (string) (getenv('ERP_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('ERP_TEST_DB_PORT') ?: '3306');
$user = (string) (getenv('ERP_TEST_DB_USER') ?: 'root');
$pass = (string) (getenv('ERP_TEST_DB_PASS') ?: '');
$database = 'erp_reset_' . bin2hex(random_bytes(6));
$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-reset-' . bin2hex(random_bytes(6));
$server = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if (!str_contains(strtolower((string) $server->query('SELECT VERSION()')->fetchColumn()), 'mariadb')) {
    throw new RuntimeException('Esta prueba exige MariaDB real.');
}

function resetAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeResetTestTree(string $path): void
{
    $resolved = realpath($path);
    $temporary = realpath(sys_get_temp_dir());
    if (
        $resolved === false
        || $temporary === false
        || !str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR)
        || !str_starts_with(basename($resolved), 'erp-reset-')
    ) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($resolved);
}

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $_ENV['DB_HOST'] = $host;
    $_ENV['DB_PORT'] = $port;
    $_ENV['DB_NAME'] = $database;
    $_ENV['DB_USER'] = $user;
    $_ENV['DB_PASS'] = $pass;
    $_ENV['APP_ENV'] = 'local';
    $_ENV['APP_URL'] = 'http://localhost';
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $_ENV['ERP_PRIVATE_PATH'] = $private;
    define('ERP_SHARED_ROOT', $private);
    require $root . '/bootstrap.php';
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    });

    $pdo = \App\Core\Database::connection();
    $pdo->exec(<<<'SQL'
CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(120),status TINYINT NOT NULL);
CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(20),status TINYINT,password_hash VARCHAR(255),last_login_at DATETIME NULL,updated_at DATETIME NULL);
CREATE TABLE user_company_access(user_id BIGINT UNSIGNED,company_id BIGINT UNSIGNED,PRIMARY KEY(user_id,company_id));
CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED,account_name VARCHAR(120),last_sync_at DATETIME NULL,last_error VARCHAR(255) NULL,updated_at DATETIME NULL);
CREATE TABLE meli_tokens(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,access_token_encrypted TEXT,updated_at DATETIME NULL);
CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT,setting_group VARCHAR(80),updated_at DATETIME NULL);
CREATE TABLE app_versions(version VARCHAR(30) PRIMARY KEY,notes TEXT);
CREATE TABLE schema_migrations(version VARCHAR(190) PRIMARY KEY);
CREATE TABLE internal_products(
 id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(120),
 source_meli_item_id BIGINT UNSIGNED NULL
);
CREATE TABLE product_meli_links(id BIGINT UNSIGNED PRIMARY KEY,internal_product_id BIGINT UNSIGNED,meli_item_id BIGINT UNSIGNED);
CREATE TABLE catalogs(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(120));
CREATE TABLE catalog_items(id BIGINT UNSIGNED PRIMARY KEY,catalog_id BIGINT UNSIGNED,meli_item_id BIGINT UNSIGNED);
CREATE TABLE monthly_reports(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE monthly_report_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED);
CREATE TABLE monthly_adjustments(id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED);
CREATE TABLE date_report_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED);
CREATE TABLE date_report_item_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED);
CREATE TABLE sales_control_fiscal_job_items(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,meli_order_id BIGINT UNSIGNED);
CREATE TABLE system_backup_archives(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36),storage_name VARCHAR(255),
 size_bytes BIGINT,checksum_sha256 CHAR(64),manifest_sha256 CHAR(64),requested_at DATETIME(3),
 verified_at DATETIME(3),status VARCHAR(20),deleted_at DATETIME NULL
);
CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED,message VARCHAR(100));
CREATE TABLE order_financial_recalc_jobs(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL
);
CREATE TABLE system_work_queue_projection(
 id BIGINT UNSIGNED PRIMARY KEY,queue_key VARCHAR(80),source_table VARCHAR(100),
 source_id VARCHAR(100),meli_account_id BIGINT UNSIGNED NULL
);
CREATE TABLE system_work_queue_run_items(
 id BIGINT UNSIGNED PRIMARY KEY,projection_id BIGINT UNSIGNED NULL,
 queue_key VARCHAR(80),source_table VARCHAR(100),source_id VARCHAR(100),
 CONSTRAINT fk_test_work_projection FOREIGN KEY(projection_id)
   REFERENCES system_work_queue_projection(id) ON DELETE SET NULL
);
CREATE TABLE app_notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED,message VARCHAR(100));
CREATE TABLE product_match_suggestions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED,status VARCHAR(20));
CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE sale_pack_rebuild_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE sale_pack_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE meli_orders(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,external_order_id VARCHAR(40),
 last_sync_at DATETIME NULL,last_error VARCHAR(255) NULL
);
CREATE TABLE meli_order_items(
 id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,
 external_item_id VARCHAR(40),
 CONSTRAINT fk_test_order_item_order FOREIGN KEY(meli_order_id)
   REFERENCES meli_orders(id) ON DELETE CASCADE
);
CREATE TABLE order_financial_recalc_job_items(
 id BIGINT UNSIGNED PRIMARY KEY,order_financial_recalc_job_id BIGINT UNSIGNED,
 meli_order_id BIGINT UNSIGNED,
 CONSTRAINT fk_test_financial_item_job FOREIGN KEY(order_financial_recalc_job_id)
   REFERENCES order_financial_recalc_jobs(id) ON DELETE CASCADE,
 CONSTRAINT fk_test_financial_item_order FOREIGN KEY(meli_order_id)
   REFERENCES meli_orders(id) ON DELETE CASCADE
);
CREATE TABLE manual_campaigns(
 id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24) NOT NULL
);
CREATE TABLE manual_campaign_items(
 id BIGINT UNSIGNED PRIMARY KEY,manual_campaign_id BIGINT UNSIGNED NOT NULL,
 meli_account_id BIGINT UNSIGNED NULL,
 CONSTRAINT fk_test_manual_campaign_item FOREIGN KEY(manual_campaign_id)
   REFERENCES manual_campaigns(id) ON DELETE CASCADE
);
CREATE TABLE manual_campaign_steps(
 id BIGINT UNSIGNED PRIMARY KEY,manual_campaign_id BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_test_manual_campaign_step FOREIGN KEY(manual_campaign_id)
   REFERENCES manual_campaigns(id) ON DELETE CASCADE
);
CREATE TABLE manual_processing_sessions(
 id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24) NOT NULL
);
CREATE TABLE manual_processing_items(
 id BIGINT UNSIGNED PRIMARY KEY,manual_processing_session_id BIGINT UNSIGNED NOT NULL,
 meli_account_id BIGINT UNSIGNED NULL,
 CONSTRAINT fk_test_manual_session_item FOREIGN KEY(manual_processing_session_id)
   REFERENCES manual_processing_sessions(id) ON DELETE CASCADE
);
CREATE TABLE manual_processing_events(
 id BIGINT UNSIGNED PRIMARY KEY,manual_processing_session_id BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_test_manual_session_event FOREIGN KEY(manual_processing_session_id)
   REFERENCES manual_processing_sessions(id) ON DELETE CASCADE
);
CREATE TABLE sales_control_fiscal_items(
 id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED
);
CREATE TABLE sales_control_fiscal_snapshots(
 id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED
);
CREATE TABLE sync_sales_audit_run_orders(
 id BIGINT UNSIGNED PRIMARY KEY,found_local_order_id BIGINT UNSIGNED NULL
);
CREATE TABLE sync_sales_audit_remote_ids(
 id BIGINT UNSIGNED PRIMARY KEY,found_local_order_id BIGINT UNSIGNED NULL
);
CREATE TABLE meli_sale_financials(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,
 identity_type ENUM('pack','order'),external_sale_id VARCHAR(40)
);
CREATE TABLE meli_sale_financial_allocations(
 id BIGINT UNSIGNED PRIMARY KEY,meli_order_item_id BIGINT UNSIGNED,
 CONSTRAINT fk_test_allocation_item FOREIGN KEY(meli_order_item_id)
   REFERENCES meli_order_items(id) ON DELETE CASCADE
);
CREATE TABLE meli_sale_financial_lines(
 id BIGINT UNSIGNED PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED,
 external_order_id VARCHAR(40) NULL
);
CREATE TABLE meli_packs(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,external_pack_id VARCHAR(40)
);
CREATE TABLE meli_pack_orders(
 meli_pack_id BIGINT UNSIGNED,meli_order_id BIGINT UNSIGNED,
 PRIMARY KEY(meli_pack_id,meli_order_id)
);
CREATE TABLE meli_pack_order_expectations(
 id BIGINT UNSIGNED PRIMARY KEY,meli_pack_id BIGINT UNSIGNED,meli_order_id BIGINT UNSIGNED NULL,
 CONSTRAINT fk_test_expectation_pack FOREIGN KEY(meli_pack_id)
   REFERENCES meli_packs(id) ON DELETE CASCADE,
 CONSTRAINT fk_test_expectation_order FOREIGN KEY(meli_order_id)
   REFERENCES meli_orders(id) ON DELETE SET NULL
);
CREATE TABLE sale_pack_reconciliation_history(
 id BIGINT UNSIGNED PRIMARY KEY,meli_pack_id BIGINT UNSIGNED,
 CONSTRAINT fk_test_pack_history FOREIGN KEY(meli_pack_id)
   REFERENCES meli_packs(id) ON DELETE CASCADE
);
CREATE TABLE sale_pack_relation_repair_audit(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,
 meli_order_id BIGINT UNSIGNED,previous_meli_pack_id BIGINT UNSIGNED,
 canonical_meli_pack_id BIGINT UNSIGNED NULL
);
CREATE TABLE meli_items(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,external_item_id VARCHAR(40)
);
CREATE TABLE product_import_sources(
 id BIGINT UNSIGNED PRIMARY KEY,source_meli_item_id BIGINT UNSIGNED,
 CONSTRAINT fk_test_product_import_item FOREIGN KEY(source_meli_item_id)
   REFERENCES meli_items(id) ON DELETE CASCADE
);
CREATE TABLE api_circuit_breakers(
 id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED
);
CREATE TABLE api_guard_admin_actions(
 id BIGINT UNSIGNED PRIMARY KEY,api_circuit_breaker_id BIGINT UNSIGNED NULL,
 CONSTRAINT fk_test_guard_circuit FOREIGN KEY(api_circuit_breaker_id)
   REFERENCES api_circuit_breakers(id) ON DELETE SET NULL
);
CREATE TABLE ml_insights_account_capabilities(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_catalog_competition(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_item_performance(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_item_prices(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_moderations(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_price_history(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_reputation_snapshots(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE ml_insights_sync_jobs(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED);
CREATE TABLE test_protected_order_refs(
 id BIGINT UNSIGNED PRIMARY KEY,meli_order_id BIGINT UNSIGNED,
 CONSTRAINT fk_test_protected_order FOREIGN KEY(meli_order_id)
   REFERENCES meli_orders(id) ON DELETE RESTRICT
);
CREATE TABLE system_cold_archive_memberships(
 archive_id BIGINT UNSIGNED,source_table VARCHAR(100),source_id BIGINT UNSIGNED,
 row_sha256 CHAR(64),source_deleted_at DATETIME(3) NULL,stale_at DATETIME(3) NULL,
 verification_error VARCHAR(80) NULL,
 PRIMARY KEY(archive_id,source_table,source_id)
);
CREATE TABLE remote_payload_objects(
 id BIGINT UNSIGNED PRIMARY KEY,payload_sha256 CHAR(64),storage_name VARCHAR(255),
 reference_count INT UNSIGNED NOT NULL
);
CREATE TABLE remote_payload_references(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payload_object_id BIGINT UNSIGNED,
 entity_table ENUM('meli_orders','meli_shipments','meli_payments','meli_packs','meli_order_items'),
 entity_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED
);
SQL);
    $pdo->exec((string) file_get_contents(
        $root . '/database/migrations/150_imported_meli_data_reset_2_26_3.sql'
    ));
    $pdo->exec(
        "INSERT INTO companies VALUES(1,'Empresa',1),(2,'Otra empresa',1);
         INSERT INTO users VALUES(1,'admin',1,'x',NULL,NULL);
         INSERT INTO user_company_access VALUES(1,1);
         INSERT INTO meli_accounts VALUES
           (10,1,'Cuenta',NULL,NULL,NULL),(11,2,'Cuenta ajena',NULL,NULL,NULL);
         INSERT INTO meli_tokens VALUES(1,10,'ciphertext',NULL);
         INSERT INTO internal_products VALUES(1,'Producto',703);
         INSERT INTO product_meli_links VALUES(1,1,700);
         INSERT INTO monthly_reports VALUES(1,10);
         INSERT INTO meli_orders VALUES
           (100,10,'BORRABLE',NULL,NULL),
           (101,10,'EVIDENCIA',NULL,NULL),
           (102,10,'EXPECTATIVA',NULL,NULL),
           (103,10,'ASIGNACION',NULL,NULL),
           (104,10,'FISCAL',NULL,NULL),
           (105,10,'FINANCIERA',NULL,NULL),
           (106,10,'RESTRINGIDA',NULL,NULL),
           (107,10,'LINEA-FINANCIERA',NULL,NULL),
           (108,10,'AUDITORIA-PACK',NULL,NULL);
         INSERT INTO monthly_report_orders VALUES(1,101);
         INSERT INTO meli_order_items VALUES(1003,103,10,'ITEM-ASIGNADO');
         INSERT INTO meli_sale_financial_allocations VALUES(1,1003);
         INSERT INTO sales_control_fiscal_items VALUES(1,104);
         INSERT INTO sales_control_fiscal_snapshots VALUES(1,104);
         INSERT INTO meli_sale_financials VALUES(1,10,'order','FINANCIERA');
         INSERT INTO meli_sale_financials VALUES(3,10,'pack','PACK-CON-LINEA');
         INSERT INTO meli_sale_financial_lines VALUES(1,3,'LINEA-FINANCIERA');
         INSERT INTO meli_packs VALUES
           (200,10,'PACK-BORRABLE'),(201,10,'PACK-EXPECTATIVA'),
           (202,10,'PACK-HISTORIA'),(203,10,'PACK-FINANCIERO'),
           (204,10,'PACK-PREVIO-AUDITADO'),(205,10,'PACK-CANONICO-AUDITADO');
         INSERT INTO meli_pack_order_expectations VALUES(1,201,102);
         INSERT INTO sale_pack_reconciliation_history VALUES(1,202);
         INSERT INTO meli_sale_financials VALUES(2,10,'pack','PACK-FINANCIERO');
         INSERT INTO sale_pack_relation_repair_audit VALUES(1,10,108,204,205);
         INSERT INTO meli_items VALUES
           (700,10,'ITEM-VINCULADO'),(701,10,'ITEM-IMPORTADO'),
           (702,10,'ITEM-BORRABLE'),(703,10,'ITEM-PRODUCTO-INTERNO');
         INSERT INTO product_import_sources VALUES(1,701);
         INSERT INTO api_circuit_breakers VALUES(300,10),(301,10);
         INSERT INTO api_guard_admin_actions VALUES(1,300);
         INSERT INTO ml_insights_account_capabilities VALUES(1,10);
         INSERT INTO ml_insights_catalog_competition VALUES(1,10);
         INSERT INTO ml_insights_item_performance VALUES(1,10);
         INSERT INTO ml_insights_item_prices VALUES(1,10);
         INSERT INTO ml_insights_moderations VALUES(1,10);
         INSERT INTO ml_insights_price_history VALUES(1,10);
         INSERT INTO ml_insights_reputation_snapshots VALUES(1,10);
         INSERT INTO ml_insights_sync_jobs VALUES(1,10);
         INSERT INTO test_protected_order_refs VALUES(1,106);
         INSERT INTO api_request_logs(meli_account_id,message) VALUES(10,'importado');
         INSERT INTO order_financial_recalc_jobs VALUES(400,NULL);
         INSERT INTO order_financial_recalc_job_items VALUES(500,400,100);
         INSERT INTO manual_campaigns VALUES(600,'completed'),(602,'completed');
         INSERT INTO manual_campaign_items VALUES
           (601,600,10),(603,602,10),(604,602,11);
         INSERT INTO manual_campaign_steps VALUES(605,600),(606,602);
         INSERT INTO manual_processing_sessions VALUES(700,'completed'),(702,'completed');
         INSERT INTO manual_processing_items VALUES
           (701,700,10),(703,702,10),(704,702,11);
         INSERT INTO manual_processing_events VALUES(705,700),(706,702);
         INSERT INTO system_work_queue_projection VALUES
           (1,'notification_fallback','meli_notification_work_items','900',10),
           (2,'financial_recalc','order_financial_recalc_jobs','400',NULL),
           (3,'operational_maintenance','cron_task_state','maintenance',NULL);
         INSERT INTO system_work_queue_run_items VALUES
           (10,1,'notification_fallback','meli_notification_work_items','900');
         INSERT INTO system_cold_archive_memberships
           VALUES(1,'api_request_logs',1,REPEAT('a',64),NULL,NULL,NULL),
                 (2,'order_financial_recalc_job_items',500,REPEAT('b',64),NULL,NULL,NULL);
         INSERT INTO app_notifications(meli_account_id,message) VALUES(10,'derivada');
         INSERT INTO product_match_suggestions(meli_account_id,status) VALUES(10,'pending');
         INSERT INTO sale_financial_reconciliation_jobs(meli_account_id) VALUES(10);
         INSERT INTO sale_pack_rebuild_runs(meli_account_id) VALUES(10);
         INSERT INTO sale_pack_reconciliation_jobs(meli_account_id) VALUES(10);
         INSERT INTO remote_payload_objects VALUES
           (50,REPEAT('a',64),'aa/shared.json.gz',3),
           (51,REPEAT('b',64),CONCAT('bb/bb/',REPEAT('b',64),'.json.gz'),1);
         INSERT INTO remote_payload_references(payload_object_id,entity_table,entity_id,meli_account_id)
           VALUES(50,'meli_orders',100,10),(50,'meli_orders',101,10),
                 (50,'meli_orders',106,10),(51,'meli_orders',100,10);"
    );

    $service = new \App\Services\ImportedMeliDataResetService();
    $changed = $service->analyze(1);
    $changedArchive = (new \App\Services\BackupArchiveService())->create(
        (string) $changed['public_id'],
        'Prueba de cambio posterior'
    );
    $pdo->prepare(
        'INSERT INTO system_backup_archives
         (public_id,storage_name,size_bytes,checksum_sha256,manifest_sha256,requested_at,verified_at,status)
         VALUES(?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),"ready")'
    )->execute([
        (string) $changed['public_id'],
        $changedArchive['storage_name'],
        $changedArchive['size'],
        $changedArchive['checksum'],
        $changedArchive['manifest_checksum'],
    ]);
    $changedBackupId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO api_request_logs(meli_account_id,message) VALUES(10,'posterior')");
    try {
        $service->authorize(
            (int) $changed['id'],
            1,
            'ELIMINAR DATOS DE MERCADO LIBRE',
            (string) $changed['confirmation_challenge'],
            $changedBackupId
        );
        throw new RuntimeException('Un conjunto cambiado fue autorizado.');
    } catch (RuntimeException $expected) {
        resetAssert(
            str_contains($expected->getMessage(), 'cambiaron después del análisis'),
            'El cambio de candidatos no produjo una explicación segura.'
        );
    }
    resetAssert(
        !(new \App\Services\DatabaseMutationFreezeService())->active(),
        'Una autorización rechazada dejó el freeze activo.'
    );

    $first = $service->analyze(1);
    $requestId = (int) $first['id'];
    $archive = (new \App\Services\BackupArchiveService())->create(
        (string) $first['public_id'],
        'Prueba de restablecimiento'
    );
    $pdo->prepare(
        'INSERT INTO system_backup_archives
         (public_id,storage_name,size_bytes,checksum_sha256,manifest_sha256,requested_at,verified_at,status)
         VALUES(?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),"ready")'
    )->execute([
        (string) $first['public_id'],
        $archive['storage_name'],
        $archive['size'],
        $archive['checksum'],
        $archive['manifest_checksum'],
    ]);
    $backupId = (int) $pdo->lastInsertId();
    try {
        $service->authorize(
            $requestId,
            1,
            'ELIMINAR DATOS DE MERCADO LIBRE',
            (string) $first['confirmation_challenge'],
            $backupId + 9999
        );
        throw new RuntimeException('Se aceptó una copia distinta de la mostrada.');
    } catch (RuntimeException $expected) {
        resetAssert(
            str_contains($expected->getMessage(), 'Cree y verifique una copia'),
            'El rechazo de la copia exacta no fue comprensible.'
        );
    }
    $service->authorize(
        $requestId,
        1,
        'ELIMINAR DATOS DE MERCADO LIBRE',
        (string) $first['confirmation_challenge'],
        $backupId
    );
    try {
        $service->authorize(
            $requestId,
            1,
            'ELIMINAR DATOS DE MERCADO LIBRE',
            (string) $first['confirmation_challenge'],
            $backupId
        );
        throw new RuntimeException('Una segunda pestaña volvió a autorizar la misma solicitud.');
    } catch (RuntimeException $expected) {
        resetAssert(
            str_contains($expected->getMessage(), 'ya no puede autorizarse'),
            'La segunda pestaña no recibió un cierre de estado comprensible.'
        );
    }
    resetAssert((new \App\Services\DatabaseMutationFreezeService())->active(), 'El freeze no quedó activo.');
    (new \App\Services\DatabaseMutationFreezeService())->heartbeat(
        'imported_data_reset',
        'request-' . $requestId
    );
    $freezeStatus = (new \App\Services\DatabaseMutationFreezeService())->status();
    resetAssert(
        (int) ($freezeStatus['context']['request_id'] ?? 0) === $requestId,
        'El heartbeat perdió el identificador que habilita los controles seguros.'
    );
    try {
        $service->analyze(1);
        throw new RuntimeException('Se creó otro análisis durante un restablecimiento activo.');
    } catch (RuntimeException $expected) {
        resetAssert(
            str_contains($expected->getMessage(), 'Ya existe un restablecimiento activo'),
            'El bloqueo de solicitudes simultáneas no fue comprensible.'
        );
    }
    $service->pause($requestId, 1);
    $service->runNext($requestId);
    resetAssert((string) $service->request($requestId, 1)['status'] === 'paused', 'La pausa no quedó persistida.');
    $service->resume($requestId, 1);
    resetAssert((string) $service->request($requestId, 1)['status'] === 'authorized', 'No reanudó con la copia vinculada.');
    for ($i = 0; $i < 100; $i++) {
        try {
            $result = $service->runNext($requestId);
        } catch (Throwable $error) {
            throw new RuntimeException(
                'Falló el primer ciclo ' . $i . ': ' . $error->getMessage(),
                0,
                $error
            );
        }
        if (($result['status'] ?? '') === 'completed') {
            break;
        }
    }
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM api_request_logs')->fetchColumn() === 0, 'No retiró el log importado.');
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM system_work_queue_projection WHERE id IN (1,2)'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM system_work_queue_projection WHERE id=3'
        )->fetchColumn() === 1,
        'La proyección dejó trabajos retirados o eliminó mantenimiento global.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM order_financial_recalc_jobs WHERE id=400'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE id=500'
        )->fetchColumn() === 0,
        'El trabajo financiero sin cuenta directa no se aisló por su orden.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaigns WHERE id=600'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaign_items WHERE id=601'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaign_steps WHERE id=605'
        )->fetchColumn() === 0,
        'La campaña autorizada quedó como cabecera huérfana o conservó sus recursos.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaigns WHERE id=602'
        )->fetchColumn() === 1
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaign_items WHERE manual_campaign_id=602'
        )->fetchColumn() === 2
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_campaign_steps WHERE id=606'
        )->fetchColumn() === 1,
        'Una campaña mixta de otra empresa fue eliminada o quedó parcialmente alterada.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_sessions WHERE id=700'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_items WHERE id=701'
        )->fetchColumn() === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_events WHERE id=705'
        )->fetchColumn() === 0,
        'La sesión manual autorizada quedó como cabecera huérfana.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_sessions WHERE id=702'
        )->fetchColumn() === 1
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_items '
            . 'WHERE manual_processing_session_id=702'
        )->fetchColumn() === 2
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM manual_processing_events WHERE id=706'
        )->fetchColumn() === 1,
        'Una sesión mixta de otra empresa fue eliminada o quedó parcialmente alterada.'
    );
    foreach ([
        'app_notifications',
        'product_match_suggestions',
        'sale_financial_reconciliation_jobs',
        'sale_pack_rebuild_runs',
        'sale_pack_reconciliation_jobs',
        'ml_insights_account_capabilities',
        'ml_insights_catalog_competition',
        'ml_insights_item_performance',
        'ml_insights_item_prices',
        'ml_insights_moderations',
        'ml_insights_price_history',
        'ml_insights_reputation_snapshots',
        'ml_insights_sync_jobs',
    ] as $derivedTable) {
        resetAssert(
            (int) $pdo->query('SELECT COUNT(*) FROM `' . $derivedTable . '`')->fetchColumn() === 0,
            'No retiró el trabajo derivado ' . $derivedTable . '.'
        );
    }
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM meli_orders WHERE id=100')->fetchColumn() === 0, 'No retiró la orden elegible.');
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM meli_orders WHERE id=101')->fetchColumn() === 1, 'Alteró evidencia mensual.');
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM meli_orders WHERE id IN (102,103,104,105,107,108)'
        )->fetchColumn() === 6,
        'Alteró órdenes que sostienen expectativas, distribución, líneas financieras, '
        . 'auditoría de pack o evidencia fiscal.'
    );
    resetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM meli_orders WHERE id=106')->fetchColumn() === 1
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM remote_payload_references
             WHERE entity_table="meli_orders" AND entity_id=106'
        )->fetchColumn() === 1,
        'Una fila retenida por integridad perdió su payload antes de confirmar el borrado.'
    );
    resetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM meli_packs WHERE id=200')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM meli_packs WHERE id IN (201,202,203,204,205)')
            ->fetchColumn() === 5,
        'La política de packs no separó ruido y evidencia.'
    );
    resetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM meli_items WHERE id=702')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM meli_items WHERE id IN (700,701,703)')
            ->fetchColumn() === 3,
        'La política de publicaciones alteró vínculos o fuentes importadas por personas.'
    );
    resetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM api_circuit_breakers WHERE id=301')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM api_circuit_breakers WHERE id=300')->fetchColumn() === 1
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM api_guard_admin_actions WHERE api_circuit_breaker_id=300'
        )->fetchColumn() === 1,
        'El reinicio de circuitos alteró la bitácora administrativa.'
    );
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM meli_tokens')->fetchColumn() === 1, 'Alteró tokens.');
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM product_meli_links')->fetchColumn() === 1, 'Alteró vínculos humanos.');
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM remote_payload_references WHERE entity_id=100')->fetchColumn() === 0, 'Dejó una referencia huérfana.');
    resetAssert((int) $pdo->query('SELECT reference_count FROM remote_payload_objects WHERE id=50')->fetchColumn() === 2, 'El objeto compartido perdió su referencia válida.');
    resetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM remote_payload_objects WHERE id=51')
            ->fetchColumn() === 0,
        'El objeto privado sin referencias quedó creciendo después del restablecimiento.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM system_cold_archive_memberships
             WHERE source_table="api_request_logs" AND source_id=1
               AND source_deleted_at IS NOT NULL'
        )->fetchColumn() === 1,
        'El restablecimiento dejó una membresía fría viva apuntando a una fila eliminada.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM system_cold_archive_memberships
             WHERE source_table="order_financial_recalc_job_items" AND source_id=500
               AND source_deleted_at IS NOT NULL'
        )->fetchColumn() === 1,
        'La cascada financiera dejó viva la membresía fría de una hija eliminada.'
    );
    resetAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM system_work_queue_run_items '
            . 'WHERE id=10 AND projection_id IS NULL'
        )->fetchColumn() === 1,
        'La bitácora de selección no conservó su evidencia al retirar la proyección.'
    );
    resetAssert(!(new \App\Services\DatabaseMutationFreezeService())->active(), 'El freeze no se liberó tras verificar.');
    resetAssert((new \App\Services\EmergencyControlService())->apiStopped(), 'La API no permaneció detenida.');
    resetAssert((new \App\Services\EmergencyControlService())->automationStopped(), 'La automatización no permaneció detenida.');

    $second = $service->analyze(1);
    $secondArchive = (new \App\Services\BackupArchiveService())->create(
        (string) $second['public_id'],
        'Segunda ejecución idempotente'
    );
    $pdo->prepare(
        'INSERT INTO system_backup_archives
         (public_id,storage_name,size_bytes,checksum_sha256,manifest_sha256,requested_at,verified_at,status)
         VALUES(?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),"ready")'
    )->execute([
        (string) $second['public_id'],
        $secondArchive['storage_name'],
        $secondArchive['size'],
        $secondArchive['checksum'],
        $secondArchive['manifest_checksum'],
    ]);
    $service->authorize(
        (int) $second['id'],
        1,
        'ELIMINAR DATOS DE MERCADO LIBRE',
        (string) $second['confirmation_challenge'],
        (int) $pdo->lastInsertId()
    );
    for ($i = 0; $i < 100; $i++) {
        $result = $service->runNext((int) $second['id']);
        if (($result['status'] ?? '') === 'completed') {
            break;
        }
    }
    resetAssert((int) $pdo->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn() === 8, 'La segunda ejecución no fue idempotente.');

    $fencing = $service->analyze(1);
    $fencingId = (int) $fencing['id'];
    $pdo->prepare(
        'UPDATE imported_data_reset_requests
         SET status="running",lease_owner="reset-current-owner",
             lease_generation=9,
             lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE)
         WHERE id=:id'
    )->execute(['id' => $fencingId]);
    $failClaimed = new ReflectionMethod($service, 'failClaimed');
    $failClaimed->invoke(
        $service,
        $fencingId,
        'reset-current-owner',
        8,
        new RuntimeException('fallo tardío')
    );
    $fencingStatus = $pdo->prepare(
        'SELECT status,lease_owner,lease_generation
         FROM imported_data_reset_requests WHERE id=:id'
    );
    $fencingStatus->execute(['id' => $fencingId]);
    $fencingRow = $fencingStatus->fetch(PDO::FETCH_ASSOC);
    resetAssert(
        is_array($fencingRow)
        && $fencingRow['status'] === 'running'
        && $fencingRow['lease_owner'] === 'reset-current-owner'
        && (int) $fencingRow['lease_generation'] === 9,
        'Un worker vencido degradó el restablecimiento vigente.'
    );
    $failClaimed->invoke(
        $service,
        $fencingId,
        'reset-current-owner',
        9,
        new RuntimeException('fallo vigente')
    );
    $fencingStatus->execute(['id' => $fencingId]);
    $fencingRow = $fencingStatus->fetch(PDO::FETCH_ASSOC);
    resetAssert(
        is_array($fencingRow)
        && $fencingRow['status'] === 'failed'
        && $fencingRow['lease_owner'] === null,
        'El propietario vigente no pudo registrar su propio fallo.'
    );

    $pdo->exec('CREATE TABLE meli_future_unknown(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED)');
    try {
        $service->analyze(1);
        throw new RuntimeException('La tabla desconocida no bloqueó el análisis.');
    } catch (RuntimeException $expected) {
        resetAssert(str_contains($expected->getMessage(), 'sin una política')
            || str_contains($expected->getMessage(), 'inventario cerrado'), 'Diagnóstico inesperado para tabla desconocida.');
    }

    $request = ['created_at' => gmdate('Y-m-d H:i:s', time() - 10)];
    if (!is_dir(\App\Core\AppPaths::backups())) {
        mkdir(\App\Core\AppPaths::backups(), 0750, true);
    }
    $archive = \App\Core\AppPaths::backups() . '/altered.backup';
    file_put_contents($archive, 'contenido-alterado');
    $pdo->prepare(
        'INSERT INTO system_backup_archives
         (public_id,storage_name,size_bytes,checksum_sha256,manifest_sha256,requested_at,verified_at,status)
         VALUES(UUID(),"altered.backup",?,REPEAT("b",64),REPEAT("c",64),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),"ready")'
    )->execute([filesize($archive)]);
    $method = new ReflectionMethod($service, 'freshVerifiedBackup');
    $backup = $method->invoke($service, $request, true);
    resetAssert($backup === null, 'Una copia alterada fue aceptada.');
    resetAssert(!str_contains(
        (string) file_get_contents($root . '/jobs/reset_imported_meli_data.php'),
        'MeliApiClient'
    ), 'El job local contiene transporte Mercado Libre.');

    fwrite(
        STDOUT,
        "OK: reset MariaDB; ordenes_protegidas=7; packs_protegidos=5; "
        . "items_protegidos=3; insights_eliminados=8; circuito_auditado=1; "
        . "proyecciones_obsoletas=2; proyeccion_global_retenida=1; payload_retenido=1; "
        . "payload_huerfano=0; cold_membership_tombstone=1; "
        . "campana_autorizada=eliminada; campana_mixta=integra; "
        . "sesion_autorizada=eliminada; sesion_mixta=integra; idempotencia=PASS.\n"
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL RESET: ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
    throw $error;
} finally {
    try {
        $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable) {
    }
    removeResetTestTree($private);
}
