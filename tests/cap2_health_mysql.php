<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_health_fixture.php';

use App\Core\HttpException;
use App\Services\CapacityChangeGuard;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_health_' . bin2hex(random_bytes(4)));

$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    cap2_health_create_schema($pdo);
    cap2_health_seed($pdo);
    cap2_health_authenticate();

    $guard = new CapacityChangeGuard($pdo);
    $guard->assertGlobalAuthorization();
    k1b_assert($guard->increaseGate()['allowed'] === true, 'authoritative_empty_must_be_healthy');
    $selectsBefore = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $startedAt = hrtime(true);
    $guard->assertGlobalAuthorization();
    $instrumentedGate = $guard->increaseGate();
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
    $selectsAfter = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $guardSelects = $selectsAfter - $selectsBefore;
    k1b_assert($instrumentedGate['allowed'] === true, 'instrumented_healthy_fixture_must_allow');
    // Calls adds five admission-only SELECTs: two schema probes, one UNION
    // identity evidence read and two persisted rhythm/pause reads. None run
    // per physical call here; the pre-calls baseline for this fixture was 12.
    k1b_assert($guardSelects <= 17, 'capacity_health_guard_select_budget_exceeded:' . $guardSelects);

    $pdo->exec("INSERT INTO companies VALUES (6,'OAuth inactiva',0)");
    $pdo->exec("INSERT INTO meli_accounts VALUES (66,6,'Cuenta OAuth 66','u66','conectado')");
    $pdo->exec("INSERT INTO meli_tokens VALUES (66,'enc',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1)");
    $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,state,next_attempt_at,last_error_class) VALUES (6,66,'FAILED',UTC_TIMESTAMP(3),'oauth_only_fixture')");
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) { $denied = $error->status === 403; }
    k1b_assert($denied, 'oauth_only_terminal_tenant_must_require_company_and_account_authority');
    $pdo->exec("INSERT INTO user_company_access VALUES (7,6,'admin')");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'oauth_only_terminal_without_readiness_or_job_must_block_increase');
    $pdo->exec('DELETE FROM oauth_refresh_operations WHERE meli_account_id=66');
    $pdo->exec('DELETE FROM user_company_access WHERE company_id=6');
    $pdo->exec('DELETE FROM meli_tokens WHERE meli_account_id=66');
    $pdo->exec('DELETE FROM meli_accounts WHERE id=66');
    $pdo->exec('DELETE FROM companies WHERE id=6');

    $pdo->exec('DELETE FROM user_company_access WHERE company_id=5');
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) { $denied = $error->status === 403; }
    k1b_assert($denied, 'active_company_account_without_readiness_or_work_must_be_covered');
    $pdo->exec("INSERT INTO user_company_access VALUES (7,5,'admin')");

    $pdo->exec('DELETE FROM user_company_access WHERE company_id=4');
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) { $denied = $error->status === 403; }
    k1b_assert($denied, 'active_company_without_account_must_be_covered');
    $pdo->exec("INSERT INTO user_company_access VALUES (7,4,'admin')");

    $pdo->exec('DELETE FROM user_company_access WHERE company_id=2');
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) {
        $denied = $error->status === 403 && $error->publicMessage === 'No tiene permisos para modificar la capacidad global.';
    }
    k1b_assert($denied, 'partial_company_coverage_must_be_generically_denied');
    $pdo->exec("INSERT INTO user_company_access VALUES (7,2,'admin')");

    $pdo->exec("INSERT INTO user_meli_account_access VALUES (7,11,'admin')");
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) { $denied = $error->status === 403; }
    k1b_assert($denied, 'account_restricted_admin_must_not_gain_unassigned_accounts_from_company_access');
    $pdo->exec('DELETE FROM user_meli_account_access');

    $pdo->exec('DELETE FROM user_company_access WHERE company_id=3');
    $denied = false;
    try { $guard->assertGlobalAuthorization(); } catch (HttpException $error) { $denied = $error->status === 403; }
    k1b_assert($denied, 'inactive_company_runnable_job_must_be_covered');
    $pdo->exec("INSERT INTO user_company_access VALUES (7,3,'admin')");
    $guard->assertGlobalAuthorization();

    $pdo->exec("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at) VALUES ('account',1,11,429,0,UTC_TIMESTAMP()),('account',1,11,429,1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR))");
    k1b_assert($guard->increaseGate()['allowed'] === true, 'local_or_expired_429_must_not_block');
    $pdo->exec("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at) VALUES ('account',99,999,429,1,UTC_TIMESTAMP())");
    k1b_assert($guard->increaseGate()['allowed'] === true, 'remote_429_outside_affected_scope_must_not_expand_health_scope');
    $pdo->exec("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at) VALUES ('account',1,11,429,1,UTC_TIMESTAMP())");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'actual_scoped_remote_429_last24h_must_block');
    $pdo->exec('DELETE FROM api_request_logs');

    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='dead' WHERE meli_account_id=11");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'dead_job_must_block');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',lease_expires_at=NULL WHERE meli_account_id=11");
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='running',lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE) WHERE meli_account_id=11");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'stale_running_job_must_block');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',lease_expires_at=NULL WHERE meli_account_id=11");
    $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,state,next_attempt_at,last_error_class) VALUES (1,11,'FAILED',UTC_TIMESTAMP(3),'fixture')");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'oauth_failure_must_block');
    $pdo->exec("UPDATE oauth_refresh_operations SET state='REMOTE_UNCERTAIN'");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'oauth_remote_uncertain_must_block');
    $pdo->exec('DELETE FROM oauth_refresh_operations');
    $pdo->exec("UPDATE meli_accounts SET status='desconectado' WHERE id=11");
    k1b_assert($guard->increaseGate()['allowed'] === false, 'oauth_reconnect_required_must_block');
    $pdo->exec("UPDATE meli_accounts SET status='conectado' WHERE id=11");
    k1b_assert($guard->increaseGate()['allowed'] === true, 'healthy_complete_snapshot_must_allow');

    $pdo->exec('DROP TABLE api_request_logs');
    k1b_assert($guard->increaseGate()['allowed'] === false, 'unknown_429_authority_must_deny_not_look_empty');

    echo 'STATUS=PASS CAP2_HEALTH_MYSQL REAL_DB=YES REAL_AUTH=YES REAL_GUARD=YES REAL_QUEUE_HEALTH=YES REAL_MELI_HTTP=0 '
        . 'FIXED_FIXTURE_SELECTS=' . $guardSelects . ' FIXED_FIXTURE_MS=' . number_format($elapsedMs, 3, '.', '') . "\n";
} finally {
    $db->cleanup();
}
