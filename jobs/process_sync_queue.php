<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$doctorMode = in_array('--doctor', $args, true);
$qaReplayMode = in_array('--qa-replay', $args, true);
$retentionStepMode = in_array('--retention-step', $args, true);
$fullJsonOutput = in_array('--json', $args, true);

if ($doctorMode) {
    require __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap' . '.php';
    $doctor = new \App\Services\CronDoctorService();
    if ($fullJsonOutput) {
        echo json_encode(
            $doctor->snapshot('cli'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) . PHP_EOL;
    } else {
        echo $doctor->textReport();
    }
    exit(0);
}

if ($qaReplayMode) {
    require __DIR__ . DIRECTORY_SEPARATOR . '_bootstrap' . '.php';
    $cycles = 60;
    foreach ($args as $arg) {
        if (str_starts_with((string) $arg, '--cycles=')) {
            $cycles = max(1, min(240, (int) substr((string) $arg, 9)));
        }
    }
    $fakeTransport = in_array('--fake-transport', $args, true)
        || filter_var((string) getenv('ERP_FAKE_MELI_TRANSPORT'), FILTER_VALIDATE_BOOL);
    $payload = [
        'ok' => $fakeTransport,
        'mode' => 'qa-replay',
        'cycles_requested' => $cycles,
        'fake_transport' => $fakeTransport,
        'read_only_preflight' => (new \App\Services\CronDoctorService())->snapshot('qa-replay-preflight'),
        'message' => $fakeTransport
            ? 'Preflight listo. Ejecute los ciclos en el arnés QA dedicado con transporte Mercado Libre falso.'
            : 'QA replay bloqueado: active --fake-transport o ERP_FAKE_MELI_TRANSPORT=true para evitar transporte real.',
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($fakeTransport ? 0 : 2);
}

// Queue V4 es la única automatización de producción. Este archivo sólo
// conserva doctor y QA con transporte falso; nada más abre locks ni PDO.
$legacyFlags = ['--retention-step', '--record', '--persist', '--execute', '--set', '--rollback', '--prepare'];
foreach ($args as $argument) {
    foreach ($legacyFlags as $flag) {
        if ($argument === $flag || str_starts_with((string) $argument, $flag . '=')) {
            fwrite(STDERR, 'LEGACY_AUTOMATION_BLOCKED component=process_sync_queue flag='
                . preg_replace('/[^A-Za-z0-9_.-]/', '_', $flag) . " remote=false http=0\n");
            exit(2);
        }
    }
}
echo 'ERP_CRON_SKIP component=process_sync_queue reason=LEGACY_AUTOMATION_RETIRED remote=false http=0' . PHP_EOL;
exit(0);

$runtimeManifest = json_decode(
    (string) @file_get_contents(dirname(__DIR__) . '/resources/runtime-manifest.json'),
    true
);
$runtimeVersion = is_array($runtimeManifest) ? (string) ($runtimeManifest['version'] ?? 'unknown') : 'unknown';
$runtimeBuild = is_array($runtimeManifest) ? (string) ($runtimeManifest['build_id'] ?? 'unknown') : 'unknown';
require __DIR__ . '/_cron_entry_state.php';
cron_entry_state_write('php_opened', $runtimeVersion, $runtimeBuild, ['result' => 'running']);

$earlyLockResult = cron_entry_early_lock();
if ($earlyLockResult['status'] === 'storage_unavailable') {
    cron_entry_state_write('entry_storage_unavailable', $runtimeVersion, $runtimeBuild, [
        'result' => 'error',
        'diagnostic' => 'cron_entry_storage_unavailable',
        'remote' => false,
    ]);
    fwrite(
        STDERR,
        'ERP_CRON_ERROR component=process_sync_queue reason=entry_storage_unavailable'
        . ' remote=false database=false' . PHP_EOL
    );
    exit(1);
}
if ($earlyLockResult['status'] === 'busy') {
    cron_entry_state_write('duplicate_skipped', $runtimeVersion, $runtimeBuild, ['result' => 'skipped']);
    echo 'ERP_CRON_SKIP component=process_sync_queue'
        . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
        . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
        . ' reason=already_running' . PHP_EOL;
    exit(0);
}
$earlyLock = $earlyLockResult['handle'];

echo 'ERP_CRON_BOOT component=process_sync_queue'
    . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
    . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
    . ' time=' . gmdate('Y-m-d\TH:i:s\Z') . PHP_EOL;
if (function_exists('ob_flush')) {
    @ob_flush();
}
flush();

require __DIR__ . '/_automation_emergency_stop.php';
$earlyRuntimeState = erp_prebootstrap_runtime_state();
$localBackupRequest = $earlyRuntimeState['backup_requested'];
$localBackupRecovery = $earlyRuntimeState['local_task'] === 'backup_recovery';
$localRestoreRequest = $earlyRuntimeState['restore_requested'];
$localDatabaseTask = $localBackupRecovery ? '' : $earlyRuntimeState['local_task'];
$localDatabaseTaskId = $earlyRuntimeState['local_id'];
$localMaintenanceRequestCount = (int) ($localBackupRequest || $localBackupRecovery)
    + (int) $localRestoreRequest
    + (int) ($localDatabaseTask !== '');
$localMaintenanceConflict = $localMaintenanceRequestCount > 1;
$databaseMutationFreeze = $earlyRuntimeState['mutation_freeze'];
$databaseSnapshotFreeze = $earlyRuntimeState['snapshot_freeze'];
$earlyRuntimeMode = erp_prebootstrap_runtime_mode($earlyRuntimeState);
if ($earlyRuntimeMode === 'maintenance') {
    cron_entry_state_write('database_maintenance', $runtimeVersion, $runtimeBuild, [
        'result' => 'waiting',
        'remote' => false,
        'snapshot' => $databaseSnapshotFreeze,
    ]);
    echo 'ERP_CRON_WAIT reason=database_maintenance remote=false database=false' . PHP_EOL;
    exit(0);
}
if ($earlyRuntimeMode === 'automation_stopped') {
    cron_entry_state_write('automation_stopped', $runtimeVersion, $runtimeBuild, [
        'result' => 'waiting',
        'remote' => false,
    ]);
    echo 'ERP_CRON_WAIT reason=manual_automation_stop remote=false database=false' . PHP_EOL;
    exit(0);
}
if ($retentionStepMode && $earlyRuntimeMode !== 'normal') {
    cron_entry_state_write('database_maintenance', $runtimeVersion, $runtimeBuild, [
        'result' => 'waiting',
        'diagnostic' => 'retention_runtime_guard_active',
        'remote' => false,
    ]);
    echo 'ERP_CRON_WAIT component=technical_retention'
        . ' reason=runtime_guard_active remote=false database=false' . PHP_EOL;
    exit(0);
}
if ($earlyRuntimeMode === 'local_maintenance' && !defined('ERP_LOCAL_MAINTENANCE_ONLY')) {
    define('ERP_LOCAL_MAINTENANCE_ONLY', true);
}
if (!$retentionStepMode && !defined('ERP_LOCAL_MAINTENANCE_ONLY')) {
    cron_entry_state_write('finished', $runtimeVersion, $runtimeBuild, [
        'result' => 'skipped',
        'reason' => 'LEGACY_AUTOMATION_RETIRED',
        'remote' => false,
        'http' => 0,
    ]);
    echo 'ERP_CRON_SKIP component=process_sync_queue'
        . ' reason=LEGACY_AUTOMATION_RETIRED remote=false http=0' . PHP_EOL;
    exit(0);
}
require __DIR__ . '/_meli_emergency_stop.php';

register_shutdown_function(static function () use ($runtimeVersion, $runtimeBuild): void {
    if (defined('ERP_CRON_BOOTSTRAP_LOADED')) {
        return;
    }
    $error = error_get_last();
    cron_entry_state_write('failed_before_bootstrap', $runtimeVersion, $runtimeBuild, [
        'result' => 'error',
        'diagnostic' => is_array($error) ? 'php_bootstrap_failure' : 'bootstrap_not_completed',
        'remote' => false,
    ]);
});

use App\Services\ApiBudgetService;
use App\Services\ApiGuardService;
use App\Services\AppSettingsService;
use App\Services\CatalogDescriptionJobService;
use App\Services\CronDeadlineContext;
use App\Services\CronExecutionPlanner;
use App\Services\CronHealthService;
use App\Services\CronTaskStateService;
use App\Services\CronWorkAvailabilityService;
use App\Services\CronWorkCoordinator;
use App\Services\MeliItemSyncJobService;
use App\Services\NotificationBackfillService;
use App\Services\NotificationCoalescerService;
use App\Services\OperationalMaintenanceService;
use App\Services\OrderDateRepairService;
use App\Services\OrderEnrichmentService;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\QuestionSyncService;
use App\Services\RecurringSyncService;
use App\Services\ResumableCampaignWorkerService;
use App\Services\SafeErrorPresenter;
use App\Services\SalesAuditRunService;
use App\Services\SalesFiscalPreparationService;
use App\Services\HistoricalPackReconciliationService;
use App\Services\SaleFinancialService;
use App\Services\SalesRepairService;
use App\Services\SyncQueueService;
use App\Services\WebhookSpoolService;

require __DIR__ . '/_bootstrap.php';
define('ERP_CRON_BOOTSTRAP_LOADED', true);
\App\Services\ApiExecutionMetadataContext::resetRemoteDispatchCount();
cron_entry_state_write('bootstrap_loaded', $runtimeVersion, $runtimeBuild, ['result' => 'running']);

if ($retentionStepMode) {
    $result = (new \App\Services\TechnicalRetentionCliService())->runStep(500);
    cron_entry_state_write('finished', $runtimeVersion, $runtimeBuild, [
        'result' => !empty($result['skipped']) ? 'waiting' : 'completed',
        'processed' => (int) ($result['processed'] ?? 0),
        'remote' => false,
    ]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
$persistEntry = static function (string $stage, array $extra = []) use ($runtimeVersion, $runtimeBuild): void {
    cron_entry_state_write($stage, $runtimeVersion, $runtimeBuild, $extra);
    (new \App\Services\CronEntryStateService())->record($stage, $runtimeVersion, $runtimeBuild, $extra);
};

$executionSource = in_array('--manual', $args, true) ? 'manual_cli' : 'scheduled_cli';
$bootstrapJournal = new \App\Services\CronBootstrapJournalService();
$bootstrapAttempt = $bootstrapJournal->begin(
    'process_sync_queue',
    $runtimeVersion,
    $runtimeBuild,
    $executionSource
);
$persistEntry('database_connected', ['result' => 'running']);
$bootstrapId = (int) $bootstrapAttempt['id'];
$bootstrapJournal->stage($bootstrapId, 'validating_installation');

if (defined('ERP_LOCAL_MAINTENANCE_ONLY')) {
    $backupCoordinator = null;
    $backupReconciliation = null;
    try {
        if ($localBackupRequest || $localBackupRecovery) {
            $backupCoordinator = new \App\Services\LocalMaintenanceCoordinator();
            $backupReconciliation = $backupCoordinator->reconcileBackup(
                \App\Core\Database::connection()
            );
            if ($backupReconciliation['state'] === 'blocked') {
                $bootstrapJournal->stage($bootstrapId, 'completed');
                $persistEntry('finished', [
                    'result' => 'waiting',
                    'diagnostic' => $backupReconciliation['reason'],
                    'remote' => false,
                    'local_maintenance' => true,
                ]);
                echo 'ERP_CRON_WAIT component=local_backup'
                    . ' reason=' . preg_replace(
                        '/[^A-Za-z0-9_.-]/',
                        '_',
                        (string) $backupReconciliation['reason']
                    )
                    . ' remote=false' . PHP_EOL;
                exit(0);
            }
            if (in_array(
                (string) $backupReconciliation['state'],
                ['terminal_reconciled', 'idle'],
                true
            )) {
                $localBackupRequest = false;
                $localBackupRecovery = false;
                if (!$localRestoreRequest && $localDatabaseTask === '') {
                    $bootstrapJournal->stage($bootstrapId, 'completed');
                    $persistEntry('finished', [
                        'result' => 'completed',
                        'remote' => false,
                        'local_maintenance' => true,
                    ]);
                    echo 'ERP_CRON_LOCAL_MAINTENANCE result=reconciled'
                        . ' component=local_backup remote=false' . PHP_EOL;
                    exit(0);
                }
            } else {
                $localBackupRequest = true;
            }
        }
        // Una copia activa tiene prioridad sobre el saneamiento o el reinicio
        // de datos. Esos encargos permanecen congelados y se retomarán en el
        // ciclo posterior; no se convierten en un conflicto terminal.
        if ($localBackupRequest && $localDatabaseTask !== '') {
            $localDatabaseTask = '';
            $localDatabaseTaskId = 0;
        }
        $localMaintenanceConflict = (
            (int) $localBackupRequest
            + (int) $localRestoreRequest
            + (int) ($localDatabaseTask !== '')
        ) > 1;
        if ($localMaintenanceConflict) {
            throw new RuntimeException(
                'Hay más de una solicitud de mantenimiento local activa.'
            );
        }
        if ($localRestoreRequest) {
            $result = (new \App\Services\RestoreService())->processRequested();
            $maintenanceComponent = 'local_restore';
        } elseif ($localBackupRequest) {
            $backupPostReconciliation = null;
            try {
                $result = (new \App\Services\BackupCenterService())->processRequested();
            } finally {
                // Cubre el corte/fallo entre el estado terminal en MariaDB y
                // la liberación de los marcadores físicos.
                $backupPostReconciliation = $backupCoordinator->reconcileBackup(
                    \App\Core\Database::connection()
                );
            }
            if ($backupPostReconciliation['state'] === 'blocked') {
                throw new RuntimeException(
                    'La copia terminó sin poder reconciliar sus marcadores locales.'
                );
            }
            $maintenanceComponent = 'local_backup';
        } elseif ($localDatabaseTask === 'database_sanitation') {
            $step = (new \App\Services\DatabaseMaintenanceService())->runCliStep(
                $localDatabaseTaskId
            );
            $result = [
                'result' => $step === null
                    ? 'idle'
                    : (string) ($step['status']['status'] ?? 'partial'),
            ];
            $maintenanceComponent = 'database_sanitation';
        } elseif ($localDatabaseTask === 'imported_data_reset') {
            $step = (new \App\Services\ImportedMeliDataResetService())->runNext(
                $localDatabaseTaskId
            );
            $result = [
                'result' => $step === null
                    ? 'idle'
                    : (string) ($step['status'] ?? 'partial'),
            ];
            $maintenanceComponent = 'imported_data_reset';
        } else {
            throw new RuntimeException('El mantenimiento local no tiene una solicitud reconocida.');
        }
        $bootstrapJournal->stage($bootstrapId, 'completed');
        $persistEntry('finished', [
            'result' => (string) ($result['result'] ?? 'completed'),
            'remote' => false,
            'local_maintenance' => true,
        ]);
        echo 'ERP_CRON_LOCAL_MAINTENANCE result='
            . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($result['result'] ?? 'completed'))
            . ' component=' . $maintenanceComponent
            . ' remote=false' . PHP_EOL;
        exit(0);
    } catch (Throwable $maintenanceError) {
        $bootstrapJournal->stage($bootstrapId, 'failed');
        $maintenanceDiagnostic = match (true) {
            $localMaintenanceConflict => 'local_maintenance_conflict',
            $localRestoreRequest => 'local_restore_failed',
            $localBackupRequest => 'local_backup_failed',
            $localDatabaseTask === 'database_sanitation' => 'database_sanitation_failed',
            $localDatabaseTask === 'imported_data_reset' => 'imported_data_reset_failed',
            default => 'local_maintenance_failed',
        };
        $persistEntry('finished', [
            'result' => 'failed',
            'diagnostic' => $maintenanceDiagnostic,
            'remote' => false,
            'local_maintenance' => true,
        ]);
        echo 'ERP_CRON_ERROR component='
            . match (true) {
                $localMaintenanceConflict => 'local_maintenance',
                $localRestoreRequest => 'local_restore',
                $localBackupRequest => 'local_backup',
                $localDatabaseTask !== '' => $localDatabaseTask,
                default => 'local_maintenance',
            }
            . ' remote=false diagnostic=' . $maintenanceDiagnostic . PHP_EOL;
        exit(1);
    }
}

$integrityService = new \App\Services\ReleaseIntegrityService();
try {
    $integrityService->assertReady('process_sync_queue');
} catch (Throwable $integrityError) {
    $componentStatus = (new \App\Services\ComponentSchemaContractService())->status('process_sync_queue');
    $rawInspection = $integrityService->inspect(false);
    $inspectionErrors = array_values(array_filter(
        (array) ($rawInspection['errors'] ?? []),
        static fn (mixed $error): bool => is_array($error)
    ));
    $integrityMismatch = array_filter(
        $inspectionErrors,
        static fn (array $error): bool => !in_array(
            (string) ($error['code'] ?? ''),
            ['migration_pending', 'database_version_mismatch'],
            true
        )
    ) !== [];
    $migrationBlocked = !$integrityMismatch
        && $componentStatus['state'] === 'migration_required';
    $firstIntegrityError = $inspectionErrors[0] ?? [];
    $failureReason = $migrationBlocked
        ? 'migration_pending'
        : (string) ($firstIntegrityError['code'] ?? 'release_integrity_failed');
    $failureComponent = $migrationBlocked
        ? (string) (($componentStatus['missing'][0] ?? '') ?: 'process_sync_queue')
        : (string) ($firstIntegrityError['component'] ?? 'process_sync_queue');
    $safe = \App\Services\SafeErrorPresenter::report(
        $integrityError,
        $migrationBlocked
            ? 'La estructura requerida todavía no está instalada.'
            : 'El ERP se detuvo antes de abrir las colas.',
        [
            'component' => 'process_sync_queue',
            'stage' => 'release_integrity',
            'reason' => $failureReason,
            'failure_component' => $failureComponent,
            'component_state' => (string) $componentStatus['state'],
        ]
    );
    $bootstrapJournal->finish(
        $bootstrapId,
        $migrationBlocked ? 'skipped' : 'error',
        0,
        $migrationBlocked ? 0 : 1,
        false,
        $safe['message'],
        $safe['reference']
    );
    $persistEntry('failed_before_bootstrap', [
        'result' => $migrationBlocked ? 'skipped' : 'error',
        'diagnostic' => $safe['reference'],
        'remote' => false,
    ]);
    fwrite(
        $migrationBlocked ? STDOUT : STDERR,
        ($migrationBlocked ? 'ERP_CRON_SKIP' : 'ERP_CRON_ERROR') . ' component=process_sync_queue'
        . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
        . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
        . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $failureReason)
        . ' failure_component=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $failureComponent)
        . ' component_state=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $componentStatus['state'])
        . ($migrationBlocked ? ' remote=false' : '')
        . PHP_EOL
    );
    exit($migrationBlocked ? 0 : 1);
}
$persistEntry('installation_validated', ['result' => 'running']);
$persistEntry('lock_acquired', ['result' => 'running']);

$emergencyStop = new \App\Services\MeliEmergencyStopService();
$apiEmergencyStop = $emergencyStop->active();

$bootstrapJournal->stage($bootstrapId, 'preparing_queues');
$persistEntry('queues_prepared', ['result' => 'running']);
$settings = new AppSettingsService();
$batchPolicy = new \App\Services\CronBatchPolicyService($settings);
$rhythmPolicy = new \App\Services\ApiRhythmPolicyService($settings);
$capacityPlan = \App\Services\CronCapacityPlan::build($rhythmPolicy, $settings);
$runtimeSeconds = $capacityPlan->runtimeSeconds();
$acceptSeconds = $capacityPlan->acceptSeconds();
CronDeadlineContext::start(
    $runtimeSeconds,
    $acceptSeconds,
    $settings->int('cron.api_timeout_seconds', 8),
    $settings->int('cron.api_connect_timeout_seconds', 3)
);

$health = new CronHealthService();
$health->markInterrupted('process_sync_queue', max(60, $settings->int('cron.running_stale_seconds', 180)));
$run = $health->begin(
    'process_sync_queue',
    $executionSource,
    max(1, $settings->int('cron.main_interval_minutes', 5))
);
$healthId = (int) $run['id'];
$runToken = (string) $run['run_token'];
$capacityPlan->record($runToken);
$workRuns = new \App\Services\WorkQueueRunService();
$workRunId = $workRuns->begin($runToken, $executionSource);
$integrityService->stampRun($healthId, 'process_sync_queue');
job_completion_state(false);
register_shutdown_function(static function () use ($health, $healthId, $bootstrapJournal, $bootstrapId): void {
    if (job_completion_state()) {
        return;
    }
    $fatal = error_get_last();
    try {
        $health->finish(
            $healthId,
            'error',
            ['end_reason' => 'interrupted', 'fatal_type' => $fatal['type'] ?? null],
            'La ejecución terminó sin completar su cierre seguro.',
            1
        );
    } catch (Throwable) {
        // El cierre secundario nunca reemplaza el error original.
    }
    $bootstrapJournal->finish(
        $bootstrapId,
        'error',
        0,
        1,
        false,
        'La ejecución terminó sin completar su cierre seguro.'
    );
});

echo 'ERP_CRON_START run=' . $runToken
    . ' component=process_sync_queue'
    . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
    . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
    . ' source=' . $executionSource
    . ' budget=' . $runtimeSeconds . 's' . PHP_EOL;
job_flush_output();

$bootstrapJournal->stage($bootstrapId, 'processing');
$coordinator = new CronWorkCoordinator($healthId, $runtimeSeconds, $runToken);
$taskState = new CronTaskStateService();
$taskState->recoverAbandoned(max(60, $settings->int('cron.running_stale_seconds', 180)));
(new \App\Services\ExecutionCoordinationService())->releaseAbandonedCli();
$summary = [];

try {
    $guard = new ApiGuardService();
    $guard->closeExpired();
    $globalPause = false;
    foreach ($guard->openCircuits(20) as $circuit) {
        if (($circuit['reason'] ?? '') === 'app_blocked' || ($circuit['scope'] ?? '') === 'app') {
            $globalPause = true;
            break;
        }
    }

    $budget = new ApiBudgetService();
    $priorityBudgetEnabled = $settings->bool('api.cron.priority_budget_enabled', true);
    $guarded = static function (string $jobType, callable $callback) use ($budget, $priorityBudgetEnabled, $globalPause): array {
        if ($globalPause) {
            return [
                'processed' => 0,
                'errors' => 0,
                'status' => 'deferred',
                'stop_reason' => 'api_guard',
                'message' => 'Consultas Mercado Libre aplazadas por protección global.',
            ];
        }
        if ($priorityBudgetEnabled) {
            $gate = $budget->canRunJobType($jobType, null, 1);
            if (empty($gate['allowed'])) {
                return [
                    'processed' => 0,
                    'errors' => 0,
                    'status' => 'deferred',
                    'stop_reason' => 'api_budget',
                    'message' => (string) $gate['message'],
                    'next_safe_at' => $gate['next_safe_at'] ?? null,
                ];
            }
        }
        return $callback();
    };

    $descriptionService = new CatalogDescriptionJobService();
    $callbacks = [
        'operational_maintenance' => static fn(): array =>
            (new OperationalMaintenanceService())->run($batchPolicy->limit('operational_maintenance')),
        'monthly_report_maintenance' => static fn(): array => [
            'processed' => (new \App\Services\MonthlyReportService())->detectPostCloseAdjustments(),
            'errors' => 0,
        ],
        'notification_spool' => static fn(float $deadline): array =>
            (new WebhookSpoolService())->replay(
                $batchPolicy->limit('notification_spool'),
                $deadline
            ),
        'notification_backfill' => static fn(): array =>
            (new NotificationBackfillService())->processDue($batchPolicy->limit('notification_backfill')),
        'notification_fallback' => static fn(float $deadline): array =>
            $guarded('orders_event_sync', static fn(): array => (new NotificationCoalescerService())->processDue(
                $batchPolicy->limit('notification_fallback'),
                $deadline
            )),
        'recurring_sync' => static fn(): array => (new RecurringSyncService())->processDue(),
        'orders_sync' => static fn(): array =>
            $guarded('orders_sync', static fn(): array => (new SyncQueueService())->processDue($batchPolicy->limit('orders_sync'))),
        'order_enrichment' => static fn(): array =>
            $guarded('order_enrichment', static fn(): array => (new OrderEnrichmentService())->processDue(
                $batchPolicy->limit('order_enrichment'),
                CronDeadlineContext::deadline()
            )),
        'sale_pack_reconciliation' => static fn(): array =>
            $guarded('orders_sync', static fn(): array => (new HistoricalPackReconciliationService())->processDue(
                $batchPolicy->limit('sale_pack_reconciliation')
            )),
        'sale_financial_reconciliation' => static fn(): array =>
            $guarded('billing', static fn(): array => (new SaleFinancialService())->processDue(
                $batchPolicy->limit('sale_financial_reconciliation')
            )),
        'order_date_repair' => static fn(): array =>
            (new OrderDateRepairService())->processDue($batchPolicy->limit('order_date_repair')),
        'manual_campaign' => static fn(float $deadline): array =>
            (new ResumableCampaignWorkerService())->run($deadline, $runToken),
        'questions' => static fn(): array =>
            $guarded('questions', static fn(): array => (new QuestionSyncService())->syncNextActive()),
        'financial_recalc' => static fn(): array =>
            $guarded('billing', static fn(): array => (new OrderFinancialRecalcJobService())->processDue($batchPolicy->limit('financial_recalc'))),
        'sales_repair' => static fn(): array =>
            $guarded('sales_audit', static fn(): array => (new SalesRepairService())->processDue($batchPolicy->limit('sales_repair'))),
        'sales_audit' => static fn(): array =>
            $guarded('sales_audit', static fn(): array => (new SalesAuditRunService())->processDue(
                $batchPolicy->limit('sales_audit'),
                CronDeadlineContext::deadline()
            )),
        'sales_fiscal' => static fn(): array =>
            $guarded('billing_info', static fn(): array => (new SalesFiscalPreparationService())->processDue($batchPolicy->limit('sales_fiscal'))),
        'catalog_descriptions' => static fn(): array =>
            $guarded('description_job', static fn(): array => $descriptionService->processDue(
                null,
                $batchPolicy->limit('catalog_descriptions'),
                CronDeadlineContext::deadline()
            )),
        'items_sync' => static fn(): array =>
            $guarded('items_sync', static fn(): array => (new MeliItemSyncJobService())->processDue(
                $batchPolicy->limit('items_sync'),
                CronDeadlineContext::deadline()
            )),
        'module_jobs' => static fn(): array =>
            (new \App\Core\Modules\ModuleKernel())->processDueJobs($batchPolicy->limit('module_jobs'), CronDeadlineContext::deadline()),
    ];

    $definitions = (new \App\Services\CronV3OwnershipService())->filterV2Definitions(
        (new \App\Services\CronTaskDefinitionRegistry())->all()
    );
    if ($apiEmergencyStop) {
        // Solo estas tareas están certificadas como completamente locales.
        // Spool, módulos y conciliaciones se excluyen porque pueden derivar en
        // transporte remoto según el contenido del trabajo.
        $localOnlyKeys = [
            'operational_maintenance',
            'monthly_report_maintenance',
        ];
        $definitions = array_values(array_filter(
            $definitions,
            static fn (array $definition): bool => in_array((string) $definition['key'], $localOnlyKeys, true)
        ));
        $summary['api_emergency_stop'] = true;
    }
    // Compatibilidad histórica: cron.max_api_tasks_per_run ya no limita
    // definiciones. ApiRhythmPolicyService cuenta transportes reales.
    $maxScheduledTasks = $capacityPlan->maxClaims();
    // El presupuesto comienza cuando ya existe el registro de callbacks. El
    // bootstrap y la construcción del catálogo no pueden consumir la reserva
    // dirigida antes de medir la campaña.
    $executionWindow = CronDeadlineContext::window();
    if (!$executionWindow instanceof \App\Services\CronExecutionWindow) {
        throw new RuntimeException('La ventana única de Cron no quedó inicializada.');
    }
    $laneBudgets = \App\Services\CronLaneBudgetService::fromWindow($executionWindow, $settings);
    $planner = new CronExecutionPlanner(
        $definitions,
        $taskState,
        new CronWorkAvailabilityService(),
        $laneBudgets,
        $maxScheduledTasks,
        $settings->bool('cron.backlog_aware_scheduler_enabled', true)
    );
    $candidateCount = 0;
    $notStartedCount = 0;
    $selectedCount = 0;
    $selectedTaskKeys = [];
    $selectionPersisted = false;
    while (($plan = $planner->next($runToken)) !== null) {
        $definition = $plan['definition'];
        $key = $definition['key'];
        $lane = (string) ($definition['lane'] ?? (!empty($definition['api']) ? 'normal' : 'local'));
        $budgetLane = $key === 'notification_spool' ? 'spool' : $lane;
        $candidateCount = $planner->candidateCount();
        $workRuns->candidates($workRunId, $candidateCount);
        if ($plan['state'] !== 'claimed') {
            $notStartedCount++;
            $reason = (string) ($plan['reason'] ?? 'not_started');
            $workRuns->notStarted($workRunId, $key, $reason, $definition, $candidateCount);
            if ($key === 'manual_campaign') {
                $summary[$key] = [
                    'selected' => 0,
                    'started' => 0,
                    'not_started' => 1,
                    'processed' => 0,
                    'completed' => 0,
                    'errors' => 0,
                    'status' => $reason === 'claim_lost' ? 'waiting_lock' : 'waiting_deadline',
                    'stop_reason' => $reason,
                    'campaign_result' => 'not_started',
                    'campaign_reason' => $reason,
                ];
            } else {
                $summary[$key] = [
                    'selected' => 0,
                    'started' => 0,
                    'not_started' => 1,
                    'processed' => 0,
                    'completed' => 0,
                    'errors' => 0,
                    'status' => $reason === 'claim_lost' ? 'waiting_lock' : 'waiting_deadline',
                    'stop_reason' => $reason,
                ];
            }
            continue;
        }

        if (!$selectionPersisted) {
            $persistEntry('work_selected', ['result' => 'running', 'processed' => 0]);
            $selectionPersisted = true;
        }
        $selectedCount++;
        $selectedTaskKeys[] = $key;
        $workRuns->selected($workRunId, $definition, $selectedCount);
        $result = $coordinator->run(
            $key,
            $definition['priority'],
            $callbacks[$key],
            $lane,
            $laneBudgets->deadline($budgetLane),
            (string) ($definition['selection_reason'] ?? '')
        );
        if (max(0, (int) ($result['started'] ?? 0)) > 0) {
            $workRuns->started($workRunId, $key);
        }
        $summary[$key] = $result;
        $taskState->finished($key, $definition['interval'], $result, $runToken);
        $workRuns->result($workRunId, $key, $result, $planner->remeasure($key));
    }
    if (!$selectionPersisted) {
        $persistEntry('work_selected', [
            'result' => $candidateCount > 0 ? 'waiting' : 'empty',
            'processed' => 0,
            'not_started' => $notStartedCount,
        ]);
    }
    // El sondeo global es un checkpoint periódico, no parte obligatoria de
    // cada minuto de ejecución. Entre checkpoints solo persistimos las colas
    // que el planificador realmente observó; así la campaña y el transporte
    // útil no pierden su ventana recorriendo todos los probes.
    $globalAvailabilityCheckpoint = ((int) gmdate('i')) % 5 === 0;
    $availability = $globalAvailabilityCheckpoint
        ? $planner->availabilitySnapshot()
        : $planner->observedAvailabilitySnapshot(true);
    $summary['work_availability'] = $availability;
    $summary['availability_checkpoint'] = $globalAvailabilityCheckpoint ? 'global' : 'incremental';
    (new \App\Services\CronBacklogSnapshotService())->record($runToken, $availability);

    $summary['recurring_enqueued'] = (int) ($summary['recurring_sync']['enqueued'] ?? 0);
    $summary['notification_events_processed'] = (int) ($summary['notification_fallback']['processed'] ?? 0);
    $summary['catalog_description_processed'] = (int) ($summary['catalog_descriptions']['processed'] ?? 0);
    $summary['api_budget'] = $budget->summary(20);
    $summary['api_priority_budget_enabled'] = $priorityBudgetEnabled;
    $summary['skipped_by_api_guard'] = $globalPause;
    $summary['coordinator'] = $coordinator->summary();
    $summary['candidate_tasks'] = $planner->candidateKeys();
    $summary['selected_tasks'] = $planner->claimedKeys();
    $startedTotal = 0;
    $inspectedTotal = 0;
    $deferredTotal = 0;
    $remoteCallTotal = 0;
    $attemptedRemoteCallTotal = 0;
    $blockedRemoteCallTotal = 0;
    $checkpointApprovedTotal = 0;
    $completedTotal = 0;
    $errorTotal = 0;
    foreach (($summary['coordinator']['steps'] ?? []) as $step) {
        if (is_array($step)) {
            $startedTotal += max(0, (int) ($step['started'] ?? 0));
            $inspectedTotal += max(0, (int) ($step['inspected'] ?? 0));
            $deferredTotal += max(0, (int) ($step['deferred'] ?? 0));
            $remoteCallTotal += max(0, (int) ($step['remote_calls'] ?? 0));
            $attemptedRemoteCallTotal += max(0, (int) ($step['attempted_remote_calls'] ?? 0));
            $blockedRemoteCallTotal += max(0, (int) ($step['blocked_remote_calls'] ?? 0));
            $checkpointApprovedTotal += max(0, (int) ($step['checkpoint_approved'] ?? 0));
            $completedTotal += max(0, (int) ($step['completed'] ?? 0));
            $errorTotal += max(0, (int) ($step['errors'] ?? 0));
        }
    }
    $summary['candidates'] = $candidateCount;
    $summary['selected'] = $selectedCount;
    $summary['started'] = $startedTotal;
    $summary['inspected'] = $inspectedTotal;
    $summary['deferred'] = $deferredTotal;
    $summary['remote_calls'] = $remoteCallTotal;
    $summary['attempted_remote_calls'] = $attemptedRemoteCallTotal;
    $summary['blocked_remote_calls'] = $blockedRemoteCallTotal;
    $summary['checkpoint_approved'] = $checkpointApprovedTotal;
    $summary['completed'] = $completedTotal;
    $summary['not_started'] = $notStartedCount;
    $summary['processed'] = $completedTotal;
    $summary['errors'] = $errorTotal;
    $knownQueues = 0;
    $actionableWork = 0;
    foreach ($summary['work_availability'] as $availability) {
        if (!empty($availability['known'])) {
            $knownQueues++;
            $actionableWork += max(0, (int) ($availability['work_count'] ?? 0));
        }
    }
    $stepStatuses = array_values(array_map(
        static fn (array $step): string => (string) ($step['status'] ?? ''),
        array_filter((array) ($summary['coordinator']['steps'] ?? []), 'is_array')
    ));
    if (!CronDeadlineContext::canAcceptWork()) {
        $summary['end_reason'] = 'deadline_reached';
    } elseif ($errorTotal > 0) {
        $summary['end_reason'] = 'persistent_errors';
    } elseif ($globalPause) {
        $summary['end_reason'] = 'api_cooldown';
    } elseif ($completedTotal > 0) {
        $summary['end_reason'] = 'work_completed';
    } elseif (in_array('waiting_budget', $stepStatuses, true)) {
        $summary['end_reason'] = 'budget_exhausted';
    } elseif (in_array('waiting_guard', $stepStatuses, true)) {
        $summary['end_reason'] = 'api_cooldown';
    } elseif (in_array('waiting_lock', $stepStatuses, true)) {
        $summary['end_reason'] = 'work_locked';
    } elseif ($actionableWork > 0) {
        $summary['end_reason'] = 'eligible_work_not_selected';
    } else {
        $summary['end_reason'] = $knownQueues > 0 ? 'no_due_work' : 'queue_empty';
    }
    if ($summary['end_reason'] === 'deadline_reached' && is_array($summary['manual_campaign'] ?? null)) {
        $manualCampaignSummary = $summary['manual_campaign'];
        if ((int) ($manualCampaignSummary['approved'] ?? 0) > 0) {
            $summary['end_reason'] = 'deadline_reached_after_progress';
        } elseif ((int) ($manualCampaignSummary['deferred'] ?? 0) > 0) {
            $summary['end_reason'] = 'deadline_reached_after_deferred';
        } elseif ((string) ($manualCampaignSummary['campaign_reason'] ?? '') === 'deadline_too_short') {
            $summary['end_reason'] = 'deadline_too_short';
        }
    }
    (new \App\Services\OperationalSnapshotService())->recordCron(
        $runToken,
        $summary,
        is_array($summary['work_availability'] ?? null) ? $summary['work_availability'] : []
    );
    $workRuns->finish($workRunId, $summary);
    $health->finish(
        $healthId,
        $errorTotal > 0 ? 'partial' : 'success',
        $summary,
        $errorTotal > 0 ? 'La ejecución terminó con asuntos aislados por revisar.' : null,
        0
    );
    $bootstrapJournal->finish(
        $bootstrapId,
        $errorTotal > 0 && $completedTotal > 0 ? 'partial' : ($errorTotal > 0 ? 'error' : 'success'),
        $completedTotal,
        $errorTotal,
        false,
        $errorTotal > 0 ? 'La ejecución terminó con asuntos por revisar.' : 'La ejecución cerró correctamente.'
    );
    $persistEntry('finished', [
        'result' => $errorTotal > 0 ? 'partial' : 'success',
        'processed' => $completedTotal,
        'remote' => (int) ($summary['coordinator']['remote_calls'] ?? 0) > 0,
    ]);
    job_completion_state(true);
    if ($fullJsonOutput) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
    $manualCampaign = is_array($summary['manual_campaign'] ?? null) ? $summary['manual_campaign'] : [];
    $selectedKeys = array_values(array_filter(array_map('strval', $summary['selected_tasks'] ?? [])));
    $cronLineExtra = '';
    if ($selectedKeys !== []) {
        $cronLineExtra .= ' selected_tasks=' . preg_replace('/[^A-Za-z0-9_,.-]/', '_', implode(',', $selectedKeys));
    }
    if ($manualCampaign !== []) {
        $cronLineExtra .= ' campaign_id=' . max(0, (int) ($manualCampaign['campaign_id'] ?? 0));
        $cronLineExtra .= ' campaign_result=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($manualCampaign['campaign_result'] ?? $manualCampaign['status'] ?? 'unknown'));
        $cronLineExtra .= ' campaign_reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($manualCampaign['campaign_reason'] ?? $manualCampaign['stop_reason'] ?? 'unknown'));
        if (!empty($manualCampaign['campaign_next_at'])) {
            $cronLineExtra .= ' campaign_next_at=' . preg_replace('/[^A-Za-z0-9:T+_.-]/', '_', (string) $manualCampaign['campaign_next_at']);
        }
        $cronLineExtra .= ' inspected=' . max(0, (int) ($manualCampaign['inspected'] ?? 0));
        $cronLineExtra .= ' deferred=' . max(0, (int) ($manualCampaign['deferred'] ?? 0));
        $cronLineExtra .= ' approved=' . max(0, (int) ($manualCampaign['approved'] ?? 0));
    }
    $cronLineExtra .= ' selected=' . $selectedCount;
    $cronLineExtra .= ' started=' . $startedTotal;
    $cronLineExtra .= ' inspected=' . $inspectedTotal;
    $cronLineExtra .= ' deferred=' . $deferredTotal;
    $cronLineExtra .= ' attempted_remote_calls=' . $attemptedRemoteCallTotal;
    $cronLineExtra .= ' remote_calls=' . $remoteCallTotal;
    $cronLineExtra .= ' blocked_remote_calls=' . $blockedRemoteCallTotal;
    $cronLineExtra .= ' checkpoint_approved=' . $checkpointApprovedTotal;
    $cronLineExtra .= ' completed=' . $completedTotal;
    $cronLineExtra .= ' not_started=' . $notStartedCount;
    echo 'ERP_CRON_OK'
        . ' run=' . $runToken
        . ' component=process_sync_queue'
        . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
        . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
        . ' duration_ms=' . (int) ($summary['coordinator']['used_ms'] ?? 0)
        . ' tasks=' . count($summary['coordinator']['steps'] ?? [])
        . ' processed=' . $completedTotal
        . ' queue=' . ($actionableWork > 0 ? 'pending' : 'empty')
        . ' errors=' . $errorTotal
        . ' attention=' . ($errorTotal > 0 ? 'required' : 'none')
        . ' reason=' . $summary['end_reason']
        . $cronLineExtra . PHP_EOL;
    job_flush_output();

    // El resultado comercial ya quedó aprobado y visible. La proyección es
    // secundaria y se actualiza una cola por ciclo únicamente si queda margen.
    if (CronDeadlineContext::remainingSeconds() >= 5.0) {
        try {
            (new \App\Services\WorkQueueProjectionService())->refreshNextQueue();
        } catch (Throwable) {
            // Una proyección atrasada nunca invalida el trabajo ya completado.
        }
    }
    exit(0);
} catch (Throwable $error) {
    $safe = SafeErrorPresenter::report(
        $error,
        'El coordinador del cron se detuvo de forma segura.',
        ['job' => 'process_sync_queue']
    );
    $summary['coordinator'] = $coordinator->summary();
    $summary['diagnostic_id'] = $safe['reference'];
    $summary['end_reason'] = 'error';
    $health->finish($healthId, 'error', $summary, $safe['message'], 1);
    $bootstrapJournal->finish(
        $bootstrapId,
        'error',
        (int) ($summary['processed'] ?? 0),
        1,
        false,
        $safe['message'],
        $safe['reference']
    );
    $persistEntry('finished', [
        'result' => 'error',
        'processed' => (int) ($summary['processed'] ?? 0),
        'remote' => false,
        'diagnostic' => $safe['reference'],
    ]);
    job_completion_state(true);
    fwrite(
        STDERR,
        'ERP_CRON_ERROR run=' . $runToken
        . ' component=process_sync_queue'
        . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
        . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeBuild)
        . ' diagnostic=' . $safe['reference']
        . ' message=' . $safe['message'] . PHP_EOL
    );
    exit(1);
}
