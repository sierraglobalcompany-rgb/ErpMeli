<?php

declare(strict_types=1);

use App\Core\AppPaths;
use App\Core\Crypto;
use App\Core\Database;
use App\Services\InstalledVersionMarkerService;

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$base = rtrim((string) (getenv('ERP_2380_HTTP_BASE') ?: ''), '/');
$artifactDirectory = (string) (getenv('ERP_2380_HTTP_ARTIFACTS') ?: '');
$email = 'qa-2380-admin@local.invalid';
$password = 'Qa-2380-Local-Only!';
$cookie = $artifactDirectory . DIRECTORY_SEPARATOR . 'http-cookie.txt';

if (!preg_match('#^http://127\.0\.0\.1:\d+/erp-meli$#D', $base)) {
    fwrite(STDERR, "HTTP_BASE_INVALID\n");
    exit(2);
}
if (!is_dir($artifactDirectory) || !is_writable($artifactDirectory)) {
    fwrite(STDERR, "ARTIFACT_DIRECTORY_INVALID\n");
    exit(2);
}

/** @return array{status:int,body:string,location:string} */
function qa2380Request(string $url, string $cookie, ?array $post = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('curl_init_failed');
    }
    $origin = (string) preg_replace('#(/erp-meli)$#', '', rtrim((string) getenv('ERP_2380_HTTP_BASE'), '/'));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Origin: ' . $origin,
            'Referer: ' . rtrim((string) getenv('ERP_2380_HTTP_BASE'), '/') . '/actualizar.php',
        ],
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($curl);
    if (!is_string($raw)) {
        $message = curl_error($curl);
        unset($curl);
        throw new RuntimeException('local_http_failed:' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerBytes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $headerBytes);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $match) === 1) {
        $location = trim($match[1]);
    }
    $body = substr($raw, $headerBytes);
    unset($curl);
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function qa2380Csrf(string $html): string
{
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('csrf_missing');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** @return array<string,array{rows:int,checksum:int}> */
function qa2380DatabaseSnapshot(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
         ORDER BY BINARY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        $name = (string) $table;
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $name) !== 1) {
            throw new RuntimeException('unsafe_table_name');
        }
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
        $checksumRow = $pdo->query('CHECKSUM TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
        $snapshot[$name] = [
            'rows' => $rows,
            'checksum' => (int) ($checksumRow['Checksum'] ?? 0),
        ];
    }
    return $snapshot;
}

/** @return list<array<string,mixed>> */
function qa2380Rows(PDO $pdo, string $sql): array
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row): array {
        ksort($row, SORT_STRING);
        return $row;
    }, $rows);
}

/** @return array<string,string> */
function qa2380StorageSnapshot(string $root): array
{
    $snapshot = [];
    if (!is_dir($root)) {
        return $snapshot;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if (
            $relative === 'installed-release.json'
            || $relative === 'web-cache-invalidation.json'
            || str_starts_with($relative, 'logs/')
        ) {
            continue;
        }
        $hash = hash_file('sha256', $entry->getPathname());
        if (!is_string($hash)) {
            throw new RuntimeException('storage_hash_failed');
        }
        $snapshot[$relative] = $hash;
    }
    ksort($snapshot, SORT_STRING);
    return $snapshot;
}

function qa2380WriteHtml(string $path, string $html, string $releaseRoot): void
{
    $asset = str_replace('\\', '/', $releaseRoot . '/public/assets/update.css');
    $assetUrl = 'file:///' . ltrim(str_replace(' ', '%20', $asset), '/');
    $html = str_replace('/erp-meli/public/assets/update.css', $assetUrl, $html);
    if (file_put_contents($path, $html, LOCK_EX) === false) {
        throw new RuntimeException('html_receipt_write_failed');
    }
}

$pdo = null;
$releaseRoot = dirname(__DIR__);
$stage = 'bootstrap';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $stage = 'database_connect';
    $pdo = Database::connection();
    $stage = 'seed_baseline';
    @unlink($cookie);
    $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    $insertUser = $pdo->prepare(
        'INSERT INTO users
           (name,email,password_hash,role,status,is_temporary,must_change_password)
         VALUES (?, ?, ?, "admin", 1, 0, 0)'
    );
    $insertUser->execute(['Administrador QA 2.38.0', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO companies(name,nit,status) VALUES('Empresa QA 2380','QA-2380',1)");
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO user_company_access(user_id,company_id,access_role,granted_by)
         VALUES(?,?,'admin',?)"
    )->execute([$userId, $companyId, $userId]);
    $accountIds = [];
    for ($index = 1; $index <= 3; $index++) {
        $pdo->prepare(
            "INSERT INTO meli_accounts(company_id,account_name,meli_user_id,nickname,status)
             VALUES(?,?,?,?, 'conectado')"
        )->execute([$companyId, 'Cuenta QA ' . $index, 238000 + $index, 'qa2380_' . $index]);
        $accountId = (int) $pdo->lastInsertId();
        $accountIds[] = $accountId;
        $pdo->prepare(
            "INSERT INTO user_meli_account_access(user_id,meli_account_id,access_role,granted_by)
             VALUES(?,?,'admin',?)"
        )->execute([$userId, $accountId, $userId]);
        $pdo->prepare(
            "INSERT INTO meli_tokens
               (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
             VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),?)"
        )->execute([
            $accountId,
            Crypto::encrypt('qa-access-' . $index),
            Crypto::encrypt('qa-refresh-' . $index),
            $index,
        ]);
    }
    $pdo->prepare(
        "INSERT INTO internal_products(company_id,internal_sku,name,manual_cost,status)
         VALUES(?,'SKU-QA-2380','Producto QA 2380',17.50,'active')"
    )->execute([$companyId]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meli_items
           (meli_account_id,external_item_id,title,seller_sku,price,status,synced_at)
         VALUES(?,'MCO-QA-2380','Publicación QA 2380','SKU-QA-2380',99.00,'active',UTC_TIMESTAMP())"
    )->execute([$accountIds[0]]);
    $itemId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO product_meli_links
           (internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status,link_source)
         VALUES(?,?,?,0,1.0000,'active','manual')"
    )->execute([$productId, $accountIds[0], $itemId]);
    $pdo->prepare(
        "INSERT INTO meli_orders
           (meli_account_id,external_order_id,date_created,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(?,2380001,UTC_TIMESTAMP(),'paid',99.00,99.00,'COP',UTC_TIMESTAMP())"
    )->execute([$accountIds[0]]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "UPDATE queue_v4_clean_control
         SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1,
             readiness_passed_accounts=3,certified_at=UTC_TIMESTAMP(3),activated_at=UTC_TIMESTAMP(3),updated_by=?
         WHERE control_key='primary'"
    )->execute([$userId]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_readiness_runs
           (state,expected_accounts,passed_accounts,started_by,finished_at)
         VALUES('CERTIFIED',3,3,?,UTC_TIMESTAMP(3))"
    )->execute([$userId]);
    $readinessRunId = (int) $pdo->lastInsertId();
    foreach ($accountIds as $accountId) {
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_readiness_accounts
               (readiness_run_id,company_id,meli_account_id,outcome)
             VALUES(?,?,?,'PASS')"
        )->execute([$readinessRunId, $companyId, $accountId]);
    }
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
           (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,completed_at)
         VALUES(?,?,'order_exact','2380001','qa-2380-sentinel','completed','{}',UTC_TIMESTAMP(3))"
    )->execute([$companyId, $accountIds[0]]);
    $pdo->exec("DELETE FROM app_versions WHERE version IN ('2.37.2','2.38.0')");
    $pdo->exec("INSERT INTO app_versions(version,notes) VALUES('2.37.2','baseline sintético local')");
    $setting = $pdo->prepare(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES('app.version','2.37.2',0,'system')
         ON DUPLICATE KEY UPDATE setting_value='2.37.2',is_encrypted=0,setting_group='system'"
    );
    $setting->execute();
    $lastMigration = (string) $pdo->query(
        "SELECT version FROM schema_migrations
         ORDER BY CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED) DESC, BINARY version DESC LIMIT 1"
    )->fetchColumn();
    $check($lastMigration === '294_queue_v4_clean_greenfield_2_37_0.sql', 'schema_294_missing');
    $check((new InstalledVersionMarkerService())->write('2.37.2', $lastMigration), 'baseline_marker_write_failed');

    $stage = 'login_get';
    $login = qa2380Request($base . '/login.php', $cookie);
    $check($login['status'] === 200, 'login_get_http_' . $login['status']);
    $stage = 'login_post';
    $authenticated = qa2380Request($base . '/login.php', $cookie, [
        '_token' => qa2380Csrf($login['body']),
        'email' => $email,
        'password' => $password,
    ]);
    $check($authenticated['status'] === 303, 'login_post_http_' . $authenticated['status']);

    $stage = 'updater_before';
    $before = qa2380Request($base . '/actualizar.php', $cookie);
    qa2380WriteHtml($artifactDirectory . '/actualizar-before.html', $before['body'], $releaseRoot);
    $check($before['status'] === 200, 'updater_before_http_' . $before['status']);
    $check(str_contains($before['body'], 'Actualización lista para continuar'), 'metadata_ready_title_missing');
    $check(str_contains($before['body'], '295_inventory_warehouse_v1_2_38_0.sql'), 'migration_295_not_ready');
    $check(!str_contains($before['body'], 'manifest_installed_inventory_mismatch'), 'legacy_false_positive_visible');

    $stage = 'authorize';
    $authorized = qa2380Request($base . '/actualizar.php', $cookie, [
        '_token' => qa2380Csrf($before['body']),
        'action' => 'authorize',
        'password' => $password,
        'backup_choice' => 'skip',
    ]);
    $check(
        $authorized['status'] === 303 && str_contains($authorized['location'], 'actualizar.php?result=advanced'),
        'authorize_http_' . $authorized['status'] . '_location_' . rawurlencode($authorized['location'])
    );
    $authorizedPage = qa2380Request($base . '/actualizar.php?result=advanced', $cookie);
    qa2380WriteHtml($artifactDirectory . '/actualizar-authorize.html', $authorizedPage['body'], $releaseRoot);
    $check($authorizedPage['status'] === 200, 'authorize_followup_http_' . $authorizedPage['status']);
    $check(str_contains($authorizedPage['body'], 'Continuar actualización'), 'continue_button_missing');

    $stage = 'pre_mutation_snapshot';
    $dbBefore = qa2380DatabaseSnapshot($pdo);
    $otherSettingsBefore = qa2380Rows(
        $pdo,
        "SELECT * FROM app_settings WHERE setting_key<>'app.version' ORDER BY BINARY setting_key"
    );
    $otherVersionsBefore = qa2380Rows(
        $pdo,
        "SELECT * FROM app_versions WHERE version<>'2.38.0' ORDER BY BINARY version,id"
    );
    $businessBefore = qa2380Rows(
        $pdo,
        "SELECT 'company' kind,id entity_id,name authority FROM companies WHERE id=" . $companyId
        . " UNION ALL SELECT 'account',id,account_name FROM meli_accounts WHERE company_id=" . $companyId
        . " UNION ALL SELECT 'product',id,internal_sku FROM internal_products WHERE company_id=" . $companyId
        . " UNION ALL SELECT 'item',id,external_item_id FROM meli_items WHERE meli_account_id IN ("
        . implode(',', $accountIds) . ")"
        . " UNION ALL SELECT 'order',id,CAST(external_order_id AS CHAR) FROM meli_orders WHERE id=" . $orderId
        . " ORDER BY kind,entity_id"
    );
    $tokenBefore = qa2380Rows(
        $pdo,
        "SELECT meli_account_id,SHA2(access_token_encrypted,256) access_sha,
                SHA2(refresh_token_encrypted,256) refresh_sha,expires_at,refresh_version
         FROM meli_tokens WHERE meli_account_id IN (" . implode(',', $accountIds) . ") ORDER BY meli_account_id"
    );
    $queueBefore = qa2380Rows(
        $pdo,
        "SELECT control_key,engine_state,readiness_state,scheduler_enabled,
                readiness_passed_accounts,certified_at,activated_at,updated_by
         FROM queue_v4_clean_control WHERE control_key='primary'"
    );
    $configBefore = hash_file('sha256', $releaseRoot . '/config.env');
    $storageBefore = qa2380StorageSnapshot(AppPaths::storage());
    $markerBefore = (string) file_get_contents(AppPaths::storage('installed-release.json'));
    $stage = 'metadata_post';
    $completed = qa2380Request($base . '/actualizar.php', $cookie, [
        '_token' => qa2380Csrf($authorizedPage['body']),
        'action' => 'migrate',
    ]);
    $completedPage = qa2380Request(
        $base . (str_contains($completed['location'], 'result=stopped')
            ? '/actualizar.php?result=stopped'
            : '/actualizar.php?result=advanced'),
        $cookie
    );
    qa2380WriteHtml($artifactDirectory . '/actualizar-after.html', $completedPage['body'], $releaseRoot);
    $check(
        $completed['status'] === 303 && str_contains($completed['location'], 'actualizar.php?result=advanced'),
        'migrate_http_' . $completed['status'] . '_location_' . rawurlencode($completed['location'])
    );
    $check($completedPage['status'] === 200, 'migrate_followup_http_' . $completedPage['status']);
    $check(str_contains($completedPage['body'], 'Actualización completada'), 'completed_title_missing');
    $check(str_contains($completedPage['body'], 'Queue V4 Clean'), 'queue_v4_preservation_notice_missing');

    $stage = 'post_mutation_snapshot';
    $dbAfter = qa2380DatabaseSnapshot($pdo);
    $allowedTables = [
        'app_settings' => true,
        'app_versions' => true,
        'schema_migrations' => true,
        'system_update_locks' => true,
        'system_update_migrations' => true,
        'system_update_migration_events' => true,
        'system_update_migration_replacements' => true,
        'queue_v4_clean_checkpoints' => true,
    ];
    foreach ($dbBefore as $table => $authority) {
        if (!isset($allowedTables[$table])) {
            $check(($dbAfter[$table] ?? null) === $authority, 'unexpected_database_mutation:' . $table);
        }
    }
    $check(
        qa2380Rows($pdo, "SELECT * FROM app_settings WHERE setting_key<>'app.version' ORDER BY BINARY setting_key") === $otherSettingsBefore,
        'unexpected_app_settings_mutation'
    );
    $check(
        qa2380Rows($pdo, "SELECT * FROM app_versions WHERE version<>'2.38.0' ORDER BY BINARY version,id") === $otherVersionsBefore,
        'unexpected_app_versions_mutation'
    );
    $check((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.38.0', 'app_version_not_promoted');
    $check((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.38.0'")->fetchColumn() === 1, 'app_versions_target_missing');
    $check(qa2380Rows(
        $pdo,
        "SELECT 'company' kind,id entity_id,name authority FROM companies WHERE id=" . $companyId
        . " UNION ALL SELECT 'account',id,account_name FROM meli_accounts WHERE company_id=" . $companyId
        . " UNION ALL SELECT 'product',id,internal_sku FROM internal_products WHERE company_id=" . $companyId
        . " UNION ALL SELECT 'item',id,external_item_id FROM meli_items WHERE meli_account_id IN ("
        . implode(',', $accountIds) . ")"
        . " UNION ALL SELECT 'order',id,CAST(external_order_id AS CHAR) FROM meli_orders WHERE id=" . $orderId
        . " ORDER BY kind,entity_id"
    ) === $businessBefore, 'business_sentinel_changed');
    $check(qa2380Rows(
        $pdo,
        "SELECT meli_account_id,SHA2(access_token_encrypted,256) access_sha,
                SHA2(refresh_token_encrypted,256) refresh_sha,expires_at,refresh_version
         FROM meli_tokens WHERE meli_account_id IN (" . implode(',', $accountIds) . ") ORDER BY meli_account_id"
    ) === $tokenBefore, 'oauth_sentinel_changed');
    $check(qa2380Rows(
        $pdo,
        "SELECT control_key,engine_state,readiness_state,scheduler_enabled,
                readiness_passed_accounts,certified_at,activated_at,updated_by
         FROM queue_v4_clean_control WHERE control_key='primary'"
    ) === $queueBefore, 'queue_v4_control_changed');
    $check(hash_equals((string) $configBefore, (string) hash_file('sha256', $releaseRoot . '/config.env')), 'config_changed');
    $check(qa2380StorageSnapshot(AppPaths::storage()) === $storageBefore, 'unexpected_storage_mutation');
    $markerAfter = (string) file_get_contents(AppPaths::storage('installed-release.json'));
    $check(!hash_equals(hash('sha256', $markerBefore), hash('sha256', $markerAfter)), 'marker_not_replaced');
    $marker = (new InstalledVersionMarkerService())->read();
    $check($marker['valid'] && $marker['version'] === '2.38.0' && $marker['last_migration'] === '295_inventory_warehouse_v1_2_38_0.sql', 'target_marker_invalid');
    $check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 295, 'schema_count_not_295');
    $check((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='295_inventory_warehouse_v1_2_38_0.sql'")->fetchColumn() === 1, 'migration_295_not_exactly_once');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('inventory_warehouses','inventory_balances','inventory_movements','inventory_reviews')")->fetchColumn() === 4, 'inventory_tables_missing');
    $check((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_checkpoints WHERE company_id=" . $companyId . " AND producer_key IN ('inventory_pending_floor','inventory_pending_cursor')")->fetchColumn() === 6, 'inventory_cutover_checkpoints_missing');

    $root = qa2380Request($base . '/', $cookie);
    $asset = qa2380Request($base . '/public/assets/update.css', $cookie);
    $inventory = qa2380Request($base . '/inventory', $cookie);
    $kardex = qa2380Request($base . '/inventory/kardex', $cookie);
    $check(in_array($root['status'], [200, 302, 303], true), 'subfolder_root_http_' . $root['status']);
    $check($asset['status'] === 200, 'subfolder_asset_http_' . $asset['status']);
    $check($inventory['status'] === 200, 'subfolder_inventory_http_' . $inventory['status']);
    $check($kardex['status'] === 200, 'subfolder_kardex_http_' . $kardex['status']);
    $check(str_contains($inventory['body'], 'Inventario'), 'inventory_title_missing');
    $check(str_contains($kardex['body'], 'Kardex'), 'kardex_title_missing');

    $receipt = [
        'status' => 'PASS',
        'checks' => $checks,
        'base_path' => '/erp-meli',
        'before' => ['file_version' => '2.38.0', 'app_version' => '2.37.2', 'marker' => '2.37.2', 'schema' => 294],
        'after' => ['file_version' => '2.38.0', 'app_version' => '2.38.0', 'marker' => '2.38.0', 'schema' => 295],
        'database_write_set' => ['migration:295', 'app_settings:app.version', 'app_versions:2.38.0', 'inventory_tables', 'inventory_cutover_checkpoints'],
        'filesystem_write_set' => ['storage/installed-release.json'],
        'business_sentinel_mutations' => 0,
        'queue_v4_control_mutations' => 0,
        'oauth_sentinel_mutations' => 0,
        'config_mutations' => 0,
        'inventory_http' => 200,
        'kardex_http' => 200,
        'other_storage_mutations' => 0,
        'real_meli_http_calls' => 0,
        'raw_storage_touched' => false,
    ];
    file_put_contents(
        $artifactDirectory . '/HTTP_ACTUALIZAR_2380_RECEIPT.json',
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
    echo 'HTTP actualizar 2.38.0: PASS checks=' . $checks . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP actualizar 2.38.0: FAIL stage=' . $stage . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
}
