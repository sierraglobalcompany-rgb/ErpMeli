<?php
declare(strict_types=1);
// Persistence/transaction regression, not a replacement for physical client/fence tests.
require __DIR__.'/cap2_manual_fixture.php';
use App\Core\Crypto;
use App\Services\OAuthTokenRefreshService;
$h=cap2_manual_database(); $pdo=$h->pdo();
try {
    $pdo->exec("UPDATE meli_tokens SET expires_at='2000-01-01 00:00:00' WHERE meli_account_id=9011");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9012,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([Crypto::encrypt('other-access-fixture'),Crypto::encrypt('other-refresh-fixture')]);
    $read=static function(int $id) use($pdo): array {
        $s=$pdo->prepare('SELECT * FROM meli_tokens WHERE meli_account_id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
    };
    $other=$read(9012); $before=$read(9011); $requests=0;
    $wire=static function(array $payload) use($pdo,&$requests): array {
        k1b_assert(!$pdo->inTransaction(),'oauth_transport_inside_transaction');
        k1b_assert(($payload['grant_type']??'')==='refresh_token','oauth_grant_changed');
        ++$requests;
        return ['access_token'=>'rotated-access-fixture','refresh_token'=>'rotated-refresh-fixture','expires_in'=>21600,'token_type'=>'Bearer'];
    };
    $service=new OAuthTokenRefreshService(9011);
    $result=$service->refresh($wire);
    k1b_assert($requests===1 && (int)$result['refresh_version']===(int)$before['refresh_version']+1,'oauth_rotation_not_persisted_once');
    k1b_assert(Crypto::decrypt($result['access_token_encrypted'])==='rotated-access-fixture','oauth_rotated_access_not_saved');
    k1b_assert($read(9012)===$other,'oauth_changed_other_account');
    $service->refresh($wire);
    k1b_assert($requests===1,'fresh_oauth_performed_another_request');
    $pdo->beginTransaction();
    k1b_assert(cap2_manual_rejected(static fn()=>$service->refresh($wire)),'oauth_accepted_caller_transaction');
    k1b_assert($pdo->inTransaction() && $requests===1,'oauth_rolled_back_caller_or_sent');
    $pdo->rollBack();
    $pdo->exec("UPDATE meli_tokens SET expires_at='2000-01-01 00:00:00' WHERE meli_account_id=9011");
    $beforeFailure=$read(9011);
    k1b_assert(cap2_manual_rejected(static fn()=>$service->refresh(static function(array $payload): array {
        throw new RuntimeException('fixture_provider_failure');
    })),'oauth_provider_failure_accepted');
    k1b_assert($read(9011)===$beforeFailure,'oauth_failure_overwrote_credentials');
    $pdo->exec("CREATE TRIGGER calls_oauth_fail BEFORE UPDATE ON meli_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_local_write_failure'");
    try {
        k1b_assert(cap2_manual_rejected(static fn()=>$service->refresh($wire)),'oauth_database_failure_accepted');
        k1b_assert(!$pdo->inTransaction(),'oauth_failed_rotation_leaked_transaction');
        k1b_assert($read(9011)===$beforeFailure,'oauth_partial_rotation_not_rolled_back');
    } finally { $pdo->exec('DROP TRIGGER calls_oauth_fail'); }
    k1b_assert($read(9012)===$other,'oauth_error_changed_other_account');
    echo "PASS OAuth schema301 persistence, isolation, local rollback and transaction ownership\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $h->cleanup();
}
