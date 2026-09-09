<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Services\AppSettingsService;
use App\Services\Migrator;
use App\Services\NotificationWorkItemService;
use App\Services\WorkQueueProjectionService;

const CAP2_BROWSER_STEP_ROOT = 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/browser-step';
const CAP2_BROWSER_STEP_INSTALL = CAP2_BROWSER_STEP_ROOT . '/install';
const CAP2_BROWSER_STEP_META = CAP2_BROWSER_STEP_ROOT . '/fixture-meta.json';
const CAP2_BROWSER_STEP_WIRE = CAP2_BROWSER_STEP_ROOT . '/wire.jsonl';

$dbName = (string) getenv('DB_NAME');
K1dSafeTestDatabase::assertGuard(
    (string) getenv('APP_ENV'),
    (string) getenv('ML_WRITE_ENABLED'),
    (string) getenv('DB_HOST'),
    $dbName
);
if ((string) getenv('DB_PORT') !== '33079'
    || preg_match('/^erp_meli_k1d_test_cap2_browser_step_[a-z0-9_]+$/', $dbName) !== 1) {
    throw new RuntimeException('CAP2_BROWSER_STEP_DB_GUARD=FAIL');
}

if (($argv[1] ?? '') === '--cleanup') {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=33079;charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    echo 'CAP2_BROWSER_STEP_CLEANUP=PASS DB=' . $dbName . PHP_EOL;
    exit(0);
}

if (!is_dir(CAP2_BROWSER_STEP_ROOT) && !mkdir(CAP2_BROWSER_STEP_ROOT, 0770, true) && !is_dir(CAP2_BROWSER_STEP_ROOT)) {
    throw new RuntimeException('No se pudo crear el directorio QA descartable.');
}
if (!is_dir(CAP2_BROWSER_STEP_INSTALL) && !mkdir(CAP2_BROWSER_STEP_INSTALL, 0770, true) && !is_dir(CAP2_BROWSER_STEP_INSTALL)) {
    throw new RuntimeException('No se pudo crear la instalación QA descartable.');
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', CAP2_BROWSER_STEP_INSTALL);
}

$database = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $database->pdo();
    $existing = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
    )->fetchColumn();
    if ($existing !== 0) {
        throw new RuntimeException('La base browser-step debe estar vacía antes del setup.');
    }
    (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->run(301);
    Database::setConnection($pdo);

    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9101,'CAP2 browser step',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES
        (9111,9101,'Normal checkpoint',99111,'conectado'),
        (9113,9101,'Protected 429',99113,'conectado'),
        (9114,9101,'Failure and continuation',99114,'conectado')");
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary) VALUES
        (9107,'CAP2 browser user','browser-step@example.invalid','unused','admin',1,0)");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9107,9101)');
    $token = $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    );
    foreach ([9111, 9113, 9114] as $accountId) {
        $token->execute([$accountId, Crypto::encrypt('browser-access-' . $accountId), Crypto::encrypt('browser-refresh-' . $accountId)]);
    }
    $pdo->exec("UPDATE queue_v4_clean_control
        SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1,last_scheduler_at=UTC_TIMESTAMP(3)
        WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");

    $settings = new AppSettingsService();
    foreach ([
        'notifications.debounce_seconds' => '0',
        'items.hybrid_notification_updates_enabled' => '0',
        'api.rhythm.burst_size' => '100',
        'manual.api_calls_per_step' => '3',
        'manual.api_calls_ceiling' => '55',
        'manual_campaign.preview_cache_seconds' => '5',
    ] as $key => $value) {
        $settings->set($key, $value, 'browser_step');
    }
    AppSettingsService::clearCache();

    $orderChunk = static function (PDO $connection, int $batchId, int $accountId, int $sequence, int $ageMinutes): int {
        $connection->prepare(
            "INSERT INTO sync_batches(id,meli_account_id,period_year,period_month,date_from,date_to,status)
             VALUES(?,?,2026,8,'2026-08-01','2026-08-31','queued')
             ON DUPLICATE KEY UPDATE id=VALUES(id)"
        )->execute([$batchId, $accountId]);
        $connection->prepare(
            "INSERT INTO sync_batch_chunks
             (sync_batch_id,meli_account_id,sync_type,sequence_no,date_from,date_to,status,next_run_at,queued_at,created_at)
             VALUES(?,?,'orders',?,'2026-08-01','2026-08-31','queued',UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE))"
        )->execute([$batchId, $accountId, $sequence, $ageMinutes]);
        return (int) $connection->lastInsertId();
    };
    $question = static function (PDO $connection, int $accountId, int $meliUserId, int $remoteId, int $ageMinutes): int {
        $connection->prepare(
            "INSERT INTO meli_notification_events
             (meli_account_id,topic,resource,payload_json,payload_hash,created_at)
             VALUES(?,'question',?,'{}',?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE))"
        )->execute([$accountId, '/questions/' . $remoteId, hash('sha256', $accountId . '|' . $remoteId), $ageMinutes]);
        $eventId = (int) $connection->lastInsertId();
        $workId = (int) (new NotificationWorkItemService())->enqueue(
            $eventId,
            $accountId,
            $meliUserId,
            [
                'valid' => true,
                'actionable' => true,
                'canonical_topic' => 'question',
                'resource_type' => 'question',
                'resource_id' => (string) $remoteId,
                'priority' => 10,
            ],
            null
        );
        $connection->prepare(
            'UPDATE meli_notification_work_items
             SET first_received_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE),next_run_at=UTC_TIMESTAMP()
             WHERE id=?'
        )->execute([$ageMinutes, $workId]);
        return $workId;
    };

    $normalOrder = $orderChunk($pdo, 9211, 9111, 1, 30);
    $normalQuestion = $question($pdo, 9111, 99111, 93101, 10);
    $rateFirst = $question($pdo, 9113, 99113, 93301, 20);
    $rateSecond = $question($pdo, 9113, 99113, 93302, 10);
    $failureFirst = $orderChunk($pdo, 9214, 9114, 1, 30);
    $failureSecond = $question($pdo, 9114, 99114, 93402, 20);

    $projection = new WorkQueueProjectionService();
    if (!$projection->refreshQueue('orders_sync') || !$projection->refreshQueue('notification_fallback')) {
        throw new RuntimeException('No se pudo preparar la proyección real del paso manual.');
    }
    $meta = [
        'normal' => ['account_id' => 9111, 'order_chunk_id' => $normalOrder, 'question_work_id' => $normalQuestion, 'question_remote_id' => 93101],
        'rate_429' => ['account_id' => 9113, 'first_work_id' => $rateFirst, 'second_work_id' => $rateSecond, 'first_remote_id' => 93301, 'second_remote_id' => 93302],
        'failure' => [
            'account_id' => 9114,
            'first_chunk_id' => $failureFirst,
            'second_work_id' => $failureSecond,
            'second_remote_id' => 93402,
        ],
    ];
    file_put_contents(CAP2_BROWSER_STEP_META, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents(CAP2_BROWSER_STEP_WIRE, '', LOCK_EX);
    echo 'CAP2_BROWSER_STEP_SETUP=PASS DB=' . $dbName . ' SCHEMA=301 SOURCES=6' . PHP_EOL;
} catch (Throwable $error) {
    $database->cleanup();
    throw $error;
}
