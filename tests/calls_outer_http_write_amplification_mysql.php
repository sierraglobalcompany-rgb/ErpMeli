<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\QueueV4Clean\OuterCronHttpReceipt;

final class OuterReceiptMeasuredStatement extends PDOStatement
{
    public static array $executions=[];
    protected function __construct() {}
    public function execute(?array $params=null): bool
    {
        self::$executions[]=$this->queryString;
        return parent::execute($params);
    }
}
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33338','DB_USER'=>'root','DB_PASS'=>'local-http-receipt-test-only','DB_NAME'=>'erp_meli_k1d_test_http_writes_'.bin2hex(random_bytes(5)), 'CALLS_VERIFY_QA_ROOT'=>dirname(__DIR__).'/storage/codex-http-phase1'] as $k=>$v) { putenv($k.'='.$v); }
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/115_resumable_execution_journal_2_21_3.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS system_execution_(?:runs|attempts)\s*\(.*?ENGINE=InnoDB.*?;/s',$sql,$tables);
    foreach($tables[0] as $table) { $pdo->exec($table); }
    $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(255))');
    $pdo->exec('CREATE TABLE system_cold_archives(dataset_key VARCHAR(80))');
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/303_outer_cron_http_receipt.sql'));
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OuterReceiptMeasuredStatement::class,[]]);
    foreach(['known'=>3,'cancelled'=>2,'refunded_cancelled'=>3,'boundary_refunded_cancelled'=>4] as $mode=>$expected) {
        OuterReceiptMeasuredStatement::$executions=[];
        $before=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts')->fetchColumn();
        $result=OuterCronHttpReceipt::within($pdo,['max_calls'=>10,'ceiling'=>55],static function () use($mode): array {
            $id=bin2hex(random_bytes(20));
            OuterCronHttpReceipt::reserve($id,'GET','/orders/100',9001,9011,'queue_v4_clean');
            if($mode==='known') {
                OuterCronHttpReceipt::boundary($id); OuterCronHttpReceipt::enteringWire($id); OuterCronHttpReceipt::result($id,200,'');
            } else {
                if($mode==='boundary_refunded_cancelled') { OuterCronHttpReceipt::boundary($id); }
                if(in_array($mode,['refunded_cancelled','boundary_refunded_cancelled'],true)) { OuterCronHttpReceipt::released($id); }
                OuterCronHttpReceipt::cancelBeforeTransport($id);
            }
            return ['status'=>'completed'];
        });
        $childWrites=array_filter(OuterReceiptMeasuredStatement::$executions,static fn(string $sql): bool => preg_match('/^(INSERT INTO|UPDATE) system_execution_attempts\s/',$sql)===1);
        $parentWrites=array_filter(OuterReceiptMeasuredStatement::$executions,static fn(string $sql): bool => preg_match('/^(INSERT INTO|UPDATE) system_execution_runs\s/',$sql)===1);
        $rows=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts')->fetchColumn()-$before;
        k1b_assert($rows===1 && count($childWrites)===$expected && count($parentWrites)===2,'measured writes '.$mode);
        echo json_encode(['mode'=>$mode,'child_rows'=>$rows,'child_dml'=>count($childWrites),'parent_dml'=>count($parentWrites),'outer_run_rows'=>1,'wire_http'=>0,'observer_sql_including_cycle_overhead'=>count(OuterReceiptMeasuredStatement::$executions)+1],JSON_THROW_ON_ERROR).PHP_EOL;
    }
    echo "WRITE_AMPLIFICATION_MEASURED=PASS REAL_MELI_HTTP=0 REAL_OAUTH=0\n";
} finally { $db->cleanup(); }
