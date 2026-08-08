<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/erp-technical-retention-' . bin2hex(random_bytes(5));
$private = $temporary . '/private';
$shared = $temporary . '/shared';
mkdir($private, 0700, true);
mkdir($shared . '/storage', 0700, true);
define('ERP_SHARED_ROOT', $shared);
define('ERP_RELEASE_ROOT', $root);
putenv('APP_KEY=technical-retention-test-key-which-is-long-enough');
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('ML_WRITE_ENABLED=false');
putenv('PAUSE_MELI_API=true');
require $root . '/vendor/autoload.php';

$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_technical_retention_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    $old = '2025-01-10 12:00:00';

    $pdo->exec(
        "INSERT INTO system_cron_run_steps
         (run_token,step_name,status,processed_count,finished_at,created_at)
         VALUES ('technical-test-run','orders','completed',1,'{$old}','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO system_process_metrics
         (process_type,status,processed_count,measured_at,created_at)
         VALUES ('technical-test','completed',1,'{$old}','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO system_work_queue_runs
         (run_token,origin,status,selected_count,completed_count,started_at,finished_at,created_at)
         VALUES ('technical-test-queue','cron','completed',1,1,'{$old}','{$old}','{$old}')"
    );
    $runId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO system_work_queue_run_items
         (work_queue_run_id,queue_key,source_table,source_id,result,created_at,updated_at)
         VALUES ({$runId},'local_test','system_logs','1','completed','{$old}','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO api_budget_windows
         (scope,scope_key,window_started_at,window_seconds,request_limit,request_count,
          created_at,updated_at)
         VALUES ('global','technical-test','{$old}',60,10,1,'{$old}','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO manual_engine_probe_runs
         (run_token,status,php_version,sapi,completed_at,started_at)
         VALUES ('technical-test-probe','passed','8.3','cli','{$old}','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO system_performance_metrics
         (source,metric_name,metric_value,recorded_at)
         VALUES ('server','route.duration_ms',12.5,'{$old}')"
    );
    $pdo->exec(
        "INSERT INTO system_logs (level,message,created_at)
         VALUES ('info','Prueba técnica sin secretos','{$old}')"
    );
    $pdo->exec(
        "INSERT INTO api_operation_metric_samples
         (bucket_started_at,operation_key,load_class,duration_ms,http_status,
          reached_remote,successful,created_at)
         VALUES ('{$old}','local_test','light',20,200,0,1,'{$old}')"
    );
    $pdo->exec(
        "INSERT INTO meli_webhook_events
         (event_hash,topic,resource,status,raw_json,received_at,processed_at)
         VALUES (REPEAT('a',64),'orders_v2','/orders/local','processed',
                 '{\"local\":true}','{$old}','{$old}')"
    );

    $retention = new App\Services\RetentionPolicyService();
    $technical = [
        'cron_run_steps',
        'process_metrics',
        'work_queue_items',
        'work_queue_runs',
        'api_budget_windows',
        'manual_probe_runs',
        'performance_metrics',
        'system_logs',
        'api_operation_samples',
        'webhook_events',
    ];
    foreach ($technical as $dataset) {
        for ($step = 0; $step < 20; $step++) {
            $result = $retention->runDatasetStep($dataset, 100);
            if ($result['complete']) {
                break;
            }
        }
        if (empty($result['complete'])) {
            throw new RuntimeException("La retención de {$dataset} no terminó.");
        }
    }

    foreach ([
        'system_cron_run_steps',
        'system_process_metrics',
        'system_work_queue_run_items',
        'system_work_queue_runs',
        'api_budget_windows',
        'manual_engine_probe_runs',
        'system_performance_metrics',
        'system_logs',
        'api_operation_metric_samples',
        'meli_webhook_events',
    ] as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 0) {
            throw new RuntimeException("El conjunto terminal {$table} no se retiró.");
        }
    }
    $verified = (int) $pdo->query(
        'SELECT COUNT(*) FROM system_cold_archives
         WHERE dataset_key IN (
            "cron_run_steps","process_metrics","work_queue_items","work_queue_runs",
            "api_budget_windows","manual_probe_runs","performance_metrics","system_logs",
            "api_operation_samples","webhook_events"
         )
           AND status="ready" AND verified_at IS NOT NULL
           AND membership_verified_at IS NOT NULL AND rollup_verified_at IS NOT NULL'
    )->fetchColumn();
    if ($verified !== count($technical)) {
        throw new RuntimeException('No todos los archivos técnicos quedaron verificados por fila.');
    }
    $preservedMemberships = (int) $pdo->query(
        'SELECT COUNT(*) FROM system_cold_archive_memberships
         WHERE source_deleted_at IS NOT NULL AND stale_at IS NULL'
    )->fetchColumn();
    if ($preservedMemberships !== count($technical)) {
        throw new RuntimeException(
            'La evidencia exacta de las filas retiradas no permaneció junto al archivo.'
        );
    }

    // Si la fuente cambia después del archivo, su huella deja de coincidir. La
    // retención debe conservarla y marcar el archivo como obsoleto para esa
    // fila; nunca puede borrar la versión nueva usando evidencia anterior.
    $changedAt = '2024-02-10 12:00:00';
    $pdo->exec(
        "INSERT INTO system_logs (level,message,created_at)
         VALUES ('info','Versión archivada','{$changedAt}')"
    );
    $changedId = (int) $pdo->lastInsertId();
    $archive = (new App\Services\ColdArchiveService())->create('system_logs', '2024-02');
    $retention->rollupMonth('system_logs', '2024-02');
    $pdo->prepare(
        'UPDATE system_cold_archives
         SET rollup_verified_at=UTC_TIMESTAMP(3)
         WHERE id=:id'
    )->execute(['id' => (int) $archive['id']]);
    $pdo->exec(
        "UPDATE system_logs SET message='Versión nueva'
         WHERE id={$changedId}"
    );
    if ($retention->deleteEligible('system_logs', 100) !== 0) {
        throw new RuntimeException('Se borró una fila modificada después del archivo.');
    }
    if (
        (int) $pdo->query("SELECT COUNT(*) FROM system_logs WHERE id={$changedId}")
            ->fetchColumn() !== 1
    ) {
        throw new RuntimeException('La versión nueva de la fuente no se conservó.');
    }
    $staleMembership = (int) $pdo->query(
        "SELECT COUNT(*) FROM system_cold_archive_memberships
         WHERE source_table='system_logs' AND source_id={$changedId}
           AND stale_at IS NOT NULL
           AND verification_error='source_changed_after_archive'"
    )->fetchColumn();
    if ($staleMembership !== 1) {
        throw new RuntimeException('La membresía obsoleta no quedó marcada para rearchivo.');
    }

    $storage = (string) $pdo->query(
        "SELECT storage_name FROM system_cold_archives
         WHERE id=" . (int) $archive['id']
    )->fetchColumn();
    $reverified = (new App\Services\ColdArchiveService())->verifyFile(
        App\Core\AppPaths::coldArchives() . '/' . basename($storage),
        'system_logs',
        '2024-02'
    );
    if ((int) $reverified['rows'] !== 1) {
        throw new RuntimeException('El archivo no pudo volver a verificarse tras la purga.');
    }

    // Una fila antigua creada después de cerrar el archivo mensual no tiene
    // membresía. Aunque sea terminal y supere la retención, no se puede borrar.
    $pdo->exec(
        "INSERT INTO system_logs (level,message,created_at)
         VALUES ('info','Fila posterior al archivo','{$old}')"
    );
    $lateId = (int) $pdo->lastInsertId();
    if ($retention->deleteEligible('system_logs', 100) !== 0) {
        throw new RuntimeException('Se borró una fila que no pertenecía al archivo verificado.');
    }
    if (
        (int) $pdo->query("SELECT COUNT(*) FROM system_logs WHERE id={$lateId}")
            ->fetchColumn() !== 1
    ) {
        throw new RuntimeException('La fila sin membresía no se conservó.');
    }

    $pdo->exec(
        "INSERT INTO system_technical_daily_rollups
         (rollup_date,dataset_key,dimension_key,outcome_class,records)
         VALUES ('2024-01-01','system_logs','info','info',1)"
    );
    $summaryDeleted = 0;
    for ($step = 0; $step < 20; $step++) {
        $summary = $retention->purgeSummaryStep(100);
        $summaryDeleted += (int) $summary['deleted'];
        if (
            (int) $pdo->query(
                "SELECT COUNT(*) FROM system_technical_daily_rollups
                 WHERE rollup_date='2024-01-01' AND dataset_key='system_logs'"
            )->fetchColumn() === 0
        ) {
            break;
        }
    }
    if ($summaryDeleted < 1) {
        throw new RuntimeException('La purga visible no retiró el resumen vencido.');
    }
    do {
        $summaryDone = $retention->purgeSummaryStep(100);
    } while (!$summaryDone['complete']);
    if (!$summaryDone['complete'] || $summaryDone['deleted'] !== 0) {
        throw new RuntimeException('La purga visible de resúmenes no es idempotente.');
    }

    echo 'technical_retention_2263_ok datasets=10 source_deleted_memberships='
        . $preservedMemberships
        . ' changed_retained=1 stale_memberships=' . $staleMembership
        . ' reverified_rows=' . (int) $reverified['rows']
        . ' summaries=1' . PHP_EOL;
} finally {
    App\Core\Database::setConnection($admin);
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
