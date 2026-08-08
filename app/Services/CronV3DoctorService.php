<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CronV3DoctorService
{
    private const MIGRATION_PREFIXES = [
        '240',
        '241',
        '242',
        '243',
        '244',
        '245',
        '246',
        '247',
        '248',
        '249',
        '250',
        '251',
        '252',
        '253',
        '254',
        '255',
        '256',
        '257',
        '258',
    ];

    private const REQUIRED_TABLES = [
        'cron_v3_work',
        'cron_v3_attempts',
        'cron_v3_queue_ownership',
        'cron_v3_rate_buckets',
        'cron_v3_circuit_states',
        'cron_v3_snapshots',
        'cron_v3_capability_matrix',
    ];

    private const DB_SETTING_KEYS = [
        'cron_v3.enabled',
        'cron_v3.shadow_enabled',
        'cron_v3.remote_rate_limit',
        'cron_v3.remote_one_logical_call',
    ];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(string $requestedLane = 'all'): array
    {
        $requestedLane = in_array($requestedLane, ['local', 'remote'], true) ? $requestedLane : 'all';
        $components = $this->components();
        $environment = $this->environmentFlags();
        $base = [
            'ok' => false,
            'state' => 'unavailable',
            'read_only' => true,
            'http_calls' => 0,
            'requested_lane' => $requestedLane,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'activation_authority' => 'env_resolver',
            'authority' => [
                'execution_flags' => 'process_env_then_config_env',
                'database_flags' => 'diagnostic_only',
                'ownership' => 'cron_v3_queue_ownership',
            ],
            'flags' => [
                'environment' => $environment,
                'database' => ['available' => false, 'values' => []],
                'mismatches' => [],
                'divergence' => ['present' => false, 'items' => []],
            ],
            'schema' => [
                'database_available' => false,
                'database_server' => [
                    'available' => false,
                    'vendor' => 'unknown',
                    'version' => null,
                    'minimum' => null,
                    'supported' => false,
                ],
                'migrations' => $this->migrationSourceState([]),
                'required_tables' => [],
                'missing_tables' => self::REQUIRED_TABLES,
            ],
            'snapshots' => ['local' => [], 'remote' => []],
            'ownership' => ['rows' => [], 'summary' => []],
            'expired_leases' => ['total' => 0, 'by_lane_and_type' => []],
            'rate_buckets' => ['available' => false],
            'circuits' => ['available' => false, 'by_state' => []],
            'remote_prerequisites' => $this->remotePrerequisites(),
            'components' => $components,
            'issues' => [],
        ];

        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
        } catch (Throwable) {
            $base['issues'] = ['database_unavailable'];
            return $base;
        }

        $base['schema']['database_available'] = true;
        $issues = [];
        $warnings = [];

        $databaseServer = $this->databaseServerCompatibility($pdo);
        $base['schema']['database_server'] = $databaseServer;
        if (empty($databaseServer['available'])) {
            $issues[] = 'database_version_unavailable';
        } elseif (empty($databaseServer['supported'])) {
            $issues[] = 'database_version_unsupported:'
                . (string) $databaseServer['vendor'] . ':' . (string) $databaseServer['version'];
        }
        if (empty($base['remote_prerequisites']['application_id_valid'])) {
            if ($requestedLane === 'remote' || $requestedLane === 'all') {
                $issues[] = 'meli_application_id_unavailable';
            } else {
                $warnings[] = 'meli_application_id_unavailable';
            }
        }

        $appliedMigrations = [];
        try {
            $appliedMigrations = array_values(array_map(
                'strval',
                $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN),
            ));
        } catch (Throwable) {
            $issues[] = 'schema_migrations_unavailable';
        }
        $migrationState = $this->migrationSourceState($appliedMigrations);
        $base['schema']['migrations'] = $migrationState;
        foreach ($migrationState as $migration) {
            if (empty($migration['source_present'])) {
                $issues[] = 'migration_source_missing:' . $migration['prefix'];
            }
            if (empty($migration['database_applied'])) {
                $issues[] = 'migration_not_applied:' . $migration['prefix'];
            }
        }

        $presentTables = [];
        try {
            $marks = implode(',', array_fill(0, count(self::REQUIRED_TABLES), '?'));
            $statement = $pdo->prepare(
                'SELECT table_name FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name IN (' . $marks . ')'
            );
            $statement->execute(self::REQUIRED_TABLES);
            $presentTables = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable) {
            $issues[] = 'table_inventory_unavailable';
        }
        $missingTables = array_values(array_diff(self::REQUIRED_TABLES, $presentTables));
        $base['schema']['required_tables'] = array_map(
            static fn (string $table): array => [
                'table' => $table,
                'present' => in_array($table, $presentTables, true),
            ],
            self::REQUIRED_TABLES,
        );
        $base['schema']['missing_tables'] = $missingTables;
        foreach ($missingTables as $table) {
            $issues[] = 'table_missing:' . $table;
        }

        $databaseFlags = $this->databaseFlags($pdo);
        $base['flags']['database'] = $databaseFlags;
        $flagMismatches = $this->flagMismatches($environment, $databaseFlags);
        $base['flags']['mismatches'] = $flagMismatches;
        $base['flags']['divergence'] = [
            'present' => $flagMismatches !== [],
            'items' => $flagMismatches,
        ];
        foreach ($flagMismatches as $mismatch) {
            $warnings[] = 'flag_mismatch:' . $mismatch;
        }

        if ($missingTables === []) {
            $base['snapshots'] = $this->snapshots($pdo);
            $base['ownership'] = $this->ownership($pdo);
            $base['expired_leases'] = $this->expiredLeases($pdo);
            $base['rate_buckets'] = $this->rateBuckets($pdo);
            $base['circuits'] = $this->circuits($pdo);

            if ((int) $base['expired_leases']['total'] > 0) {
                $warnings[] = 'expired_leases_present';
            }
            if (empty($base['rate_buckets']['scoped_dimensions_present'])) {
                $issues[] = 'rate_bucket_scope_dimensions_unavailable';
            }
            $activeRemoteOwnership = (int) ($base['ownership']['summary']['v3_remote_enabled'] ?? 0);
            if ($activeRemoteOwnership > 0 && empty($environment['CRON_V3_ENABLED']['value'])) {
                $warnings[] = 'remote_ownership_enabled_while_active_env_disabled';
            }
            foreach (['local', 'remote'] as $lane) {
                if ($base['snapshots'][$lane] === []) {
                    $warnings[] = 'snapshot_missing:' . $lane;
                }
            }
        }

        foreach ((array) ($components['missing'] ?? []) as $component) {
            $issues[] = 'component_missing:' . $component;
        }
        if ((array) ($components['unhandled_declared_types'] ?? []) !== []) {
            $warnings[] = 'declared_work_types_without_handler';
        }

        $issues = array_values(array_unique($issues));
        $warnings = array_values(array_unique($warnings));
        $base['issues'] = $issues;
        $base['warnings'] = $warnings;
        $base['ok'] = $issues === [];
        $base['state'] = $issues !== [] ? 'blocked' : ($warnings !== [] ? 'attention' : 'healthy');
        return $base;
    }

    /** @param array<string,mixed> $snapshot */
    public static function textReport(array $snapshot): string
    {
        $lines = [
            'CRON_V3_DOCTOR state=' . (string) ($snapshot['state'] ?? 'unavailable')
                . ' read_only=true http_calls=0',
            'lane=' . (string) ($snapshot['requested_lane'] ?? 'all'),
            'activation_authority=' . (string) ($snapshot['activation_authority'] ?? 'unknown'),
        ];
        foreach ((array) ($snapshot['issues'] ?? []) as $issue) {
            $lines[] = 'ERROR ' . (string) $issue;
        }
        foreach ((array) ($snapshot['warnings'] ?? []) as $warning) {
            $lines[] = 'WARN ' . (string) $warning;
        }
        if (count($lines) === 3) {
            $lines[] = 'OK cron_v3_doctor';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @param list<string> $applied @return list<array<string,mixed>> */
    private function migrationSourceState(array $applied): array
    {
        $migrationDirectory = dirname(__DIR__, 2) . '/database/migrations';
        $state = [];
        foreach (self::MIGRATION_PREFIXES as $prefix) {
            $files = glob($migrationDirectory . '/' . $prefix . '_*.sql') ?: [];
            sort($files);
            $sourceNames = array_map('basename', $files);
            $appliedNames = array_values(array_filter(
                $applied,
                static fn (string $version): bool => str_starts_with($version, $prefix . '_'),
            ));
            $missingAppliedNames = array_values(array_diff($sourceNames, $appliedNames));
            $state[] = [
                'prefix' => $prefix,
                'source_present' => $sourceNames !== [],
                'source_versions' => $sourceNames,
                'database_applied' => $sourceNames !== [] && $missingAppliedNames === [],
                'database_versions' => $appliedNames,
                'missing_database_versions' => $missingAppliedNames,
            ];
        }
        return $state;
    }

    /** @return array<string,array{defined:bool,value:bool|int}> */
    private function environmentFlags(): array
    {
        return [
            'CRON_V3_ENABLED' => $this->environmentBool('CRON_V3_ENABLED', false),
            'CRON_V3_SHADOW_ENABLED' => $this->environmentBool('CRON_V3_SHADOW_ENABLED', false),
            'CRON_V3_RATE_LIMIT' => $this->environmentInt('CRON_V3_RATE_LIMIT', 10),
        ];
    }

    /** @return array{application_id_defined:bool,application_id_valid:bool} */
    private function remotePrerequisites(): array
    {
        $applicationId = trim((string) Env::get('MELI_CLIENT_ID', ''));
        return [
            'application_id_defined' => $applicationId !== '',
            'application_id_valid' => $applicationId !== ''
                && strlen($applicationId) <= 120
                && preg_match('/^[A-Za-z0-9._:-]+$/', $applicationId) === 1,
        ];
    }

    /** @return array{available:bool,vendor:string,version:?string,minimum:?string,supported:bool} */
    private function databaseServerCompatibility(PDO $pdo): array
    {
        try {
            $raw = trim((string) $pdo->query('SELECT VERSION()')->fetchColumn());
            $vendor = stripos($raw, 'mariadb') !== false ? 'mariadb' : 'mysql';
            $pattern = $vendor === 'mariadb'
                ? '/(\d+\.\d+\.\d+)-MariaDB/i'
                : '/(\d+\.\d+\.\d+)/';
            $version = preg_match($pattern, $raw, $match) === 1 ? $match[1] : null;
            $minimum = $vendor === 'mariadb' ? '10.6.0' : '8.0.4';
            return [
                'available' => $version !== null,
                'vendor' => $vendor,
                'version' => $version,
                'minimum' => $minimum,
                'supported' => $version !== null && version_compare($version, $minimum, '>='),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'vendor' => 'unknown',
                'version' => null,
                'minimum' => null,
                'supported' => false,
            ];
        }
    }

    /** @return array{defined:bool,value:bool} */
    private function environmentBool(string $key, bool $default): array
    {
        $raw = Env::get($key);
        return [
            'defined' => $raw !== null,
            'value' => $raw === null ? $default : filter_var($raw, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @return array{defined:bool,value:int} */
    private function environmentInt(string $key, int $default): array
    {
        $raw = Env::get($key);
        return [
            'defined' => $raw !== null,
            'value' => $raw !== null && ctype_digit($raw) ? (int) $raw : $default,
        ];
    }

    /** @return array{available:bool,values:array<string,string>} */
    private function databaseFlags(PDO $pdo): array
    {
        try {
            $marks = implode(',', array_fill(0, count(self::DB_SETTING_KEYS), '?'));
            $statement = $pdo->prepare(
                'SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN (' . $marks . ')'
            );
            $statement->execute(self::DB_SETTING_KEYS);
            $values = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
            return ['available' => true, 'values' => $values];
        } catch (Throwable) {
            return ['available' => false, 'values' => []];
        }
    }

    /**
     * @param array<string,array{defined:bool,value:bool|int}> $environment
     * @param array{available:bool,values:array<string,string>} $database
     * @return list<string>
     */
    private function flagMismatches(array $environment, array $database): array
    {
        if (!$database['available']) {
            return ['database_flags_unavailable'];
        }
        $pairs = [
            'CRON_V3_ENABLED' => 'cron_v3.enabled',
            'CRON_V3_SHADOW_ENABLED' => 'cron_v3.shadow_enabled',
            'CRON_V3_RATE_LIMIT' => 'cron_v3.remote_rate_limit',
        ];
        $mismatches = [];
        foreach ($pairs as $environmentKey => $databaseKey) {
            if (!array_key_exists($databaseKey, $database['values'])) {
                $mismatches[] = $environmentKey . ':db_missing';
                continue;
            }
            $environmentValue = $environment[$environmentKey]['value'];
            $databaseValue = is_bool($environmentValue)
                ? filter_var($database['values'][$databaseKey], FILTER_VALIDATE_BOOL)
                : (int) $database['values'][$databaseKey];
            if ($environmentValue !== $databaseValue) {
                $mismatches[] = $environmentKey . ':env_overrides_db';
            }
        }
        return $mismatches;
    }

    /** @return array{local:list<array<string,mixed>>,remote:list<array<string,mixed>>} */
    private function snapshots(PDO $pdo): array
    {
        $result = ['local' => [], 'remote' => []];
        try {
            $rows = $pdo->query(
                "SELECT snapshot_key,snapshot_type,lane,generation,observed_at
                 FROM cron_v3_snapshots
                 WHERE lane IN ('local','remote') AND snapshot_type IN ('run','shadow','health')
                 ORDER BY observed_at DESC,snapshot_key"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lane = (string) ($row['lane'] ?? '');
                if (isset($result[$lane])) {
                    $result[$lane][] = $row;
                }
            }
        } catch (Throwable) {
            return $result;
        }
        return $result;
    }

    /** @return array{rows:list<array<string,mixed>>,summary:array<string,int>} */
    private function ownership(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
                 FROM cron_v3_queue_ownership ORDER BY lane,queue_key'
            )->fetchAll(PDO::FETCH_ASSOC);
            $summary = [
                'total' => count($rows),
                'v3_enabled' => 0,
                'v3_local_enabled' => 0,
                'v3_remote_enabled' => 0,
                'v2_enabled' => 0,
                'disabled' => 0,
            ];
            foreach ($rows as $row) {
                $enabled = (int) ($row['enabled'] ?? 0) === 1;
                $owner = (string) ($row['owner_engine'] ?? 'disabled');
                $lane = (string) ($row['lane'] ?? '');
                if ($enabled && $owner === 'v3') {
                    $summary['v3_enabled']++;
                    if ($lane === 'local') {
                        $summary['v3_local_enabled']++;
                    } elseif ($lane === 'remote') {
                        $summary['v3_remote_enabled']++;
                    }
                } elseif ($enabled && $owner === 'v2') {
                    $summary['v2_enabled']++;
                } else {
                    $summary['disabled']++;
                }
            }
            return ['rows' => $rows, 'summary' => $summary];
        } catch (Throwable) {
            return ['rows' => [], 'summary' => []];
        }
    }

    /** @return array{total:int,by_lane_and_type:list<array<string,mixed>>} */
    private function expiredLeases(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT lane,work_type,COUNT(*) AS total
                 FROM cron_v3_work
                 WHERE status='leased' AND lease_until<UTC_TIMESTAMP(3)
                 GROUP BY lane,work_type ORDER BY lane,work_type"
            )->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $rows));
            return ['total' => $total, 'by_lane_and_type' => $rows];
        } catch (Throwable) {
            return ['total' => 0, 'by_lane_and_type' => []];
        }
    }

    /** @return array<string,mixed> */
    private function rateBuckets(PDO $pdo): array
    {
        try {
            $dimensionColumns = ['scope_level', 'application_id', 'endpoint_key', 'operation_key'];
            $marks = implode(',', array_fill(0, count($dimensionColumns), '?'));
            $columnsStatement = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name=? AND column_name IN (' . $marks . ')'
            );
            $columnsStatement->execute(array_merge(['cron_v3_rate_buckets'], $dimensionColumns));
            $presentColumns = array_map('strval', $columnsStatement->fetchAll(PDO::FETCH_COLUMN));
            $missingColumns = array_values(array_diff($dimensionColumns, $presentColumns));
            $row = $pdo->query(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(used_count),0) AS used_count,
                        COALESCE(SUM(limit_count),0) AS configured_capacity,
                        COALESCE(SUM(CASE WHEN blocked_until>UTC_TIMESTAMP(3) THEN 1 ELSE 0 END),0) AS blocked,
                        MAX(updated_at) AS last_updated_at
                 FROM cron_v3_rate_buckets'
            )->fetch(PDO::FETCH_ASSOC);
            $byScopeLevel = [];
            if ($missingColumns === []) {
                $byScopeLevel = $pdo->query(
                    'SELECT scope_level,COUNT(*) AS total,
                            COALESCE(SUM(used_count),0) AS used_count,
                            COALESCE(SUM(CASE WHEN blocked_until>UTC_TIMESTAMP(3) THEN 1 ELSE 0 END),0) AS blocked,
                            MAX(updated_at) AS last_updated_at
                     FROM cron_v3_rate_buckets GROUP BY scope_level ORDER BY scope_level'
                )->fetchAll(PDO::FETCH_ASSOC);
            }
            return [
                'available' => true,
                'scoped_dimensions_present' => $missingColumns === [],
                'missing_dimension_columns' => $missingColumns,
                'by_scope_level' => $byScopeLevel,
            ] + (is_array($row) ? $row : []);
        } catch (Throwable) {
            return [
                'available' => false,
                'scoped_dimensions_present' => false,
                'missing_dimension_columns' => [],
                'by_scope_level' => [],
            ];
        }
    }

    /** @return array{available:bool,by_state:list<array<string,mixed>>} */
    private function circuits(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT state,COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN open_until>UTC_TIMESTAMP(3) THEN 1 ELSE 0 END),0) AS currently_open,
                        MAX(updated_at) AS last_updated_at
                 FROM cron_v3_circuit_states GROUP BY state ORDER BY state'
            )->fetchAll(PDO::FETCH_ASSOC);
            return ['available' => true, 'by_state' => $rows];
        } catch (Throwable) {
            return ['available' => false, 'by_state' => []];
        }
    }

    /** @return array<string,mixed> */
    private function components(): array
    {
        $classNames = [
            self::class,
            CronV3Cli::class,
            CronDeadlineContext::class,
            CronV3::class,
            CronV3Runner::class,
            CronV3WorkRepository::class,
            CronV3RateGate::class,
            CronV3Enqueuer::class,
            CronV3HandlerRegistry::class,
            CronV3WorkTypeRegistry::class,
        ];
        $root = dirname(__DIR__, 2);
        $files = [
            'jobs/cron_v3_local.php' => is_file($root . '/jobs/cron_v3_local.php'),
            'jobs/cron_v3_remote.php' => is_file($root . '/jobs/cron_v3_remote.php'),
        ];
        $classes = [];
        $missing = [];
        foreach ($classNames as $className) {
            $classes[$className] = class_exists($className);
            if (!$classes[$className]) {
                $missing[] = $className;
            }
        }
        foreach ($files as $file => $present) {
            if (!$present) {
                $missing[] = $file;
            }
        }

        $types = new CronV3WorkTypeRegistry();
        $handlers = new CronV3HandlerRegistry($types);
        CronV3DefaultHandlers::register($handlers);
        CronV3DefaultHandlerBootstrap::register($handlers);
        $registered = [
            'local' => $handlers->typesForLane('local'),
            'remote' => $handlers->typesForLane('remote'),
        ];
        $unhandled = array_merge(
            array_diff($types->forLane('local'), $registered['local']),
            array_diff($types->forLane('remote'), $registered['remote']),
        );

        return [
            'classes' => $classes,
            'files' => $files,
            'missing' => $missing,
            'handlers' => $registered,
            'unhandled_declared_types' => $unhandled,
            'handler_availability' => CronV3DefaultHandlerBootstrap::availability(),
        ];
    }
}
