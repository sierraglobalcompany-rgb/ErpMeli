<?php
declare(strict_types=1);

// Paired semantic mutations against RAW committed archives. Never mutate this
// checkout; banners, syntax/setup errors, integrity blocks and timeouts are not kills.
function mutationWrite(string $path,array $value):void {
    if(file_put_contents($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('artifact_write_failed');
}
function mutationProcess(array $command,string $cwd,string $prefix,int $timeout=180):array {
    $p=proc_open($command,[0=>['pipe','r'],1=>['file',$prefix.'.out.log','w'],2=>['file',$prefix.'.err.log','w']],$pipes,$cwd);
    if(!is_resource($p))throw new RuntimeException('process_start_failed');
    fclose($pipes[0]);$start=microtime(true);$timedOut=false;$s=proc_get_status($p);
    mutationWrite($prefix.'.process.json',['command'=>$command,'cwd'=>$cwd,'pid'=>$s['pid'],'state'=>'STARTED']);
    while($s['running']){if(microtime(true)-$start>$timeout){$timedOut=true;proc_terminate($p);break;}usleep(50000);$s=proc_get_status($p);}
    $exit=$s['exitcode'];$closed=proc_close($p);if($exit<0&&$closed>=0)$exit=$closed;
    $r=['command'=>$command,'cwd'=>$cwd,'pid'=>$s['pid'],'exit'=>$exit,'timeout'=>$timedOut,'seconds'=>microtime(true)-$start,'stdout'=>$prefix.'.out.log','stderr'=>$prefix.'.err.log'];
    mutationWrite($prefix.'.process.json',$r);return $r;
}
function mutationRecipes():array {
    return [
        'M1'=>['supported'=>true,'file'=>'app/Services/MeliApiClient.php','kind'=>'real_client_invalid_CSV_with_valid_V4_authority','edits'=>[
            ["private function assertBillingOrderDetailsCardinality(string \$method, string \$path, array \$data): void\n    {","private function assertBillingOrderDetailsCardinality(string \$method, string \$path, array \$data): void\n    {\n        return;"]]],
        'M2'=>['supported'=>true,'file'=>'app/QueueV4Clean/QueueV4CleanWorker.php','kind'=>'real_manual_seed39_local_completion','edits'=>[
            ['$outcome = $this->handle($job);', <<<'PHP'
$r1Before = QueueV4CleanCycleBudget::snapshot()['used'];
                    $outcome = $this->handle($job);
                    if (QueueV4CleanCycleBudget::snapshot()['used'] === $r1Before) {
                        QueueV4CleanCycleBudget::reserve(hash('sha1', 'mutant-local:' . $runId . ':' . $job['id']), 'queue_v4_clean');
                    }
PHP]]],
        'M3'=>['supported'=>true,'file'=>'app/QueueV4Clean/QueueV4CleanWorker.php','kind'=>'real_manual_seed86_three_resources_one_wire','edits'=>[
            ['$sync->persistSearchSnapshotForQueueV4Clean($order, $companyId);',"\$sync->persistSearchSnapshotForQueueV4Clean(\$order, \$companyId);\n                QueueV4CleanCycleBudget::reserve(hash('sha1', 'mutant-resource:' . \$job['id'] . ':' . \$id), 'queue_v4_clean');"]]],
        'M4'=>['supported'=>true,'file'=>'app/QueueV4Clean/QueueV4CleanCycleBudget.php','kind'=>'real_V4_claim_direct_transport_not_whole_launcher','edits'=>[
            ['if (self::$used >= self::$limit) {','if (self::$used > self::$limit) {']]],
        'M5'=>['supported'=>true,'file'=>'app/QueueV4Clean/QueueV4CleanCycleBudget.php','kind'=>'real_known429_then_physical_boundary_fuse_NOT_automatic_worker_continuation','edits'=>[
            ["        if (self::\$stoppedReason !== null) { throw new RuntimeException('physical_budget_stopped:' . self::\$stoppedReason); }",''],
            ['self::$used >= self::$limit || self::$stoppedReason !== null','self::$used >= self::$limit']]],
        'M6'=>['supported'=>true,'file'=>'app/Services/AutomationCliCapacityArgumentParser.php','kind'=>'real_parser_no_DB','edits'=>[
            ["throw new InvalidArgumentException('legacy_capacity_argument_removed');","return ['max_calls' => \$this->parseOne(\$options['max-jobs'])];"]]],
        'M7'=>['supported'=>true,'file'=>'app/Services/CapacityPolicyService.php','kind'=>'real_schema301_policy_crosswrites','edits'=>[
            ['$after = $this->read($pdo, $module, true);', <<<'PHP'
$otherModule = $module === 'automation' ? 'manual' : 'automation';
            foreach (array_combine(self::KEYS[$otherModule], [$current, $ceiling]) as $key => $value) {
                $stmt->execute([$key, (string) $value, $otherModule]);
            }
            $after = $this->read($pdo, $module, true);
PHP]]],
        'M8'=>['supported'=>true,'file'=>'app/QueueCore/QueueExecutionLeaseService.php','kind'=>'two_actual_launcher_processes_distinct_accounts_both_directions_live_holder_at_wire','edits'=>[
            ["if (is_array(\$row) && \$row['owner_token'] !== null && strtotime((string) \$row['expires_at'].' UTC') > time()) {","if (false && is_array(\$row) && \$row['owner_token'] !== null && strtotime((string) \$row['expires_at'].' UTC') > time()) {"]]],
        'M9'=>['supported'=>true,'file'=>'app/Services/MeliApiClient.php','kind'=>'real_client_known200_targeted_PDO_statement_fault','edits'=>[
            ["\$rhythm->finalizeKnownResult(\$rhythmPermit, \$status, \$retryAfter);\n                try {\n                    \\App\\QueueCore\\QueueCoreDispatchFence::responseKnown(\$status);", "if (\$status !== 200) { \$rhythm->finalizeKnownResult(\$rhythmPermit, \$status, \$retryAfter); }\n                try {\n                    \\App\\QueueCore\\QueueCoreDispatchFence::responseKnown(\$status);"]]],
        'M10'=>['supported'=>true,'file'=>'app/QueueV4Clean/QueueV4CleanWorker.php','kind'=>'real_SQL_persisted_receipt_NOT_proof_of_actual_send','edits'=>[
            ["'physical_http_calls' => \$unresolved > 0 ? null : \$known,","'physical_http_calls' => \$known + \$unresolved,"],
            ["'physical_http_calls_certainty' => \$unresolved > 0 ? 'UNKNOWN' : 'CERTIFIED',","'physical_http_calls_certainty' => 'CERTIFIED',"]]],
    ];
}
function mutationMaterialize(string $archive,string $dest):void {
    if(file_exists($dest))throw new RuntimeException('existing_experiment_directory');
    $z=new ZipArchive();if($z->open($archive)!==true)throw new RuntimeException('archive_open_failed');
    for($i=0;$i<$z->numFiles;$i++){$n=str_replace('\\','/',$z->getNameIndex($i));if(str_starts_with($n,'/')||preg_match('/^[A-Za-z]:/',$n)||in_array('..',explode('/',$n),true))throw new RuntimeException('unsafe_archive_member');}
    if(!mkdir($dest,0770,true)||!$z->extractTo($dest))throw new RuntimeException('archive_extract_failed');$z->close();
}

if(PHP_SAPI!=='cli')throw new RuntimeException('CLI_ONLY');
$o=getopt('',['candidate:','root:','template:','mutants:','allow-owned-mysql']);$candidate=(string)($o['candidate']??'');
if(!preg_match('/^[a-f0-9]{40}$/D',$candidate))throw new RuntimeException('explicit_full_candidate_commit_required');
$root=rtrim(str_replace('\\','/',(string)($o['root']??'')),'/');$repo=str_replace('\\','/',dirname(__DIR__));
if(!str_starts_with($root,'D:/Codex/')||in_array('..',explode('/',$root),true)||str_starts_with(strtolower($root.'/'),strtolower($repo.'/')))throw new RuntimeException('explicit_external_owned_root_required');
$dir=$root.'/mutations-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));if(!mkdir($dir,0770,true))throw new RuntimeException('root_create_failed');
$resolvedDir=strtolower(str_replace('\\','/',(string)realpath($dir)));
$resolvedRepo=strtolower(str_replace('\\','/',(string)realpath($repo)));
if($resolvedDir===''||$resolvedRepo===''||str_starts_with($resolvedDir.'/',$resolvedRepo.'/'))throw new RuntimeException('resolved_experiment_directory_inside_repository');
$sealedTemplate=null;$sealedTemplateHash=null;
if(isset($o['template'])){
    $originalTemplate=realpath((string)$o['template']);
    if($originalTemplate===false||!str_starts_with(str_replace('\\','/',$originalTemplate),'D:/Codex/'))throw new RuntimeException('private_template_path_invalid');
    $templateBytes=file_get_contents($originalTemplate);if(!is_string($templateBytes))throw new RuntimeException('private_template_read_failed');
    // One immutable external copy per run, never placed inside either archive.
    if(!mkdir($dir.'/private',0700))throw new RuntimeException('private_template_directory_failed');
    $sealedTemplate=$dir.'/private/schema301.json';$sealedTemplateHash=hash('sha256',$templateBytes);
    if(file_put_contents($sealedTemplate,$templateBytes,LOCK_EX)===false||hash_file('sha256',$sealedTemplate)!==$sealedTemplateHash)throw new RuntimeException('private_template_seal_failed');
    chmod($sealedTemplate,0400);unset($templateBytes);
}
$git=static fn(array $args,string $tag):array=>mutationProcess(array_merge(['git','-c','safe.directory='.$repo,'-C',$repo],$args),$repo,$dir.'/'.$tag);
$resolved=$git(['rev-parse',$candidate.'^{commit}'],'candidate');if($resolved['exit']!==0||trim((string)file_get_contents($resolved['stdout']))!==$candidate)throw new RuntimeException('candidate_not_resolved');
$tree=$git(['rev-parse',$candidate.'^{tree}'],'tree');if($tree['exit']!==0)throw new RuntimeException('candidate_tree_missing');$inputHashes=[];
foreach(['tests/calls_final_mutation_matrix.php','tests/calls_true_mutation_case.php','tests/calls_true_seed_fixture.php','tests/calls_true_wire_fixture.php','tests/K1dSafeTestDatabase.php','tests/k1b_bootstrap.php']as $i=>$path){
    $blob=$git(['show',$candidate.':'.$path],'input-'.$i);
    if($blob['exit']!==0||hash_file('sha256',$blob['stdout'])!==hash_file('sha256',$repo.'/'.$path))throw new RuntimeException('candidate_oracle_input_mismatch:'.$path);
    $inputHashes[$path]=hash_file('sha256',$blob['stdout']);
}
$archive=$dir.'/raw.zip';$archived=$git(['archive','--format=zip','--output='.$archive,$candidate],'archive');if($archived['exit']!==0)throw new RuntimeException('archive_failed');
$recipes=mutationRecipes();$selected=($o['mutants']??'all')==='all'?array_keys($recipes):explode(',',(string)$o['mutants']);
if($selected===[]||array_diff($selected,array_keys($recipes))!==[]||count(array_unique($selected))!==count($selected))throw new RuntimeException('invalid_mutant_selection');
$report=['candidate'=>$candidate,'tree'=>trim((string)file_get_contents($tree['stdout'])),'raw_archive_sha256'=>hash_file('sha256',$archive),'oracle_input_hashes'=>$inputHashes,
    'sealed_template_sha256'=>$sealedTemplateHash,'private_template_packaged'=>false,'required'=>10,'kills'=>0,'full_gate_complete'=>false,'state'=>'INCOMPLETE','cases'=>[]];
foreach($selected as $id){
    $r=$recipes[$id];$row=['recipe'=>$r,'state'=>'INCOMPLETE'];$caseDir=$dir.'/'.$id;mkdir($caseDir,0770,true);
    if(!$r['supported']){$row['reason']=$r['reason'];$report['cases'][$id]=$row;continue;}
    if($id!=='M6'&&!isset($o['allow-owned-mysql'])){$row['reason']='Owned local MySQL execution not explicitly enabled.';$report['cases'][$id]=$row;continue;}
    try{
        foreach(['baseline','mutant']as $v){
            mutationMaterialize($archive,$caseDir.'/'.$v);
            foreach($inputHashes as $path=>$hash)if(hash_file('sha256',$caseDir.'/'.$v.'/'.$path)!==$hash)throw new RuntimeException('archive_oracle_not_raw_git_blob');
        }
        $before=(string)file_get_contents($caseDir.'/baseline/'.$r['file']);$after=$before;
        $targetBlob=$git(['show',$candidate.':'.$r['file']],'target-'.$id);
        if($targetBlob['exit']!==0||hash_file('sha256',$targetBlob['stdout'])!==hash('sha256',$before))throw new RuntimeException('archive_target_not_raw_git_blob');
        foreach($r['edits']as[$from,$to]){
            // Preserve RAW source line endings; recipe text uses canonical LF.
            if(str_contains($after,"\r\n")){$from=str_replace("\n","\r\n",$from);$to=str_replace("\n","\r\n",$to);}
            if(substr_count($after,$from)!==1)throw new RuntimeException('semantic_anchor_not_unique');$after=str_replace($from,$to,$after);
        }
        if($after===$before)throw new RuntimeException('empty_mutation');
        $mutant=$caseDir.'/mutant/'.$r['file'];if(file_put_contents($mutant,$after,LOCK_EX)===false)throw new RuntimeException('isolated_write_failed');
        $row['baseline_sha256']=hash('sha256',$before);$row['mutant_sha256']=hash('sha256',$after);
        $diff=mutationProcess(['git','diff','--no-index','--',$caseDir.'/baseline/'.$r['file'],$mutant],$dir,$caseDir.'/semantic-diff');
        if($diff['exit']!==1||$diff['timeout'])throw new RuntimeException('semantic_diff_failed');
        $row['diff']=$diff['stdout'];$row['diff_sha256']=hash_file('sha256',$diff['stdout']);
        $row['lint']=mutationProcess([PHP_BINARY,'-l',$mutant],$dir,$caseDir.'/lint');
        if($row['lint']['exit']!==0||$row['lint']['timeout'])$row['state']='INVALID_SYNTAX_NOT_KILL';
        else{
            foreach(['baseline','mutant']as $v){
                $artifact=$caseDir.'/'.$v.'-result.json';$command=[PHP_BINARY,$caseDir.'/'.$v.'/tests/calls_true_mutation_case.php','--case='.$id,'--artifact='.$artifact,'--root='.$caseDir.'/'.$v.'-artifacts'];
                if($sealedTemplate!==null){
                    if(hash_file('sha256',$sealedTemplate)!==$sealedTemplateHash)throw new RuntimeException('sealed_template_changed');
                    $command[]='--template='.$sealedTemplate;$command[]='--template-sha256='.$sealedTemplateHash;
                }
                $row[$v.'_process']=mutationProcess($command,$caseDir.'/'.$v,$caseDir.'/'.$v);
                $row[$v]=is_file($artifact)?json_decode((string)file_get_contents($artifact),true,512,JSON_THROW_ON_ERROR):null;
                if($v==='baseline'&&($row[$v.'_process']['exit']!==0||($row[$v]['state']??'')!=='PASS'))break;
            }
            $wireClean=($row['baseline']['wire_violations']??null)===[]&&($row['baseline']['all_wire_violations']??null)===[]
                &&($row['mutant']['wire_violations']??null)===[]&&($row['mutant']['all_wire_violations']??null)===[];
            $templateMatches=$sealedTemplateHash===null||$id==='M6'||(
                ($row['baseline']['template_sha256']??null)===$sealedTemplateHash&&($row['mutant']['template_sha256']??null)===$sealedTemplateHash
                &&hash_file('sha256',$sealedTemplate)===$sealedTemplateHash);
            $row['paired_wire_clean']=$wireClean;$row['paired_template_hash_matches_seal']=$templateMatches;
            if(($row['baseline']['state']??'')!=='PASS'||$row['baseline_process']['exit']!==0||$row['baseline_process']['timeout'])$row['state']='BASELINE_NOT_GREEN';
            elseif($row['mutant_process']['timeout']??true)$row['state']='TIMEOUT_NOT_KILL';
            elseif(!$wireClean)$row['state']='WIRE_FIXTURE_FAILURE_NOT_KILL';
            elseif(!$templateMatches)$row['state']='TEMPLATE_INPUT_MISMATCH_NOT_KILL';
            elseif(($row['mutant']['state']??'')==='PASS'&&$row['mutant_process']['exit']===0)$row['state']='SURVIVED';
            elseif(($row['mutant']['state']??'')==='ORACLE_VIOLATION'&&($row['mutant']['reached']??false)===true&&($row['mutant']['witness']??false)===true
                &&$row['mutant_process']['exit']===42&&($row['mutant']['oracle_hash']??null)===($row['baseline']['oracle_hash']??'')&&($row['mutant']['cleanup']??false)===true)$row['state']='KILLED';
            else $row['state']='SETUP_OR_OTHER_GUARD_NOT_KILL';
        }
    }catch(Throwable $e){$row['state']='SETUP_FAILURE_NOT_KILL';$row['error_type']=get_class($e);$row['reason']=in_array($e->getMessage(),['semantic_anchor_not_unique','empty_mutation','semantic_diff_failed'],true)?$e->getMessage():'experiment_infrastructure_failed';}
    if($row['state']==='KILLED')$report['kills']++;
    $report['cases'][$id]=$row;mutationWrite($caseDir.'/experiment.json',$row);mutationWrite($dir.'/matrix.json',$report);
}
if(count($report['cases'])===10&&$report['kills']===10){$report['state']='PASS';$report['full_gate_complete']=true;}
elseif(count($report['cases'])<10&&$report['kills']===count($selected))$report['state']='SUBSET_PASS';
mutationWrite($dir.'/matrix.json',$report);echo 'MUTATION_RESULT='.$dir.'/matrix.json'.PHP_EOL.'MUTATIONS_DETECTED='.$report['kills'].PHP_EOL.'STATUS='.$report['state'].PHP_EOL;
exit(in_array($report['state'],['PASS','SUBSET_PASS'],true)?0:2);
