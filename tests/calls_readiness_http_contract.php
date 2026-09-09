<?php
declare(strict_types=1);
// Fast controller contract only. Real session/DB/wire integration is a separate gate.
namespace App\Core {
    final class Auth {
        public static function requireRole(string ...$roles): void {}
        public static function isTemporary(): bool { return false; }
        public static function id(): int { return 9007; }
    }
    final class ReadinessTestPDO extends \PDO { public function __construct() {} }
    final class Database { public static function connectionFresh(): \PDO { return new ReadinessTestPDO(); } }
}
namespace App\Services {
    final class AdministrativeReauthenticationService {
        public function requirePassword(string $password): void {
            $GLOBALS['password_checks']=($GLOBALS['password_checks']??0)+1;
            $GLOBALS['auth_mode']='password';
            if ($password !== 'fixture-password') throw new \RuntimeException('password_required');
        }
        public function requireRecent(int $ttl=900): void {
            $GLOBALS['auth_mode']='recent';
            \k1b_assert($ttl===600, 'recent_ttl_must_match_context');
            if (($GLOBALS['mode']??'')==='expired-confirmation') throw new \RuntimeException('confirmation_expired');
        }
    }
    final class SafeErrorPresenter {
        public static function message(\Throwable $error,string $fallback,array $context=[]): string { return $fallback; }
    }
}
namespace App\QueueV4Clean {
    final class QueueV4CleanReadinessService {
        public function __construct(\PDO $pdo) {}
        public function certify(int $actor): array { $GLOBALS['admitted']=['legacy']; return ['ok'=>true]; }
        public function prepare(int $actor): array { $GLOBALS['admitted']=['prepare',$actor]; return ['ok'=>true]; }
        public function check(int $actor,int $run,string $token,int $step): array {
            \k1b_assert(\App\Services\CronDeadlineContext::remainingSeconds()>0
                && \App\Services\CronDeadlineContext::remainingSeconds()<=45, 'deadline_missing_at_admission');
            $GLOBALS['admitted']=['check',$actor,$run,$token,$step]; return ['ok'=>true];
        }
        public function cancel(int $actor,int $run,string $token): array {
            $GLOBALS['admitted']=['cancel',$actor,$run,$token]; return ['ok'=>true];
        }
    }
    final class QueueV4CleanControlService {
        public function __construct(\PDO $pdo) {}
        public function activate(int $actor,int $run=0,string $token=''): array {
            $GLOBALS['admitted']=['activate',$actor,$run,$token]; return ['ok'=>true];
        }
        public function stop(int $actor): array { $GLOBALS['admitted']=['stop',$actor]; return ['ok'=>true]; }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
$mode=$argv[1]??'check';
$GLOBALS['mode']=$mode;
$_ENV['APP_URL']='https://local.test'; $_SERVER['HTTP_ORIGIN']='https://local.test';
$_SESSION=['_csrf'=>'qa-csrf'];
$_POST=['_token'=>'qa-csrf','action'=>$mode,'run_id'=>'21','run_token'=>str_repeat('a',64),'step_no'=>'2'];
if (in_array($mode,['prepare','activate','stop','legacy'],true)) $_POST['admin_password']='fixture-password';
if ($mode==='legacy') unset($_POST['action']);
if ($mode==='invalid-step') { $_POST['action']='check'; $_POST['step_no']='1.5'; }
if ($mode==='invalid-run') { $_POST['action']='check'; $_POST['run_id']='21tail'; }
if ($mode==='invalid-token') { $_POST['action']='check'; $_POST['run_token']=['unexpected']; }
if ($mode==='injected-scope') { $_POST['action']='check'; $_POST['meli_account_id']='999'; }
$variants=[
    'run-zero'=>['run_id'=>'0'], 'run-negative'=>['run_id'=>'-1'],
    'run-overflow'=>['run_id'=>'9999999999999999999999999'], 'run-array'=>['run_id'=>['21']],
    'step-zero'=>['step_no'=>'0'], 'step-four'=>['step_no'=>'4'], 'step-array'=>['step_no'=>['2']],
    'token-empty'=>['run_token'=>''], 'token-short'=>['run_token'=>'abc'], 'token-nonhex'=>['run_token'=>str_repeat('z',64)],
    'action-array'=>['action'=>['check']], 'action-unknown'=>['action'=>'execute'],
    'password-array'=>['admin_password'=>['bad']], 'expired-confirmation'=>[],
];
if (array_key_exists($mode,$variants)) $_POST=array_replace($_POST,['action'=>'check'],$variants[$mode]);
$noPassword=in_array($mode,['prepare-no-password','activate-no-password','stop-no-password'],true);
if ($noPassword) $_POST['action']=str_replace('-no-password','',$mode);
if ($mode==='wrong-password') { $_POST['action']='prepare'; $_POST['admin_password']='wrong'; }
if ($mode==='check-refresh') { $_POST['action']='check'; $_POST['admin_password']='fixture-password'; }
if ($mode==='cancel-orphan') { $_POST['action']='cancel'; $_POST['run_token']=''; $_POST['admin_password']='fixture-password'; }
ob_start();
register_shutdown_function(static function() use ($mode,$variants,$noPassword): void {
    $json=ob_get_clean();
    $rejected=in_array($mode,['legacy','invalid-step','invalid-run','invalid-token','injected-scope','wrong-password'],true)
        || array_key_exists($mode,$variants) || $noPassword;
    if ($rejected) {
        k1b_assert(!isset($GLOBALS['admitted']),'invalid_request_reached_service_'.$mode);
        k1b_assert(http_response_code()===409,'invalid_request_not_conflict_'.$mode);
    } else {
        $action=match($mode) {'check-refresh'=>'check','cancel-orphan'=>'cancel',default=>$mode};
        k1b_assert(($GLOBALS['admitted'][0]??null)===$action,'explicit_action_not_forwarded_'.$mode);
        $expected=in_array($action,['check','cancel'],true)?'recent':'password';
        k1b_assert(($GLOBALS['auth_mode']??null)===$expected,'wrong_reauthentication_'.$mode);
        if ($mode==='check') k1b_assert($GLOBALS['admitted'][4]===2,'exact_step_lost');
        if ($mode==='activate') k1b_assert($GLOBALS['admitted'][2]===21 && strlen($GLOBALS['admitted'][3])===64,'activation_proof_lost');
        if (in_array($mode,['check-refresh','cancel-orphan'],true)) k1b_assert(($GLOBALS['password_checks']??0)===1,'explicit_refresh_not_verified');
    }
    k1b_assert(is_array(json_decode($json,true)),'controller_response_not_json');
    k1b_assert(\App\Services\CronDeadlineContext::deadline()===null,'controller_leaked_deadline');
    echo 'PASS readiness HTTP contract '.$mode.PHP_EOL;
});
$controller=new \App\Controllers\SettingsController();
match($mode) {
    'activate','activate-no-password'=>$controller->queueV4CleanActivate(),
    'stop','stop-no-password'=>$controller->queueV4CleanStop(),
    default=>$controller->queueV4CleanReadiness(),
};
}
