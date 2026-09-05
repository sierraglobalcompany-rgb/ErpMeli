<?php
declare(strict_types=1);
// Local build tool only. Never include this file in the deployment payload.
require __DIR__ . '/k1b_bootstrap.php';
use App\Services\ManagedRuntimePublicationPolicy as Policy;
use App\Services\ReleaseIntegrityService;

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'verify';
$ref = $argv[2] ?? 'HEAD';
$out = $argv[3] ?? '';
$registryPath = 'resources/release/managed-runtime-dependencies-2.40.1.json';
function gitCap(string $root, array $args): string {
    $p = proc_open(array_merge(['git','-C',$root],$args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('git_start');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($p);
    if ($code!==0) throw new RuntimeException('git_exit_'.$code.':'.$err);
    return $stdout;
}
function jsonCap(array $v): string {
    $json=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return preg_replace_callback('/^ +/m',static fn(array $m):string=>str_repeat(' ',intdiv(strlen($m[0]),2)),$json)."\n";
}
function writeCap(string $p,string $bytes): void { if(!is_dir(dirname($p))) mkdir(dirname($p),0777,true); if(file_put_contents($p,$bytes)!==strlen($bytes)) throw new RuntimeException('write_failed'); }
if ($mode==='registry') {
    $paths=Policy::manifestPaths($root,$ref);
    $registry=json_decode(Policy::gitBlob($root,$ref,$registryPath),true,64,JSON_THROW_ON_ERROR);
    sort($paths,SORT_STRING);
    $registry['runtime_manifest_paths_sha256']=hash('sha256',implode("\n",$paths)."\n");
    writeCap($root.'/'.$registryPath,jsonCap($registry));
    echo 'REGISTRY_COMPONENTS='.count($paths)."\n"; exit;
}
if ($mode==='manifest') {
    $manifest=Policy::buildManifest($root,$ref);
    writeCap($root.'/resources/runtime-manifest.json',jsonCap($manifest));
    echo 'MANIFEST_COMPONENTS='.count($manifest['components'])."\n"; exit;
}
if ($out==='' || !preg_match('#^[A-Za-z]:[/\\\\]#',$out)) throw new RuntimeException('absolute_output_required');
$out=str_replace('\\','/',$out);
if (!is_dir($out)) mkdir($out,0777,true);
$base='191c5ee708d154471a442dfb6cca3324f8609b01';
$head=trim(gitCap($root,['rev-parse',$ref]));
$tree=trim(gitCap($root,['rev-parse',$ref.'^{tree}']));
$entries=Policy::packageEntries($root,$head);
$baseEntries=Policy::packageEntries($root,$base);
$manifest=json_decode(Policy::gitBlob($root,$head,'resources/runtime-manifest.json'),true,64,JSON_THROW_ON_ERROR);
$issues=Policy::manifestIssues($root,$manifest,$head);
if($issues!==[]) throw new RuntimeException('manifest_issues:'.jsonCap($issues));
$stage=$out.'/stage';
if(is_dir($stage)) throw new RuntimeException('stage_exists_use_unique_output');
$changes=array_values(array_filter(explode("\n",trim(gitCap($root,['diff','--name-only',$base,$head])))));
$runtime=array_fill_keys(array_column($entries,'path'),true);
$delta=array_values(array_filter($changes,static fn(string $p):bool=>isset($runtime[$p])));
foreach($baseEntries as $e){$path=$e['path'];if(!isset($runtime[$path]))throw new RuntimeException('runtime_delete_not_supported');writeCap($stage.'/'.$path,Policy::gitBlob($root,$base,$path));}
foreach($delta as $path){writeCap($stage.'/'.$path,Policy::gitBlob($root,$head,$path));}
foreach($entries as $e){if(!is_file($stage.'/'.$e['path']) || hash_file('sha256',$stage.'/'.$e['path'])!==$e['sha256'])throw new RuntimeException('base_plus_patch_mismatch:'.$e['path']);}
$inspect=(new ReleaseIntegrityService())->inspectDirectory($stage,false,false);
writeCap($out.'/integrity.json',jsonCap($inspect));
if(empty($inspect['ok'])) throw new RuntimeException('installed_integrity_failed');
if(!$delta) throw new RuntimeException('empty_delta');
$basePaths=array_fill_keys(array_column($baseEntries,'path'),true);
$stamp=gmdate('Ymd-His');$zipPath=$out.'/meli-cap-deploy-'.$stamp.'.zip';
$zip=new ZipArchive(); if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('zip_create');
$hashes=[];$restore=[];$new=[];
foreach($delta as $p){$bytes=Policy::gitBlob($root,$head,$p);$zip->addFromString($p,$bytes);$hashes[$p]=hash('sha256',$bytes);
    if(isset($basePaths[$p])){$baseBytes=Policy::gitBlob($root,$base,$p);writeCap($out.'/rollback/'.$p,$baseBytes);$restore[$p]=hash('sha256',$baseBytes);}else{$new[]=$p;}
}
$zip->close();if($zip->open($zipPath)!==true)throw new RuntimeException('zip_reopen');
if($zip->numFiles!==count($delta))throw new RuntimeException('zip_count');
foreach($hashes as $p=>$hash){if(hash('sha256',$zip->getFromName($p))!==$hash)throw new RuntimeException('zip_hash:'.$p);}
$zip->close();
writeCap($out.'/target.json',jsonCap(['base'=>$base,'head'=>$head,'tree'=>$tree,'files'=>$hashes,'base_files'=>$restore,'new_files'=>$new]));
writeCap($out.'/SHA256SUMS.txt',implode("\n",array_map(static fn($p)=>$hashes[$p].'  '.$p,array_keys($hashes)))."\n");
$control="STATUS=LOCAL_ARTIFACT_INTEGRITY_PASS\nBASE=$base\nHEAD=$head\nTREE=$tree\nRUNTIME_COMPONENTS=".count($manifest['components'])."\nDEPLOY_FILES=".count($delta)."\nDEPLOY_ZIP=$zipPath\nDEPLOY_SHA256=".hash_file('sha256',$zipPath)."\nRAW_GIT_BLOBS=YES\nZIP_REOPEN_HASHES=PASS\nPRODUCTION_CHANGED=NO\nCRON_CHANGED=NO\nMIGRATIONS_RUN=0\n";
writeCap($out.'/CONTROL.txt',$control);echo $control;
