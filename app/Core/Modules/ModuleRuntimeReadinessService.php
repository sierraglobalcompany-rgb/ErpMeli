<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use App\Services\InformationSchemaGateway;
use PDO;
use Throwable;

final class ModuleRuntimeReadinessService
{
    private const REQUIRED_MIGRATIONS = [
        '087_module_runtime_jobs_hardening_2_17_0.sql',
        '088_module_runtime_logs_recovery_2_17_1.sql',
        '089_migration_drift_recovery_2_17_2.sql',
    ];

    /** @var array{ready:bool,reason:string,missing_migrations:list<string>,missing_columns:list<string>}|null */
    private static ?array $cached = null;

    /** @return array{ready:bool,reason:string,missing_migrations:list<string>,missing_columns:list<string>} */
    public function status(bool $refresh = false): array
    {
        if (!$refresh && self::$cached !== null) {
            return self::$cached;
        }

        try {
            $pdo = Database::connection();
            $placeholders = implode(',', array_fill(0, count(self::REQUIRED_MIGRATIONS), '?'));
            $stmt = $pdo->prepare(
                "SELECT version FROM schema_migrations WHERE version IN ({$placeholders})"
            );
            $stmt->execute(self::REQUIRED_MIGRATIONS);
            $applied = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
            $missingMigrations = array_values(array_filter(
                self::REQUIRED_MIGRATIONS,
                static fn (string $migration): bool => !isset($applied[$migration])
            ));

            $requiredColumns = ['active_dedupe_key', 'lease_generation', 'lease_heartbeat_at'];
            $present = array_fill_keys(
                array_keys((new InformationSchemaGateway($pdo))->columns('system_module_jobs')),
                true
            );
            $missingColumns = array_values(array_filter(
                $requiredColumns,
                static fn (string $column): bool => !isset($present[$column])
            ));

            $ready = $missingMigrations === [] && $missingColumns === [];
            return self::$cached = [
                'ready' => $ready,
                'reason' => $ready ? 'ready' : 'migration_required',
                'missing_migrations' => $missingMigrations,
                'missing_columns' => $missingColumns,
            ];
        } catch (Throwable) {
            return self::$cached = [
                'ready' => false,
                'reason' => 'runtime_unavailable',
                'missing_migrations' => self::REQUIRED_MIGRATIONS,
                'missing_columns' => [],
            ];
        }
    }

    public function ready(bool $refresh = false): bool
    {
        return $this->status($refresh)['ready'];
    }

    public static function clear(): void
    {
        self::$cached = null;
    }
}
