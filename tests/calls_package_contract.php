<?php
declare(strict_types=1);
$callsQaRoot = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906')), '/');
if (!(str_starts_with($callsQaRoot, 'D:/Codex/') || str_starts_with($callsQaRoot, 'C:/codex/capacity-save-kiss/')) || in_array('..', explode('/', $callsQaRoot), true)) throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
putenv('CAP2_PACKAGE_TMP=' . $callsQaRoot . '/package-tests');
require __DIR__.'/cap2_package_artifact.php';
// Reuse the RAW builder and its complete safety regression; no parallel builder.
$calls=cap2BuildRawArtifact($repo,$base,$head,$case.'/calls',
    ['app/a.php','app/unchanged.php'],['app/a.php','app/unchanged.php','jobs/new.php'],'20260906','calls');
cap2PkgAssert(basename($calls['deploy_zip'])==='meli-calls-deploy-20260906.zip','calls_short_name');
cap2PkgAssert($calls['deploy_count']===2,'calls_runtime_delta');
cap2VerifyZip($calls['deploy_zip'],$calls['deploy_hashes']);
$rejected=false;
try { cap2BuildRawArtifact($repo,$base,$head,$case.'/bad',[],[],'20260906','../unsafe'); }
catch(RuntimeException $error) { $rejected=$error->getMessage()==='invalid_package_family'; }
cap2PkgAssert($rejected,'unsafe_package_family_rejected');
echo "CALLS_PACKAGE_CONTRACT_OK\n";
