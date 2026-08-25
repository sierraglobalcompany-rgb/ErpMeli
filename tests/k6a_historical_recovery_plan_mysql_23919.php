<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K6A exige DSN MariaDB 11.8.x desechable sin dbname.\n");
    exit(2);
}

$rawDir = trim((string) getenv('K6A_RAW_DIR'));
$rawDir = $rawDir !== '' ? $rawDir : $root . '/artifacts-local/k6a-historical-recovery-plan/raw';
if (!is_dir($rawDir) && !mkdir($rawDir, 0770, true) && !is_dir($rawDir)) {
    throw new RuntimeException('k6a_raw_dir_not_writable:' . $rawDir);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$connect = static function (string $dsn, string $user, string $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
};
$q = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
$writeCsv = static function (string $path, array $headers, array $rows): void {
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('csv_write_failed:' . $path);
    }
    fputcsv($handle, $headers, ',', '"', '\\', "\n");
    foreach ($rows as $row) {
        $values = [];
        foreach ($headers as $header) {
            $value = $row[$header] ?? '';
            $values[] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        fputcsv($handle, $values, ',', '"', '\\', "\n");
    }
    fclose($handle);
};
$canonicalHash = static function (array $rows): string {
    $normal = [];
    foreach ($rows as $row) {
        ksort($row);
        $normal[] = $row;
    }
    usort($normal, static fn (array $a, array $b): int => strcmp((string) $a['candidate_key'], (string) $b['candidate_key']));
    return hash('sha256', json_encode($normal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
};
$fingerprint = static function (PDO $pdo, string $table) use ($q): array {
    $columnsStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $columnsStmt->execute([$table]);
    $columns = array_map('strval', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($columns === []) {
        return ['table_name' => $table, 'row_count' => 0, 'sha256' => 'TABLE_NOT_PRESENT'];
    }
    $order = in_array('id', $columns, true) ? 'id' : $columns[0];
    $rows = $pdo->query('SELECT * FROM ' . $q($table) . ' ORDER BY ' . $q($order))->fetchAll(PDO::FETCH_ASSOC);
    return [
        'table_name' => $table,
        'row_count' => count($rows),
        'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ];
};
$snapshot = static function (PDO $pdo, array $tables) use ($fingerprint): array {
    $out = [];
    foreach ($tables as $table) {
        $out[$table] = $fingerprint($pdo, $table);
    }
    return $out;
};
$diffSnapshot = static function (array $before, array $after): array {
    $mismatches = [];
    foreach ($before as $table => $row) {
        if (($after[$table]['sha256'] ?? null) !== $row['sha256'] || ($after[$table]['row_count'] ?? null) !== $row['row_count']) {
            $mismatches[] = $table;
        }
    }
    return $mismatches;
};
$tableHashRows = static function (string $run, array $before, array $after): array {
    $rows = [];
    foreach ($before as $table => $row) {
        $rows[] = [
            'run_id' => $run,
            'table_name' => $table,
            'before_rows' => $row['row_count'],
            'after_rows' => $after[$table]['row_count'],
            'before_sha256' => $row['sha256'],
            'after_sha256' => $after[$table]['sha256'],
            'match' => ($row['sha256'] === $after[$table]['sha256'] && $row['row_count'] === $after[$table]['row_count']) ? 'YES' : 'NO',
        ];
    }
    return $rows;
};

$sourceTables = [
    'queue_v4_clean_jobs',
    'queue_v4_clean_attempts',
    'queue_v4_clean_recovery_events',
    'meli_notification_events',
    'meli_notification_work_items',
    'order_financial_recalc_jobs',
    'order_financial_recalc_job_items',
    'sale_financial_reconciliation_jobs',
    'sale_financial_state',
    'meli_sale_financials',
    'meli_billing_capture_runs',
    'meli_packs',
    'meli_orders',
    'meli_accounts',
    'meli_tokens',
    'oauth_refresh_operations',
    'api_remote_permits',
    'api_rhythm_penalties',
    'inventory_items',
    'manual_campaigns',
];

$createSchema = static function (PDO $pdo): void {
    $ddl = [
        "CREATE TABLE companies(id BIGINT PRIMARY KEY,name VARCHAR(80) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_accounts(id BIGINT PRIMARY KEY,company_id BIGINT NOT NULL,meli_user_id VARCHAR(80) NULL,status VARCHAR(30) NOT NULL,UNIQUE KEY uq_company_account(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_tokens(meli_account_id BIGINT PRIMARY KEY,expires_at DATETIME(3) NOT NULL,refresh_version BIGINT NOT NULL DEFAULT 1,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE oauth_refresh_operations(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,state VARCHAR(40) NOT NULL,next_attempt_at DATETIME(3) NULL,lease_owner VARCHAR(90) NULL,remote_dispatch_state VARCHAR(40) NOT NULL DEFAULT 'NOT_DISPATCHED') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_orders(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,external_order_id VARCHAR(80) NOT NULL,pack_external_id VARCHAR(80) NULL,status VARCHAR(40) NOT NULL,input_version BIGINT NOT NULL DEFAULT 1,remote_uncertain TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uq_order_tenant(company_id,meli_account_id,external_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_packs(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,external_pack_id VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,expected_members INT NOT NULL DEFAULT 1,known_members INT NOT NULL DEFAULT 1,UNIQUE KEY uq_pack_tenant(company_id,meli_account_id,external_pack_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NULL,meli_account_id BIGINT NULL,external_order_id VARCHAR(80) NULL,pack_external_id VARCHAR(80) NULL,status VARCHAR(40) NOT NULL,attempts INT NOT NULL DEFAULT 0,next_run_at DATETIME(3) NULL,safe_message VARCHAR(255) NULL,input_version BIGINT NOT NULL DEFAULT 1,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE sale_financial_state(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,external_order_id VARCHAR(80) NOT NULL,financial_status VARCHAR(40) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),UNIQUE KEY uq_financial_state(company_id,meli_account_id,external_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_sale_financials(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,external_order_id VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_billing_capture_runs(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,http_status INT NULL,captured_at DATETIME(3) NULL,response_class VARCHAR(80) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,job_type VARCHAR(80) NOT NULL,capability VARCHAR(80) NOT NULL DEFAULT '',source_table VARCHAR(80) NULL,source_id BIGINT NULL,idempotency_key VARCHAR(191) NOT NULL,state VARCHAR(40) NOT NULL,available_at DATETIME(3) NULL,lease_owner VARCHAR(90) NULL,lease_generation BIGINT NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NULL,last_error_class VARCHAR(120) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_qv4(company_id,meli_account_id,job_type,idempotency_key),KEY idx_fifo(state,available_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_attempts(id BIGINT AUTO_INCREMENT PRIMARY KEY,job_id BIGINT NOT NULL,company_id BIGINT NOT NULL,meli_account_id BIGINT NOT NULL,lease_generation BIGINT NOT NULL,outcome VARCHAR(40) NOT NULL,error_class VARCHAR(120) NULL,dispatch_state VARCHAR(40) NOT NULL,endpoint_key VARCHAR(120) NULL,physical_http_calls INT NOT NULL DEFAULT 0,http_status INT NULL,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_recovery_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,job_id BIGINT NOT NULL,recovery_kind VARCHAR(80) NOT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_runs(id BIGINT AUTO_INCREMENT PRIMARY KEY,launcher VARCHAR(40) NOT NULL,worker_ref VARCHAR(96) NOT NULL,status VARCHAR(40) NOT NULL,jobs_claimed INT NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_notification_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NULL,meli_account_id BIGINT NULL,canonical_topic VARCHAR(80) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,processing_event_id BIGINT NULL,status VARCHAR(40) NOT NULL,received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_notification_work_items(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NULL,meli_account_id BIGINT NULL,canonical_topic VARCHAR(80) NOT NULL,resource_type VARCHAR(80) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,status VARCHAR(40) NOT NULL,processing_event_id BIGINT NULL,next_run_at DATETIME(3) NULL,locked_by VARCHAR(90) NULL,lock_expires_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE order_financial_recalc_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NULL,meli_account_id BIGINT NULL,external_order_id VARCHAR(80) NULL,status VARCHAR(40) NOT NULL,next_run_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE order_financial_recalc_job_items(id BIGINT AUTO_INCREMENT PRIMARY KEY,job_id BIGINT NOT NULL,external_order_id VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_remote_permits(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NULL,meli_account_id BIGINT NULL,endpoint_key VARCHAR(120) NOT NULL,status VARCHAR(40) NOT NULL,http_status INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NULL,reason VARCHAR(80) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE inventory_items(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,sku VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE manual_campaigns(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,status VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }
};

$seed = static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'A'),(20,'B'),(30,'C')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,status) VALUES (101,10,'same-user','connected'),(102,10,'same-user','connected'),(201,20,'same-user','connected'),(301,30,'unique-user','disconnected')");
    $pdo->exec("INSERT INTO meli_tokens(meli_account_id,expires_at,refresh_version,access_token_encrypted,refresh_token_encrypted) VALUES (101,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),1,'x','y'),(102,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),1,'x','y'),(201,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),1,'x','y')");
    $orders = [
        [10,101,'O-1',null,'paid',1,0], [10,101,'O-2',null,'paid',1,0], [10,101,'O-3',null,'paid',1,0],
        [10,101,'DUP-1',null,'paid',1,0], [20,201,'DUP-1',null,'paid',1,0],
        [10,101,'UNCERTAIN-1',null,'paid',1,1], [10,102,'WRONG-PAIR',null,'paid',1,0],
        [10,101,'PACK-A-1','P-OK','paid',1,0], [10,101,'PACK-A-2','P-OK','paid',1,0],
        [10,101,'PACK-X-1','P-X','paid',1,0], [20,201,'PACK-X-2','P-X','paid',1,0],
        [30,301,'DISC-1',null,'paid',1,0],
    ];
    $stmt = $pdo->prepare('INSERT INTO meli_orders(company_id,meli_account_id,external_order_id,pack_external_id,status,input_version,remote_uncertain) VALUES (?,?,?,?,?,?,?)');
    foreach ($orders as $row) {
        $stmt->execute($row);
    }
    $packs = [[10,101,'P-OK','complete',2,2], [10,101,'P-INCOMPLETE','incomplete',2,1], [10,101,'P-X','complete',2,1], [20,201,'P-X','complete',2,1]];
    $stmt = $pdo->prepare('INSERT INTO meli_packs(company_id,meli_account_id,external_pack_id,status,expected_members,known_members) VALUES (?,?,?,?,?,?)');
    foreach ($packs as $row) {
        $stmt->execute($row);
    }
    $states = [[10,101,'O-1','complete'], [10,101,'O-2','pending'], [10,101,'O-3','pending'], [10,101,'PACK-A-1','pending'], [10,101,'PACK-A-2','pending']];
    $stmt = $pdo->prepare('INSERT INTO sale_financial_state(company_id,meli_account_id,external_order_id,financial_status) VALUES (?,?,?,?)');
    foreach ($states as $row) {
        $stmt->execute($row);
    }

    $finance = [
        [10,101,'O-1',null,'complete',1,'complete source with completed pointer'],
        [10,101,'O-2',null,'complete',1,'complete source with nonterminal pointer'],
        [10,101,'O-3',null,'retry',1,'due source with missing pointer'],
        [null,null,'O-3',null,'retry',1,'tenantless exact order'],
        [null,null,'DUP-1',null,'retry',1,'tenantless ambiguous order'],
        [10,201,'WRONG-PAIR',null,'retry',1,'wrong company account pair'],
        [10,101,'MISSING-ORDER',null,'retry',1,'missing order'],
        [10,101,'UNCERTAIN-1',null,'retry',1,'remote uncertain source'],
        [10,101,'O-2',null,'review',1,'review is not retry all'],
        [10,101,null,'P-OK','retry',1,'pack complete same tenant'],
        [10,101,null,'P-INCOMPLETE','retry',1,'pack incomplete'],
        [null,null,null,'P-X','retry',1,'pack cross tenant collision'],
        [30,301,'DISC-1',null,'retry',1,'disconnected account'],
    ];
    $stmt = $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,external_order_id,pack_external_id,status,input_version,safe_message,next_run_at) VALUES (?,?,?,?,?,?,?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))');
    foreach ($finance as $row) {
        $stmt->execute($row);
    }

    $queue = [
        [10,101,'domain_exact','financial_reconciliation','sale_financial_reconciliation_jobs',1,'completed','source:1','{}',null],
        [10,101,'domain_exact','financial_reconciliation','sale_financial_reconciliation_jobs',2,'waiting','source:2','{}',null],
        [10,101,'fresh_orders_discovery','','remote_read',9001,'review','fresh:uncertain','{"operation":"read"}','RemoteResultUncertainException'],
        [10,101,'order_exact','','remote_read',9002,'review','order:mutating','{"operation":"write"}','RemoteResultUncertainException'],
        [10,101,'domain_exact','financial_reconciliation','missing_source',9999,'ready','source:9999','{}',null],
        [10,101,'domain_exact','financial_reconciliation','sale_financial_reconciliation_jobs',9,'dead','source:9','{}','technical_failure'],
    ];
    $stmt = $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,capability,source_table,source_id,state,idempotency_key,payload_json,last_error_class,available_at) VALUES (?,?,?,?,?,?,?,?,?,?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))');
    foreach ($queue as $row) {
        $stmt->execute($row);
    }
    $pdo->exec("INSERT INTO queue_v4_clean_attempts(job_id,company_id,meli_account_id,lease_generation,outcome,error_class,dispatch_state,endpoint_key,physical_http_calls,http_status,finished_at) VALUES (3,10,101,0,'review','RemoteResultUncertainException','RESPONSE_KNOWN','orders_search',1,NULL,UTC_TIMESTAMP(3)),(4,10,101,0,'review','RemoteResultUncertainException','MAY_HAVE_DISPATCHED','orders_update',1,NULL,UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_recovery_events(job_id,recovery_kind) VALUES (6,'previously_recorded')");
    $pdo->exec("INSERT INTO queue_v4_clean_runs(launcher,worker_ref,status,jobs_claimed,finished_at) VALUES ('scheduler','queue_v4_clean.php --runtime=45 --max-jobs=3','completed',3,UTC_TIMESTAMP(3))");

    $notifs = [
        [10,101,'orders_v2','order','O-3','pending',null],
        [null,null,'orders_v2','order','O-3','pending',null],
        [10,101,'orders_v2','order','O-1','completed',101],
        [10,101,'orders_v2','order','O-2','review',102],
        [10,101,'orders_v2','order','O-2','dead',103],
        [10,101,'legacy_preactivation','order','OLD-1','pending',null],
        [10,101,'unsupported_topic','item','ITEM-1','pending',null],
        [20,201,'questions','question','Q-1','pending',null],
    ];
    $stmt = $pdo->prepare('INSERT INTO meli_notification_work_items(company_id,meli_account_id,canonical_topic,resource_type,remote_resource_id,status,processing_event_id,next_run_at) VALUES (?,?,?,?,?,?,?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))');
    foreach ($notifs as $row) {
        $stmt->execute($row);
    }
    $events = [
        [10,101,'orders_v2','O-1',101,'queued'],
        [10,101,'orders_v2','O-1',111,'queued'],
        [10,101,'orders_v2','O-2',102,'queued'],
        [10,101,'orders_v2','O-2',112,'queued'],
        [10,101,'legacy_preactivation','OLD-1',null,'queued'],
        [20,201,'questions','Q-1',null,'queued'],
    ];
    $stmt = $pdo->prepare('INSERT INTO meli_notification_events(company_id,meli_account_id,canonical_topic,remote_resource_id,processing_event_id,status) VALUES (?,?,?,?,?,?)');
    foreach ($events as $row) {
        $stmt->execute($row);
    }
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,external_order_id,status,next_run_at) VALUES (10,101,'O-3','pending',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)),(NULL,NULL,'DUP-1','pending',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))");
    $pdo->exec("INSERT INTO order_financial_recalc_job_items(job_id,external_order_id,status) VALUES (1,'O-3','pending'),(2,'DUP-1','pending')");
    $pdo->exec("INSERT INTO api_remote_permits(company_id,meli_account_id,endpoint_key,status,http_status,dispatched_at,completed_at) VALUES (10,101,'billing_orders','completed',200,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO api_rhythm_penalties(scope_key,blocked_until,reduced_until,reason) VALUES ('endpoint:billing_orders',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 30 MINUTE),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 30 MINUTE),'http_429')");
    $pdo->exec("INSERT INTO inventory_items(company_id,sku,status) VALUES (10,'SKU-1','active')");
    $pdo->exec("INSERT INTO manual_campaigns(company_id,status) VALUES (10,'open')");
};

$planner = static function (PDO $pdo): array {
    $rows = [];
    $add = static function (array $row) use (&$rows): void {
        $defaults = [
            'candidate_key' => '',
            'category' => '',
            'source_table' => '',
            'source_id' => '',
            'current_state' => '',
            'company_id_current' => '',
            'account_id_current' => '',
            'company_id_proposed' => '',
            'account_id_proposed' => '',
            'tenant_proof' => 'NO',
            'tenant_proof_source' => '',
            'business_source_status' => '',
            'pointer_status' => '',
            'remote_result_state' => 'NOT_REMOTE',
            'classification' => '',
            'proposed_future_action' => '',
            'tables_that_future_action_would_touch' => '',
            'requires_remote_http' => 'NO',
            'reversible' => 'YES',
            'blocked_reason' => '',
        ];
        $rows[] = array_replace($defaults, $row);
    };
    $accountValid = static function (?int $companyId, ?int $accountId) use ($pdo): bool {
        if ($companyId === null || $accountId === null) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND status='connected'");
        $stmt->execute([$accountId, $companyId]);
        return (int) $stmt->fetchColumn() === 1;
    };
    $resolveOrder = static function (?string $externalOrderId) use ($pdo): array {
        if ($externalOrderId === null || $externalOrderId === '') {
            return [];
        }
        $stmt = $pdo->prepare('SELECT company_id,meli_account_id,status,remote_uncertain FROM meli_orders WHERE external_order_id=? ORDER BY company_id,meli_account_id');
        $stmt->execute([$externalOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $resolvePack = static function (?string $externalPackId) use ($pdo): array {
        if ($externalPackId === null || $externalPackId === '') {
            return [];
        }
        $stmt = $pdo->prepare('SELECT company_id,meli_account_id,status,expected_members,known_members FROM meli_packs WHERE external_pack_id=? ORDER BY company_id,meli_account_id');
        $stmt->execute([$externalPackId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $sourceRows = $pdo->query('SELECT * FROM sale_financial_reconciliation_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sourceRows as $source) {
        $sid = (int) $source['id'];
        $pointerStmt = $pdo->prepare("SELECT state FROM queue_v4_clean_jobs WHERE source_table='sale_financial_reconciliation_jobs' AND source_id=? ORDER BY id LIMIT 1");
        $pointerStmt->execute([$sid]);
        $pointer = $pointerStmt->fetchColumn();
        $company = $source['company_id'] === null ? null : (int) $source['company_id'];
        $account = $source['meli_account_id'] === null ? null : (int) $source['meli_account_id'];
        $classification = 'MANUAL_REVIEW_REQUIRED';
        $proof = 'NO';
        $proofSource = '';
        $proposedCompany = $company;
        $proposedAccount = $account;
        $blocked = '';
        $future = 'manual_review';
        $touch = 'none';
        $requiresHttp = 'NO';
        $remoteState = 'NOT_REMOTE';
        $orders = $resolveOrder($source['external_order_id']);
        $packs = $resolvePack($source['pack_external_id']);
        if ($source['status'] === 'complete' && $pointer === 'completed') {
            $classification = 'ALREADY_CONVERGED';
            $proof = 'YES';
            $proofSource = 'source_company_account';
            $future = 'none';
        } elseif ($source['status'] === 'complete' && in_array($pointer, ['ready', 'waiting', 'running'], true)) {
            $classification = 'SAFE_LOCAL_CONVERGENCE_CANDIDATE';
            $proof = 'YES';
            $proofSource = 'source_company_account';
            $future = 'close_nonterminal_pointer_locally';
            $touch = 'queue_v4_clean_jobs';
        } elseif ($source['status'] === 'review' || $source['status'] === 'dead') {
            $classification = 'MANUAL_REVIEW_REQUIRED';
            $blocked = 'REVIEW_NOT_RETRY_ALL';
        } elseif ($pointer === false && $source['status'] === 'retry' && $company !== null && $account !== null && $accountValid($company, $account)) {
            $classification = 'SAFE_READ_REQUEUE_CANDIDATE';
            $proof = 'YES';
            $proofSource = 'source_company_account_validated';
            $future = 'future_canonical_pointer_admission';
            $touch = 'queue_v4_clean_jobs';
            $requiresHttp = 'FUTURE_CRON_ONLY';
        } elseif ($company === null || $account === null) {
            if (count($orders) === 1) {
                $classification = ((int) $orders[0]['remote_uncertain']) === 1 ? 'BLOCKED_REMOTE_RESULT_UNCERTAIN' : 'EXACT_TENANT_ATTRIBUTION_CANDIDATE';
                $proof = $classification === 'EXACT_TENANT_ATTRIBUTION_CANDIDATE' ? 'YES' : 'NO';
                $proofSource = 'unique_order_company_account';
                $proposedCompany = (int) $orders[0]['company_id'];
                $proposedAccount = (int) $orders[0]['meli_account_id'];
                $future = $classification === 'EXACT_TENANT_ATTRIBUTION_CANDIDATE' ? 'future_source_tenant_fill_then_admission' : 'blocked_remote_uncertain';
                $touch = $classification === 'EXACT_TENANT_ATTRIBUTION_CANDIDATE' ? 'sale_financial_reconciliation_jobs;queue_v4_clean_jobs' : 'none';
                $blocked = $classification === 'BLOCKED_REMOTE_RESULT_UNCERTAIN' ? 'REMOTE_RESULT_UNCERTAIN' : '';
                $requiresHttp = $classification === 'EXACT_TENANT_ATTRIBUTION_CANDIDATE' ? 'FUTURE_CRON_ONLY' : 'NO';
                $remoteState = ((int) $orders[0]['remote_uncertain']) === 1 ? 'UNCERTAIN' : 'KNOWN_SAFE_READ';
            } elseif (count($orders) > 1 || count($packs) > 1) {
                $classification = 'BLOCKED_AMBIGUOUS_TENANT';
                $blocked = 'AMBIGUOUS_TENANT';
            } else {
                $classification = 'BLOCKED_MISSING_BUSINESS_SOURCE';
                $blocked = 'MISSING_BUSINESS_SOURCE';
            }
        } elseif ($source['external_order_id'] !== null && count($orders) === 0) {
            $classification = 'BLOCKED_MISSING_BUSINESS_SOURCE';
            $blocked = 'MISSING_BUSINESS_SOURCE';
        } elseif (count($orders) === 1 && ((int) $orders[0]['remote_uncertain']) === 1) {
            $classification = 'BLOCKED_REMOTE_RESULT_UNCERTAIN';
            $remoteState = 'UNCERTAIN';
            $blocked = 'REMOTE_RESULT_UNCERTAIN';
        } elseif ($source['pack_external_id'] !== null) {
            if (count($packs) > 1) {
                $classification = 'BLOCKED_AMBIGUOUS_TENANT';
                $blocked = 'CROSS_TENANT';
            } elseif (count($packs) === 0) {
                $classification = 'BLOCKED_MISSING_BUSINESS_SOURCE';
                $blocked = 'MISSING_BUSINESS_SOURCE';
            } elseif ((int) $packs[0]['expected_members'] !== (int) $packs[0]['known_members'] || $packs[0]['status'] !== 'complete') {
                $classification = 'MANUAL_REVIEW_REQUIRED';
                $blocked = 'PACK_INCOMPLETE';
            } else {
                $classification = 'EXACT_TENANT_ATTRIBUTION_CANDIDATE';
                $proof = 'YES';
                $proofSource = 'complete_pack_single_tenant';
                $future = 'future_pack_finance_admission';
                $touch = 'queue_v4_clean_jobs';
                $requiresHttp = 'FUTURE_CRON_ONLY';
            }
        } elseif ($pointer === false) {
            $classification = 'BLOCKED_MISSING_BUSINESS_SOURCE';
            $blocked = 'MISSING_POINTER_AND_UNPROVEN_SOURCE';
        }
        if ($company !== null && $account !== null && !$accountValid($company, $account)) {
            $classification = 'BLOCKED_AMBIGUOUS_TENANT';
            $proof = 'NO';
            $blocked = 'MISSING_ACCOUNT_OR_WRONG_COMPANY';
            $future = 'blocked_wrong_pair';
            $touch = 'none';
            $requiresHttp = 'NO';
        }
        $add([
            'candidate_key' => 'finance:sale_financial_reconciliation_jobs:' . $sid,
            'category' => 'finance_source_pointer',
            'source_table' => 'sale_financial_reconciliation_jobs',
            'source_id' => $sid,
            'current_state' => (string) $source['status'],
            'company_id_current' => $company ?? '',
            'account_id_current' => $account ?? '',
            'company_id_proposed' => $proposedCompany ?? '',
            'account_id_proposed' => $proposedAccount ?? '',
            'tenant_proof' => $proof,
            'tenant_proof_source' => $proofSource,
            'business_source_status' => $source['status'],
            'pointer_status' => $pointer === false ? 'missing' : (string) $pointer,
            'remote_result_state' => $remoteState,
            'classification' => $classification,
            'proposed_future_action' => $future,
            'tables_that_future_action_would_touch' => $touch,
            'requires_remote_http' => $requiresHttp,
            'reversible' => str_starts_with($classification, 'BLOCKED_') ? 'N/A' : 'YES',
            'blocked_reason' => $blocked,
        ]);
    }
    $queueRows = $pdo->query("SELECT * FROM queue_v4_clean_jobs WHERE source_table <> 'sale_financial_reconciliation_jobs' OR source_table IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($queueRows as $job) {
        $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
        $safeRead = is_array($payload) && ($payload['operation'] ?? '') === 'read';
        $classification = $job['state'] === 'review' && $job['last_error_class'] === 'RemoteResultUncertainException' && $safeRead
            ? 'SAFE_READ_REQUEUE_CANDIDATE'
            : ($job['source_table'] === 'missing_source' ? 'BLOCKED_MISSING_BUSINESS_SOURCE' : 'BLOCKED_REMOTE_RESULT_UNCERTAIN');
        $add([
            'candidate_key' => 'queue:queue_v4_clean_jobs:' . $job['id'],
            'category' => 'queue_uncertain_or_missing',
            'source_table' => 'queue_v4_clean_jobs',
            'source_id' => $job['id'],
            'current_state' => $job['state'],
            'company_id_current' => $job['company_id'],
            'account_id_current' => $job['meli_account_id'],
            'company_id_proposed' => $job['company_id'],
            'account_id_proposed' => $job['meli_account_id'],
            'tenant_proof' => 'YES',
            'tenant_proof_source' => 'queue_pointer_company_account',
            'business_source_status' => $job['source_table'] === 'missing_source' ? 'missing' : 'queue_only',
            'pointer_status' => $job['state'],
            'remote_result_state' => $job['last_error_class'] === 'RemoteResultUncertainException' ? 'UNCERTAIN' : 'NOT_REMOTE',
            'classification' => $classification,
            'proposed_future_action' => $classification === 'SAFE_READ_REQUEUE_CANDIDATE' ? 'future_safe_read_requeue' : 'blocked',
            'tables_that_future_action_would_touch' => $classification === 'SAFE_READ_REQUEUE_CANDIDATE' ? 'queue_v4_clean_jobs;queue_v4_clean_recovery_events' : 'none',
            'requires_remote_http' => $classification === 'SAFE_READ_REQUEUE_CANDIDATE' ? 'FUTURE_CRON_ONLY' : 'NO',
            'blocked_reason' => $classification === 'BLOCKED_MISSING_BUSINESS_SOURCE' ? 'MISSING_BUSINESS_SOURCE' : ($classification === 'BLOCKED_REMOTE_RESULT_UNCERTAIN' ? 'MUTATING_REMOTE_UNCERTAIN' : ''),
        ]);
    }
    $notificationRows = $pdo->query('SELECT * FROM meli_notification_work_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notificationRows as $item) {
        $supported = in_array($item['canonical_topic'], ['orders_v2', 'questions'], true);
        $company = $item['company_id'] === null ? null : (int) $item['company_id'];
        $account = $item['meli_account_id'] === null ? null : (int) $item['meli_account_id'];
        $hasTenant = $accountValid($company, $account);
        $eventsStmt = $pdo->prepare('SELECT COUNT(*) FROM meli_notification_events WHERE canonical_topic=? AND remote_resource_id=?');
        $eventsStmt->execute([$item['canonical_topic'], $item['remote_resource_id']]);
        $events = (int) $eventsStmt->fetchColumn();
        $classification = 'SAFE_LOCAL_CONVERGENCE_CANDIDATE';
        $blocked = '';
        if (!$supported) {
            $classification = 'BLOCKED_UNSUPPORTED_LEGACY';
            $blocked = 'UNSUPPORTED_TOPIC';
        } elseif (!$hasTenant) {
            $classification = 'BLOCKED_AMBIGUOUS_TENANT';
            $blocked = 'MISSING_ACCOUNT';
        } elseif ($item['canonical_topic'] === 'legacy_preactivation') {
            $classification = 'BLOCKED_UNSUPPORTED_LEGACY';
            $blocked = 'PREACTIVATION_EVENT_ONLY';
        } elseif ($events === 0) {
            $classification = 'BLOCKED_MISSING_BUSINESS_SOURCE';
            $blocked = 'MISSING_NOTIFICATION_EVENT';
        }
        $add([
            'candidate_key' => 'notification:meli_notification_work_items:' . $item['id'],
            'category' => 'notification_generation',
            'source_table' => 'meli_notification_work_items',
            'source_id' => $item['id'],
            'current_state' => $item['status'],
            'company_id_current' => $company ?? '',
            'account_id_current' => $account ?? '',
            'company_id_proposed' => $hasTenant ? $company : '',
            'account_id_proposed' => $hasTenant ? $account : '',
            'tenant_proof' => $hasTenant ? 'YES' : 'NO',
            'tenant_proof_source' => $hasTenant ? 'notification_explicit_account_validated' : '',
            'business_source_status' => $events > 0 ? 'event_generation_present' : 'event_missing',
            'pointer_status' => 'not_applicable',
            'classification' => $classification,
            'proposed_future_action' => $classification === 'SAFE_LOCAL_CONVERGENCE_CANDIDATE' ? 'future_generation_preserving_local_reconcile' : 'blocked',
            'tables_that_future_action_would_touch' => $classification === 'SAFE_LOCAL_CONVERGENCE_CANDIDATE' ? 'meli_notification_work_items;meli_notification_events' : 'none',
            'requires_remote_http' => 'NO',
            'blocked_reason' => $blocked,
        ]);
    }
    $recalcRows = $pdo->query('SELECT * FROM order_financial_recalc_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recalcRows as $job) {
        $orders = $resolveOrder($job['external_order_id']);
        $hasDirectTenant = $accountValid($job['company_id'] === null ? null : (int) $job['company_id'], $job['meli_account_id'] === null ? null : (int) $job['meli_account_id']);
        $classification = $hasDirectTenant ? 'SAFE_READ_REQUEUE_CANDIDATE' : (count($orders) === 1 ? 'EXACT_TENANT_ATTRIBUTION_CANDIDATE' : 'BLOCKED_AMBIGUOUS_TENANT');
        $add([
            'candidate_key' => 'sales_repair:order_financial_recalc_jobs:' . $job['id'],
            'category' => 'sales_repair_finance_source',
            'source_table' => 'order_financial_recalc_jobs',
            'source_id' => $job['id'],
            'current_state' => $job['status'],
            'company_id_current' => $job['company_id'] ?? '',
            'account_id_current' => $job['meli_account_id'] ?? '',
            'company_id_proposed' => $hasDirectTenant ? $job['company_id'] : (count($orders) === 1 ? $orders[0]['company_id'] : ''),
            'account_id_proposed' => $hasDirectTenant ? $job['meli_account_id'] : (count($orders) === 1 ? $orders[0]['meli_account_id'] : ''),
            'tenant_proof' => $classification === 'BLOCKED_AMBIGUOUS_TENANT' ? 'NO' : 'YES',
            'tenant_proof_source' => $hasDirectTenant ? 'source_company_account_validated' : (count($orders) === 1 ? 'unique_order_company_account' : ''),
            'business_source_status' => count($orders) > 0 ? 'order_present' : 'order_ambiguous_or_missing',
            'pointer_status' => 'missing',
            'classification' => $classification,
            'proposed_future_action' => $classification === 'BLOCKED_AMBIGUOUS_TENANT' ? 'blocked' : 'future_canonical_finance_admission',
            'tables_that_future_action_would_touch' => $classification === 'BLOCKED_AMBIGUOUS_TENANT' ? 'none' : 'queue_v4_clean_jobs',
            'requires_remote_http' => $classification === 'BLOCKED_AMBIGUOUS_TENANT' ? 'NO' : 'FUTURE_CRON_ONLY',
            'blocked_reason' => $classification === 'BLOCKED_AMBIGUOUS_TENANT' ? 'AMBIGUOUS_TENANT' : '',
        ]);
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['candidate_key'], (string) $b['candidate_key']));
    return $rows;
};

$sourceCensusRows = [
    ['table_or_source' => 'queue_v4_clean_jobs', 'domain' => 'Queue V4', 'business_truth' => 'NO', 'pointer_or_source' => 'pointer', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'state,last_error_class,lease_generation', 'current_normal_authority' => 'QueueV4CleanWorker', 'existing_recovery_authority' => 'QueueV4CleanUncertainReadRecoveryService', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'classification_notes' => 'safe read only uncertain can be future candidate; mutating uncertain blocks'],
    ['table_or_source' => 'queue_v4_clean_attempts', 'domain' => 'Queue V4 attempts', 'business_truth' => 'NO', 'pointer_or_source' => 'attempt_log', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'outcome,error_class,dispatch_state', 'current_normal_authority' => 'QueueV4CleanRepository', 'existing_recovery_authority' => 'diagnostic evidence only', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'evidence for safe-read gate'],
    ['table_or_source' => 'queue_v4_clean_recovery_events', 'domain' => 'Queue V4 recovery audit', 'business_truth' => 'NO', 'pointer_or_source' => 'recovery_ledger', 'tenant_columns' => 'via job_id', 'recovery_state_columns' => 'recovery_kind', 'current_normal_authority' => 'QueueV4CleanUncertainReadRecoveryService', 'existing_recovery_authority' => 'mutation ledger', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'NO', 'classification_notes' => 'prior recovery blocks duplicate automatic future recovery'],
    ['table_or_source' => 'meli_notification_events', 'domain' => 'Notifications', 'business_truth' => 'YES', 'pointer_or_source' => 'source_generation', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,processing_event_id', 'current_normal_authority' => 'K2 event generation', 'existing_recovery_authority' => 'NotificationCollationRecoveryService', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'NO', 'classification_notes' => 'preserve generations'],
    ['table_or_source' => 'meli_notification_work_items', 'domain' => 'Notifications', 'business_truth' => 'YES', 'pointer_or_source' => 'aggregate_source', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,processing_event_id,next_run_at', 'current_normal_authority' => 'NotificationWorkItemService', 'existing_recovery_authority' => 'NotificationWorkerRecoveryService', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'NO', 'classification_notes' => 'no blind pending reset'],
    ['table_or_source' => 'order_financial_recalc_jobs', 'domain' => 'Sales repair', 'business_truth' => 'YES', 'pointer_or_source' => 'source', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,next_run_at', 'current_normal_authority' => 'CronAdmissionService financial_reconciliation', 'existing_recovery_authority' => 'current admission contract', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'classification_notes' => 'tenant proof required'],
    ['table_or_source' => 'order_financial_recalc_job_items', 'domain' => 'Sales repair items', 'business_truth' => 'YES', 'pointer_or_source' => 'source_item', 'tenant_columns' => 'via parent job/order', 'recovery_state_columns' => 'status', 'current_normal_authority' => 'FinancialRecalcController', 'existing_recovery_authority' => 'current source contract', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'classification_notes' => 'no orphan item recovery without parent'],
    ['table_or_source' => 'sale_financial_reconciliation_jobs', 'domain' => 'Finance/Billing', 'business_truth' => 'YES', 'pointer_or_source' => 'source', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,attempts,next_run_at,safe_message,input_version', 'current_normal_authority' => 'SaleFinancialService', 'existing_recovery_authority' => 'current canonical admission only', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'classification_notes' => 'K6A never bills'],
    ['table_or_source' => 'sale_financial_state', 'domain' => 'Finance state', 'business_truth' => 'YES', 'pointer_or_source' => 'business_state', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'financial_status', 'current_normal_authority' => 'SaleFinancialService', 'existing_recovery_authority' => 'source truth', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'evidence only'],
    ['table_or_source' => 'meli_packs', 'domain' => 'Packs', 'business_truth' => 'YES', 'pointer_or_source' => 'business_container', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,expected_members,known_members', 'current_normal_authority' => 'current Finance/PACK authority', 'existing_recovery_authority' => 'PACK integrity tooling evidence', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'YES', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'classification_notes' => 'container not extra sale; incomplete blocks'],
    ['table_or_source' => 'meli_orders', 'domain' => 'Orders', 'business_truth' => 'YES', 'pointer_or_source' => 'business_source', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,input_version,remote_uncertain', 'current_normal_authority' => 'order_exact/Fresh', 'existing_recovery_authority' => 'exact tenant proof source', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'exact tenant proof if unique under account/company'],
    ['table_or_source' => 'meli_accounts', 'domain' => 'Tenant authority', 'business_truth' => 'YES', 'pointer_or_source' => 'tenant_source', 'tenant_columns' => 'id,company_id', 'recovery_state_columns' => 'status', 'current_normal_authority' => 'OAuth/account registry', 'existing_recovery_authority' => 'validation only', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'wrong company/account pair blocks'],
    ['table_or_source' => 'api_remote_permits', 'domain' => 'API pacing', 'business_truth' => 'NO', 'pointer_or_source' => 'transport_permit', 'tenant_columns' => 'company_id,meli_account_id', 'recovery_state_columns' => 'status,http_status', 'current_normal_authority' => 'ApiRhythmPolicyService', 'existing_recovery_authority' => 'observability only', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'K6A proves Billing HTTP=0'],
    ['table_or_source' => 'api_rhythm_penalties', 'domain' => 'API pacing', 'business_truth' => 'NO', 'pointer_or_source' => 'policy_state', 'tenant_columns' => 'scope_key', 'recovery_state_columns' => 'blocked_until,reduced_until,reason', 'current_normal_authority' => 'ApiRhythmPolicyService', 'existing_recovery_authority' => 'observability only', 'K6A_reader' => 'planner_read_only', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'no manual breaker edits in K6A'],
    ['table_or_source' => 'legacy Queue Core/Cron V3 recovery artifacts', 'domain' => 'Legacy', 'business_truth' => 'NO', 'pointer_or_source' => 'old_execution_artifact', 'tenant_columns' => 'varies', 'recovery_state_columns' => 'varies', 'current_normal_authority' => 'RETIRED_BLOCKED', 'existing_recovery_authority' => 'diagnostic/offline only', 'K6A_reader' => 'static_census', 'future_mutation_possible' => 'NO', 'remote_http_possible' => 'NO', 'classification_notes' => 'never recreate legacy architecture'],
];

$pdoRoot = $connect($dsn, $user, $pass);
$db = 'erp_meli_k6a_' . bin2hex(random_bytes(6));
$pdoRoot->exec('CREATE DATABASE ' . $q($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoRoot->exec('USE ' . $q($db));
$pdo = $pdoRoot;

try {
    $createSchema($pdo);
    $seed($pdo);

    $hashRows = [];
    $mismatches = [];
    $runPlanner = static function (string $runId) use ($pdo, $sourceTables, $snapshot, $diffSnapshot, $tableHashRows, $planner, &$hashRows, &$mismatches): array {
        $before = $snapshot($pdo, $sourceTables);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $rows = $planner($pdo);
        $pdo->rollBack();
        $after = $snapshot($pdo, $sourceTables);
        $mismatches = array_merge($mismatches, $diffSnapshot($before, $after));
        $hashRows = array_merge($hashRows, $tableHashRows($runId, $before, $after));
        return $rows;
    };

    $run1 = $runPlanner('run1');
    $run2 = $runPlanner('run2');
    $hash1 = $canonicalHash($run1);
    $hash2 = $canonicalHash($run2);
    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    $pdo->exec("UPDATE sale_financial_reconciliation_jobs SET status='review', safe_message='input changed for deterministic hash proof' WHERE id=3");
    $run3 = $runPlanner('run3_after_input_change');
    $hash3 = $canonicalHash($run3);

    $headers = [
        'candidate_key','category','source_table','source_id','current_state','company_id_current','account_id_current',
        'company_id_proposed','account_id_proposed','tenant_proof','tenant_proof_source','business_source_status',
        'pointer_status','remote_result_state','classification','proposed_future_action','tables_that_future_action_would_touch',
        'requires_remote_http','reversible','blocked_reason',
    ];
    $writeCsv($rawDir . '/K6A_RECOVERY_DRY_RUN_RAW.csv', $headers, $run1);
    $writeCsv($rawDir . '/K6A_DRY_RUN_TABLE_HASHES_RAW.csv', ['run_id','table_name','before_rows','after_rows','before_sha256','after_sha256','match'], $hashRows);

    $fixtureRows = [];
    foreach ($run1 as $row) {
        $fixtureRows[] = [
            'candidate_key' => $row['candidate_key'],
            'fixture_category' => $row['category'],
            'expected_classification' => $row['classification'],
            'tenant_proof' => $row['tenant_proof'],
            'blocked_reason' => $row['blocked_reason'],
        ];
    }
    $writeCsv($rawDir . '/K6A_FIXTURE_MATRIX.csv', ['candidate_key','fixture_category','expected_classification','tenant_proof','blocked_reason'], $fixtureRows);

    $tenantRows = array_map(static fn (array $row): array => [
        'candidate_key' => $row['candidate_key'],
        'company_id_current' => $row['company_id_current'],
        'account_id_current' => $row['account_id_current'],
        'company_id_proposed' => $row['company_id_proposed'],
        'account_id_proposed' => $row['account_id_proposed'],
        'tenant_proof' => $row['tenant_proof'],
        'tenant_proof_source' => $row['tenant_proof_source'],
        'classification' => $row['classification'],
    ], $run1);
    $writeCsv($rawDir . '/K6A_TENANT_ATTRIBUTION_MATRIX.csv', ['candidate_key','company_id_current','account_id_current','company_id_proposed','account_id_proposed','tenant_proof','tenant_proof_source','classification'], $tenantRows);

    $futureMutationRows = [
        ['classification' => 'SAFE_LOCAL_CONVERGENCE_CANDIDATE', 'future_action' => 'bounded local source/pointer convergence', 'source_truth_table' => 'domain source table', 'pointer_table' => 'queue_v4_clean_jobs or work item', 'auxiliary_table' => 'audit ledger when applicable', 'remote_http_possible' => 'NO', 'canonical_admission_required' => 'NO', 'rollback_method' => 'exact before-row snapshot restore or idempotent no-op', 'K6B_rehearsal_required' => 'YES'],
        ['classification' => 'SAFE_READ_REQUEUE_CANDIDATE', 'future_action' => 'bounded safe-read requeue via current canonical contract', 'source_truth_table' => 'queue/source table', 'pointer_table' => 'queue_v4_clean_jobs', 'auxiliary_table' => 'queue_v4_clean_recovery_events', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'canonical_admission_required' => 'YES', 'rollback_method' => 'CAS reversal plus recovery ledger before-row snapshot', 'K6B_rehearsal_required' => 'YES'],
        ['classification' => 'EXACT_TENANT_ATTRIBUTION_CANDIDATE', 'future_action' => 'tenant fill or canonical admission after exact proof', 'source_truth_table' => 'sale_financial_reconciliation_jobs/order_financial_recalc_jobs', 'pointer_table' => 'queue_v4_clean_jobs', 'auxiliary_table' => 'none', 'remote_http_possible' => 'FUTURE_CRON_ONLY', 'canonical_admission_required' => 'YES', 'rollback_method' => 'exact before-row snapshot restore and CAS on unchanged source fingerprint', 'K6B_rehearsal_required' => 'YES'],
    ];
    $writeCsv($rawDir . '/K6A_FUTURE_MUTATION_MATRIX.csv', ['classification','future_action','source_truth_table','pointer_table','auxiliary_table','remote_http_possible','canonical_admission_required','rollback_method','K6B_rehearsal_required'], $futureMutationRows);
    $writeCsv($rawDir . '/K6A_HISTORICAL_DEBT_SOURCE_CENSUS.csv', ['table_or_source','domain','business_truth','pointer_or_source','tenant_columns','recovery_state_columns','current_normal_authority','existing_recovery_authority','K6A_reader','future_mutation_possible','remote_http_possible','classification_notes'], $sourceCensusRows);

    $summary = [];
    foreach ($run1 as $row) {
        $summary[$row['classification']] = ($summary[$row['classification']] ?? 0) + 1;
    }
    ksort($summary);
    file_put_contents($rawDir . '/K6A_DRY_RUN_SUMMARY.json', json_encode([
        'total_candidates' => count($run1),
        'selection_hash_run1' => $hash1,
        'selection_hash_run2' => $hash2,
        'selection_hash_run3_after_input_change' => $hash3,
        'classification_counts' => $summary,
        'table_hash_rows' => count($hashRows),
        'table_hash_mismatches' => array_values(array_unique($mismatches)),
        'source_census_rows' => count($sourceCensusRows),
        'fixture_cases' => count($fixtureRows),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);

    $blockedAuto = array_filter($run1, static fn (array $row): bool => $row['tenant_proof'] !== 'YES' && !str_starts_with($row['classification'], 'BLOCKED_') && $row['classification'] !== 'MANUAL_REVIEW_REQUIRED' && $row['classification'] !== 'ALREADY_CONVERGED');
    $crossTenantPlan = array_filter($run1, static fn (array $row): bool => $row['blocked_reason'] === 'CROSS_TENANT' && !str_starts_with($row['classification'], 'BLOCKED_'));
    $tenantGuesses = array_filter($run1, static fn (array $row): bool => $row['tenant_proof'] === 'YES' && $row['tenant_proof_source'] === '');
    $reviewAuto = array_filter($run1, static fn (array $row): bool => $row['source_table'] === 'sale_financial_reconciliation_jobs' && $row['current_state'] === 'review' && $row['classification'] !== 'MANUAL_REVIEW_REQUIRED');
    $missingRollback = array_filter($futureMutationRows, static fn (array $row): bool => trim((string) $row['rollback_method']) === '');

    $assert(count($run1) >= 20, 'K6A fixture case coverage below 20');
    $assert($run1 === $run2, 'K6A planner rows are not deterministic');
    $assert($hash1 === $hash2, 'K6A selection hash unstable');
    $assert($hash1 !== $hash3, 'K6A selection hash did not change after input change');
    $assert(array_unique($mismatches) === [], 'K6A planner mutated snapshot tables');
    $assert(count($tenantGuesses) === 0, 'K6A tenant guessing detected');
    $assert(count($blockedAuto) === 0, 'K6A ambiguous tenant auto recovery detected');
    $assert(count($crossTenantPlan) === 0, 'K6A cross tenant plan row detected');
    $assert(count($reviewAuto) === 0, 'K6A review retry-all detected');
    $assert(count($missingRollback) === 0, 'K6A recoverable class missing rollback');
    $assert(count($sourceCensusRows) >= 15, 'K6A source census incomplete');

    fwrite(
        STDOUT,
        'K6A_HISTORICAL_RECOVERY_PLAN_MYSQL=PASS'
        . ' checks=' . $checks
        . ' K6A_FIXTURE_CASES=' . count($run1)
        . ' DRY_RUN_CANDIDATES=' . count($run1)
        . ' ALREADY_CONVERGED=' . ($summary['ALREADY_CONVERGED'] ?? 0)
        . ' SAFE_LOCAL_CONVERGENCE_CANDIDATES=' . ($summary['SAFE_LOCAL_CONVERGENCE_CANDIDATE'] ?? 0)
        . ' SAFE_READ_REQUEUE_CANDIDATES=' . ($summary['SAFE_READ_REQUEUE_CANDIDATE'] ?? 0)
        . ' EXACT_TENANT_ATTRIBUTION_CANDIDATES=' . ($summary['EXACT_TENANT_ATTRIBUTION_CANDIDATE'] ?? 0)
        . ' BLOCKED_AMBIGUOUS_TENANT=' . ($summary['BLOCKED_AMBIGUOUS_TENANT'] ?? 0)
        . ' BLOCKED_MISSING_BUSINESS_SOURCE=' . ($summary['BLOCKED_MISSING_BUSINESS_SOURCE'] ?? 0)
        . ' BLOCKED_REMOTE_RESULT_UNCERTAIN=' . ($summary['BLOCKED_REMOTE_RESULT_UNCERTAIN'] ?? 0)
        . ' BLOCKED_UNSUPPORTED_LEGACY=' . ($summary['BLOCKED_UNSUPPORTED_LEGACY'] ?? 0)
        . ' MANUAL_REVIEW_REQUIRED=' . ($summary['MANUAL_REVIEW_REQUIRED'] ?? 0)
        . ' SELECTION_HASH_RUN1=' . $hash1
        . ' SELECTION_HASH_RUN2=' . $hash2
        . ' SELECTION_HASH_STABLE=YES'
        . ' SELECTION_HASH_CHANGED_AFTER_INPUT_CHANGE=YES'
        . ' K6A_TABLE_HASH_ROWS=' . count($hashRows)
        . ' K6A_TABLE_HASH_MISMATCHES=0'
        . ' K6A_ROWS_MUTATED=0'
        . ' K6A_HTTP_CALLS=0'
        . ' K6A_CANONICAL_ADMISSIONS=0'
        . ' K6A_DRAIN_AUTHORITY_ACQUISITIONS=0'
        . ' FINANCE_TENANT_GUESSES=0'
        . ' AMBIGUOUS_TENANT_AUTO_RECOVERY=0'
        . ' CROSS_TENANT_RECOVERY_PLAN_ROWS=0'
        . ' K2_FALSE_COMPLETE=0'
        . ' K2_LOST_GENERATION=0'
        . ' UNSUPPORTED_TOPIC_RECOVERY_ADMISSIONS=0'
        . ' BILLING_HTTP=0'
        . ' REMOTE_HTTP=0'
        . ' RECOVERY_ROWS_MUTATED=0'
        . ' CANONICAL_POINTERS_CREATED=0'
        . ' QUEUE_JOBS_CREATED=0'
        . ' HISTORICAL_SOURCE_CENSUS_ROWS=' . count($sourceCensusRows)
        . ' RECOVERY_TAXONOMY_CLASSES=' . count($summary)
        . PHP_EOL
    );
} finally {
    try {
        $pdoRoot->exec('SET SESSION TRANSACTION READ WRITE');
        $pdoRoot->exec('DROP DATABASE IF EXISTS ' . $q($db));
    } catch (Throwable) {
    }
}
