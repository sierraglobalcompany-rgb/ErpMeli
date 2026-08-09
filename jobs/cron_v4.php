<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
$result=(new \App\QueueCore\CronV4Cli())->run($argv??[]);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['ok'])?0:2);
