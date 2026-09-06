<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

use App\Services\ManualPhysicalCallBudget;

$policy = ['current'=>55, 'ceiling'=>55, 'revision'=>'current-revision'];
foreach ([1,2,3,15,55] as $limit) {
    $configuration = ManualPhysicalCallBudget::previewConfiguration(['physical_api_call_budget'=>$limit], $policy);
    k1b_assert($configuration['physical_api_call_budget']===$limit, 'Preview preserves the confirmed physical budget.');
    k1b_assert($configuration['capacity_revision']==='current-revision', 'Revision comes from server policy.');
    k1b_assert(ManualPhysicalCallBudget::resolve($configuration+['preview_format'=>3],100,$policy)===$limit, 'Forged POST cannot exceed preview capacity.');
}
$configuration = ManualPhysicalCallBudget::previewConfiguration([], ['current'=>100,'ceiling'=>100,'revision'=>'hundred']);
k1b_assert($configuration['physical_api_call_budget']===100, 'Technical maximum 100 is supported.');
foreach ([
    fn()=>ManualPhysicalCallBudget::resolve(['preview_format'=>2,'block_size'=>60],100,$policy),
    fn()=>ManualPhysicalCallBudget::resolve(['preview_format'=>2,'block_size'=>3],100,$policy),
    fn()=>ManualPhysicalCallBudget::previewConfiguration(['physical_api_call_budget'=>56],$policy),
    fn()=>ManualPhysicalCallBudget::previewConfiguration(['physical_api_call_budget'=>0],$policy),
    fn()=>ManualPhysicalCallBudget::previewConfiguration(['physical_api_call_budget'=>'1.5'],$policy),
    fn()=>ManualPhysicalCallBudget::previewConfiguration(['capacity_revision'=>'stale'],$policy),
    fn()=>ManualPhysicalCallBudget::resolve(['preview_format'=>3,'physical_api_call_budget'=>55],55,['current'=>15,'ceiling'=>55,'revision'=>'reduced']),
] as $reject) {
    $rejected=false;
    try {$reject();} catch (RuntimeException) {$rejected=true;}
    k1b_assert($rejected, 'Unsafe or stale capacity must require recalculation.');
}
echo "STATUS=PASS CAPACITY_MANUAL_BUDGET\n";
