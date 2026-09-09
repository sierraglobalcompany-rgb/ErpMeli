<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\Services\ManualPhysicalCallBudget;
$policy=['current'=>3,'ceiling'=>55,'revision'=>'certified'];
foreach ([['block_size'=>1],['preview_format'=>3,'physical_api_call_budget'=>3,'capacity_revision'=>'legacy'],['preview_format'=>4,'physical_api_call_budget'=>4,'capacity_revision'=>'old']] as $config) {
    $rejected=false;
    try {ManualPhysicalCallBudget::resolve($config,3,$policy);} catch(RuntimeException) {$rejected=true;}
    k1b_assert($rejected,'uncertified_or_reduced_preview_requires_recalculation');
}
k1b_assert(ManualPhysicalCallBudget::resolve(['preview_format'=>4,'physical_api_call_budget'=>2,'capacity_revision'=>'older'],100,$policy)===2,'certified_compatible_budget_does_not_increase');
echo "STATUS=PASS CAP2_MANUAL_BUDGET\n";
