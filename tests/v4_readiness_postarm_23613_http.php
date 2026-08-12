<?php

declare(strict_types=1);

use App\Core\AppPaths;
use App\Core\Crypto;
use App\Core\Database;
use App\Services\AppSettingsService;
use App\Services\InstalledVersionMarkerService;
use App\Services\V4ReadinessBootstrapService;

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FOCAL_23613_ERROR=' . $error::class . ':' . $error->getMessage() . PHP_EOL);
    exit(4);
});

$base = rtrim((string) getenv('ERP_23613_FOCAL_BASE'), '/');
$artifacts = (string) getenv('ERP_23613_FOCAL_ARTIFACTS');
$authorityHead = strtolower(trim((string) getenv('ERP_23613_AUTHORITY_HEAD')));
$authorityTree = strtolower(trim((string) getenv('ERP_23613_AUTHORITY_TREE')));
$serviceSha = strtolower(trim((string) getenv('ERP_23613_SERVICE_SHA256')));
$classifierSha = strtolower(trim((string) getenv('ERP_23613_CLASSIFIER_SHA256')));
$email = 'release-authority-23613@local.invalid';
$password = 'Release-Authority-23613-Local!';
$cookie = $artifacts . DIRECTORY_SEPARATOR . 'focal-cookie.txt';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(preg_match('#^http://127\.0\.0\.1:\d+/erp-meli$#D', $base) === 1, 'base_invalid');
$assert(is_dir($artifacts) && is_writable($artifacts), 'artifacts_invalid');
foreach ([$authorityHead, $authorityTree] as $identity) {
    $assert(preg_match('/^[a-f0-9]{40}$/D', $identity) === 1, 'git_identity_invalid');
}
foreach ([$serviceSha, $classifierSha] as $sha) {
    $assert(preg_match('/^[a-f0-9]{64}$/D', $sha) === 1, 'source_sha_invalid');
}

/** @return array{status:int,body:string,location:string} */
function focalRequest(string $url, string $cookie, ?array $post = null, bool $json = false): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('curl_init_failed');
    }
    $base = rtrim((string) getenv('ERP_23613_FOCAL_BASE'), '/');
    $origin = (string) preg_replace('#/erp-meli$#D', '', $base);
    $headers = ['Origin: ' . $origin, 'Referer: ' . $base . '/settings/cron'];
    if ($json) {
        $headers[] = 'Accept: application/json';
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($curl);
    if (!is_string($raw)) {
        throw new RuntimeException('curl_failed:' . curl_error($curl));
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerBytes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $headerBytes);
    $location = preg_match('/^Location:\s*(.+)$/mi', $head, $match) === 1 ? trim($match[1]) : '';
    return ['status' => $status, 'body' => substr($raw, $headerBytes), 'location' => $location];
}

function focalCsrf(string $html): string
{
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('csrf_missing');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function focalUpsert(PDO $pdo, string $key, string $value, string $group = 'queue_core'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,?) '
        . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,setting_group=VALUES(setting_group)'
    );
    $stmt->execute([$key, $value, $group]);
}

function focalSeed(PDO $pdo, string $releaseRoot): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'queue_core_attempts', 'queue_core_dispatch_journal', 'queue_core_jobs', 'queue_core_runs',
        'queue_core_readiness_receipts', 'queue_core_readiness_captures', 'queue_core_readiness_capture_items',
        'meli_tokens', 'meli_accounts', 'companies',
    ] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $company = $pdo->prepare('INSERT INTO companies(id,name,status) VALUES (?,?,1)');
    $account = $pdo->prepare(
        "INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES (?,?,?,?,'conectado')"
    );
    $token = $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) '
        . 'VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),?)'
    );
    foreach ([[1, 1], [4, 2], [5, 3]] as $index => [$companyId, $accountId]) {
        $company->execute([$companyId, 'Empresa local ' . ($index + 1)]);
        $account->execute([$accountId, $companyId, 'Cuenta local ' . ($index + 1), 100000 + $accountId]);
        $token->execute([
            $accountId,
            Crypto::encrypt('local-access-' . $accountId),
            Crypto::encrypt('local-refresh-' . $accountId),
            20 + $accountId,
        ]);
    }

    focalUpsert($pdo, 'app.version', '2.36.13', 'system');
    focalUpsert($pdo, 'cron_v3.operational_phase', 'retired_for_v4', 'cron_v3');
    focalUpsert($pdo, 'cron_v3.certified_cutover.phase', 'retired_for_v4', 'cron_v3');
    focalUpsert($pdo, V4ReadinessBootstrapService::SCHEDULER_AUTHORITY_KEY, json_encode([
        'status' => 'absent',
        'authority' => 'permanent_admin_explicit_confirmation',
        'recorded_at' => '2026-08-12T00:00:00Z',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $pdo->exec("UPDATE cron_v3_queue_ownership SET enabled=0,owner_engine='disabled'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='disabled',readiness_mode='idle',readiness_context_hash=NULL,generation=0,changed_by='release_authority_23613'");
    $pdo->exec('UPDATE queue_core_execution_leases SET launcher=NULL,heartbeat_at=NULL,expires_at=NULL,generation=0');
    $pdo->exec('UPDATE queue_core_feature_flags SET enabled=0,generation=0');
    AppSettingsService::clearCache();

    file_put_contents($releaseRoot . '/PAUSE_MELI_API', "local-only\n");
    file_put_contents($releaseRoot . '/PAUSE_ERP_AUTOMATION', "local-only\n");
    @unlink($releaseRoot . '/API_CANARY.json');
}

$pdo = Database::connectionFresh();
$releaseRoot = dirname(__DIR__);
$mode = (string) (getenv('ERP_23613_CONFIG_MODE') ?: '');
$assert(in_array($mode, ['classic', 'managed'], true), 'config_mode_invalid');
$expectedConfig = $mode === 'managed'
    ? $releaseRoot . '/shared/config.env'
    : $releaseRoot . '/config.env';
$secondaryConfig = $mode === 'managed'
    ? $releaseRoot . '/config.env'
    : $releaseRoot . '/shared/config.env';
$assert(AppPaths::configFile() === $expectedConfig, 'app_paths_config_authority_invalid:' . $mode);
$assert(!file_exists($secondaryConfig), 'secondary_config_present_before_post:' . $mode);
$rawPaths = [$releaseRoot . '/storage/raw', $releaseRoot . '/shared/storage/raw'];
$rawBefore = array_map('file_exists', $rawPaths);
$assert($rawBefore === [false, false], 'raw_fixture_not_empty');
$assert(trim((string) file_get_contents($releaseRoot . '/VERSION')) === '2.36.13', 'file_version_invalid');
$assert(V4ReadinessBootstrapService::REQUIRED_VERSION === '2.36.13', 'service_version_invalid');
$assert(V4ReadinessBootstrapService::LAST_MIGRATION === '293_queue_core_runtime_profile_defaults_b2_1.sql', 'migration_authority_invalid');

@unlink($cookie);
$pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
$insert = $pdo->prepare(
    'INSERT INTO users(name,email,password_hash,role,status,is_temporary,must_change_password) '
    . 'VALUES (?,?,?,"admin",1,0,0)'
);
$insert->execute(['Administrador 2.36.13', $email, password_hash($password, PASSWORD_DEFAULT)]);
focalSeed($pdo, $releaseRoot);
(new InstalledVersionMarkerService())->write('2.36.13', V4ReadinessBootstrapService::LAST_MIGRATION);

$login = focalRequest($base . '/login.php', $cookie);
$assert($login['status'] === 200, 'login_get_http_' . $login['status']);
$auth = focalRequest($base . '/login.php', $cookie, [
    '_token' => focalCsrf($login['body']),
    'email' => $email,
    'password' => $password,
]);
$assert($auth['status'] === 303, 'login_post_http_' . $auth['status'] . ':' . $auth['location']);

$settings = focalRequest($base . '/settings/cron', $cookie);
$assert($settings['status'] === 200, 'settings_http_' . $settings['status'] . ':' . $settings['location']);
$setup = focalRequest($base . '/settings/cron/v3-setup.json', $cookie, null, true);
$assert($setup['status'] === 200, 'setup_http_' . $setup['status']);
$setupJson = json_decode($setup['body'], true, 64, JSON_THROW_ON_ERROR);
$v4 = (array) (($setupJson['setup']['v4_readiness_bootstrap'] ?? null) ?? []);
$assert(($v4['state'] ?? null) === 'ready_to_arm', 'get_state_invalid:' . (string) ($v4['state'] ?? ''));
$assert(($v4['reason'] ?? null) === 'preconditions_pass', 'get_reason_invalid:' . (string) ($v4['reason'] ?? ''));

$post = focalRequest($base . '/settings/cron/v3-setup/prepare-safe-config', $cookie, [
    '_token' => focalCsrf($settings['body']),
    'operation' => 'v4_readiness_bootstrap',
    'admin_password' => $password,
], true);
$payload = json_decode($post['body'], true, 64, JSON_THROW_ON_ERROR);
$assert($post['status'] === 200, 'post_http_invalid:' . $post['status']);
$assert(($payload['state'] ?? null) === 'environment_armed', 'post_state_invalid:' . (string) ($payload['state'] ?? ''));
$assert(($payload['scheduler_created'] ?? null) === false, 'scheduler_created');
$assert(($payload['engine_activated'] ?? null) === false, 'engine_activated');
$assert((int) ($payload['remote_http_calls'] ?? 0) === 0, 'remote_http_calls_nonzero');

$get2 = focalRequest($base . '/settings/cron/v3-setup.json', $cookie, null, true);
$assert($get2['status'] === 200, 'get2_http_' . $get2['status']);
$get2Json = json_decode($get2['body'], true, 64, JSON_THROW_ON_ERROR);
$v4Get2 = (array) (($get2Json['setup']['v4_readiness_bootstrap'] ?? null) ?? []);
$assert(($v4Get2['state'] ?? null) === 'ready_for_context', 'get2_state_invalid:' . (string) ($v4Get2['state'] ?? ''));
$assert(($v4Get2['reason'] ?? null) === 'stable_authorities_ready', 'get2_reason_invalid:' . (string) ($v4Get2['reason'] ?? ''));
$assert(($v4Get2['feature_generation_authority']['profile'] ?? null) === 'armed', 'get2_feature_profile_invalid');
$assert(($v4Get2['runtime_authority']['profile'] ?? null) === 'armed', 'get2_runtime_profile_invalid');
$assert(($v4Get2['engine']['active_engine'] ?? null) === 'disabled', 'get2_engine_active');
$assert(($v4Get2['engine']['readiness_mode'] ?? null) === 'idle', 'get2_engine_mode');
$assert((int) ($v4Get2['engine']['generation'] ?? -1) === 0, 'get2_engine_generation');
$assert(str_contains((string) file_get_contents($expectedConfig), 'CRON_V4_ENABLED=true'), 'authority_config_not_armed');
$assert(!file_exists($secondaryConfig), 'secondary_config_written:' . $mode);
$assert(!is_file($releaseRoot . '/PAUSE_MELI_API'), 'api_marker_still_present');
$assert(is_file($releaseRoot . '/PAUSE_ERP_AUTOMATION'), 'automation_marker_removed');

$rawAfter = array_map('file_exists', $rawPaths);
$assert($rawAfter === $rawBefore, 'raw_storage_changed');
$receipt = [
    'authority' => [
        'head' => $authorityHead,
        'tree' => $authorityTree,
        'service_file_sha256' => $serviceSha,
        'classifier_test_sha256' => $classifierSha,
    ],
    'fixture' => [
        'config_mode' => $mode,
        'config_authority' => str_replace('\\', '/', $expectedConfig),
        'version' => '2.36.13',
        'app_version' => '2.36.13',
        'schema' => 293,
        'last_migration' => V4ReadinessBootstrapService::LAST_MIGRATION,
        'engine' => ['active' => 'disabled', 'mode' => 'idle', 'generation' => 0],
        'feature_flags' => ['count' => 5, 'enabled' => 0, 'generation' => 0],
        'scheduler_absence_authority' => 'permanent_admin_explicit_confirmation',
    ],
    'get' => ['http' => $setup['status'], 'state' => $v4['state'], 'reason' => $v4['reason']],
    'get2' => ['http' => $get2['status'], 'state' => $v4Get2['state'], 'reason' => $v4Get2['reason']],
    'post' => [
        'http' => $post['status'],
        'state' => $payload['state'],
        'scheduler_created' => $payload['scheduler_created'],
        'engine_activated' => $payload['engine_activated'],
        'remote_http_calls' => (int) ($payload['remote_http_calls'] ?? 0),
    ],
    'raw_storage_touched' => false,
    'checks' => $checks,
    'verdict' => 'PASS',
];
$receiptPath = $artifacts . '/ERP_MELI_2.36.13_POSTARM_RECEIPT.json';
$bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$assert(file_put_contents($receiptPath, $bytes) === strlen($bytes), 'receipt_write_failed');

$pdo->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
@unlink($cookie);
echo 'FOCAL_GET_STATE=ready_to_arm FOCAL_GET_REASON=preconditions_pass '
    . 'FOCAL_POST_HTTP=200 FOCAL_POST_STATE=environment_armed POSTIMAGE_GET_STATE=ready_for_context CONFIG_MODE=' . $mode . ' '
    . 'SCHEDULER_CREATED=false ENGINE_ACTIVATED=false MELI_HTTP_CALLS=0 RAW_STORAGE_TOUCHED=NO '
    . 'CHECKS=' . $checks . PHP_EOL;
