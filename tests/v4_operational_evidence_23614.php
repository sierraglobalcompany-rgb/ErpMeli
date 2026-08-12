<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$source = static function (string $path) use ($root): string {
    $bytes = file_get_contents($root . '/' . $path);
    if (!is_string($bytes)) {
        throw new RuntimeException('source_unavailable:' . $path);
    }
    return $bytes;
};

try {
    $bootstrap = $source('app/Services/V4ReadinessBootstrapService.php');
    $readiness = $source('app/QueueCore/QueueCoreReadinessReceiptService.php');
    $health = $source('app/QueueCore/QueueCoreHealthService.php');
    $release = $source('app/QueueCore/QueueCoreReleaseEvidenceService.php');
    $deployment = $source('app/Services/QueueCoreDeploymentGateService.php');

    $assert(str_contains($bootstrap, "public const REQUIRED_VERSION = '2.36.14'"), 'release_version_gate_invalid');
    $assert(!str_contains($bootstrap, '->certifyBackup('), 'bootstrap_still_certifies_backup');
    $assert(str_contains(
        $bootstrap,
        "['preflight', 'canary:3', 'convergence:3', 'capacity', 'manifest']",
    ), 'receipt_inventory_contract_invalid');
    $assert(!str_contains(
        $bootstrap,
        "['preflight', 'canary:3', 'convergence:3', 'backup', 'capacity', 'manifest']",
    ), 'backup_remains_in_receipt_inventory');

    $assert(str_contains($readiness, "foreach (['capacity', 'manifest'] as \$type)"), 'activation_evidence_contract_invalid');
    $assert(!str_contains($readiness, 'QUEUE_CORE_APPROVED_BACKUP_PATH'), 'backup_path_remains_in_activation_context');
    $assert(!str_contains($readiness, 'QUEUE_CORE_APPROVED_BACKUP_SHA256'), 'backup_sha_remains_in_activation_context');
    $assert(!str_contains($readiness, '->verifyBackup('), 'activation_still_revalidates_backup');

    $assert(str_contains($health, "foreach (['capacity', 'manifest'] as \$type)"), 'health_operational_evidence_invalid');
    $assert(!str_contains($health, "'backup_artifact_unavailable'"), 'health_still_requires_backup_artifact');
    $assert(str_contains($health, "e.evidence_type IN ('capacity','manifest')"), 'health_failed_evidence_scope_invalid');

    $assert(str_contains($release, 'public function verifyBackup('), 'verify_backup_removed');
    $assert(str_contains($release, 'public function certifyBackup('), 'certify_backup_removed');
    $assert(str_contains($release, "'backup',"), 'backup_receipt_type_removed');
    $assert(str_contains($deployment, "'backup_evidence_required'"), 'deployment_backup_gate_removed');
    $assert(str_contains($deployment, '->verifyBackup('), 'deployment_backup_verification_removed');

    echo 'V4 operational evidence 2.36.14: PASS checks=' . $checks
        . ' readiness=preflight,canary:3,convergence:3,capacity,manifest'
        . ' verify_backup_preserved=YES deployment_backup_preserved=YES' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'V4 operational evidence 2.36.14: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
