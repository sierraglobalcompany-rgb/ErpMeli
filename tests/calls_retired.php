<?php
declare(strict_types=1);
namespace App\Core {
    final class Auth {
        public static function requireRole(string ...$roles): void { $GLOBALS['roles']=$roles; }
        public static function isTemporary(): bool { return false; }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
$_SESSION=['_csrf'=>'qa-only']; $_POST=['_token'=>'qa-only'];
$_ENV['APP_URL']='https://local.invalid'; $_SERVER['HTTP_ORIGIN']='https://local.invalid';
ob_start();
register_shutdown_function(static function():void {
    $payload=json_decode((string)ob_get_clean(),true);
    k1b_assert(($payload['ok']??true)===false && ($payload['retired']??false)===true,'retired_route_reports_success');
    k1b_assert(($payload['processed']??null)===false,'retired_route_missing_no_effect');
    k1b_assert(http_response_code()===410,'retired_route_not_gone');
    k1b_assert(($GLOBALS['roles']??[])===['admin'],'retired_manual_requires_same_admin_role');
    echo "PASS retired route honest 410/no work/admin\n";
});
(new \App\Controllers\SyncController())->processNowJson();
}
