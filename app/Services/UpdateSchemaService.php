<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class UpdateSchemaService
{
    /** @return array{hash:string,schema:array<string,mixed>,classification:string,differences:list<array<string,string>>} */
    public function inspect(?array $expected = null): array
    {
        $pdo = Database::connectionFresh();
        $gateway = new InformationSchemaGateway($pdo);
        $database = $gateway->database();
        $tables = $gateway->schemaSnapshot();
        $schema = ['database' => $database, 'tables' => $tables];
        $canonical = $this->canonicalJson($schema);
        $hash = hash('sha256', $canonical);
        $differences = [];
        $classification = $this->classify($tables, $expected, $differences);

        return [
            'hash' => $hash,
            'schema' => $schema,
            'classification' => $classification,
            'differences' => $differences,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public function persist(?int $runId, array $snapshot, ?string $appVersion): void
    {
        try {
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare(
                'INSERT INTO system_update_schema_fingerprints
                 (run_id,app_version,fingerprint_sha256,schema_json,classification)
                 VALUES (:run_id,:version,:hash,:schema,:classification)'
            );
            $stmt->execute([
                'run_id' => $runId,
                'version' => $appVersion,
                'hash' => $snapshot['hash'],
                'schema' => json_encode($snapshot['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'classification' => $snapshot['classification'],
            ]);
            if ($runId !== null) {
                $delete = $pdo->prepare('DELETE FROM system_update_schema_differences WHERE run_id=:run_id');
                $delete->execute(['run_id' => $runId]);
                $insert = $pdo->prepare(
                    'INSERT INTO system_update_schema_differences
                     (run_id,object_type,object_name,difference_type,expected_value,actual_value,severity)
                     VALUES (:run_id,:type,:name,:difference,:expected,:actual,:severity)'
                );
                foreach ($snapshot['differences'] as $difference) {
                    $insert->execute([
                        'run_id' => $runId,
                        'type' => $difference['object_type'],
                        'name' => $difference['object_name'],
                        'difference' => $difference['difference_type'],
                        'expected' => $difference['expected_value'] ?? null,
                        'actual' => $difference['actual_value'] ?? null,
                        'severity' => $difference['severity'] ?? 'warning',
                    ]);
                }
            }
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo persistir el fingerprint de esquema.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $tables
     * @param list<array<string,string>> $differences
     */
    private function classify(array $tables, ?array $expected, array &$differences): string
    {
        if ($tables === []) {
            return 'damaged_installation';
        }
        if (!isset($tables['schema_migrations'], $tables['users'])) {
            return 'legacy_detected';
        }
        $registry = $this->registryClassification();
        if ($registry !== null) {
            return $registry;
        }
        $historical = $this->historicalDrift($tables);
        if ($historical !== []) {
            array_push($differences, ...$historical);
            return 'known_drifted';
        }
        if ($expected === null || !isset($expected['tables']) || !is_array($expected['tables'])) {
            return 'known_exact';
        }
        foreach ($expected['tables'] as $tableName => $definition) {
            if (!isset($tables[$tableName])) {
                $differences[] = [
                    'object_type' => 'table',
                    'object_name' => (string) $tableName,
                    'difference_type' => 'missing',
                    'expected_value' => 'present',
                    'actual_value' => 'missing',
                    'severity' => 'critical',
                ];
                continue;
            }
            $expectedColumns = is_array($definition) ? ($definition['columns'] ?? []) : [];
            foreach (is_array($expectedColumns) ? $expectedColumns : [] as $column => $type) {
                if (!isset($tables[$tableName]['columns'][$column])) {
                    $differences[] = [
                        'object_type' => 'column',
                        'object_name' => $tableName . '.' . $column,
                        'difference_type' => 'missing',
                        'expected_value' => (string) $type,
                        'actual_value' => 'missing',
                        'severity' => 'critical',
                    ];
                }
            }
        }
        return $differences === [] ? 'known_exact' : 'known_incomplete';
    }

    private function registryClassification(): ?string
    {
        try {
            $installed = (new AppVersionService())->installedVersion();
            $filesVersion = AppVersionService::fileVersion();
            if (
                preg_match('/^\d+\.\d+\.\d+/', $installed) === 1
                && preg_match('/^\d+\.\d+\.\d+/', $filesVersion) === 1
                && version_compare($installed, $filesVersion, '>')
            ) {
                return 'future_version';
            }
            $applied = array_map(
                'strval',
                Database::connectionFresh()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
            );
            if (count($applied) < 16) {
                return 'legacy_detected';
            }
            $known = array_fill_keys(
                array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []),
                true
            );
            foreach ($applied as $migration) {
                if (!isset($known[$migration])) {
                    return 'unknown_schema';
                }
            }
        } catch (Throwable) {
            return 'damaged_installation';
        }
        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $tables
     * @return list<array<string,string>>
     */
    private function historicalDrift(array $tables): array
    {
        try {
            $applied = Database::connectionFresh()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [];
        }
        $requirements = [
            '001_initial_schema.sql' => ['users', 'companies', 'meli_accounts', 'meli_orders', 'meli_items'],
            '006_app_settings_versions_update_logs.sql' => ['app_settings', 'app_versions', 'update_logs'],
            '017_sync_center_imports_2_3.sql' => ['sync_batches', 'sync_batch_chunks'],
            '019_api_guardrails_2_4.sql' => ['api_request_logs', 'api_circuit_breakers'],
            '020_questions_2_4.sql' => ['meli_questions'],
            '028_sync_audit_recurring_2_4_6.sql' => ['sync_sales_audits', 'sync_recurring_rules'],
            '035_order_financial_reconciliation_2_7.sql' => ['meli_order_financials'],
            '037_financial_recalc_queue_ui_2_7_2.sql' => ['order_financial_recalc_jobs', 'order_financial_recalc_job_items'],
            '040_catalogs_2_8.sql' => ['catalogs', 'catalog_items', 'catalog_categories'],
            '043_meli_product_update_reviews_2_8_8.sql' => ['meli_product_update_reviews'],
            '058_catalog_description_jobs_2_8_23.sql' => ['catalog_description_jobs', 'catalog_description_job_items'],
            '059_secure_update_engine_2_9.sql' => ['system_update_runs', 'system_update_releases'],
        ];
        $appliedMap = array_fill_keys(array_map('strval', $applied), true);
        $differences = [];
        foreach ($requirements as $migration => $requiredTables) {
            if (!isset($appliedMap[$migration])) {
                continue;
            }
            foreach ($requiredTables as $table) {
                if (!isset($tables[$table])) {
                    $differences[] = [
                        'object_type' => 'table',
                        'object_name' => $table,
                        'difference_type' => 'missing_after_applied_migration',
                        'expected_value' => $migration,
                        'actual_value' => 'missing',
                        'severity' => 'critical',
                    ];
                }
            }
        }
        return $differences;
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (array &$item) use (&$sort): void {
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as &$child) {
                if (is_array($child)) {
                    $sort($child);
                }
            }
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
