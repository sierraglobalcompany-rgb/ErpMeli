<?php

declare(strict_types=1);

/*
 * Phase 1 RED contract for the Calls V2 follow-up phases.
 *
 * This is intentionally a source-level reproduction: it neither creates a
 * database nor opens an HTTP connection.  A non-zero exit in --red mode is
 * the expected result until the production phases close every listed gap.
 */

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('required_source_missing:' . $relative);
    }

    return (string) file_get_contents($path);
};

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "PASS={$label}\n";
        return;
    }

    $failures[] = $label;
    echo "RED={$label}\n";
};

$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$billingImporter = $read('app/Services/OrderBillingImportService.php');
$saleFinancial = $read('app/Services/SaleFinancialService.php');
$receiptWorker = $worker;
$transport = $read('app/Services/CurlMeliHttpTransport.php');
$migration122 = $read('database/migrations/122_sale_financial_reconciliation_2_24_0.sql');
$migration242 = $read('database/migrations/242_financial_state_v3_2_29_0.sql');

// Phase 3: automatic Billing may select exactly one source/order for one GET.
$automaticGroupsNeighbors = str_contains($worker, 'contiguousFinancialReconciliationSourceIds($job, 60)')
    || str_contains($worker, 'count($batchSourceIds) > 1');
$automaticImporterGroupsIds = str_contains($billingImporter, 'array_chunk($accountOrders, $perRequest)')
    || str_contains($billingImporter, "['order_ids' => implode(',', \$externalIds)]");
$assert(
    !$automaticGroupsNeighbors && !$automaticImporterGroupsIds,
    'automatic_billing_get_has_exactly_one_order_id'
);

// Phase 4: a pack can retain its identity, but every Billing GET is one order.
$packGroupsIds = str_contains($saleFinancial, "['order_ids' => implode(',', \$allExternalOrderIds)]")
    || str_contains($saleFinancial, "['order_ids' => implode(',', \$externalOrderIds)]")
    || str_contains($saleFinancial, 'BILLING_MAX_ORDER_IDS');
$assert(!$packGroupsIds, 'pack_billing_get_has_exactly_one_order_id');

// Phase 7 F1: a pre-curl marker is unresolved evidence, never an exact call.
$physicalStartedCertified = str_contains(
    $receiptWorker,
    "e.dispatch_state IN ('PHYSICAL_STARTED','RESPONSE_KNOWN')"
);
$assert(!$physicalStartedCertified, 'f1_pre_curl_marker_is_unknown_not_exact_physical_call');

// Phase 7 F2: receipt of HTTP status must survive a journal/local-write error.
// Direct, fallible responseKnown calls in the wire boundary can throw before
// MeliApiClient receives the status/body and therefore lose known evidence.
$knownStatusCanBeLost = str_contains(
    $transport,
    'QueueV4CleanOAuthDispatchFence::responseKnown($status);'
) || str_contains(
    $transport,
    'QueueV4CleanDispatchFence::responseKnown($status);'
);
$assert(!$knownStatusCanBeLost, 'f2_known_http_status_survives_local_journal_failure');

// Schema 301 viability is deliberately fail-closed.  One-order captures have
// scope, one requested ID, status and hash fields, but the immutable evidence
// enum cannot record the required billing_order_v2 type without a migration.
$captureFieldsPresent = preg_match(
    '/CREATE TABLE IF NOT EXISTS meli_billing_capture_runs[\\s\\S]*?(?=CREATE TABLE IF NOT EXISTS meli_sale_financials)/',
    $migration122,
    $captureMatch
) === 1;
foreach (['company_id', 'meli_account_id', 'requested_order_ids_json', 'http_status', 'response_hash'] as $requiredCaptureField) {
    $captureFieldsPresent = $captureFieldsPresent
        && str_contains($captureMatch[0] ?? '', $requiredCaptureField);
}
$hasBillingOrderV2EvidenceType = preg_match(
    "/evidence_type ENUM\\([^)]*'billing_order_v2'/",
    $migration242
) === 1;
$schemaViable = $captureFieldsPresent && $hasBillingOrderV2EvidenceType;
$assert($schemaViable, 'schema_301_persists_one_order_billing_order_v2_checkpoint_evidence');

if ($failures !== []) {
    fwrite(STDERR, "EXPECTED_RED_FAILURES=" . implode(',', $failures) . "\n");
    fwrite(STDERR, "SCHEMA_301_VIABILITY=" . ($schemaViable ? 'PASS' : 'FAIL_CLOSED') . "\n");
    exit(1);
}

echo "STATUS=PASS CALLS_V2_PHASE1_CONTRACT\n";
