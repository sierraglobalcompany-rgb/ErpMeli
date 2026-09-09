<?php
declare(strict_types=1);

// Execute only while holding the shared Migrator slot. Disposable MySQL, real
// localhost requests, production Router/Auth/CSRF, cURL fake only at Meli wire.
$runOutput = rtrim((string) (getenv('CALLS_VERIFY_RUN_OUTPUT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906'), '/\\');
$root = rtrim((string) (getenv('CALLS_ENTRYPOINTS_ROOT') ?: $runOutput . '/entrypoints'), '/\\');
foreach ([$root, $root . '/sessions'] as $directory) {
    if (!is_dir($directory)) mkdir($directory, 0770, true);
}
putenv('CAP2_MANUAL_QA_ROOT=' . $root);
putenv('CALLS_QA_STORAGE_ROOT=');
require __DIR__ . '/cap2_manual_fixture.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL:' . $label);
    echo 'PASS:' . $label . PHP_EOL;
};
$database = cap2_manual_database();
$server = null;
try {
    $pdo = $database->pdo();
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary,expires_at) VALUES
        (9017,'Operator QA','operator-entrypoints@example.invalid','unused','operador',1,0,NULL),
        (9018,'Temporary QA','temporary-entrypoints@example.invalid','unused','admin',1,1,DATE_ADD(NOW(),INTERVAL 1 DAY))");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9017,9001),(9018,9001)');
    $source = cap2_manual_notification($pdo, '78101');
    $untrustedLink = '<a href="https://untrusted.invalid/fixture">untrusted-source-link</a>';
    $pdo->prepare('UPDATE system_work_queue_projection SET content_summary=? WHERE queue_key=? AND source_id=?')
        ->execute([$untrustedLink, $source['queue_key'], $source['source_id']]);
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,status,total_items,processed_items,source_type,source_id)
        VALUES(9002,9012,'pending',1,0,'order',78102)");
    $foreignId = (int) $pdo->lastInsertId();
    (new App\Services\WorkQueueProjectionService())->refreshQueue('financial_recalc');
    $pdo->prepare("UPDATE system_work_queue_projection SET human_label='FOREIGN-CONTENT-78102' WHERE queue_key='financial_recalc' AND source_id=?")->execute([(string) $foreignId]);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM system_work_queue_projection WHERE human_label='FOREIGN-CONTENT-78102' AND company_id=9002 AND meli_account_id=9012")->fetchColumn() === 1, 'foreign_fixture_actually_exists');
    file_put_contents($root . '/wire.jsonl', '');
    $browserRequested = in_array('--readiness-browser', $argv, true);
    $port = $browserRequested ? 18145 : 18143;
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
    $assert(is_resource($probe), 'exclusive_local_port_available');
    fclose($probe);
    putenv('APP_URL=http://127.0.0.1:' . $port);
    putenv('SESSION_SECURE=false');
    putenv('CALLS_ENTRYPOINTS_QA=1');
    putenv('CALLS_READINESS_BROWSER=' . ($browserRequested ? '1' : '0'));
    putenv('CALLS_ENTRYPOINTS_INSTALL=' . ERP_INSTALLATION_ROOT);
    putenv('CALLS_ENTRYPOINTS_ROOT=' . $root);
    putenv('CALLS_BROWSER_OUTPUT_ROOT=' . $root);
    putenv('CALLS_BROWSER_BASE_URL=http://127.0.0.1:' . $port);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/calls_entrypoints_router.php'],
        [0 => ['pipe','r'], 1 => ['file',$root . '/server.log','w'], 2 => ['file',$root . '/server-error.log','w']], $pipes,
        dirname(__DIR__), null, ['bypass_shell' => true, 'create_no_window' => true]);
    $assert(is_resource($server), 'local_server_started');
    fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) {
        $socket = @fsockopen('127.0.0.1', $port);
        if (is_resource($socket)) { fclose($socket); break; }
        usleep(100000);
    }
    $cookie = '';
    $request = static function (string $path, ?array $data = null, array $extra = []) use (&$cookie, $port): array {
        $handle = curl_init('http://127.0.0.1:' . $port . $path);
        $headers = ['Cookie: ' . $cookie];
        if ($data !== null) $headers[] = 'Origin: http://127.0.0.1:' . $port;
        foreach ($extra as $key => $value) {
            $headers = array_values(array_filter($headers, static fn ($header) => !str_starts_with($header, $key . ':')));
            $headers[] = $key . ': ' . $value;
        }
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 35, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '']);
        if ($data !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
        $raw = curl_exec($handle);
        if (!is_string($raw)) throw new RuntimeException('Local HTTP failed');
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $size);
        if (preg_match('/Set-Cookie: (erp_meli_session=[^;\r\n]+)/i', $head, $match)) $cookie = $match[1];
        preg_match('/Location: ([^\r\n]+)/i', $head, $location);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => $location[1] ?? ''];
    };
    $login = static function (string $kind) use ($request): string {
        $result = $request('/__fixture/session?kind=' . $kind);
        return (string) (json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR)['csrf']);
    };
    $snapshot = static function () use ($pdo): string {
        $state = [];
        foreach (['manual_campaign_previews','manual_campaign_preview_items','manual_campaigns','manual_campaign_items',
            'queue_core_jobs','queue_core_attempts','queue_v4_clean_jobs','queue_v4_clean_runs','queue_v4_clean_attempts',
            'queue_core_execution_leases','meli_notification_work_items','sync_batch_chunks','order_financial_recalc_jobs',
            'meli_tokens','system_work_queue_projection'] as $table) {
            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
            $encoded = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded);
            $state[$table] = $encoded;
        }
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    };
    $zero = static function (string $before, string $label) use ($snapshot, $assert, $root): void {
        $assert(hash_equals($before, $snapshot()), $label . '_business_and_preview_unchanged');
        clearstatcache(true, $root . '/wire.jsonl');
        $assert(filesize($root . '/wire.jsonl') === 0, $label . '_wire_zero');
    };
    $csrf = $login('admin');
    $page = $request('/settings/manual-processing?scope=sales&account_id=9011');
    $assert($page['status'] === 200, 'real_GET_manual_page');
    preg_match('/name="capacity_revision" value="([^"]+)"/', $page['body'], $revision);
    $assert(!empty($revision[1]), 'actual_form_capacity_revision');
    $preview = $request('/settings/manual-processing/preview', ['_token' => $csrf, 'scope' => 'sales', 'account_id' => '9011',
        'physical_api_call_budget' => '1', 'capacity_revision' => html_entity_decode($revision[1])]);
    parse_str((string) parse_url($preview['location'], PHP_URL_QUERY), $query);
    $token = (string) ($query['preview'] ?? '');
    $assert($preview['status'] === 302 && preg_match('/^[a-f0-9]{40}$/', $token) === 1, 'actual_POST_preview_creates_token');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM manual_campaign_preview_items')->fetchColumn() > 0, 'preview_has_real_eligible_source');
    foreach (['anonymous','operator','temporary','wrong_origin','no_csrf','invalid_csrf'] as $kind) {
        $csrf = $login(in_array($kind, ['anonymous','operator','temporary'], true) ? $kind : 'admin');
        $data = ['_token' => $csrf, 'preview_token' => $token, 'physical_api_call_budget' => '1'];
        if ($kind === 'no_csrf') unset($data['_token']);
        if ($kind === 'invalid_csrf') $data['_token'] = 'invalid';
        $before = $snapshot();
        $response = $request('/settings/manual-processing/start', $data, $kind === 'wrong_origin' ? ['Origin' => 'https://untrusted.invalid'] : []);
        $assert($response['status'] === ($kind === 'anonymous' ? 302 : 403), $kind . '_START_denied');
        if ($kind === 'anonymous') $assert(str_ends_with($response['location'], '/login'), 'anonymous_redirect_login');
        else $assert($response['body'] !== '' && !str_contains($response['body'], 'Cálculo terminado'), $kind . '_explicit_error');
        $zero($before, $kind);
    }
    $csrf = $login('admin');
    $before = $snapshot();
    $page = $request('/settings/manual-processing?scope=sales&account_id=9011&preview=' . $token);
    $assert($page['status'] === 200 && str_contains($page['body'], $token), 'GET_persisted_preview_is_readable');
    $zero($before, 'GET_preview');
    $before = $snapshot();
    $cron = $request('/settings/cron/test', ['_token' => $csrf, '_json' => '1'], ['Accept' => 'application/json']);
    $payload = json_decode($cron['body'], true, 512, JSON_THROW_ON_ERROR);
    // This deliberately incomplete local installation must report failure,
    // not successful certification. The test does not fake readiness/integrity.
    $assert($cron['status'] === 500 && ($payload['ok'] ?? null) === false && !empty($payload['message']), 'cronTest_incomplete_install_explicit_failure');
    $zero($before, 'cronTest');
    $before = $snapshot();
    $detail = $request('/settings/cron/work?queue_key=financial_recalc&source_id=' . $foreignId);
    $assert(in_array($detail['status'], [403,404], true), 'foreign_source_detail_denied');
    $assert(!str_contains($detail['body'], 'FOREIGN-CONTENT-78102'), 'foreign_source_content_not_leaked');
    $zero($before, 'foreign_detail');
    $before = $snapshot();
    $owned = $request('/settings/cron/work?queue_key=notification_fallback&source_id=' . $source['source_id']);
    $assert($owned['status'] === 200, 'own_source_detail_positive_control');
    $assert(!str_contains($owned['body'], $untrustedLink)
        && str_contains($owned['body'], htmlspecialchars($untrustedLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        'source_external_link_rendered_as_escaped_text_not_navigation');
    $login('foreign');
    $foreignOwned = $request('/settings/cron/work?queue_key=financial_recalc&source_id=' . $foreignId);
    $assert($foreignOwned['status'] === 200 && str_contains($foreignOwned['body'], 'FOREIGN-CONTENT-78102'), 'foreign_source_owner_positive_control');
    $login('admin');
    $zero($before, 'authorized_detail_controls');
    $before = $snapshot();
    $invalid = $request('/settings/manual-processing?scope=invalid_scope');
    $assert($invalid['status'] >= 400, 'invalid_GET_scope_explicit_error');
    $zero($before, 'invalid_scope');
    $before = $snapshot();
    // Real SQL failure, not a PDO double: corrupt only this disposable schema,
    // restore immediately, then compare the complete business snapshot.
    $pdo->exec('ALTER TABLE system_work_queue_projection RENAME COLUMN source_id TO qa_missing_source_id');
    try {
        $failed = $request('/settings/cron/work?queue_key=notification_fallback&source_id=' . $source['source_id']);
        $assert($failed['status'] === 500, 'detail_SQL_failure_explicit_500_not_empty_success');
        $assert(!str_contains($failed['body'], 'SQLSTATE') && !str_contains($failed['body'], 'qa_missing_source_id'), 'detail_SQL_failure_no_internal_leak');
    } finally {
        $pdo->exec('ALTER TABLE system_work_queue_projection RENAME COLUMN qa_missing_source_id TO source_id');
    }
    $zero($before, 'detail_SQL_failure');
    echo "STATUS=PASS CALLS_ENTRYPOINTS_HTTP MYSQL_SCHEMA=301 AUTH=REAL ROUTER=REAL CSRF=REAL MELI_HTTP=0\n";
    echo "LIMITS: cronTest success-path certification not covered by this matrix.\n";
    require __DIR__ . '/calls_scopes_matrix.php';
    calls_scopes_matrix($pdo, $request, $login, $assert, $source);
    if ($browserRequested) {
        require __DIR__ . '/calls_entrypoints_readiness_browser.php';
        calls_entrypoints_readiness_browser($pdo, $assert);
    }
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $database->cleanup();
    echo "CLEANUP=server_stopped_disposable_database_dropped\n";
}
