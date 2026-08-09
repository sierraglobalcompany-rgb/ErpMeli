<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
$option=static function(array $argv,string $name,int $default):int{$prefix='--'.$name.'=';foreach($argv as $arg)if(str_starts_with($arg,$prefix))return(int)substr($arg,strlen($prefix));return $default;};
$textOption=static function(array $argv,string $name):?string{$prefix='--'.$name.'=';foreach($argv as $arg)if(str_starts_with($arg,$prefix)){ $value=trim(substr($arg,strlen($prefix)));return $value!==''?$value:null;}return null;};
try{\App\Core\Database::useProfile('cli');$result=(new \App\QueueCore\QueueCoreCanaryService(\App\Core\Database::connectionFresh()))->run(
    $option($argv??[],'account',0),$option($argv??[],'max-jobs',3),$option($argv??[],'max-http',2),$option($argv??[],'deadline',20),
    $textOption($argv??[],'bootstrap-from')
);}catch(\Throwable){$result=['ok'=>false,'status'=>'BLOCKED','reason'=>'canary_unavailable','physical_http_calls'=>0];}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['ok'])?0:2);

