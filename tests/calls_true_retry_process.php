<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_true_wire_fixture.php';
require __DIR__.'/calls_true_seed_fixture.php';

// Second, fresh PHP process for one scheduler execution; never creates or repairs fixture state.
$options=getopt('',['context:']);
$path=realpath((string)($options['context']??''));
true_seed_assert($path!==false&&str_starts_with(str_replace('\\','/',$path),'D:/Codex/'),'RETRY_CONTEXT_LOCAL');
$context=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
K1dSafeTestDatabase::assertGuard((string)getenv('APP_ENV'),(string)getenv('ML_WRITE_ENABLED'),(string)getenv('DB_HOST'),(string)getenv('DB_NAME'));
true_seed_assert(getenv('DB_NAME')===$context['db_name'],'RETRY_OWNED_DATABASE');
define('ERP_INSTALLATION_ROOT',$context['installation_root']);
$pdo=App\Core\Database::connection();
App\Services\CallsTrueWire::$execution=2;
App\Services\CallsTrueWire::$ledger=dirname($path).'/retry-child-wire.jsonl';
App\Services\CallsTrueWire::$respond=static fn(array $e):array=>true_seed_response($e,$context['source'],'200');
App\Services\CronDeadlineContext::start(45,43,8,3);
try{$result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run($context['budget'],45);}
finally{App\Services\CronDeadlineContext::clear();}
file_put_contents(dirname($path).'/retry-child-result.json',json_encode(['pid'=>getmypid(),'result'=>$result,'wire'=>App\Services\CallsTrueWire::$entries,'violations'=>App\Services\CallsTrueWire::$violations],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

