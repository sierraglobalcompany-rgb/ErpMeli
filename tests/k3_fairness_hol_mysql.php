<?php
declare(strict_types=1);
require __DIR__.'/r0_h3_bootstrap.php';
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\OrderEnrichmentService;
use App\Services\CronAdmissionService;
function holCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function holCursor(PDO $p,int $n):void {$p->prepare("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,next_due_at,last_job_id) VALUES('pack_discovery_fairness',0,0,UTC_TIMESTAMP(3),?) ON DUPLICATE KEY UPDATE last_job_id=VALUES(last_job_id)")->execute([$n]);}
function holCursorValue(PDO $p):int {return (int)$p->query("SELECT last_job_id FROM queue_v4_clean_checkpoints WHERE producer_key='pack_discovery_fairness' AND company_id=0 AND meli_account_id=0")->fetchColumn();}
function holSource(PDO $p,array $t):int {$id=(new OrderEnrichmentService())->enqueue($t['meli_account_id'],$t['order_id'],'pack',$t['external_pack_id'],10);$p->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$id]);return $id;}
function holDead(PDO $p,array $t,int $id):void {r0h3_insert($p,'queue_v4_clean_jobs',['company_id'=>$t['company_id'],'meli_account_id'=>$t['meli_account_id'],'job_type'=>'domain_exact','resource_id'=>(string)$id,'idempotency_key'=>'domain:order_enrichment_pack:source:'.$id,'payload_json'=>json_encode(['capability'=>'order_enrichment_pack','source_id'=>$id,'payload'=>['pack_id'=>$t['external_pack_id']]]),'state'=>'dead','available_at'=>gmdate('Y-m-d H:i:s')]);}
function holSchedule(PDO $p):int {$o=new QueueV4CleanProducer($p,new QueueV4CleanRepository($p));return (int)(new ReflectionMethod($o,'schedulePackExactDiscovery'))->invoke($o,[['company_id'=>7100,'meli_account_id'=>7101],['company_id'=>7200,'meli_account_id'=>7201],['company_id'=>7200,'meli_account_id'=>7202]]);}
function holRejectNewSources(PDO $p):void {$p->exec("CREATE TRIGGER hol_nonadmissible BEFORE INSERT ON order_resource_enrichment_jobs FOR EACH ROW BEGIN IF NEW.resource_type='pack' THEN SET NEW.status='error'; SET NEW.failure_class='synthetic_admission_denial'; END IF; END");}
function holSourceCount(PDO $p):int {return (int)$p->query('SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)')->fetchColumn();}
function holHistory(PDO $p):string {return hash('sha256',json_encode([$p->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'queue_v4.pack_discovery_%' ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM queue_v4_clean_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM queue_v4_clean_transport_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM order_resource_enrichment_jobs WHERE meli_account_id=7101 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]));}
$cases=['uncertain','uncertain_safe_get','coverage_fallback','existing_fallback','both_denied','deduplicated','occupancy_two','tenant_isolation','exception_after_success'];
if(isset($argv[1]))$cases=[$argv[1]];
$results=[];$failed=0;
foreach($cases as $case){
 $pdo->exec('DROP TRIGGER IF EXISTS hol_nonadmissible');$pdo->exec('DROP TRIGGER IF EXISTS hol_abort_second');$fixture=r0h3_seed($pdo);$h=$fixture['healthy'];$before=holHistory($pdo);
 try{
  switch($case){
   case 'uncertain':case 'uncertain_safe_get':
    $id=holSource($pdo,$h[0]);$class=$case==='uncertain'?'remote_result_uncertain':'remote_result_uncertain_safe_get';$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='error',failure_class=? WHERE id=?")->execute([$class,$id]);$hash=r0h3_source_hash($pdo,$id);holCursor($pdo,1);$created=holSchedule($pdo);
    holCheck($created===2,'T1/T2 healthy coverage must fill two slots beyond protected source');holCheck(r0h3_source_hash($pdo,$id)===$hash,'protected source changed');$q=$pdo->prepare("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id=? AND meli_account_id=? AND company_id=? AND job_type='domain_exact'");$q->execute([(string)$id,$h[0]['meli_account_id'],$h[0]['company_id']]);holCheck((int)$q->fetchColumn()===0,'protected source retried');break;
   case 'coverage_fallback':
    holSource($pdo,$h[1]);holCursor($pdo,1);$count=holSourceCount($pdo);$relations=$pdo->query('SELECT * FROM order_resource_enrichment_job_orders ORDER BY order_resource_enrichment_job_id,meli_order_id')->fetchAll(PDO::FETCH_ASSOC);holRejectNewSources($pdo);$created=holSchedule($pdo);holCheck($created===1,'T3 denied coverage must fall back to existing');holCheck(holCursorValue($pdo)===1,'T3 successful route must be existing');holCheck(holSourceCount($pdo)===$count,'T3 rollback left orphan source');holCheck($pdo->query('SELECT * FROM order_resource_enrichment_job_orders ORDER BY order_resource_enrichment_job_id,meli_order_id')->fetchAll(PDO::FETCH_ASSOC)===$relations,'T3 rollback left partial or dangling relation');break;
   case 'existing_fallback':
    $id=holSource($pdo,$h[0]);holDead($pdo,$h[0],$id);holCursor($pdo,2);$created=holSchedule($pdo);holCheck($created===1,'T4 deduplicated existing must fall back to coverage');holCheck(holCursorValue($pdo)===2,'T4 successful route must be coverage');break;
   case 'both_denied':case 'deduplicated':
    $id=holSource($pdo,$h[1]);holDead($pdo,$h[1],$id);$route=$case==='both_denied'?1:2;holCursor($pdo,$route);$count=holSourceCount($pdo);holRejectNewSources($pdo);$created=holSchedule($pdo);holCheck($created===0,'T5/T6 denied or deduplicated receipt must not count');holCheck(holCursorValue($pdo)===$route,'T5/T6 non-admission consumed fairness');holCheck(holSourceCount($pdo)===$count,'T5/T6 orphan source after rollback');break;
   case 'occupancy_two':
    foreach([$h[0],$h[1]]as $t){$id=holSource($pdo,$t);$pdo->beginTransaction();$r=(new CronAdmissionService($pdo))->submit('order_enrichment_pack',$t['company_id'],$t['meli_account_id'],$id,'source:'.$id,['pack_id'=>$t['external_pack_id']]);$pdo->commit();holCheck($r['accepted']&&!$r['deduplicated'],'fixture admission failed');}holCursor($pdo,1);$count=holSourceCount($pdo);holCheck(holSchedule($pdo)===0&&holSourceCount($pdo)===$count,'T7 target two exceeded');break;
   case 'tenant_isolation':
    // Restrict the call to one certified account; other account has eligible packs too.
    holCursor($pdo,1);$o=new QueueV4CleanProducer($pdo,new QueueV4CleanRepository($pdo));$created=(new ReflectionMethod($o,'schedulePackExactDiscovery'))->invoke($o,[['company_id'=>7200,'meli_account_id'=>7201]]);holCheck($created===2,'T8 scoped admission failed');holCheck((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200 AND meli_account_id<>7201")->fetchColumn()===0,'T8 crossed account');break;
   case 'exception_after_success':
    holCursor($pdo,1);$count=holSourceCount($pdo);$second=$pdo->quote($h[1]['external_pack_id']);$pdo->exec("CREATE TRIGGER hol_abort_second BEFORE INSERT ON queue_v4_clean_jobs FOR EACH ROW BEGIN IF JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.payload.pack_id'))=$second THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='hol_expected_sql_abort'; END IF; END");$caught=null;try{holSchedule($pdo);}catch(Throwable $e){$caught=$e;}holCheck($caught!==null&&str_contains($caught->getMessage(),'hol_expected_sql_abort'),'exception must propagate, not become non-admission');holCheck(holSourceCount($pdo)===$count&&holCursorValue($pdo)===1,'exception must rollback earlier success and cursor');holCheck((int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200')->fetchColumn()===0,'exception left earlier pointer');break;
   default:throw new RuntimeException('unknown test');
  }
  holCheck(holHistory($pdo)===$before,'T9 H3/settings/attempts/transport history changed');$results[$case]='PASS';
 }catch(Throwable $e){$results[$case]='FAIL: '.$e->getMessage();$failed++;}
 finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS hol_nonadmissible');$pdo->exec('DROP TRIGGER IF EXISTS hol_abort_second');}
}
echo json_encode(['cases'=>$results,'failed'=>$failed,'real_mariadb'=>true,'real_producer'=>true,'real_admission'=>true,'HTTP'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
exit($failed===0?0:1);
