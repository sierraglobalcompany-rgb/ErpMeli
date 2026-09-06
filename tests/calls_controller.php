<?php
declare(strict_types=1);
// Controller-only boundary test. Domain execution is separately integration-tested.
namespace App\Core {
    final class Auth {
        public static function requireRole(string ...$roles): void {}
        public static function isTemporary(): bool { return false; }
        public static function id(): int { return 9007; }
    }
}
namespace App\Services {
    final class SystemSafetyStatusService {
        public function status(): array { return ['api'=>'running','automation'=>'running']; }
    }
    final class ManualSingleStepService {
        public function executePreview(string $token,int $user,int $calls): array {
            $GLOBALS['calls_admitted'] = [$token,$user,$calls];
            return ['status'=>'deferred','selected_count'=>30,'processed_count'=>1,'not_processed_count'=>29,
                'requested_api_calls'=>$calls,'effective_api_calls'=>$calls,'api_calls_used'=>1,'api_calls_remaining'=>0,
                'evidence_state'=>'CERTIFIED','stop_reason'=>'call_budget_exhausted','results'=>[['status'=>'deferred']]];
        }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
$_ENV['APP_URL']='https://local.test'; $_SERVER['HTTP_ORIGIN']='https://local.test';
$_SESSION=['_csrf'=>'qa-csrf'];
$mode=$argv[1]??'valid';
$_POST=['_token'=>'qa-csrf','preview_token'=>str_repeat('a',40),'scope'=>'recommended','physical_api_call_budget'=>'1'];
if ($mode==='legacy') $_POST['process_limit']='30';
if ($mode==='invalid') $_POST['physical_api_call_budget']='1.5';
register_shutdown_function(static function() use ($mode): void {
    if ($mode!=='valid') {
        k1b_assert(!isset($GLOBALS['calls_admitted']),'invalid_or_legacy_post_executed');
        echo "PASS calls controller rejects $mode\n"; return;
    }
    k1b_assert(($GLOBALS['calls_admitted'][2]??null)===1,'physical_request_not_forwarded');
    $r=\App\Core\Session::get('manual_processing_result');
    k1b_assert($r['completed']===0 && $r['waiting']===1 && $r['not_started']===29,'partial_result_not_honest');
    k1b_assert(($r['api_calls_used']??null)===1,'physical_evidence_lost_by_controller');
    echo "PASS calls controller forwards calls and retains evidence\n";
});
(new \App\Controllers\SettingsController())->manualProcessingStart();
}
