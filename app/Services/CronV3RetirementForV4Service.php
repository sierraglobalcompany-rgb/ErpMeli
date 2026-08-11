<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Retira exclusivamente la autoridad técnica V3 antes de preparar V4.
 *
 * El SQL manual certificado permanece como oracle y recovery de último
 * recurso. Esta clase reproduce su lock, fences, write-set y postcondiciones
 * sin activar V4, tocar Queue Core ni borrar evidencia histórica.
 */
final class CronV3RetirementForV4Service
{
    public const CONFIRMATION_PHRASE = 'RETIRAR_AUTORIDAD_V3_PARA_PREPARAR_V4';
    public const LOCK_NAME = 'erp_meli_v3_retirement_for_v4_2363';
    public const REQUIRED_APP_VERSION = '2.36.5';
    /** @var list<string> */
    public const SUPPORTED_APP_VERSIONS = ['2.36.5', '2.36.6'];
    public const LAST_MIGRATION = '293_queue_core_runtime_profile_defaults_b2_1.sql';
    public const CHANGED_BY = 'v3_retired_for_v4_2363';
    public const RECEIPT_KEY = 'cron_v3.retirement_for_v4.receipt';

    /** @var array<string,string> */
    public const TARGET_SETTINGS = [
        'cron_v3.enabled' => '0',
        'cron_v3.shadow_enabled' => '0',
        'cron_v3.operational_mode' => '0',
        'cron_v3.v2_runtime_disabled' => '1',
        'cron_v3.rollback_enabled' => '0',
        'cron_v3.operational_phase' => 'retired_for_v4',
        'cron_v3.certified_cutover.enabled' => '0',
        'cron_v3.certified_cutover.phase' => 'retired_for_v4',
        'cron_v3.canary.phase' => 'rolled_back',
    ];

    public function __construct(
        private readonly ?Closure $pdoFactory = null,
        private readonly ?string $configPath = null,
        private readonly ?Closure $failpoint = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        try {
            $pdo = $this->connection();
            $authority = $this->inspect($pdo, false);
            return $this->preflightResult($authority);
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'state' => 'blocked',
                'reason' => $this->safeReason($error),
                'required_confirmation_phrase' => self::CONFIRMATION_PHRASE,
                'receipt' => null,
            ];
        }
    }

    /** @return array{ok:bool,message:string,receipt:array<string,mixed>} */
    public function retire(int $actorUserId, string $confirmation): array
    {
        if ($actorUserId < 1) {
            throw new RuntimeException('v3_retirement_admin_required');
        }
        if (!hash_equals(self::CONFIRMATION_PHRASE, trim($confirmation))) {
            throw new RuntimeException('v3_retirement_confirmation_invalid');
        }

        $pdo = $this->connection();
        $lockAcquired = false;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
            $lock->execute([self::LOCK_NAME]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('v3_retirement_lock_busy');
            }
            $lockAcquired = true;

            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->exec("SET SESSION time_zone='+00:00'");
            $pdo->beginTransaction();

            $authority = $this->inspect($pdo, true);
            $preflight = $this->preflightResult($authority);
            if (!$preflight['ok']) {
                throw new RuntimeException((string) $preflight['reason']);
            }

            $this->hit('before_first_mutation');
            $updateOwnership = $pdo->prepare(
                "UPDATE cron_v3_queue_ownership
                 SET owner_engine='disabled',enabled=0,changed_by=?,changed_at=UTC_TIMESTAMP(3)
                 WHERE owner_engine='v3' OR enabled=1"
            );
            $updateOwnership->execute([self::CHANGED_BY]);
            $ownershipChanged = $updateOwnership->rowCount();
            if ($ownershipChanged !== $authority['active_v3_ownership']) {
                throw new RuntimeException('v3_retirement_ownership_cas_changed');
            }

            $this->hit('after_ownership');
            $settingsChanged = $this->countChangedSettings($authority['settings']);
            $this->upsertTargetSettings($pdo);

            $this->hit('after_settings');
            $configRecheck = $this->safeConfigAuthority();
            if (!$this->sameConfigAuthority($authority['safe_config'], $configRecheck)) {
                throw new RuntimeException('v3_retirement_config_authority_changed');
            }

            $post = $this->postconditions($pdo, $authority);
            $this->hit('after_postconditions');

            $receipt = [
                'operation' => 'cron_v3_retirement_for_v4',
                'preimage_hash' => $authority['preimage_hash'],
                'ownership_pre' => $authority['ownership'],
                'ownership_post' => $post['ownership'],
                'ownership_rows_changed' => $ownershipChanged,
                'settings_rows_changed' => $settingsChanged,
                'engine_generation' => $authority['engine_generation'],
                'work_rows_pre' => $authority['work_rows'],
                'work_rows_post' => $post['work_rows'],
                'attempt_rows_pre' => $authority['attempt_rows'],
                'attempt_rows_post' => $post['attempt_rows'],
                'business_rows_changed' => 0,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'result' => 'PASS',
            ];
            $receiptJson = json_encode(
                $receipt,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $this->upsertSetting($pdo, self::RECEIPT_KEY, $receiptJson, 'cron_v3_audit');
            $receipt['receipt_sha256'] = hash('sha256', $receiptJson);

            $this->hit('before_commit');
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Autoridad V3 retirada. No se borraron trabajos y Cron V4 continúa apagado.',
                'receipt' => $receipt,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            if ($lockAcquired) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([self::LOCK_NAME]);
                } catch (Throwable) {
                    // La conexión se descarta al terminar la petición.
                }
            }
        }
    }

    /**
     * @return array{
     *   app_version:string,
     *   schema_count:int,
     *   schema_max:int,
     *   migration_293_count:int,
     *   engine_generation:int,
     *   engine_active:string,
     *   engine_readiness:string,
     *   engine_context:?string,
     *   active_v3_ownership:int,
     *   foreign_enabled_ownership:int,
     *   ownership:list<array<string,mixed>>,
     *   settings:array<string,?string>,
     *   work_rows:int,
     *   attempt_rows:int,
     *   preimage_hash:string,
     *   safe_config:array<string,mixed>,
     *   receipt:?array<string,mixed>
     * }
     */
    private function inspect(PDO $pdo, bool $lockRows): array
    {
        $this->assertWriteSetTopology($pdo);
        $suffix = $lockRows ? ' FOR UPDATE' : '';

        $appVersionQuery = $pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version'" . $suffix
        );
        $appVersionQuery->execute();
        $appVersionRows = $appVersionQuery->fetchAll(PDO::FETCH_COLUMN);
        $appVersion = count($appVersionRows) === 1 ? (string) $appVersionRows[0] : '';

        $schema = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(MAX(CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)),0) AS max_version,
                    SUM(version='" . self::LAST_MIGRATION . "') AS migration_293
             FROM schema_migrations"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $engineQuery = $pdo->prepare(
            "SELECT active_engine,readiness_mode,readiness_context_hash,generation
             FROM queue_engine_control WHERE control_key='primary'" . $suffix
        );
        $engineQuery->execute();
        $engineRows = $engineQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($engineRows) !== 1) {
            throw new RuntimeException('v3_retirement_engine_authority_invalid');
        }
        $engine = $engineRows[0];

        $keys = array_keys(self::TARGET_SETTINGS);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $settingsQuery = $pdo->prepare(
            'SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN (' . $marks . ')' .
            ' ORDER BY BINARY setting_key' . $suffix
        );
        $settingsQuery->execute($keys);
        $settings = [];
        foreach ($settingsQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = $row['setting_value'] === null
                ? null
                : (string) $row['setting_value'];
        }

        $ownershipQuery = $pdo->query(
            "SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
             FROM cron_v3_queue_ownership
             WHERE owner_engine='v3' OR enabled=1
             ORDER BY lane,queue_key" . $suffix
        );
        $ownership = array_map([$this, 'normalizeOwnership'], $ownershipQuery->fetchAll(PDO::FETCH_ASSOC));
        $foreignEnabled = (int) $pdo->query(
            "SELECT COUNT(*) FROM cron_v3_queue_ownership
             WHERE enabled=1 AND owner_engine<>'v3'"
        )->fetchColumn();

        $workRows = (int) $pdo->query('SELECT COUNT(*) FROM cron_v3_work')->fetchColumn();
        $attemptRows = (int) $pdo->query('SELECT COUNT(*) FROM cron_v3_attempts')->fetchColumn();
        $receipt = $this->storedReceipt($pdo);
        $safeConfig = $this->safeConfigAuthority();
        $generation = max(0, (int) ($engine['generation'] ?? 0));

        return [
            'app_version' => $appVersion,
            'schema_count' => (int) ($schema['total'] ?? 0),
            'schema_max' => (int) ($schema['max_version'] ?? 0),
            'migration_293_count' => (int) ($schema['migration_293'] ?? 0),
            'engine_generation' => $generation,
            'engine_active' => (string) ($engine['active_engine'] ?? ''),
            'engine_readiness' => (string) ($engine['readiness_mode'] ?? ''),
            'engine_context' => $engine['readiness_context_hash'] === null
                ? null
                : (string) $engine['readiness_context_hash'],
            'active_v3_ownership' => count($ownership),
            'foreign_enabled_ownership' => $foreignEnabled,
            'ownership' => $ownership,
            'settings' => $settings,
            'work_rows' => $workRows,
            'attempt_rows' => $attemptRows,
            'preimage_hash' => $this->preimageHash($settings, $ownership, $generation),
            'safe_config' => $safeConfig,
            'receipt' => $receipt,
        ];
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    private function preflightResult(array $authority): array
    {
        $reason = 'ready';
        if (!in_array($authority['app_version'], self::SUPPORTED_APP_VERSIONS, true)) {
            $reason = 'v3_retirement_app_version_invalid';
        } elseif ($authority['schema_count'] !== 293
            || $authority['schema_max'] !== 293
            || $authority['migration_293_count'] !== 1) {
            $reason = 'v3_retirement_schema_invalid';
        } elseif ($authority['engine_active'] !== 'disabled'
            || $authority['engine_readiness'] !== 'idle'
            || $authority['engine_context'] !== null) {
            $reason = 'v3_retirement_engine_not_idle';
        } elseif (empty($authority['safe_config']['ok'])) {
            $reason = ($authority['safe_config']['process_override_conflicts'] ?? []) !== []
                ? 'v3_retirement_process_override_conflict'
                : 'v3_retirement_effective_flags_invalid';
        } elseif ($authority['foreign_enabled_ownership'] !== 0) {
            $reason = 'v3_retirement_foreign_ownership';
        } elseif ($authority['active_v3_ownership'] < 1) {
            $reason = is_array($authority['receipt']) && ($authority['receipt']['result'] ?? '') === 'PASS'
                ? 'v3_retirement_already_completed'
                : 'v3_retirement_no_active_ownership';
        }

        return [
            'ok' => $reason === 'ready',
            'state' => $reason === 'ready' ? 'ready' : 'blocked',
            'reason' => $reason,
            'required_confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'required_app_version' => self::REQUIRED_APP_VERSION,
            'app_version' => $authority['app_version'],
            'schema_count' => $authority['schema_count'],
            'schema_max' => $authority['schema_max'],
            'migration_293_count' => $authority['migration_293_count'],
            'active_v3_ownership' => $authority['active_v3_ownership'],
            'foreign_enabled_ownership' => $authority['foreign_enabled_ownership'],
            'engine' => [
                'active_engine' => $authority['engine_active'],
                'readiness_mode' => $authority['engine_readiness'],
                'generation' => $authority['engine_generation'],
            ],
            'effective_flags' => $authority['safe_config']['effective_flags'] ?? [],
            'process_override_conflicts' => $authority['safe_config']['process_override_conflicts'] ?? [],
            'receipt' => $authority['receipt'],
        ];
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    private function postconditions(PDO $pdo, array $authority): array
    {
        $keys = array_column($authority['ownership'], 'queue_key');
        $ownership = [];
        if ($keys !== []) {
            $query = $pdo->prepare(
                'SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
                 FROM cron_v3_queue_ownership WHERE queue_key IN (' .
                implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY lane,queue_key'
            );
            $query->execute($keys);
            $ownership = array_map([$this, 'normalizeOwnership'], $query->fetchAll(PDO::FETCH_ASSOC));
        }
        foreach ($ownership as $row) {
            if ($row['owner_engine'] !== 'disabled'
                || $row['enabled'] !== 0
                || $row['changed_by'] !== self::CHANGED_BY) {
                throw new RuntimeException('v3_retirement_ownership_postcondition_failed');
            }
        }
        $activePost = (int) $pdo->query(
            "SELECT COUNT(*) FROM cron_v3_queue_ownership WHERE owner_engine='v3' OR enabled=1"
        )->fetchColumn();
        if ($activePost !== 0) {
            throw new RuntimeException('v3_retirement_active_ownership_remains');
        }

        $validSettings = 0;
        $selectSetting = $pdo->prepare(
            'SELECT setting_value,is_encrypted,setting_group FROM app_settings WHERE setting_key=?'
        );
        foreach (self::TARGET_SETTINGS as $key => $expected) {
            $selectSetting->execute([$key]);
            $row = $selectSetting->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || (string) ($row['setting_value'] ?? '') !== $expected
                || (int) ($row['is_encrypted'] ?? 1) !== 0
                || (string) ($row['setting_group'] ?? '') !== 'cron_v3') {
                throw new RuntimeException('v3_retirement_settings_postcondition_failed');
            }
            $validSettings++;
        }
        if ($validSettings !== 9) {
            throw new RuntimeException('v3_retirement_settings_postcondition_failed');
        }

        $workRows = (int) $pdo->query('SELECT COUNT(*) FROM cron_v3_work')->fetchColumn();
        $attemptRows = (int) $pdo->query('SELECT COUNT(*) FROM cron_v3_attempts')->fetchColumn();
        if ($workRows !== $authority['work_rows'] || $attemptRows !== $authority['attempt_rows']) {
            throw new RuntimeException('v3_retirement_historical_rows_changed');
        }
        $engine = $pdo->query(
            "SELECT active_engine,readiness_mode,readiness_context_hash,generation
             FROM queue_engine_control WHERE control_key='primary'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($engine)
            || (string) ($engine['active_engine'] ?? '') !== 'disabled'
            || (string) ($engine['readiness_mode'] ?? '') !== 'idle'
            || $engine['readiness_context_hash'] !== null
            || (int) ($engine['generation'] ?? -1) !== $authority['engine_generation']) {
            throw new RuntimeException('v3_retirement_engine_authority_changed');
        }

        return ['ownership' => $ownership, 'work_rows' => $workRows, 'attempt_rows' => $attemptRows];
    }

    private function assertWriteSetTopology(PDO $pdo): void
    {
        $tables = $pdo->query(
            "SELECT table_name,table_type,engine FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN ('app_settings','cron_v3_queue_ownership')"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== 2) {
            throw new RuntimeException('v3_retirement_write_tables_invalid');
        }
        foreach ($tables as $table) {
            if ((string) ($table['table_type'] ?? '') !== 'BASE TABLE'
                || strcasecmp((string) ($table['engine'] ?? ''), 'InnoDB') !== 0) {
                throw new RuntimeException('v3_retirement_write_tables_invalid');
            }
        }
        $triggers = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
             WHERE trigger_schema=DATABASE()
               AND event_object_table IN ('app_settings','cron_v3_queue_ownership')"
        )->fetchColumn();
        if ($triggers !== 0) {
            throw new RuntimeException('v3_retirement_write_trigger_invalid');
        }
    }

    /** @param array<string,?string> $settings @param list<array<string,mixed>> $ownership */
    private function preimageHash(array $settings, array $ownership, int $generation): string
    {
        ksort($settings, SORT_STRING);
        $settingLines = [];
        foreach ($settings as $key => $value) {
            $settingLines[] = $key . '=' . ($value ?? '<SQL-NULL>');
        }
        $ownershipLines = [];
        foreach ($ownership as $row) {
            $ownershipLines[] = implode('|', [
                $row['queue_key'],
                $row['lane'],
                $row['owner_engine'],
                (string) $row['enabled'],
                $row['changed_by'] ?? '<SQL-NULL>',
                $this->oracleTimestamp($row['changed_at'] ?? null),
            ]);
        }
        return hash('sha256',
            'erp-meli-2363-v3-retirement-preimage-v1|settings|' .
            ($settingLines === [] ? '<NONE>' : implode("\n", $settingLines)) .
            '|ownership|' . ($ownershipLines === [] ? '<NONE>' : implode("\n", $ownershipLines)) .
            '|engine|disabled|idle|' . $generation
        );
    }

    private function oracleTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '<SQL-NULL>';
        }
        $text = str_replace('T', ' ', (string) $value);
        if (!str_contains($text, '.')) {
            $text .= '.000000';
        } else {
            [$head, $fraction] = explode('.', $text, 2);
            $text = $head . '.' . str_pad(substr($fraction, 0, 6), 6, '0');
        }
        return str_replace(' ', 'T', $text) . 'Z';
    }

    /** @return array<string,mixed> */
    private function safeConfigAuthority(): array
    {
        return (new CronV3SetupAssistantService(null, $this->configPath))->retirementPreflightSnapshot();
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function sameConfigAuthority(array $before, array $after): bool
    {
        return hash_equals((string) ($before['config_sha256'] ?? ''), (string) ($after['config_sha256'] ?? ''))
            && ($before['effective_flags'] ?? null) === ($after['effective_flags'] ?? null)
            && ($before['process_override_conflicts'] ?? null) === ($after['process_override_conflicts'] ?? null)
            && !empty($after['ok']);
    }

    /** @param array<string,?string> $settings */
    private function countChangedSettings(array $settings): int
    {
        $changed = 0;
        foreach (self::TARGET_SETTINGS as $key => $value) {
            if (!array_key_exists($key, $settings) || $settings[$key] !== $value) {
                $changed++;
            }
        }
        return $changed;
    }

    private function upsertTargetSettings(PDO $pdo): void
    {
        foreach (self::TARGET_SETTINGS as $key => $value) {
            $this->upsertSetting($pdo, $key, $value, 'cron_v3');
        }
    }

    private function upsertSetting(PDO $pdo, string $key, string $value, string $group): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
             VALUES (?,?,0,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,setting_group=VALUES(setting_group)'
        );
        $statement->execute([$key, $value, $group]);
    }

    /** @return ?array<string,mixed> */
    private function storedReceipt(PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $statement->execute([self::RECEIPT_KEY]);
        $raw = $statement->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $receipt = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($receipt) ? $receipt : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeOwnership(array $row): array
    {
        return [
            'queue_key' => (string) ($row['queue_key'] ?? ''),
            'lane' => (string) ($row['lane'] ?? ''),
            'owner_engine' => (string) ($row['owner_engine'] ?? ''),
            'enabled' => (int) ($row['enabled'] ?? 0),
            'changed_by' => $row['changed_by'] === null ? null : (string) $row['changed_by'],
            'changed_at' => $row['changed_at'] === null ? null : (string) $row['changed_at'],
        ];
    }

    private function connection(): PDO
    {
        if ($this->pdoFactory instanceof Closure) {
            $pdo = ($this->pdoFactory)();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('v3_retirement_connection_factory_invalid');
            }
            return $pdo;
        }
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', 'localhost'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME', '')
            ),
            Env::get('DB_USER', ''),
            Env::get('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION time_zone='+00:00'");
        return $pdo;
    }

    private function hit(string $name): void
    {
        if ($this->failpoint instanceof Closure) {
            ($this->failpoint)($name);
        }
    }

    private function safeReason(Throwable $error): string
    {
        $reason = $error->getMessage();
        return str_starts_with($reason, 'v3_retirement_') ? $reason : 'v3_retirement_preflight_unavailable';
    }
}
