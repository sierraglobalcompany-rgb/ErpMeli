<?php
declare(strict_types=1);
require __DIR__.'/k1d_h8_terminal_newline.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\Services\Migrator;
use App\Services\ReleaseIntegrityService;

// Only run in a materialized local sandbox, never in a checkout or live root.
$root=realpath(getenv('H8_SANDBOX')?:'');
k1b_assert(is_string($root)&&$root===realpath(dirname(__DIR__))&&is_file($root.'/.h8-local-sandbox'),'LOCAL_SANDBOX_REQUIRED');
define('ERP_RELEASE_ROOT',$root);define('ERP_SHARED_ROOT',$root);
$harness=K1dSafeTestDatabase::createFromEnvironment();
$temp=sys_get_temp_dir().'/meli-h8-test-'.bin2hex(random_bytes(6));mkdir($temp);
try {
    $pdo=$harness->pdo();
    $initDir=$temp.'/baseline/database/migrations';mkdir($initDir,0770,true);
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){
        if((int)basename($file)<=300)copy($file,$initDir.'/'.basename($file));
    }
    (new Migrator($pdo,$initDir))->run();
    $schema=(int)$pdo->query('SELECT MAX(CAST(version AS UNSIGNED)) FROM schema_migrations')->fetchColumn();
    k1b_assert($schema===300,'BASELINE_SCHEMA_300');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES('app.version','2.40.1',0,'system') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $probeDir=$temp.'/probe/database/migrations';mkdir($probeDir,0770,true);
    $name='900_h8_terminal_probe.sql';$path=$probeDir.'/'.$name;
    $sql="INSERT INTO h8_sentinel(marker) VALUES(1);\n";
    $pdo->exec('CREATE TABLE h8_sentinel(marker INT NOT NULL)');
    $stmt=$pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');$stmt->execute([$name]);
    $adoptions=0;
    foreach([[$sql,$sql."\n"],[$sql."\n",$sql]] as [$active,$registered]){
        file_put_contents($path,$active);
        $stmt=$pdo->prepare("INSERT INTO system_update_migrations(migration_key,checksum_sha256,state) VALUES(?,?,'applied') ON DUPLICATE KEY UPDATE checksum_sha256=VALUES(checksum_sha256),state='applied',safe_error_message=NULL");
        $stmt->execute([$name,hash('sha256',$registered)]);
        $results=(new Migrator($pdo,$probeDir))->run(1);
        $stmt=$pdo->prepare('SELECT state,checksum_sha256,safe_error_message FROM system_update_migrations WHERE migration_key=?');$stmt->execute([$name]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        k1b_assert($results===[['status'=>'adopted','version'=>$name]],'TERMINAL_ONLY_ADOPTED_RESULT');
        k1b_assert($row['state']==='adopted'&&$row['checksum_sha256']===hash('sha256',$active),'ADOPTION_PERSISTED');
        k1b_assert(str_contains($row['safe_error_message'],'LF terminal')&&str_contains($row['safe_error_message'],'SQL_EXECUTED=NO'),'SPECIFIC_ADOPTION_MESSAGE');
        $adoptions++;
    }
    $sqlCount=(int)$pdo->query('SELECT COUNT(*) FROM h8_sentinel')->fetchColumn();
    k1b_assert($sqlCount===0,'ADOPTION_NEVER_EXECUTES_SQL_BODY');
    $events=$pdo->query("SELECT stage,status,context_json FROM system_update_migration_events WHERE migration_key='900_h8_terminal_probe.sql' AND stage='checksum_terminal_lf_equivalent'")->fetchAll(PDO::FETCH_ASSOC);
    k1b_assert(count($events)===2,'BOTH_ADOPTIONS_TRACED');
    foreach($events as $event){$context=json_decode($event['context_json'],true);k1b_assert($event['status']==='adopted'&&$context['sql_executed']===false,'ADOPTION_SQL_FALSE');}
    file_put_contents($path,"INSERT INTO h8_sentinel(marker) VALUES(2);\n");
    $blocked=false;try{(new Migrator($pdo,$probeDir))->run(1);}catch(App\Services\MigrationExecutionException){$blocked=true;}
    k1b_assert($blocked,'SQL_MUTATION_BLOCKED');
    $stmt=$pdo->prepare('SELECT state FROM system_update_migrations WHERE migration_key=?');$stmt->execute([$name]);
    k1b_assert($stmt->fetchColumn()==='drifted','SQL_MUTATION_IS_DRIFT');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM h8_sentinel')->fetchColumn()===0,'DRIFT_SQL_NOT_EXECUTED');
    foreach(['schema_migrations'=>'version','system_update_migrations'=>'migration_key'] as $table=>$column){$stmt=$pdo->prepare('DELETE FROM '.$table.' WHERE '.$column.'=?');$stmt->execute([$name]);}
    $pdo->exec('DROP TABLE h8_sentinel');
    echo "PORTABLE_TERMINAL_LF_ADOPTIONS={$adoptions}/2\nSQL_EXECUTIONS_DURING_ADOPTION={$sqlCount}\nSQL_MUTATION_DRIFT_BLOCKED=YES\n";
    foreach(h8Fixtures() as [$name,$targetHash,$remoteHash]){
        $path='database/migrations/'.$name;$target=h8Blob($path);k1b_assert(hash('sha256',$target)===$targetHash,'EXACT_BASE_BYTES');
        h8Put($root,$path,$target."\n");k1b_assert(hash_file('sha256',$root.'/'.$path)===$remoteHash,'PRODUCTION_SHAPED_FILE');
        $stmt=$pdo->prepare("UPDATE system_update_migrations SET checksum_sha256=?,state='applied',safe_error_message=NULL WHERE migration_key=?");$stmt->execute([$remoteHash,$name]);
        k1b_assert($stmt->rowCount()===1,'EXISTING_APPLIED_HISTORY');
    }
    $pre=(new ReleaseIntegrityService())->inspectDirectory($root,false,false);
    $matched=count(array_filter($pre['components'],static fn($c)=>$c['matches']));
    k1b_assert($pre['ok']&&$matched===1240,'PRE301_FULL_RUNTIME_MATCH');
    $lastEvent=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM system_update_migration_events')->fetchColumn();
    $results=(new Migrator($pdo,$root.'/database/migrations'))->run(1);
    $applied=array_values(array_filter($results,static fn($r)=>$r['status']==='applied'));
    k1b_assert($applied===[['status'=>'applied','version'=>'301_k1d_api_safety_2_40_1.sql']],'ONLY_301_APPLIED');
    $stmt=$pdo->prepare("SELECT migration_key FROM system_update_migration_events WHERE id>? AND stage='sql_execution_started'");$stmt->execute([$lastEvent]);$sqlKeys=$stmt->fetchAll(PDO::FETCH_COLUMN);
    k1b_assert($sqlKeys===['301_k1d_api_safety_2_40_1.sql'],'NO_HISTORICAL_SQL_REEXECUTION');
    $schema=(int)$pdo->query('SELECT MAX(CAST(version AS UNSIGNED)) FROM schema_migrations')->fetchColumn();
    k1b_assert($schema===301,'POST301_SCHEMA');
    $post=(new ReleaseIntegrityService())->inspectDirectory($root,true,false);
    $postMatched=count(array_filter($post['components'],static fn($c)=>$c['matches']));
    k1b_assert($post['ok']&&$postMatched===1240,'POST301_FULL_INTEGRITY');
    foreach(h8Fixtures() as [$name,,$remoteHash]){
        $stmt=$pdo->prepare('SELECT checksum_sha256 FROM system_update_migrations WHERE migration_key=?');$stmt->execute([$name]);
        k1b_assert($stmt->fetchColumn()===$remoteHash,'EXISTING_REMOTE_HISTORY_PRESERVED');
    }
    echo "PRE301_RELEASE_COMPONENT_MATCH={$matched}\nPRE301_HISTORICAL_SQL_EXECUTED=0\nMIGRATION_301_APPLIED_LOCAL=YES\nPOST301_SCHEMA_LOCAL={$schema}\nPOST301_RELEASE_INTEGRITY_OK=YES\nPOST301_RUNTIME_COMPONENT_MATCH={$postMatched}\nPRODUCTION_CHANGED_BY_CODEX=NO\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally {$harness->cleanup();h8Remove($temp);echo "LOCAL_TEST_DB_REMOVED=YES\n";}
