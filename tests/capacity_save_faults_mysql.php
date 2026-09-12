<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\CapacityPolicyService;

// Optional exact historical service is loaded only for RED comparison, before autoload.
if (isset($argv[2])) {
    $baseline = str_replace('\\', '/', realpath($argv[2]) ?: '');
    k1b_assert(str_starts_with($baseline, 'C:/codex/capacity-save-kiss/') && str_ends_with($baseline, '/baseline/CapacityPolicyService.php'), 'baseline_path_owned');
    require $baseline;
}
final class CapacityFaultPdo extends PDO
{
    public bool $releaseFails = false;
    public bool $rollbackFails = false;
    public bool $commitAckLost = false;
    public int $commits = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->releaseFails && str_starts_with($query, 'SELECT RELEASE_LOCK')) throw new RuntimeException('fixture_release_failure');
        return parent::prepare($query, $options);
    }
    public function commit(): bool {
        $result = parent::commit();
        $this->commits++;
        if ($this->commitAckLost) throw new RuntimeException('fixture_commit_ack_lost');
        return $result;
    }
    public function rollBack(): bool {
        if ($this->rollbackFails) throw new RuntimeException('fixture_rollback_failure');
        return parent::rollBack();
    }
}
$case = $argv[1] ?? 'health';
putenv('APP_ENV=test'); putenv('ML_WRITE_ENABLED=false'); putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079')); putenv('DB_USER=root'); putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_capacity_fault_' . bin2hex(random_bytes(5)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $observer=$db->pdo();
    $observer->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT, is_encrypted TINYINT DEFAULT 0, setting_group VARCHAR(80)) ENGINE=InnoDB');
    $pdo=new CapacityFaultPdo('mysql:host=127.0.0.1;port='.$db->port.';dbname='.$db->dbName, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_PERSISTENT=>false]);
    $policy=new CapacityPolicyService($pdo);
    $before=$policy->snapshot('automation');
    $lock='capacity:'.substr(hash('sha256', $db->dbName),0,32).':automation';
    if ($case==='busy') {
        $stmt=$observer->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$lock]);
        k1b_assert((int)$stmt->fetchColumn()===1,'fixture_holds_lock');
    }
    if ($case==='stale') $before['revision']='outdated-revision';
    $pdo->releaseFails=in_array($case,['release','write_release','write_rollback'],true);
    $pdo->rollbackFails=$case==='write_rollback';
    $pdo->commitAckLost=$case==='commit_unknown';
    if (str_starts_with($case,'write_')) {
        $observer->exec("CREATE TRIGGER capacity_second_write_failure BEFORE INSERT ON app_settings FOR EACH ROW BEGIN IF NEW.setting_key='automation.api_calls_ceiling' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_primary_write_failure'; END IF; END");
    }
    $error=null; $saved=null;
    try {
        // A legacy gate deliberately denies. New signature receives exactly four args.
        $args=['automation',10,55,$before['revision']];
        if (isset($argv[2])) $args[]=static fn():array=>['allowed'=>false,'message'=>'fixture_health_denied'];
        // Cleanup RED must reach writes; only the health comparison denies the old gate.
        if (isset($argv[2]) && $case!=='health') $args[4]=static fn():array=>['allowed'=>true];
        $saved=$policy->save(...$args);
    } catch (Throwable $caught) { $error=$caught; }
    if (in_array($case,['health','release'],true)) {
        k1b_assert($error===null, 'acknowledged_save_must_succeed:'.($error?->getMessage() ?? ''));
        k1b_assert($saved['current']===10 && $pdo->commits===1, 'one_acknowledged_commit');
        k1b_assert((int)$observer->query("SELECT setting_value FROM app_settings WHERE setting_key='automation.max_api_calls_per_cycle'")->fetchColumn()===10, 'independent_connection_observes_committed_pair');
    } elseif (str_starts_with($case,'write_')) {
        k1b_assert($error instanceof PDOException && str_contains($error->getMessage(),'fixture_primary_write_failure'), 'primary_error_preserved:'.($error?->getMessage() ?? 'none'));
        k1b_assert($pdo->commits===0 && (int)$observer->query('SELECT COUNT(*) FROM app_settings')->fetchColumn()===0, 'no_half_pair_visible');
    } elseif ($case==='commit_unknown') {
        k1b_assert($saved===null && $error?->getMessage()==='fixture_commit_ack_lost' && $pdo->commits===1, 'lost_ack_propagated_without_retry');
        k1b_assert((int)$observer->query('SELECT COUNT(*) FROM app_settings')->fetchColumn()===2, 'lost_ack_may_have_committed_not_zero');
    } elseif (in_array($case,['stale','busy'],true)) {
        k1b_assert($error instanceof App\Core\HttpException && $error->status===409 && $error->safe, 'known_conflict_has_safe_specific_http_result');
        k1b_assert((int)$observer->query('SELECT COUNT(*) FROM app_settings')->fetchColumn()===0,'conflict_zero_writes');
        if ($case==='busy') { $stmt=$observer->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]); }
    } else throw new RuntimeException('unknown_case');
    // A fresh request cannot retain the old request connection; closing it releases its named lock.
    unset($policy,$pdo);
    $stmt=$observer->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$lock]);
    k1b_assert((int)$stmt->fetchColumn()===1,'fresh_connection_can_acquire_after_request_closes');
    $stmt=$observer->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]);
    echo 'STATUS=PASS CAPACITY_SAVE_FAULTS_MYSQL CASE='.$case.' DB='.$observer->query('SELECT VERSION()')->fetchColumn()." REAL_DB=YES FAULT_BOUNDARY=PDO_ACK_OR_CLEANUP REAL_MELI_HTTP=0\n";
} finally {
    // Fault injection must not prevent teardown of our own live transaction.
    if (isset($pdo)) {
        $pdo->rollbackFails=false; $pdo->releaseFails=false;
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    unset($policy,$pdo,$error,$caught);
    $db->cleanup();
}
