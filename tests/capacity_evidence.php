<?php
declare(strict_types=1);
// Explicit evidence allowlist. Never includes npm caches, private configuration or staging tree.
require __DIR__.'/k1b_bootstrap.php';
use App\Services\ManagedRuntimePublicationPolicy as Policy;
$out=str_replace('\\','/',$argv[1]??'');$qa=str_replace('\\','/',$argv[2]??'');
$target=json_decode(file_get_contents($out.'/target.json'),true,64,JSON_THROW_ON_ERROR);
$files=[];
foreach(['CONTROL.txt','README.md','target.json','SHA256SUMS.txt','integrity.json','rollback.zip','verificar.ps1','QA.md','POST.md'] as $name){
    if(!is_file($out.'/'.$name))throw new RuntimeException('missing_evidence:'.$name);
    $files[$name]=file_get_contents($out.'/'.$name);
}
foreach(array_merge(glob($qa.'/*.log')?:[],glob($qa.'/*.png')?:[]) as $p)$files['qa/'.basename($p)]=file_get_contents($p);
foreach(glob(__DIR__.'/../qa/capacity-*')?:[] as $p)$files['review/'.basename($p)]=file_get_contents($p);
foreach(array_merge(glob(__DIR__.'/capacity_*.php')?:[],glob(__DIR__.'/capacity_*.js')?:[]) as $p){
    $rel='tests/'.basename($p);$files[$rel]=Policy::gitBlob(dirname(__DIR__),$target['head'],$rel);
}
$hashes=[];foreach($files as $p=>$bytes)$hashes[$p]=hash('sha256',$bytes);
$files['EVIDENCE_HASHES.json']=json_encode($hashes,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$path=$out.'/meli-cap-qa-'.gmdate('Ymd-His').'.zip';
$zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('qa_zip_create');
foreach($files as $p=>$bytes)$zip->addFromString($p,$bytes);$zip->close();
if($zip->open($path)!==true || $zip->numFiles!==count($files))throw new RuntimeException('qa_zip_reopen_count');
foreach($files as $p=>$bytes)if(!hash_equals(hash('sha256',$bytes),hash('sha256',$zip->getFromName($p))))throw new RuntimeException('qa_zip_hash:'.$p);
$zip->close();
$result='QA_ZIP='.$path."\nQA_SHA256=".hash_file('sha256',$path)."\nQA_ZIP_REOPEN_HASHES=PASS\nQA_ENTRIES=".count($files)."\n";
file_put_contents($out.'/CONTROL.txt',$result,FILE_APPEND);echo $result;
