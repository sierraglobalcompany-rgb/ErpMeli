<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';

// Behavior gate: removing the migration-only terminal-LF tolerance must fail.
// Historical expectations are frozen from meli-h8-evidence-20260905-053647.zip.
const H8_BASE='e42aa65f8ce05102a12fcac30848f70da9e1d8e2';
function h8Fixtures(): array {
    return [
        ['220_cron_doctor_blocker_map_2_28_40.sql','7e3215d514924c81990541fbeded37815f91016b7befa62844cf9ab11656fde2','20e8703dbaf72ff281df85214d71cd1a397c0fff1abecb97b4095272b60a6c84'],
        ['221_cron_readonly_fast_shell_2_28_41.sql','cfdcdc98a64a2c22e89420bf7428ad85f71e002d6f6ad317835e206345f7cff9','909e9daa3e08621df1ea92637a9635bb90a6470682b246db018772f19d205a94'],
        ['222_http_rhythm_rolling_window_2_28_42.sql','a0170626c388e502b6d1b4b658abfb1cd805c0a301a76c41d42e1732e297f10c','990d6ddaf5ddeb0a3776ffb23098c1a8f5a289733661e218caf90320ff59fbfb'],
        ['223_incremental_drainable_scheduler_2_28_43.sql','04e6c0d3c38e6abdcd5cf8e6b10d60bfe90d03e212b71ea4d4ea543fbe797124','354a3cdc9167246b02cdd9278efba77bc37f4d8d80ad2a0f921c7d02a0184410'],
        ['224_campaign_executable_projection_2_28_44.sql','3df1692a87421c81a42f60b9699ef80fac1feac396969bc1d16c063da494815e','77674a1ac92eae5f047e0803ff14d580e5c7f2a4727cece09aad72815df99b95'],
        ['225_queue_batch_contracts_2_28_45.sql','47237ae8425f4fa4290ba62d4a3c07a80ae699d700f00e1c9b9fef9a916b7ef1','3bc6b84c2eb0b9fc8d3676635621276d664bceccdefbf9e27827d117b1563b7a'],
        ['226_actionable_cron_history_2_28_46.sql','adf55e737ed763a689f119dfb8e6f157a6449a06c014b039d6b6c5ec1da2adbb','3e55c1b39b02647cdb5987ec8bcc4976c4cbe2de079e5f5ca36c02ed44023553'],
        ['227_cron_retention_rollups_2_28_47.sql','1e58596ef40e28ca1f20ccb36d41c181dd8cac2e5d037d4e47534430da33bead','41854b4698d6977188f984f21622fbb4c9b7e6d93e8ddeaca395250196827a70'],
    ];
}
function h8Blob(string $path): string {
    $repo=getenv('H8_GIT_ROOT')?:dirname(__DIR__);
    $p=proc_open(['git','-C',$repo,'cat-file','blob',H8_BASE.':'.$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    k1b_assert(is_resource($p),'GIT_STARTED');fclose($pipes[0]);
    $bytes=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    k1b_assert(proc_close($p)===0,'GIT_BLOB_READ:'.$path.':'.$error);
    return $bytes;
}
function h8Put(string $root,string $path,string $bytes):void {
    if(!is_dir(dirname($root.'/'.$path)))mkdir(dirname($root.'/'.$path),0770,true);
    file_put_contents($root.'/'.$path,$bytes);
}
function h8Remove(string $root):void {
    $real=realpath($root);
    k1b_assert(is_string($real)&&str_starts_with(basename($real),'meli-h8-test-'),'TEMP_ROOT_GUARD');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($real);
}
function h8Component(string $root,string $path,string $expected,string $actual):array {
    h8Put($root,$path,$actual);
    $manifest=['version'=>'2.40.1','build_id'=>'fixture','minimum_migration'=>'301_probe.sql','components'=>[
        'cron_probe'=>['path'=>$path,'sha256'=>hash('sha256',$expected),'sha256_lf'=>hash('sha256',str_replace(["\r\n","\r"],"\n",$expected)),'text'=>true]]];
    h8Put($root,'resources/runtime-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
    $inspection=(new App\Services\ReleaseIntegrityService())->inspectDirectory($root,false,false);
    return $inspection['components']['cron_probe'];
}
function h8Unit():int {
    $root=sys_get_temp_dir().'/meli-h8-test-'.bin2hex(random_bytes(6));mkdir($root);
    h8Put($root,'VERSION',"2.40.1\n");h8Put($root,'database/migrations/301_probe.sql',"SELECT 1;\n");
    $mismatches=0;$modeFailures=0;$proof=0;$falseAccepts=0;
    try {
        foreach(h8Fixtures() as [$name,$targetHash,$remoteHash]){
            $path='database/migrations/'.$name;$bytes=h8Blob($path);
            k1b_assert(hash('sha256',$bytes)===$targetHash,'TARGET_SHA:'.$name);
            k1b_assert(hash('sha256',$bytes."\n")===$remoteHash,'REMOTE_PLUS_LF_SHA:'.$name);$proof++;
            $component=h8Component($root,$path,$bytes,$bytes."\n");
            if(!$component['matches'])$mismatches++;
            if($component['matches']&&$component['match_mode']!=='migration_text_terminal_lf')$modeFailures++;
        }
        $sql="SELECT 1;\n";$path='database/migrations/900_probe.sql';
        $negative=[[$path,$sql,"SELECT 2;\n"],[$path,$sql,"SELECT 1;\n-- new comment\n"],[$path,$sql,"SELECT  1;\n"],
            [$path,$sql,"SELECT 1;\n\n\n"],['app/probe.php',"<?php echo 1;\n","<?php echo 1;\n\n"],
            ['public/app.css',"a { color:red; }\n","a { color:red; }\n\n"],['resources/probe.json',"{}\n","{}\n\n"]];
        foreach($negative as [$p,$expected,$actual])if(h8Component($root,$p,$expected,$actual)['matches'])$falseAccepts++;
        $extraFailures=0;
        foreach([["SELECT 1;\n\n","SELECT 1;\n",true],["SELECT 1;\n","SELECT 1;\r\n\r\n",true],
            ["SELECT 1;\n","SELECT 1;\r",true],["SELECT 1;","SELECT 1;\n",false],
            ["SELECT 1;\n\n\n","SELECT 1;\n\n",false],["SELECT 1;\n","\xEF\xBB\xBFSELECT 1;\n\n",false]] as [$e,$a,$want]){
            if(h8Component($root,$path,$e,$a)['matches']!==$want)$extraFailures++;
        }
        $migrator=(new ReflectionClass(App\Services\Migrator::class))->newInstanceWithoutConstructor();
        $method=new ReflectionMethod($migrator,'isPortableLineEndingChecksum');$portable=0;$portableFalseAccepts=0;
        foreach([["SELECT 1;\n","SELECT 1;\n\n"],["SELECT 1;\n\n","SELECT 1;\n"]] as [$active,$registered]){
            h8Put($root,$path,$active);
            if($method->invoke($migrator,$root.'/'.$path,hash('sha256',$registered)))$portable++;
        }
        foreach($negative as [$p,$active,$registered]){
            h8Put($root,$p,$active);
            if($method->invoke($migrator,$root.'/'.$p,hash('sha256',$registered)))$portableFalseAccepts++;
        }
        echo "REMOTE_EQUALS_TARGET_PLUS_ONE_LF={$proof}/8\nRELEASE_COMPONENT_MISMATCHES={$mismatches}\nMIGRATION_TERMINAL_LF_CASES=".(8-$mismatches)."/8\nNEGATIVE_CONTROLS=7\nNEGATIVE_CONTROL_FALSE_ACCEPTS={$falseAccepts}\nMATCH_MODE_FAILURES={$modeFailures}\nBOUNDARY_FAILURES={$extraFailures}\nMIGRATOR_PORTABLE_DIRECTIONS={$portable}/2\nMIGRATOR_FALSE_ACCEPTS={$portableFalseAccepts}\n";
        return $mismatches+$falseAccepts+$modeFailures+$extraFailures+$portableFalseAccepts===0 && $portable===2?0:20;
    } finally {h8Remove($root);}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(h8Unit());
