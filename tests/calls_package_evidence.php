<?php
declare(strict_types=1);
require __DIR__.'/capacity_evidence.php';

function callsEvidenceAssert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function callsEvidenceReject(callable $callback,string $expected): void
{
    try { $callback(); } catch (RuntimeException $error) {
        callsEvidenceAssert($error->getMessage()===$expected,'wrong_rejection:'.$error->getMessage());
        return;
    }
    throw new RuntimeException('missing_rejection:'.$expected);
}

$base = 'D:/Codex/tmp/erp-meli/calls-20260906/package-tests/evidence-'.bin2hex(random_bytes(5));
$workspace = $base.'/repository'; $qa = $base.'/curated'; $artifact = $base.'/artifact';
foreach ([$workspace,$qa,$artifact] as $directory) mkdir($directory,0777,true);
cap2Git($workspace,['init','-q']);
cap2Git($workspace,['config','user.name','Calls Evidence Fixture']);
cap2Git($workspace,['config','user.email','calls@example.invalid']);
$tracked = [
    'tests/calls_alpha.php'=>"<?php // committed calls\n",
    'tests/calls_verify.ps1'=>"Write-Output 'fixture'\n",
    'tests/cap2_alpha.php'=>"<?php // retained prerequisite\n",
    'tests/capacity_evidence.php'=>"<?php // builder\n",
    'tests/k1b_bootstrap.php'=>"<?php // bootstrap\n",
    'tests/K1dSafeTestDatabase.php'=>"<?php // disposable harness, no data\n",
    'tests/unrelated.php'=>"<?php // not evidence\n",
    'app/Private.php'=>"<?php // raw source excluded\n",
    'docs/superpowers/plans/2026-09-06-calls.md'=>"# Approved calls fixture plan\n",
    'docs/superpowers/plans/2026-09-05-cap2.md'=>"# Legacy plan\n",
];
foreach ($tracked as $path=>$bytes) cap2WriteFile($workspace.'/'.$path,$bytes);
cap2Git($workspace,['add','.']); cap2Git($workspace,['commit','-qm','fixture']);
$head = trim(cap2Git($workspace,['rev-parse','HEAD'])['stdout']);
cap2WriteFile($workspace.'/tests/calls_alpha.php',"<?php // dirty must never replace committed evidence\n");
cap2WriteFile($workspace.'/docs/superpowers/plans/2026-09-06-calls.md',"# dirty plan\n");
$artifactFiles = ['CONTROL.txt'=>"DEPLOY_APPROVED=NO\n",'README.md'=>"Fixture only\n",
    'target.json'=>cap2Json(['head'=>$head]),'SHA256SUMS.txt'=>"fixture\n",'integrity.json'=>"{}\n",
    'rollback.zip'=>"synthetic rollback\n",'verificar.ps1'=>"Write-Output 'fixture'\n"];
foreach ($artifactFiles as $path=>$bytes) cap2WriteFile($artifact.'/'.$path,$bytes);
$approved = ['RESULTS.md'=>"Final fixture results\n",'calls-static.log'=>"PASS\n",'browser-desktop.png'=>"synthetic image\n"];
foreach ($approved as $path=>$bytes) cap2WriteFile($qa.'/'.$path,$bytes);
cap2WriteFile($qa.'/evidence.json',cap2Json(['files'=>array_keys($approved)]));
foreach (['calls-historical.log','secret.env','fixture-meta.json','wire.jsonl','session.txt','database.sql','cache.log','private/RESULTS.md','readiness/RESULTS.md'] as $path) {
    cap2WriteFile($qa.'/'.$path,"unapproved fixture data\n");
}
cap2WriteFile($workspace.'/.superpowers/sdd/2026-09-06-calls/progress.md',"unapproved local review\n");
$result = cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260906','calls');
callsEvidenceAssert(basename($result['qa_zip'])==='meli-calls-qa-20260906.zip','calls_short_qa_name');
$zip = new ZipArchive(); callsEvidenceAssert($zip->open($result['qa_zip'])===true,'calls_zip_reopens');
$names = $result['entries'];
foreach (['tests/calls_alpha.php','tests/calls_verify.ps1','tests/cap2_alpha.php','tests/capacity_evidence.php',
    'tests/k1b_bootstrap.php','tests/K1dSafeTestDatabase.php','plan/2026-09-06-calls.md','qa/evidence.json'] as $path) {
    callsEvidenceAssert(in_array($path,$names,true),'required_calls_evidence:'.$path);
}
foreach ($approved as $path=>$bytes) callsEvidenceAssert($zip->getFromName('qa/'.$path)===$bytes,'curated_bytes:'.$path);
callsEvidenceAssert($zip->getFromName('tests/calls_alpha.php')===$tracked['tests/calls_alpha.php'],'test_is_raw_target_blob');
callsEvidenceAssert($zip->getFromName('plan/2026-09-06-calls.md')===$tracked['docs/superpowers/plans/2026-09-06-calls.md'],'plan_is_raw_target_blob');
foreach (['tests/unrelated.php','app/Private.php','plan/2026-09-05-cap2.md','review/progress.md',
    'qa/calls-historical.log','qa/secret.env','qa/fixture-meta.json','qa/wire.jsonl','qa/session.txt',
    'qa/database.sql','qa/cache.log','qa/private/RESULTS.md','qa/readiness/RESULTS.md'] as $path) {
    callsEvidenceAssert(!in_array($path,$names,true),'unapproved_excluded:'.$path);
}
$hashes = json_decode($zip->getFromName('EVIDENCE_HASHES.json'),true,64,JSON_THROW_ON_ERROR);
foreach ($hashes as $path=>$hash) callsEvidenceAssert(hash('sha256',(string)$zip->getFromName($path))===$hash,'verified_hash:'.$path);
$zip->close();
callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260906','unknown'),'invalid_package_family');
foreach (['../RESULTS.md','nested/RESULTS.md','secret.env','session.txt','database.sql','fixture-meta.json','wire.jsonl','cache.log'] as $path) {
    cap2WriteFile($qa.'/evidence.json',cap2Json(['files'=>[$path]]));
    callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260907','calls'),'calls_evidence_path_denied');
}
cap2WriteFile($qa.'/evidence.json',cap2Json(['files'=>['RESULTS.md','RESULTS.md']]));
callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260907','calls'),'calls_evidence_manifest_invalid');
cap2WriteFile($qa.'/evidence.json',cap2Json(['files'=>['missing.md']]));
callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260907','calls'),'calls_evidence_file_missing');
rename($qa.'/evidence.json',$qa.'/manifest-held.json');
callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260907','calls'),'calls_evidence_manifest_required');
rename($qa.'/manifest-held.json',$qa.'/evidence.json');
cap2WriteFile($qa.'/evidence.json',cap2Json(['files'=>array_keys($approved)]));
cap2Git($workspace,['rm','-f','docs/superpowers/plans/2026-09-06-calls.md']);
cap2Git($workspace,['commit','-qm','fixture without calls plan']);
$withoutPlan = trim(cap2Git($workspace,['rev-parse','HEAD'])['stdout']);
cap2WriteFile($artifact.'/target.json',cap2Json(['head'=>$withoutPlan]));
callsEvidenceReject(fn()=>cap2BuildEvidenceZip($workspace,$qa,$artifact,'20260907','calls'),'calls_plan_missing_from_target');
callsEvidenceAssert(str_contains((string)file_get_contents($artifact.'/CONTROL.txt'),'DEPLOY_APPROVED=NO'),'never_approves_deploy');
echo "CALLS_PACKAGE_EVIDENCE_OK\nFIXTURE_ROOT=".$base."\n";
