<?php
declare(strict_types=1);
// Separate request/process: do not fake or reset the in-flight parent's budget.
define('ERP_INSTALLATION_ROOT',(string)$argv[3]);
define('ERP_SHARED_ROOT',(string)$argv[4]);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
K1dSafeTestDatabase::assertGuard((string)getenv('APP_ENV'),(string)getenv('ML_WRITE_ENABLED'),(string)getenv('DB_HOST'),(string)getenv('DB_NAME'));
session_save_path((string)$argv[2]);session_name('erp_meli_session');session_id((string)$argv[1]);session_start();
if(($argv[5]??'')==='hold-session') {echo "LOCKED\n";flush();sleep(2);session_write_close();exit;}
session_write_close();
$pdo=App\Core\Database::connection();
(new App\QueueV4Clean\QueueV4CleanControlService($pdo))->stop(9007);
$run=(new App\QueueV4Clean\QueueV4CleanReadinessService($pdo))->prepare(9007);
echo json_encode(['run_id'=>$run['run_id']],JSON_THROW_ON_ERROR);
