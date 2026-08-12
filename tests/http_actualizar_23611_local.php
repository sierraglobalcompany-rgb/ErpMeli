<?php

declare(strict_types=1);

use App\Core\AppPaths;
use App\Core\Database;
use App\Services\InstalledVersionMarkerService;

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$base = rtrim((string) (getenv('ERP_23611_HTTP_BASE') ?: ''), '/');
$artifactDirectory = (string) (getenv('ERP_23611_HTTP_ARTIFACTS') ?: '');
$email = 'qa-23611-admin@local.invalid';
$password = 'Qa-23611-Local-Only!';
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
function qa23611Request(string $url, string $cookie, ?array $post = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('curl_init_failed');
    }
    $origin = (string) preg_replace('#(/erp-meli)$#', '', rtrim((string) getenv('ERP_23611_HTTP_BASE'), '/'));
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
            'Referer: ' . rtrim((string) getenv('ERP_23611_HTTP_BASE'), '/') . '/actualizar.php',
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

function qa23611Csrf(string $html): string
{
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('csrf_missing');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** @return array<string,array{rows:int,checksum:int}> */
function qa23611DatabaseSnapshot(PDO $pdo): array
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
function qa23611Rows(PDO $pdo, string $sql): array
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row): array {
        ksort($row, SORT_STRING);
        return $row;
    }, $rows);
}

/** @return array<string,string> */
function qa23611StorageSnapshot(string $root): array
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
        if ($relative === 'installed-release.json') {
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

function qa23611WriteHtml(string $path, string $html, string $releaseRoot): void
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
    $insertUser->execute(['Administrador QA 2.36.11', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $pdo->exec("DELETE FROM app_versions WHERE version IN ('2.36.10','2.36.11')");
    $pdo->exec("INSERT INTO app_versions(version,notes) VALUES('2.36.10','baseline sintético local')");
    $setting = $pdo->prepare(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES('app.version','2.36.10',0,'system')
         ON DUPLICATE KEY UPDATE setting_value='2.36.10',is_encrypted=0,setting_group='system'"
    );
    $setting->execute();
    $lastMigration = (string) $pdo->query(
        "SELECT version FROM schema_migrations
         ORDER BY CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED) DESC, BINARY version DESC LIMIT 1"
    )->fetchColumn();
    $check($lastMigration === '293_queue_core_runtime_profile_defaults_b2_1.sql', 'schema_293_missing');
    $check((new InstalledVersionMarkerService())->write('2.36.10', $lastMigration), 'baseline_marker_write_failed');

    $stage = 'login_get';
    $login = qa23611Request($base . '/login.php', $cookie);
    $check($login['status'] === 200, 'login_get_http_' . $login['status']);
    $stage = 'login_post';
    $authenticated = qa23611Request($base . '/login.php', $cookie, [
        '_token' => qa23611Csrf($login['body']),
        'email' => $email,
        'password' => $password,
    ]);
    $check($authenticated['status'] === 303, 'login_post_http_' . $authenticated['status']);

    $stage = 'updater_before';
    $before = qa23611Request($base . '/actualizar.php', $cookie);
    qa23611WriteHtml($artifactDirectory . '/actualizar-before.html', $before['body'], $releaseRoot);
    $check($before['status'] === 200, 'updater_before_http_' . $before['status']);
    $check(str_contains($before['body'], 'Actualización lista para continuar'), 'metadata_ready_title_missing');
    $check(str_contains($before['body'], 'Falta confirmar la versión instalada'), 'metadata_ready_notice_missing');
    $check(!str_contains($before['body'], 'manifest_installed_inventory_mismatch'), 'legacy_false_positive_visible');

    $stage = 'authorize';
    $authorized = qa23611Request($base . '/actualizar.php', $cookie, [
        '_token' => qa23611Csrf($before['body']),
        'action' => 'authorize',
        'password' => $password,
        'backup_choice' => 'skip',
    ]);
    $check(
        $authorized['status'] === 303 && str_contains($authorized['location'], 'actualizar.php?result=advanced'),
        'authorize_http_' . $authorized['status'] . '_location_' . rawurlencode($authorized['location'])
    );
    $authorizedPage = qa23611Request($base . '/actualizar.php?result=advanced', $cookie);
    qa23611WriteHtml($artifactDirectory . '/actualizar-authorize.html', $authorizedPage['body'], $releaseRoot);
    $check($authorizedPage['status'] === 200, 'authorize_followup_http_' . $authorizedPage['status']);
    $check(str_contains($authorizedPage['body'], 'Continuar actualización'), 'continue_button_missing');

    $stage = 'pre_mutation_snapshot';
    $dbBefore = qa23611DatabaseSnapshot($pdo);
    $otherSettingsBefore = qa23611Rows(
        $pdo,
        "SELECT * FROM app_settings WHERE setting_key<>'app.version' ORDER BY BINARY setting_key"
    );
    $otherVersionsBefore = qa23611Rows(
        $pdo,
        "SELECT * FROM app_versions WHERE version<>'2.36.11' ORDER BY BINARY version,id"
    );
    $storageBefore = qa23611StorageSnapshot(AppPaths::storage());
    $markerBefore = (string) file_get_contents(AppPaths::storage('installed-release.json'));
    $stage = 'metadata_post';
    $completed = qa23611Request($base . '/actualizar.php', $cookie, [
        '_token' => qa23611Csrf($authorizedPage['body']),
        'action' => 'migrate',
    ]);
    $completedPage = qa23611Request(
        $base . (str_contains($completed['location'], 'result=stopped')
            ? '/actualizar.php?result=stopped'
            : '/actualizar.php?result=advanced'),
        $cookie
    );
    qa23611WriteHtml($artifactDirectory . '/actualizar-after.html', $completedPage['body'], $releaseRoot);
    $check(
        $completed['status'] === 303 && str_contains($completed['location'], 'actualizar.php?result=advanced'),
        'migrate_http_' . $completed['status'] . '_location_' . rawurlencode($completed['location'])
    );
    $check($completedPage['status'] === 200, 'migrate_followup_http_' . $completedPage['status']);
    $check(str_contains($completedPage['body'], 'Actualización completada'), 'completed_title_missing');
    $check(str_contains($completedPage['body'], 'Solo se confirmó la metadata de la release'), 'metadata_only_notice_missing');

    $stage = 'post_mutation_snapshot';
    $dbAfter = qa23611DatabaseSnapshot($pdo);
    $allowedTables = ['app_settings' => true, 'app_versions' => true];
    foreach ($dbBefore as $table => $authority) {
        if (!isset($allowedTables[$table])) {
            $check(($dbAfter[$table] ?? null) === $authority, 'unexpected_database_mutation:' . $table);
        }
    }
    $check(
        qa23611Rows($pdo, "SELECT * FROM app_settings WHERE setting_key<>'app.version' ORDER BY BINARY setting_key") === $otherSettingsBefore,
        'unexpected_app_settings_mutation'
    );
    $check(
        qa23611Rows($pdo, "SELECT * FROM app_versions WHERE version<>'2.36.11' ORDER BY BINARY version,id") === $otherVersionsBefore,
        'unexpected_app_versions_mutation'
    );
    $check((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.36.11', 'app_version_not_promoted');
    $check((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.36.11'")->fetchColumn() === 1, 'app_versions_target_missing');
    $check(qa23611StorageSnapshot(AppPaths::storage()) === $storageBefore, 'unexpected_storage_mutation');
    $markerAfter = (string) file_get_contents(AppPaths::storage('installed-release.json'));
    $check(!hash_equals(hash('sha256', $markerBefore), hash('sha256', $markerAfter)), 'marker_not_replaced');
    $marker = (new InstalledVersionMarkerService())->read();
    $check($marker['valid'] && $marker['version'] === '2.36.11' && $marker['last_migration'] === $lastMigration, 'target_marker_invalid');
    $check((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 293, 'schema_count_not_293');

    $root = qa23611Request($base . '/', $cookie);
    $asset = qa23611Request($base . '/public/assets/update.css', $cookie);
    $cronSettings = qa23611Request($base . '/settings/cron', $cookie);
    $v4Start = strpos($cronSettings['body'], 'data-v4-readiness-form');
    $v4End = strpos($cronSettings['body'], 'data-v4-readiness-receipt', $v4Start ?: 0);
    $v4Form = $v4Start !== false && $v4End !== false
        ? substr($cronSettings['body'], $v4Start, $v4End - $v4Start)
        : '';
    $retirementStart = strpos($cronSettings['body'], 'data-cron-v3-retirement-form');
    $retirementEnd = strpos($cronSettings['body'], 'data-cron-v3-retirement-receipt', $retirementStart ?: 0);
    $retirementForm = $retirementStart !== false && $retirementEnd !== false
        ? substr($cronSettings['body'], $retirementStart, $retirementEnd - $retirementStart)
        : '';
    $check(in_array($root['status'], [200, 302, 303], true), 'subfolder_root_http_' . $root['status']);
    $check($asset['status'] === 200, 'subfolder_asset_http_' . $asset['status']);
    $check($cronSettings['status'] === 200, 'subfolder_cron_settings_http_' . $cronSettings['status']);
    $check($v4Form !== '' && str_contains($v4Form, 'name="admin_password"'), 'v4_password_field_missing');
    $check(!str_contains($v4Form, 'name="confirmation_phrase"'), 'v4_confirmation_phrase_present');
    $check(!str_contains($v4Form, 'name="scheduler_absent_confirmed"'), 'v4_scheduler_checkbox_present');
    $check(str_contains($retirementForm, 'name="confirmation_phrase"'), 'retirement_confirmation_phrase_missing');

    $receipt = [
        'status' => 'PASS',
        'checks' => $checks,
        'base_path' => '/erp-meli',
        'before' => ['file_version' => '2.36.11', 'app_version' => '2.36.10', 'marker' => '2.36.10', 'schema' => 293],
        'after' => ['file_version' => '2.36.11', 'app_version' => '2.36.11', 'marker' => '2.36.11', 'schema' => 293],
        'database_write_set' => ['app_settings:app.version', 'app_versions:2.36.11'],
        'filesystem_write_set' => ['storage/installed-release.json'],
        'other_table_mutations' => 0,
        'other_storage_mutations' => 0,
        'real_meli_http_calls' => 0,
        'raw_storage_touched' => false,
    ];
    file_put_contents(
        $artifactDirectory . '/HTTP_ACTUALIZAR_23611_RECEIPT.json',
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
    echo 'HTTP actualizar 2.36.11: PASS checks=' . $checks . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP actualizar 2.36.11: FAIL stage=' . $stage . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cookie);
    if ($pdo instanceof PDO) {
        $pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
    }
}
