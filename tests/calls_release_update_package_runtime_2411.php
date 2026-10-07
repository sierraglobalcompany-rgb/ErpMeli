<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\Services\ManagedRuntimePublicationPolicy;
$root=dirname(__DIR__);
$path=$argv[1]??'';
k1b_assert(is_file($path),'explicit_local_QA_package_required');
$zip=new ZipArchive();
k1b_assert($zip->open($path,ZipArchive::CHECKCONS)===true,'QA_zip_reopens');
try{
 $manifest=json_decode((string)$zip->getFromName('update-manifest.json'),true,512,JSON_THROW_ON_ERROR);
 k1b_assert(($manifest['version']??'')==='2.41.1','current_release_version');
 $expected=array_column(ManagedRuntimePublicationPolicy::packageEntries($root,'HEAD'),null,'path');
 $actual=[];
 foreach($manifest['files']??[] as $f){
  $p=(string)($f['path']??'');
  k1b_assert(isset($expected[$p])&&!isset($actual[$p]),'unexpected_or_duplicate_package_path:'.$p);
  $bytes=$zip->getFromName($p);
  k1b_assert(is_string($bytes)&&hash('sha256',$bytes)===$f['sha256']
    && $f['sha256']===$expected[$p]['sha256'],'QA_raw_git_bytes:'.$p);
  $actual[$p]=true;
 }
 $missing=array_keys(array_diff_key($expected,$actual));
 echo 'MISSING_RUNTIME_PATHS='.json_encode($missing,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
 k1b_assert($missing===[],'QA_package_full_managed_runtime_required');
 k1b_assert($zip->numFiles===count($expected)+1,'QA_no_unlisted_zip_entries');
 $installed=$root.'/storage/codex-release-2411/package-installed-'.bin2hex(random_bytes(5));
 k1b_assert(mkdir($installed,0777,true)&&$zip->extractTo($installed),'QA_extract_complete');
 $inspection=(new App\Services\ReleaseIntegrityService())->inspectDirectory($installed,false,false);
 echo 'QA_INSTALLED_ERRORS='.json_encode($inspection['errors']??[],JSON_THROW_ON_ERROR).PHP_EOL;
 k1b_assert(!empty($inspection['ok']),'QA_installed_ReleaseIntegrity_required');
 k1b_assert(count($inspection['components']??[])===count($expected)-1,'QA_installed_component_count');
 echo 'QA_INSTALLED_RELEASE_INTEGRITY=PASS'.PHP_EOL;
 echo 'PACKAGE_RUNTIME_COVERAGE='.count($actual).'/'.count($expected)." PASS\n";
 echo "REAL_MELI_HTTP=0 REAL_OAUTH=0 PRODUCTION_CHANGED=NO\n";
}finally{$zip->close();}
