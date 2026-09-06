<?php
declare(strict_types=1);
// Isolated controller presentation boundary; admission and domain behavior are
// tested with their real implementations in cap2_manual_{admission,outcomes}.
namespace App\Core {
    final class Auth {
        public static function requireRole(string $role): void {}
        public static function isTemporary(): bool {return false;}
        public static function id(): int {return 9007;}
    }
}
namespace App\Services {
    final class SystemSafetyStatusService {public function status(): array {return ['api'=>'running','automation'=>'running'];}}
    final class CapacityPolicyService {
        public const TECHNICAL_MAX=100;
        public function snapshot(string $module): array {return ['current'=>3];}
        public function validatePair(mixed $current,mixed $ceiling): array {return ['current'=>(int)$current,'ceiling'=>(int)$ceiling];}
    }
    final class ManualSingleStepService {
        public function executePreview(string $token,int $user,int $limit): array {
            return ['status'=>'deferred','selected_count'=>3,'processed_count'=>1,'completed_count'=>0,'deferred_count'=>1,'review_error_count'=>0,'not_processed_count'=>2,'message'=>'Progreso guardado; vuelva a calcular.','results'=>[['status'=>'deferred']]];
        }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
$_ENV['APP_URL']='https://local.test';$_SERVER['HTTP_ORIGIN']='https://local.test';
$_SESSION=['_csrf'=>'qa-csrf'];$_POST=['_token'=>'qa-csrf','preview_token'=>str_repeat('a',40),'scope'=>'orders','physical_api_call_budget'=>3];
register_shutdown_function(static function(): void {
    $r=\App\Core\Session::get('manual_processing_result');
    k1b_assert($r['processed']===1 && $r['completed']===0 && $r['waiting']===1 && $r['review']===0 && $r['not_started']===2,'controller_does_not_report_selected_as_processed_or_deferred_as_error:'.json_encode($r));
    echo "STATUS=PASS CAP2_MANUAL_PRESENTATION\n";
});
(new \App\Controllers\SettingsController())->manualProcessingStart();
}
