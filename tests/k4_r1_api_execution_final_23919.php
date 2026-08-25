<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Services\ApiRhythmDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\SchemaInspectorService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K4 R1 exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k4_r1_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    putenv('APP_KEY=k4-r1-api-execution-final');
    $_ENV['APP_KEY'] = 'k4-r1-api-execution-final';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    final class K4R1CountingTransport implements MeliHttpTransportInterface
    {
        public int $calls = 0;

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            $this->calls++;
            return [
                'status' => 200,
                'body' => ['id' => 101001, 'results' => []],
                'headers' => [],
                'curl_error' => '',
                'duration_ms' => 10,
                'wire_bytes' => 1,
                'decoded_bytes' => 1,
            ];
        }
    }

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
        fwrite(STDERR, "ERROR: K4 R1 exige MariaDB 11.8+.\n");
        exit(2);
    }
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_user_id BIGINT UNSIGNED NULL,account_name VARCHAR(160) NULL,status VARCHAR(40) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NULL,expires_at DATETIME NOT NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES (?,?,0),(?,?,0),(?,?,0)')
        ->execute(['api.guard.enabled', '0', 'api.budget.enabled', '0', 'api.pacing.enabled', '1']);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'K4R1','conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')
        ->execute([101, Crypto::encrypt('k4-r1-access'), Crypto::encrypt('k4-r1-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);

    $sources = [
        'queue_v4_clean' => 'active_automatic',
        MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT => 'active_automatic',
        MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR => 'active_automatic',
        MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT => 'active_automatic',
        MeliTransportSourcePolicy::QUEUE_V4_OAUTH => 'active_automatic',
        'queue_v4_clean_readiness' => 'preflight_readiness',
        'manual_exact' => 'manual_only',
        'manual_campaign' => 'manual_only',
        'manual_emergency_canary' => 'manual_only',
        'manual_emergency_oauth_refresh' => 'manual_only',
        'cron_v3_remote' => 'logically_retired',
        'cron_v3' => 'logically_retired',
        'queue_core' => 'compatibility_only',
        'queue_core_webhook' => 'compatibility_only',
        'webhook_worker' => 'compatibility_only',
        'cron' => 'compatibility_only',
        'web' => 'manual_only',
    ];
    foreach ($sources as $source => $target) {
        $capabilities = MeliTransportSourcePolicy::capabilities((string) $source);
        $assert($capabilities['known'], 'CENSUS_SOURCE_UNKNOWN:' . $source);
        $assert($capabilities['target'] === $target, 'CENSUS_TARGET_DRIFT:' . $source);
    }
    $assert(!MeliTransportSourcePolicy::capabilities('module:meli-safe-local')['known'], 'MODULE_FAMILY_WILDCARD_STILL_PRESENT');
    $assert(!MeliTransportSourcePolicy::capabilities('module:meli-notifications')['known'], 'MODULE_SOURCE_BOUNDARY_NOT_FAIL_CLOSED');

    $client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $policy = (string) file_get_contents($root . '/app/Services/MeliTransportSourcePolicy.php');
    $assert(substr_count($client, 'MeliTransportSourcePolicy::capabilities($source)') === 1, 'SOURCE_POLICY_NOT_SNAPSHOTTED_ONCE');
    $assert(!str_contains($client, 'MeliTransportSourcePolicy::requiresCurrentOAuthFence($source)'), 'OAUTH_POLICY_RECHECK_AFTER_SNAPSHOT');
    $assert(!str_contains($policy, 'preg_match(\'/^module:meli-'), 'MODULE_REGEX_BOUNDARY_STILL_PRESENT');
    $assert(str_contains($client, 'pacing_authority_unavailable'), 'PACING_COMPATIBILITY_DOES_NOT_FAIL_CLOSED');

    $cases = [
        ['source' => 'queue_v4_clean', 'method' => 'GET', 'path' => '/orders/search', 'scope' => 'rhythm_authority_unavailable'],
        ['source' => 'queue_v4_clean_readiness', 'method' => 'GET', 'path' => '/users/me', 'scope' => 'rhythm_authority_unavailable'],
        ['source' => 'cron_v3_remote', 'method' => 'GET', 'path' => '/orders/search', 'scope' => 'pacing_authority_unavailable'],
    ];
    foreach ($cases as $case) {
        SchemaInspectorService::clearCache();
        $transport = new K4R1CountingTransport();
        $api = new MeliApiClient(101, $transport);
        try {
            $api->request((string) $case['method'], (string) $case['path'], [], false, [
                'source' => (string) $case['source'],
                'company_id' => 10,
                'job_type' => (string) $case['source'],
            ]);
            throw new RuntimeException('CASE_CROSSED_TRANSPORT:' . $case['source']);
        } catch (ApiRhythmDeferredException $error) {
            $assert($error->blockingScope === $case['scope'], 'CASE_BLOCKING_SCOPE_DRIFT:' . $case['source'] . ':' . $error->blockingScope);
            $assert($transport->calls === 0, 'CASE_TRANSPORT_LEAK:' . $case['source']);
        }
    }

    $oauthTransport = new K4R1CountingTransport();
    try {
        (new MeliApiClient(101, $oauthTransport))->exchangeOAuthToken(
            ['grant_type' => 'refresh_token', 'refresh_token' => 'redacted'],
            [
                'source' => MeliTransportSourcePolicy::QUEUE_V4_OAUTH,
                'company_id' => 10,
                'account_id' => 101,
                'job_type' => 'oauth',
            ]
        );
        throw new RuntimeException('QUEUE_V4_OAUTH_CROSSED_TRANSPORT_WITHOUT_RHYTHM');
    } catch (ApiRhythmDeferredException $error) {
        $assert($error->blockingScope === 'rhythm_authority_unavailable', 'QUEUE_V4_OAUTH_BLOCKING_SCOPE_DRIFT:' . $error->blockingScope);
        $assert($oauthTransport->calls === 0, 'QUEUE_V4_OAUTH_TRANSPORT_LEAK');
    }

    $moduleTransport = new K4R1CountingTransport();
    try {
        (new MeliApiClient(101, $moduleTransport))->request('GET', '/orders/search', [], false, [
            'source' => 'module:meli-safe-local',
            'company_id' => 10,
            'job_type' => 'module',
        ]);
        throw new RuntimeException('MODULE_SOURCE_CROSSED_TRANSPORT');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'meli_transport_source_unknown', 'MODULE_SOURCE_ERROR_NOT_CANONICAL:' . $error->getMessage());
        $assert($moduleTransport->calls === 0, 'MODULE_SOURCE_TRANSPORT_LEAK');
    }

    fwrite(
        STDOUT,
        'K4_R1_API_EXECUTION_FINAL_23919=PASS'
        . ' SOURCE_CAPABILITY_CENSUS=PASS'
        . ' READINESS_SOURCE_RESOLVED=PASS'
        . ' MODULE_SOURCE_BOUNDARY=PASS'
        . ' PACING_COMPATIBILITY_FAIL_CLOSED=PASS'
        . ' SOURCE_POLICY_SNAPSHOT=PASS'
        . ' REAL_MELI_HTTP=0'
        . ' checks=' . $checks
        . PHP_EOL
    );
} finally {
    if (isset($server, $database)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
