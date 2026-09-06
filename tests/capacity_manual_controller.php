<?php
declare(strict_types=1);
namespace App\Core {
    final class Auth {
        public static function requireRole(string $role): void { if($role!=='admin')throw new \RuntimeException('Admin gate required.'); }
        public static function isTemporary(): bool { return false; }
        public static function id(): int { return 7; }
    }
}
namespace App\Services {
    final class SystemSafetyStatusService { public function status(): array { return ['api'=>'running','automation'=>'running']; } }
    final class CapacityPolicyService {
        public const TECHNICAL_MAX=100;
        public function snapshot(string $module): array { return ['module'=>$module,'current'=>1,'ceiling'=>55,'revision'=>'qa-current-one']; }
        public function validatePair(mixed $current,mixed $ceiling): array { return ['current'=>(int)$current,'ceiling'=>(int)$ceiling]; }
    }
    final class ManualSingleStepService {
        public static array $received=[];
        public function executePreview(string $token,int $userId,int $limit): array {
            self::$received=[$token,$userId,$limit];
            return ['status'=>'completed','selected_count'=>$limit,'remote_dispatches'=>0];
        }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
$_ENV['APP_URL']='https://local.test';
$_SERVER['HTTP_ORIGIN']='https://local.test';
$_SESSION=['_csrf'=>'qa-csrf'];
$_POST=['_token'=>'qa-csrf','preview_token'=>str_repeat('a',40),'scope'=>'financial','physical_api_call_budget'=>'30'];
register_shutdown_function(static function(): void {
    $received=\App\Services\ManualSingleStepService::$received;
    k1b_assert($received===[str_repeat('a',40),7,30], 'Real manualProcessingStart must forward the requested physical-call budget independently of displayed resources; received '.json_encode($received));
    echo "STATUS=PASS CAPACITY_MANUAL_CONTROLLER REAL_CONTROLLER=YES REAL_HTTP=0\n";
});
(new \App\Controllers\SettingsController())->manualProcessingStart();
}
