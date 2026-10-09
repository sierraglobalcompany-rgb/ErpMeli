<?php
declare(strict_types=1);
$repo=str_replace('\\','/',dirname(__DIR__));
$sandbox=$repo.'/storage/codex-atomic-2411/run-'.bin2hex(random_bytes(5));
mkdir($sandbox,0770,true);
define('ERP_INSTALLATION_ROOT',$sandbox.'/installation');
define('ERP_SHARED_ROOT',ERP_INSTALLATION_ROOT.'/shared');
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\Services\ManagedRuntimePublicationPolicy as Policy;
use App\Services\UpdateReleaseService;
use App\Services\UpdateManifestService;
use App\Services\UpdateFilesystemService;
use App\Services\ReleaseIntegrityService;
use App\Services\Migrator;
use App\Services\UpdateSchemaService;
use App\Services\AppVersionService;
$mode=$argv[1]??'integration';
$package=$argv[2]??'';
function check(bool $ok,string $label):void { k1b_assert($ok,$label); echo "PASS:$label\n"; }
// Exercise the real verifier: one exact dotfile exception, never a source-code assertion.
$dotRoot=$sandbox.'/dotpaths'; mkdir($dotRoot,0770,true);
file_put_contents($dotRoot.'/.htaccess','synthetic apache runtime');
$dotHash=hash_file('sha256',$dotRoot.'/.htaccess');
$manifestVerifier=new UpdateManifestService();
$manifestVerifier->verifyFiles(['files'=>[['path'=>'.htaccess','sha256'=>$dotHash]]],$dotRoot);
check(true,'htaccess_exact_hash_accepted');
foreach(['.env','.git','.git/config','.foo','../escape.php','.htaccess.extra'] as $denied){
 $rejected=false;
 try{$manifestVerifier->verifyFiles(['files'=>[['path'=>$denied,'sha256'=>$dotHash]]],$dotRoot);}catch(RuntimeException $error){$rejected=$error->getMessage()==='El manifiesto contiene una ruta de archivo insegura.';}
 check($rejected,'unsafe_dotpath_rejected:'.$denied);
}
foreach(['wrong_hash','missing'] as $case){
 if($case==='missing')unlink($dotRoot.'/.htaccess');
 $rejected=false;
 try{$manifestVerifier->verifyFiles(['files'=>[['path'=>'.htaccess','sha256'=>$case==='wrong_hash'?str_repeat('0',64):$dotHash]]],$dotRoot);}catch(RuntimeException){$rejected=true;}
 check($rejected,'htaccess_integrity_enforced:'.$case);
}
function materialize(string $root,string $ref,string $target):void {
 $archive=$root.'/storage/codex-atomic-2411/base-raw-'.$ref.'.zip';
 if(!is_file($archive)){
  $process=proc_open(['git','-c','core.autocrlf=false','-C',$root,'archive','--format=zip',$ref],[0=>['pipe','r'],1=>['file',$archive,'wb'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('git_archive_start_failed');
  fclose($pipes[0]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
  if(proc_close($process)!==0)throw new RuntimeException('git_archive_failed:'.$error);
 }
 $zip=new ZipArchive(); k1b_assert($zip->open($archive)===true,'base_archive_opens');
 try{
 $runtime=json_decode((string)$zip->getFromName('resources/runtime-manifest.json'),true,512,JSON_THROW_ON_ERROR);
 $entries=$runtime['components']; $entries[]=['path'=>'resources/runtime-manifest.json'];
 foreach($entries as $entry){
  $p=$entry['path']; @mkdir(dirname($target.'/'.$p),0770,true);
  $bytes=$zip->getFromName($p); k1b_assert(is_string($bytes),'base_archive_path_exists:'.$p);
  if(isset($entry['sha256']))k1b_assert(in_array(hash('sha256',$bytes),[$entry['sha256'],$entry['sha256_lf']??''],true),'base_archive_authorized_hash:'.$p);
  file_put_contents($target.'/'.$p,$bytes);
 }
 }finally{$zip->close();}
}
$service=new UpdateReleaseService();
$legacy=ERP_INSTALLATION_ROOT;
materialize($repo,'a3035e4881eb2c53e35ea9a41ffa9c3b783d0219',$legacy);
$versionProperty=new ReflectionProperty(AppVersionService::class,'fileVersion');
$versionProperty->setValue(null,'2.41.0'); // emulate the installed legacy bootstrap, not candidate code VERSION
@mkdir(ERP_SHARED_ROOT.'/storage',0770,true);
file_put_contents($legacy.'/bin/build-tool-not-runtime.php','<?php // must never enter recovery snapshot');
file_put_contents($legacy.'/config.env','SYNTHETIC_PRIVATE_MARKER=not-a-secret');
file_put_contents($legacy.'/PAUSE_ERP_AUTOMATION','synthetic');
$snapshot=new ReflectionMethod(UpdateReleaseService::class,'snapshotLegacyRelease');
$verify=new ReflectionMethod(UpdateReleaseService::class,'verifyReleaseAgainstManifest');
if($mode==='red-snapshot'){
 try{$previous=$snapshot->invoke($service);}catch(RuntimeException $error){
  foreach(glob($legacy.'/releases/legacy-*')?:[] as $path){
   echo 'SNAPSHOT_ERRORS='.json_encode((new ReleaseIntegrityService())->inspectDirectory($path,false,false)['errors'],JSON_THROW_ON_ERROR)."\n";
  }
  throw $error;
 }
 $path=$legacy.'/'.$previous['path'];
 check(is_file($path.'/cron-status.php'),'snapshot_includes_cron_status');
 check(!file_exists($path.'/bin/build-tool-not-runtime.php'),'snapshot_excludes_build_tools');
 $verify->invoke($service,$path,[]);
 check(true,'snapshot_verifies_for_rollback');
 exit;
}
check(is_file($package),'explicit_QA_package_exists');
$zip=new ZipArchive();
check($zip->open($package,ZipArchive::CHECKCONS)===true,'QA_package_reopens');
$source=$sandbox.'/extracted';
mkdir($source,0770,true);
check($zip->extractTo($source),'QA_package_extracts');
$zip->close();
$manifest=(new UpdateManifestService())->fromDirectory($source);
if($mode==='red-stage'){
 $service->stage($source,$manifest);
 check(true,'complete_2411_stage_accepts_exact_runtime_bin');
 exit;
}
(new UpdateManifestService())->verifyFiles($manifest,$source);
check(true,'actual_manifest_file_hashes_verify');
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1',
 'DB_PORT'=>getenv('DB_PORT')?:'33338','DB_USER'=>'root','DB_PASS'=>getenv('DB_PASS')?:'local-http-receipt-test-only',
 'DB_NAME'=>'erp_meli_k1d_test_atomic_'.bin2hex(random_bytes(5)),
 'CALLS_VERIFY_QA_ROOT'=>$repo.'/storage/codex-atomic-2411'] as $key=>$value){putenv($key.'='.$value);}
$db=K1dSafeTestDatabase::createFromEnvironment();
try{
 $pdo=$db->pdo();
 // Schema303 was applied independently while code stayed2410: seed from canonical SQL RAW bytes.
 (new Migrator($pdo,$source.'/database/migrations'))->run(303);
 $migration='303_outer_cron_http_receipt.sql';
 (new App\Services\AppSettingsService())->set('app.version','2.41.0','test');
 check(trim(file_get_contents($legacy.'/VERSION'))==='2.41.0','before_files_version2410');
 $stmt=$pdo->prepare('SELECT * FROM schema_migrations WHERE version=?');
 $stmt->execute([$migration]); $before=$stmt->fetchAll();
 check(count($before)===1,'schema303_already_recorded_once');
 $checksumStmt=$pdo->prepare('SELECT checksum_sha256 FROM system_update_migrations WHERE migration_key=?');
 $checksumStmt->execute([$migration]); $checksumBefore=$checksumStmt->fetchColumn();
 putenv('APP_KEY=synthetic-local-master-test-key');
 if(in_array($mode,['metadata-red','metadata-fences'],true)){
  $metadata=new App\Services\DirectUpdateMetadataPromotionService();
  $metadata->promote($pdo,'2.41.1',$migration,'local test');
  $metadata->restoreForRollback($pdo,'2.41.1','2.41.0',$migration,'local rollback test');
  check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.0','rollback_metadata_canonical');
  if($mode==='metadata-fences'){
   $metadata->promote($pdo,'2.41.1',$migration,'local test');
   $markerPath=App\Core\AppPaths::storage('installed-release.json');
   $markerBefore=file_get_contents($markerPath);
   $historyBefore=$pdo->query('SELECT * FROM app_versions ORDER BY id')->fetchAll();
   $rejected=false;
   try{$metadata->restoreForRollback($pdo,'2.41.2','2.41.0',$migration);}catch(RuntimeException $e){$rejected=$e->getMessage()==='direct_update_version_cas_miss';}
   check($rejected,'rollback_CAS_mismatch_rejected');
   check(file_get_contents($markerPath)===$markerBefore && $pdo->query('SELECT * FROM app_versions ORDER BY id')->fetchAll()===$historyBefore,'CAS_failure_preserves_marker_and_history');
   putenv('APP_KEY='); $rejected=false;
   try{$metadata->restoreForRollback($pdo,'2.41.1','2.41.0',$migration);}catch(RuntimeException $e){$rejected=$e->getMessage()==='installed_release_marker_write_failed';}
   putenv('APP_KEY=synthetic-local-master-test-key');
   check($rejected && file_get_contents($markerPath)===$markerBefore,'marker_failure_preserves_old_bytes');
   check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.1','marker_failure_rolls_back_DB');
   check($pdo->query('SELECT * FROM app_versions ORDER BY id')->fetchAll()===$historyBefore,'marker_failure_preserves_history');
   check((int)$pdo->query("SELECT IS_FREE_LOCK('erp_meli_direct_update_metadata')")->fetchColumn()===1,'metadata_lock_released_after_failure');
   $metadata->restoreForRollback($pdo,'2.41.1','2.41.0',$migration);
   $marker=(new App\Services\InstalledVersionMarkerService())->read();
   check($marker['valid'] && $marker['version']==='2.41.0' && $marker['last_migration']===$migration,'rollback_marker_preserves_schema303');
  }
  exit;
 }
 if(in_array($mode,['page-red','search-no-fanout'],true)){
  $settings=new App\Services\AppSettingsService();
  foreach(['1'=>1,'50'=>50,'51'=>50,'100'=>50,'invalid'=>50] as $input=>$expected){
   $settings->set('sync.page_limit',(string)$input,'test');
   check((new App\Services\SyncSettingsService())->pageLimit()===$expected,'orders_search_limit_'.$input);
  }
  if($mode==='search-no-fanout'){
   $settings->set('sync.page_limit','100','test');
   $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Synthetic local search',1)");
   $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Synthetic local search','99011','conectado')");
   $pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,watermark_at,next_due_at) VALUES('fresh_orders',9001,9011,'2026-09-01','2026-09-01')");
   require __DIR__.'/calls_true_seed_fixture.php';
   $reader=new class implements App\Services\MeliReadClientInterface {
    public array $calls=[];
    public function get(string $path,array $query=[],array $meta=[]):array {
     $this->calls[]=[$path,$query];
     if($path!=='/orders/search')throw new RuntimeException('UNEXPECTED_SEARCH_FANOUT');
     return ['results'=>array_map('true_seed_order_body',['880001','880002','880003']),'paging'=>['total'=>3,'offset'=>0,'limit'=>$query['limit']]];
    }
   };
   $noNetwork=new class implements App\Services\MeliHttpTransportInterface {
    public function request(string $method,string $url,array $data,array $headers,bool $form,array $timeouts):array {throw new RuntimeException('UNEXPECTED_PHYSICAL_HTTP');}
   };
   $repoQueue=new App\QueueV4Clean\QueueV4CleanRepository($pdo);
   $worker=new App\QueueV4Clean\QueueV4CleanWorker($pdo,$repoQueue,static fn(int $account)=>$reader,
    static fn(int $account)=>new App\Services\OrderSyncService($account,new App\Services\MeliApiClient($account,$noNetwork)));
   $handle=new ReflectionMethod(App\QueueV4Clean\QueueV4CleanWorker::class,'handle');
   $result=$handle->invoke($worker,['id'=>1,'company_id'=>9001,'meli_account_id'=>9011,'job_type'=>'fresh_orders_discovery',
    'payload'=>['from'=>'2026-09-01T00:00:00Z','to'=>'2026-09-01T00:05:00Z','offset'=>0]]);
   check(count($reader->calls)===1 && $reader->calls[0][0]==='/orders/search' && $reader->calls[0][1]['limit']===50,'captured_queue_v4_orders_search_limit50');
   check((int)$pdo->query('SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=9011')->fetchColumn()===3,'three_search_snapshots_persisted_without_fanout');
   check($result['state']==='completed','orders_search_local_step_completed');
  }
  exit;
 }
 if($mode==='version-fences'){
  check($service->healthCheck($source)['ok'],'valid_protected_runtime_health');
  $versionBytes=file_get_contents($source.'/VERSION');
  foreach(['corrupt-version','','2.41','2.0.0'] as $invalid){
   file_put_contents($source.'/VERSION',$invalid);
   check(!$service->healthCheck($source)['ok'],'version_bypass_rejected:'.($invalid===''?'empty':$invalid));
  }
  file_put_contents($source.'/VERSION','corrupt-version');
  $bootstrap=file_get_contents($source.'/bootstrap.php');file_put_contents($source.'/bootstrap.php','tampered');
  check(!$service->healthCheck($source)['ok'],'corrupt_version_and_runtime_rejected');
  file_put_contents($source.'/bootstrap.php',$bootstrap);file_put_contents($source.'/VERSION',$versionBytes);
  $old=$sandbox.'/valid-legacy';mkdir($old.'/public',0770,true);mkdir($old.'/database/migrations',0770,true);
  file_put_contents($old.'/bootstrap.php','<?php');file_put_contents($old.'/public/index.php','<?php');file_put_contents($old.'/VERSION','2.11.5');
  check($service->healthCheck($old)['ok'],'valid_legacy_without_manifest_accepted');
  unlink($old.'/VERSION');check(!$service->healthCheck($old)['ok'],'missing_version_remains_rejected_by_existing_contract');
  exit;
 }
 if(in_array($mode,['outer','outer-pointer-failure','outer-health-pointer-drift','outer-wrong-destination'],true)){
  check($service->healthCheck($source)['ok'],'candidate_health_accepts_old_DB_version');
  $versionBytes=file_get_contents($source.'/VERSION');file_put_contents($source.'/VERSION','corrupt-version');
  check(!$service->healthCheck($source)['ok'],'candidate_health_rejects_corrupt_runtime');
  file_put_contents($source.'/VERSION',$versionBytes);
  $brokenPDO=new class extends PDO {public function __construct(){} public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {throw new RuntimeException('synthetic_unavailable_DB');}};
  App\Core\Database::setConnection($brokenPDO);
  check(!$service->healthCheck($source)['ok'],'candidate_health_rejects_unavailable_DB');
  App\Core\Database::setConnection($pdo);
  (new App\Services\InstalledVersionMarkerService())->write('2.41.0',$migration);
  $keys=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
  check($keys!==false,'ephemeral_QA_key_created');
  $public=openssl_pkey_get_details($keys)['key'];
  $keyId='local-master-'.bin2hex(random_bytes(4));
  $keyStmt=$pdo->prepare("INSERT INTO system_update_trusted_keys(key_id,public_key_pem,status,channels_json) VALUES(?,?,'active',?)");
  $keyStmt->execute([$keyId,$public,json_encode([$manifest['channel']])]);
  unset($manifest['signature'],$manifest['signature_status']);
  $manifest['signing_key_id']=$keyId;
  $canonical=new ReflectionMethod(UpdateManifestService::class,'canonicalJson');
  openssl_sign($canonical->invoke(new UpdateManifestService(),$manifest),$signature,$keys,OPENSSL_ALGO_SHA256);
  $manifest['signature']=base64_encode($signature);
  (new UpdateFilesystemService())->ensureDirectories();
  $outerPackage=App\Core\AppPaths::updateInbox().'/local-master.erpupd';
  copy($package,$outerPackage);
  $signedZip=new ZipArchive(); check($signedZip->open($outerPackage)===true,'outer_package_opens');
  $signedZip->addFromString('update-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)); $signedZip->close();
  $engine=new App\Services\SecureUpdateEngineService();
  $run=$engine->createRun(hash('sha256',realpath($outerPackage)),'atomic',false,'ACTUALIZAR SIN RESPALDO',null);
  echo 'OUTER_RUN_CREATED='.$run['state']."\n";
  for($step=0;$step<20 && !in_array($run['state'],['completed','failed','manual_intervention','rollback_pending'],true);$step++){
   if($mode==='outer-health-pointer-drift' && $run['state']==='health_check'){
    $pointerPath=ERP_INSTALLATION_ROOT.'/shared/current-release.json';
    $originalPointer=file_get_contents($pointerPath);
    $pointer=json_decode($originalPointer,true,512,JSON_THROW_ON_ERROR);
    foreach(['release_id'=>'other-valid-id','version'=>'2.41.0','path'=>'releases/'.$run['previous_release_id']] as $dimension=>$wrong){
     $altered=$pointer;$altered[$dimension]=$wrong;
     file_put_contents($pointerPath,json_encode($altered,JSON_THROW_ON_ERROR));
     $run=$engine->process((int)$run['id']);
     check($run['state']==='rollback_pending','health_pointer_drift_rejected:'.$dimension);
     check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.0','health_pointer_drift_never_promotes_metadata:'.$dimension);
     check(is_file(ERP_INSTALLATION_ROOT.'/shared/maintenance.json'),'health_pointer_drift_keeps_maintenance:'.$dimension);
     $pdo->prepare("UPDATE system_update_runs SET state='health_check' WHERE id=?")->execute([(int)$run['id']]);
    }
    file_put_contents($pointerPath,$originalPointer);
    $run=$engine->run((int)$run['id']);
   }
   $run=$engine->process((int)$run['id']); echo 'OUTER_STATE='.$run['state']."\n";
  }
  check($run['state']==='completed','outer_engine_completed');
  check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.1','outer_canonical_metadata_promoted');
  check((new ReleaseIntegrityService())->inspectDirectory($run['staging_path'],true,false)['ok'],'outer_final_integrity_with_DB');
  $marker=(new App\Services\InstalledVersionMarkerService())->read();
  check($marker['valid'] && $marker['version']==='2.41.1' && $marker['last_migration']===$migration,'outer_marker_version_and_migration303');
  check((int)$pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.41.1'")->fetchColumn()===1,'outer_target_history_exactly_once');
  $migrationEvents=$pdo->prepare("SELECT context_json FROM system_update_events WHERE run_id=? AND event_code='migrations_completed'");
  $migrationEvents->execute([(int)$run['id']]);$migrationEvidence=json_decode((string)$migrationEvents->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
  check(($migrationEvidence['applied_or_adopted']??null)===0,'outer_new_migrations_zero');
  check((new Migrator($pdo,$run['staging_path'].'/database/migrations'))->pendingCount()===0,'outer_target_pending_zero');
  if($mode==='outer-wrong-destination'){
   $pointerPath=ERP_INSTALLATION_ROOT.'/shared/current-release.json';$original=file_get_contents($pointerPath);
   $pointer=json_decode($original,true,512,JSON_THROW_ON_ERROR);
   $previousPath=ERP_INSTALLATION_ROOT.'/releases/'.$pointer['previous_release_id'];
   $alternateId='valid-but-wrong-previous';$alternatePath=ERP_INSTALLATION_ROOT.'/releases/'.$alternateId;
   check(rename($previousPath,$alternatePath),'alternate_valid_release_fixture_created');
   check((new ReleaseIntegrityService())->inspectDirectory($alternatePath,false,false)['ok'],'alternate_destination_is_valid_runtime');
   $pointer['previous_release_id']=$alternateId;file_put_contents($pointerPath,json_encode($pointer,JSON_THROW_ON_ERROR));
   $rejected=false;try{$engine->rollback((int)$run['id']);}catch(RuntimeException $e){$rejected=$e->getMessage()==='update_rollback_pointer_version_mismatch';}
   check($rejected && $engine->run((int)$run['id'])['state']==='rollback_pending','valid_wrong_rollback_destination_rejected');
   check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.1','wrong_destination_metadata_preserved');
   rename($alternatePath,$previousPath);file_put_contents($pointerPath,$original);
  }
  if($mode==='outer-pointer-failure'){
   $pointerPath=ERP_INSTALLATION_ROOT.'/shared/current-release.json';
   $pointerBytes=file_get_contents($pointerPath);
   $badPointer=json_decode($pointerBytes,true,512,JSON_THROW_ON_ERROR);$badPointer['previous_release_id']='missing-previous-release';
   file_put_contents($pointerPath,json_encode($badPointer,JSON_THROW_ON_ERROR));
   $rejected=false;try{$engine->rollback((int)$run['id']);}catch(RuntimeException){$rejected=true;}
   check($rejected && $engine->run((int)$run['id'])['state']!=='rolled_back','pointer_failure_never_marks_rolled_back');
   check(is_file(ERP_INSTALLATION_ROOT.'/shared/maintenance.json'),'pointer_failure_keeps_maintenance');
   check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.1','pointer_failure_preserves_metadata');
   file_put_contents($pointerPath,$pointerBytes);
   $markerBytes=file_get_contents(App\Core\AppPaths::storage('installed-release.json'));
   (new App\Services\AppSettingsService())->set('app.version','2.41.2','synthetic CAS interference');
   $rejected=false;try{$engine->rollback((int)$run['id']);}catch(RuntimeException $e){$rejected=$e->getMessage()==='direct_update_version_cas_miss';}
   check($rejected && $engine->run((int)$run['id'])['state']==='rollback_pending','engine_CAS_failure_never_closes_rollback');
   check(is_file(ERP_INSTALLATION_ROOT.'/shared/maintenance.json') && file_get_contents(App\Core\AppPaths::storage('installed-release.json'))===$markerBytes,'engine_CAS_failure_preserves_marker_and_maintenance');
   (new App\Services\AppSettingsService())->set('app.version','2.41.1','synthetic restore test state');
   putenv('APP_KEY=');$rejected=false;
   try{$engine->rollback((int)$run['id']);}catch(RuntimeException $e){$rejected=$e->getMessage()==='installed_release_marker_write_failed';}
   putenv('APP_KEY=synthetic-local-master-test-key');
   check($rejected && $engine->run((int)$run['id'])['state']==='rollback_pending','engine_marker_failure_never_closes_rollback');
   check(is_file(ERP_INSTALLATION_ROOT.'/shared/maintenance.json') && $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.1','engine_marker_failure_rolls_back_metadata_keeps_maintenance');
  }
  $rolled=$engine->rollback((int)$run['id']);
  check($rolled['version']==='2.41.0','outer_rollback_file_version');
  check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn()==='2.41.0','outer_rollback_metadata_version');
  $stmt->execute([$migration]);check($stmt->fetchAll()===$before,'outer_migration303_record_unchanged');
  $checksumStmt->execute([$migration]);check($checksumStmt->fetchColumn()===$checksumBefore,'outer_migration303_checksum_unchanged');
  $marker=(new App\Services\InstalledVersionMarkerService())->read();
  check($marker['valid'] && $marker['version']==='2.41.0' && $marker['last_migration']===$migration,'outer_rollback_marker_schema303');
  check((new ReleaseIntegrityService())->inspectDirectory(ERP_INSTALLATION_ROOT.'/'.$rolled['path'],true,false)['ok'],'outer_previous_release_integrity_with_DB');
  echo "ATOMIC_UPDATE=PASS\nROLLBACK_CODE_AND_METADATA=PASS\n";
  exit;
 }
 $checksumStmt=$pdo->prepare('SELECT checksum_sha256 FROM system_update_migrations WHERE migration_key=?');
 $checksumStmt->execute([$migration]); $checksumBefore=$checksumStmt->fetchColumn();
 $diagnosis=(new UpdateSchemaService())->inspect($manifest['schema']??null);
 echo 'DIAGNOSIS='.$diagnosis['classification']."\n";
 check(!in_array($diagnosis['classification'],['known_drifted','unknown_schema','future_version','damaged_installation'],true),'diagnosis_non_blocking');
 $staged=$service->stage($source,$manifest);
 check(is_dir($staged),'atomic_stage_pass');
 foreach(['bin/unapproved.php','bin/subdir/hidden.php'] as $bad){
  @mkdir(dirname($staged.'/'.$bad),0770,true); file_put_contents($staged.'/'.$bad,'<?php');
  $rejected=false;
  try{$service->stage($source,$manifest);}catch(RuntimeException){$rejected=true;}
  unlink($staged.'/'.$bad); if(str_contains($bad,'subdir/'))rmdir(dirname($staged.'/'.$bad));
  check($rejected,'unauthorized_bin_rejected:'.$bad);
 }
 foreach(['tests','docs','tools','storage','vendor','graphify-out','.env','config.env','PAUSE_MELI_API','PAUSE_ERP_AUTOMATION'] as $bad){
  file_put_contents($staged.'/'.$bad,'forbidden');
  $rejected=false;
  try{$service->stage($source,$manifest);}catch(RuntimeException){$rejected=true;}
  unlink($staged.'/'.$bad);
  check($rejected,'forbidden_payload_rejected:'.$bad);
 }
 $migrator=new Migrator($pdo,$staged.'/database/migrations');
 $results=$migrator->run(1);
 echo 'TARGET_MIGRATION_RESULTS='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
 echo 'TARGET_PENDING='.$migrator->pendingCount()."\n";
 check(count(array_filter($results,static fn(array $r):bool=>in_array($r['status'],['applied','adopted'],true)))===0 && $migrator->pendingCount()===0,'new_migrations_zero');
 $pointer=$service->activate($staged,$manifest);
 check($pointer['version']==='2.41.1','atomic_activate_version2411');
 $health=$service->healthCheck($staged);
 echo 'ATOMIC_HEALTH='.json_encode($health,JSON_THROW_ON_ERROR)."\n";
 echo 'ATOMIC_HEALTH_INTEGRITY_ERRORS='.json_encode((new ReleaseIntegrityService())->inspectDirectory($staged,true,false)['errors'],JSON_THROW_ON_ERROR)."\n";
 check($health['ok'],'atomic_health_pass');
 (new App\Services\DirectUpdateMetadataPromotionService())->promote($pdo,'2.41.1',$migration,'local direct integration');
 check((new ReleaseIntegrityService())->inspectDirectory($staged,true,false)['ok'],'canonical_integrity_after_promotion');
 $integrity=(new ReleaseIntegrityService())->inspectDirectory($staged,false,false);
 check($integrity['ok'] && count($integrity['components'])===1256,'release_integrity_1256');
 $snapshotPath=$legacy.'/releases/'.$pointer['previous_release_id'];
 check(trim(file_get_contents($snapshotPath.'/VERSION'))==='2.41.0','snapshot_previous_version2410');
 check(is_file($snapshotPath.'/cron-status.php'),'snapshot_cron_status_present');
 check(!file_exists($snapshotPath.'/bin/build-tool-not-runtime.php') && !file_exists($snapshotPath.'/config.env') && !file_exists($snapshotPath.'/PAUSE_ERP_AUTOMATION'),'snapshot_excludes_build_tools_and_state');
 $rolled=$service->rollback();
 (new App\Services\DirectUpdateMetadataPromotionService())->restoreForRollback($pdo,'2.41.1','2.41.0',$migration,'local direct rollback');
 check($rolled['version']==='2.41.0','rollback_code_previous_version');
 check($service->healthCheck($snapshotPath)['ok'],'rollback_previous_release_bootable_verified');
 $stmt->execute([$migration]);
 check($stmt->fetchAll()===$before,'migration303_record_and_checksum_unchanged');
 $checksumStmt->execute([$migration]);
 check($checksumStmt->fetchColumn()===$checksumBefore,'migration303_checksum_unchanged');
 check($pdo->query('SELECT MAX(CAST(SUBSTRING_INDEX(version, "_", 1) AS UNSIGNED)) FROM schema_migrations')->fetchColumn()==303,'schema_remains303');
 check(!file_exists($legacy.'/cron-created') && !file_exists(ERP_SHARED_ROOT.'/cron-created'),'cron_not_created');
 echo "ATOMIC_UPDATE_2410_SCHEMA303_TO_2411=PASS\nROLLBACK_CODE_WITH_SCHEMA303=PASS\nREAL_MELI_HTTP=0\nREAL_OAUTH=0\n";
}finally{$db->cleanup();}
