<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';
require dirname(__DIR__) . '/app/Services/UpdateFilesystemService.php';
require dirname(__DIR__) . '/app/Services/UpdateManifestService.php';

use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\UpdateManifestService;

$targetVersion = trim((string) (getenv('ERP_RELEASE_ARTIFACT_VERSION') ?: '2.38.4'));
$upgradeFrom = trim((string) (getenv('ERP_RELEASE_ARTIFACT_UPGRADE_FROM') ?: '2.38.3'));
$requiredMigration = trim((string) (getenv('ERP_RELEASE_ARTIFACT_MIGRATION') ?: '296_queue_v4_clean_oauth_control_plane_2_38_3.sql'));
$forbiddenMigrationPrefix = trim((string) (getenv('ERP_RELEASE_ARTIFACT_FORBIDDEN_MIGRATION_PREFIX') ?: '297_'));
$overlayLabel = trim((string) (getenv('ERP_RELEASE_ARTIFACT_OVERLAY_LABEL') ?: 'FTP_REPAIR_OVERLAY'));
$directory = $argv[1] ?? '';
if (!is_dir($directory) || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, 'Release artifacts ' . $targetVersion . ": FAIL arguments\n");
    exit(2);
}

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
};
$readZip = static function (string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('zip_open_failed:' . basename($path));
    }
    $files = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $bytes = $zip->getFromIndex($index);
            if ($name === '' || !is_string($bytes) || isset($files[strtolower($name)])) {
                throw new RuntimeException('zip_inventory_invalid:' . basename($path));
            }
            $files[$name] = $bytes;
        }
    } finally {
        $zip->close();
    }
    return $files;
};

try {
    $version = ManagedRuntimePublicationPolicy::VERSION;
    $fullPath = $directory . '/ERP_MELI_' . $version . '_GIT_EXACT.zip';
    $overlayPath = $directory . '/ERP_MELI_' . $version . '_' . $overlayLabel . '.zip';
    $updatePath = $directory . '/ERP_MELI_' . $version . '_UPDATE_PACKAGE.erpupd';
    $inventoryPath = $directory . '/ERP_MELI_' . $version . '_' . $overlayLabel . '_INVENTORY.json';
    $authorityPath = $directory . '/ERP_MELI_' . $version . '_ARTIFACT_MANIFEST.json';
    $sumsPath = $directory . '/ERP_MELI_' . $version . '_SHA256SUMS.txt';
    foreach ([$fullPath, $overlayPath, $updatePath, $inventoryPath, $authorityPath, $sumsPath] as $path) {
        $assert(is_file($path), 'artifact_missing:' . basename($path));
    }

    $entries = ManagedRuntimePublicationPolicy::packageEntries($root, 'HEAD');
    $expected = [];
    foreach ($entries as $entry) {
        $expected[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', $entry['path']);
    }
    $full = $readZip($fullPath);
    $assert(count($full) === count($expected), 'full_count_invalid');
    $assert(array_keys($full) === array_keys($expected), 'full_paths_invalid');
    foreach ($expected as $path => $bytes) {
        $assert(hash_equals(hash('sha256', $bytes), hash('sha256', $full[$path])), 'full_blob_invalid:' . $path);
    }
    $runtimeManifest = json_decode($full['resources/runtime-manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $assert(ManagedRuntimePublicationPolicy::packageIssues($root, $runtimeManifest, $full, 'HEAD') === [], 'full_policy_invalid');

    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    $overlay = $readZip($overlayPath);
    $overlayRows = is_array($inventory['files'] ?? null) ? $inventory['files'] : [];
    $assert((int) ($inventory['file_count'] ?? -1) === count($overlayRows), 'overlay_count_authority_invalid');
    $assert(count($overlay) === count($overlayRows), 'overlay_count_invalid');
    foreach ($overlayRows as $row) {
        $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
        $assert(isset($overlay[$path]), 'overlay_path_missing:' . $path);
        $assert(hash_equals((string) $row['sha256'], hash('sha256', $overlay[$path])), 'overlay_hash_invalid:' . $path);
    }

    $update = $readZip($updatePath);
    $manifestBytes = $update['update-manifest.json'] ?? null;
    $assert(is_string($manifestBytes), 'update_manifest_missing');
    $manifestService = new UpdateManifestService();
    $manifest = $manifestService->decode($manifestBytes);
    $assert(($manifest['source_trust'] ?? null) === 'local_admin', 'update_source_trust_invalid');
    $assert(($manifest['version'] ?? null) === $targetVersion, 'update_version_invalid');
    $assert(($manifest['upgrade_from'] ?? null) === [$upgradeFrom], 'update_source_version_invalid');
    $assert(in_array('295_inventory_warehouse_v1_2_38_0.sql', (array) ($manifest['migrations'] ?? []), true), 'migration_295_missing');
    $assert(in_array($requiredMigration, (array) ($manifest['migrations'] ?? []), true), 'required_migration_missing');
    $assert(!array_filter((array) ($manifest['migrations'] ?? []), static fn (string $name): bool => str_starts_with($name, $forbiddenMigrationPrefix)), 'forbidden_migration_present');
    $assert(!isset($manifest['signature']), 'unexpected_update_signature');
    $assert($manifestService->verifySignature($manifest) === 'local_unsigned', 'local_admin_signature_status_invalid');
    unset($update['update-manifest.json']);
    $assert(array_keys($update) === array_keys($expected), 'update_paths_invalid');
    foreach ((array) ($manifest['files'] ?? []) as $row) {
        $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
        $assert(isset($update[$path]), 'update_file_missing:' . $path);
        $assert(hash_equals((string) $row['sha256'], hash('sha256', $update[$path])), 'update_file_hash_invalid:' . $path);
    }

    foreach (array_keys($full + $overlay + $update) as $path) {
        $normalized = strtolower((string) $path);
        $assert(!in_array($normalized, ['.env', 'config.env', 'shared/config.env', 'pause_meli_api', 'pause_erp_automation', 'shared/current-release.json'], true), 'protected_file_packaged:' . $path);
        $assert(!preg_match('#^(?:storage|shared/storage|logs|sessions|backups)/#', $normalized), 'protected_tree_packaged:' . $path);
    }
    $requiredOverlayPaths = ['VERSION',
        'app/QueueV4Clean/QueueV4CleanDatabaseContract.php',
        'app/QueueV4Clean/QueueV4CleanOAuthStageContext.php',
        'app/QueueV4Clean/QueueV4CleanSafeDiagnosticService.php',
        'app/QueueV4Clean/QueueV4CleanOAuthSupervisor.php',
        'app/QueueV4Clean/QueueV4CleanScheduler.php',
        'app/Services/CurlMeliHttpTransport.php',
        'app/Services/ManagedRuntimePublicationPolicy.php',
        'app/Services/MeliCliRuntimeCapabilityService.php',
        'app/Services/MeliApiClient.php',
        'app/Services/OAuthTokenRefreshService.php',
        'jobs/queue_v4_clean.php',
        'jobs/queue_v4_runtime_self_check.php',
        'resources/release/managed-runtime-dependencies-' . $targetVersion . '.json',
        'resources/release/queue-v4-canonical-db-contract-' . $targetVersion . '.json',
        'resources/release/updater-authority-' . $targetVersion . '.json',
        'resources/runtime-manifest.json'];
    if ($targetVersion === '2.38.5') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanCycleBudget.php',
            'app/QueueV4Clean/QueueV4CleanDispatchFence.php',
            'app/QueueV4Clean/QueueV4CleanHealthSnapshotService.php',
            'app/QueueV4Clean/QueueV4CleanMaintenanceService.php',
            'app/QueueV4Clean/QueueV4CleanSalesAuditStage.php',
            'app/QueueV4Clean/QueueV4CleanUncertainReadRecoveryService.php',
            'app/Services/QueueV4PreTransportDeferredException.php',
            'database/migrations/297_queue_v4_transport_sales_api_health_2_38_5.sql',
            'resources/release/managed-runtime-dependencies-2.38.5.json',
            'resources/release/queue-v4-canonical-db-contract-2.38.5.json',
            'resources/release/updater-authority-2.38.5.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.38.6') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Core/PrivatePathAuthority.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/QueueOAuthDurableRecoveryStore.php',
            'jobs/queue_v4_runtime_self_check.php',
            'resources/release/managed-runtime-dependencies-2.38.6.json',
            'resources/release/queue-v4-canonical-db-contract-2.38.6.json',
            'resources/release/updater-authority-2.38.6.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.38.7') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanDatabaseContract.php',
            'app/QueueV4Clean/QueueV4CleanScheduler.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/SalesAuditRunService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.38.7.json',
            'resources/release/queue-v4-canonical-db-contract-2.38.7.json',
            'resources/release/updater-authority-2.38.7.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.38.8') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueCore/QueueCoreOwnershipGuard.php',
            'app/QueueV4Clean/QueueV4CleanDatabaseContract.php',
            'app/QueueV4Clean/QueueV4CleanScheduler.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliTransportSourcePolicy.php',
            'app/Services/SalesAuditExactRepairService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.38.8.json',
            'resources/release/queue-v4-canonical-db-contract-2.38.8.json',
            'resources/release/updater-authority-2.38.8.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.38.9') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanDatabaseContract.php',
            'app/QueueV4Clean/QueueV4CleanDispatchFence.php',
            'app/QueueV4Clean/QueueV4CleanReadinessService.php',
            'app/QueueV4Clean/QueueV4CleanTransportJournal.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliTransportSourcePolicy.php',
            'app/Services/SalesAuditExactRepairService.php',
            'composer.json',
            'database/migrations/298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'resources/release/managed-runtime-dependencies-2.38.9.json',
            'resources/release/queue-v4-canonical-db-contract-2.38.9.json',
            'resources/release/updater-authority-2.38.9.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.0') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'composer.json',
            'jobs/process_sync_queue.php',
            'launcher/cron.php',
            'resources/release/managed-runtime-dependencies-2.39.0.json',
            'resources/release/updater-authority-2.39.0.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.1') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/QueueCore/QueueEngineControlCli.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'composer.json',
            'jobs/cron_v3_local.php',
            'jobs/cron_v3_remote.php',
            'jobs/cron_v4.php',
            'jobs/queue_core_canary.php',
            'jobs/queue_core_historical.php',
            'jobs/queue_core_rollback.php',
            'jobs/queue_engine_control.php',
            'resources/release/managed-runtime-dependencies-2.39.1.json',
            'resources/release/updater-authority-2.39.1.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.2') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/ClaimController.php',
            'app/Controllers/ModuleAdminController.php',
            'app/Controllers/QuestionController.php',
            'app/Controllers/SalesControlController.php',
            'app/Controllers/SettingsController.php',
            'app/Controllers/SyncController.php',
            'app/Core/Modules/ModuleEventDispatcher.php',
            'app/Core/Modules/ModuleJobRunner.php',
            'app/Modules/MeliGrowth/Controllers/DashboardController.php',
            'app/Modules/MeliGrowth/Views/index.php',
            'app/Modules/MeliInsights/Controllers/DashboardController.php',
            'app/Modules/MeliInsights/Views/index.php',
            'app/Modules/Shared/Controllers/ModuleDashboardController.php',
            'app/Modules/Shared/Views/dashboard.php',
            'app/Repositories/SettingsDefinitionRepository.php',
            'app/Services/CronV3MaintenanceProducer.php',
            'app/Services/CronV3ProducerService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/ManualProcessingService.php',
            'app/Services/NotificationBackfillService.php',
            'app/Services/QuestionSyncService.php',
            'app/Services/RecurringSyncService.php',
            'app/Services/SalesFiscalPreparationService.php',
            'app/Services/SettingsSectionService.php',
            'app/Views/notifications/index.php',
            'app/Views/sales/claims/index.php',
            'app/Views/sales/questions/index.php',
            'app/Views/sales_control/fiscal.php',
            'app/Views/settings/modules.php',
            'app/Views/settings/section.php',
            'app/Views/sync/recurring.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.2.json',
            'resources/release/updater-authority-2.39.2.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.3') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanDatabaseContract.php',
            'app/QueueV4Clean/QueueV4CleanDispatchFence.php',
            'app/QueueV4Clean/QueueV4CleanWorker.php',
            'app/Services/CronAdmissionService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliTransportSourcePolicy.php',
            'app/Services/OrderFinancialRecalcJobService.php',
            'app/Services/SaleFinancialService.php',
            'app/Services/SalesAuditExactRepairService.php',
            'composer.json',
            'database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql',
            'resources/release/managed-runtime-dependencies-2.39.3.json',
            'resources/release/queue-v4-canonical-db-contract-2.39.3.json',
            'resources/release/updater-authority-2.39.3.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.4') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanProducer.php',
            'app/QueueV4Clean/QueueV4CleanRepository.php',
            'app/QueueV4Clean/QueueV4CleanScheduler.php',
            'app/Services/ApiRhythmPolicyService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliApiClient.php',
            'app/Services/SaleFinancialService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.4.json',
            'resources/release/updater-authority-2.39.4.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.5') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanWorker.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.5.json',
            'resources/release/updater-authority-2.39.5.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.6') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/SaleFinancialService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.6.json',
            'resources/release/updater-authority-2.39.6.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.7') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/SaleFinancialService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.7.json',
            'resources/release/updater-authority-2.39.7.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.8') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/OrderSyncService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.8.json',
            'resources/release/updater-authority-2.39.8.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.9') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanRepository.php',
            'app/QueueV4Clean/QueueV4CleanWorker.php',
            'app/Services/ApiGuardService.php',
            'app/Services/ApiRhythmPolicyService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliApiClient.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.9.json',
            'resources/release/updater-authority-2.39.9.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.10') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanCycleBudget.php',
            'app/QueueV4Clean/QueueV4CleanProducer.php',
            'app/QueueV4Clean/QueueV4CleanRepository.php',
            'app/QueueV4Clean/QueueV4CleanScheduler.php',
            'app/QueueV4Clean/QueueV4CleanWorker.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliApiClient.php',
            'app/Services/MeliTransportSourcePolicy.php',
            'app/Services/OrderSyncService.php',
            'app/Services/SaleFinancialService.php',
            'app/Services/SalesAuditRunService.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.10.json',
            'resources/release/updater-authority-2.39.10.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.11') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/Repositories/SettingsDefinitionRepository.php',
            'app/Services/ApiRhythmPolicyService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Views/settings/api_workload.php',
            'resources/release/managed-runtime-dependencies-2.39.11.json',
            'resources/release/updater-authority-2.39.11.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.12') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/QueueV4Clean/QueueV4CleanHealthSnapshotService.php',
            'app/Repositories/SettingsDefinitionRepository.php',
            'app/Services/ApiLogRiskPresenter.php',
            'app/Services/ApiRhythmPolicyService.php',
            'app/Services/LogQueryService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/SettingsSectionService.php',
            'app/Views/logs/index.php',
            'app/Views/settings/api_workload.php',
            'resources/release/managed-runtime-dependencies-2.39.12.json',
            'resources/release/updater-authority-2.39.12.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.13') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/Services/ApiHealthService.php',
            'app/Services/ApiRhythmPolicyService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Views/settings/api_health.php',
            'app/Views/settings/api_health_incident_show.php',
            'app/Views/settings/api_health_incidents.php',
            'app/Views/settings/api_workload.php',
            'resources/release/managed-runtime-dependencies-2.39.13.json',
            'resources/release/updater-authority-2.39.13.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.14') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/Services/ApiHealthService.php',
            'app/Services/ApiIncidentReadModelService.php',
            'app/Services/ApiLogRiskPresenter.php',
            'app/Services/AutomationRuntimeStatusService.php',
            'app/Services/CronHealthService.php',
            'app/Services/LogQueryService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/RuntimeProcessInventoryService.php',
            'jobs/process_sync_queue.php',
            'jobs/queue_engine_control.php',
            'resources/release/managed-runtime-dependencies-2.39.14.json',
            'resources/release/updater-authority-2.39.14.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.15') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ApiHealthService.php',
            'app/Services/ApiLogRiskPresenter.php',
            'app/Services/ApiRequestOutcomeClassifier.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'jobs/process_sync_queue.php',
            'jobs/queue_core_health_snapshot.php',
            'jobs/queue_core_preflight.php',
            'jobs/queue_core_rollback.php',
            'resources/release/managed-runtime-dependencies-2.39.15.json',
            'resources/release/updater-authority-2.39.15.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.16') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/QueueV4Clean/QueueV4CleanScheduler.php',
            'app/Services/ApiIncidentMaterializerService.php',
            'app/Services/ApiIncidentReadModelService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'composer.json',
            'resources/release/managed-runtime-dependencies-2.39.16.json',
            'resources/release/updater-authority-2.39.16.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.17') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Controllers/SettingsController.php',
            'app/Services/AlertService.php',
            'app/Services/ApiHealthService.php',
            'app/Services/ApiIncidentMaterializerService.php',
            'app/Services/CronApiRiskSummaryService.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'jobs/api_incident_materializer_diagnose.php',
            'jobs/process_sync_queue.php',
            'resources/release/managed-runtime-dependencies-2.39.17.json',
            'resources/release/updater-authority-2.39.17.json',
            'resources/runtime-manifest.json',
        ];
    } elseif ($targetVersion === '2.39.18') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/SaleFinancialService.php',
            'app/Views/settings/cron_shell.php',
            'composer.json',
            'public/assets/app.js',
            'resources/release/managed-runtime-dependencies-2.39.18.json',
            'resources/release/updater-authority-2.39.18.json',
            'resources/runtime-manifest.json',
        ];
        $assert(($inventory['base_commit'] ?? null) === '28bd74ecd0b528aaad2ffa3af11114dfbff5737c', 'overlay_base_not_installed_23917');
        $assert(count($overlayRows) === count($requiredOverlayPaths), 'overlay_redundant_or_missing_paths');
    } elseif ($targetVersion === '2.39.19') {
        $requiredOverlayPaths = [
            'VERSION',
            'app/QueueV4Clean/QueueV4CleanRepository.php',
            'app/Services/ManagedRuntimePublicationPolicy.php',
            'app/Services/MeliApiClient.php',
            'app/Services/SaleFinancialService.php',
            'resources/release/managed-runtime-dependencies-2.39.19.json',
            'resources/release/updater-authority-2.39.19.json',
            'resources/runtime-manifest.json',
        ];
        $assert(($inventory['base_commit'] ?? null) === 'c4db6be5e41213a52f476dff03f099b10fae6624', 'overlay_base_not_23918_authority');
        $assert(count($overlayRows) === count($requiredOverlayPaths), 'overlay_redundant_or_missing_paths');
    }
    foreach (array_values(array_unique($requiredOverlayPaths)) as $requiredOverlay) {
        $assert(isset($overlay[$requiredOverlay]), 'required_overlay_path_missing:' . $requiredOverlay);
    }

    $authority = json_decode((string) file_get_contents($authorityPath), true, 64, JSON_THROW_ON_ERROR);
    foreach ((array) ($authority['artifacts'] ?? []) as $name => $definition) {
        $path = $directory . '/' . $name;
        $assert(is_file($path), 'authority_artifact_missing:' . $name);
        $assert(hash_equals((string) $definition['sha256'], hash_file('sha256', $path)), 'authority_hash_invalid:' . $name);
    }
    $sumLines = file($sumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($sumLines as $line) {
        $assert(preg_match('~^([a-f0-9]{64})  ([^/\\\\]+)$~', $line, $match) === 1, 'sha_line_invalid');
        $assert(is_file($directory . '/' . $match[2]), 'sha_target_missing');
        $assert(hash_equals($match[1], hash_file('sha256', $directory . '/' . $match[2])), 'sha_target_invalid:' . $match[2]);
    }
    fwrite(STDOUT, 'Release artifacts ' . $targetVersion . ': PASS checks=' . $checks . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release artifacts ' . $targetVersion . ': FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
