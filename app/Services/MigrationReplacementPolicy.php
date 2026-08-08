<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class MigrationReplacementPolicy
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $manifestPath
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array{
     *   authorized:bool,
     *   reason:string,
     *   old_checksum:?string,
     *   new_checksum:string,
     *   source_diagnostic_id:?string,
     *   authorization_id:?string
     * }
     */
    public function evaluate(
        string $migrationKey,
        string $registeredChecksum,
        string $observedChecksum,
        array $metadata,
        string $diagnosticId,
        bool $persistAuthorization = true
    ): array {
        $state = (string) ($metadata['state'] ?? '');
        $rule = $this->rule($migrationKey, $registeredChecksum, $observedChecksum, $state);
        if ($rule === null) {
            return $this->decision(false, 'rule_missing', null, $observedChecksum);
        }

        $oldChecksum = (string) $rule['old_checksum'];
        $newChecksum = (string) $rule['new_checksum'];
        if ($this->isApplied($migrationKey)) {
            return $this->decision(false, 'already_applied', $oldChecksum, $newChecksum);
        }

        $driftRecovery = $state === 'drifted'
            && hash_equals($newChecksum, $registeredChecksum)
            && hash_equals($newChecksum, $observedChecksum);
        $regularReplacement = hash_equals($oldChecksum, $registeredChecksum)
            && hash_equals($newChecksum, $observedChecksum);

        if (!$driftRecovery && !$regularReplacement) {
            return $this->decision(false, 'checksum_mismatch', $oldChecksum, $newChecksum);
        }
        $requiredStates = isset($rule['required_state_any']) && is_array($rule['required_state_any'])
            ? array_values(array_map('strval', $rule['required_state_any']))
            : [(string) ($rule['required_state'] ?? 'failed')];
        if ($regularReplacement && !in_array($state, $requiredStates, true)) {
            return $this->decision(false, 'state_mismatch', $oldChecksum, $newChecksum);
        }
        if ($driftRecovery && !$this->hasDriftAttempt($migrationKey, $newChecksum)) {
            return $this->decision(false, 'drift_attempt_missing', $oldChecksum, $newChecksum);
        }

        $ignoreSourceFailure = !empty($rule['ignore_source_failure_when_schema_matches']);
        $event = $ignoreSourceFailure ? null : $this->sourceFailure($migrationKey, $oldChecksum);
        $sourceFailureOptional = !empty($rule['source_failure_optional']);
        if ($event === null && !$sourceFailureOptional) {
            return $this->decision(false, 'source_failure_missing', $oldChecksum, $newChecksum);
        }
        if ($event !== null) {
            $context = is_string($event['context_json'] ?? null)
                ? json_decode((string) $event['context_json'], true)
                : [];
            $failedStage = is_array($context) && is_string($context['failed_stage'] ?? null)
                ? $context['failed_stage']
                : (string) ($event['stage'] ?? '');
            $eventMatches = true;
            if ($failedStage !== (string) ($rule['required_stage'] ?? '')) {
                $eventMatches = false;
            }
            if ((string) ($event['driver_code'] ?? '') !== (string) ($rule['required_driver_code'] ?? '')) {
                $eventMatches = false;
            }
            $requiredSqlState = (string) ($rule['required_sql_state'] ?? '');
            if ($requiredSqlState !== '' && strcasecmp((string) ($event['sql_state'] ?? ''), $requiredSqlState) !== 0) {
                $eventMatches = false;
            }
            if (!$this->messageMatches((string) ($event['safe_message'] ?? ''), $rule)) {
                $eventMatches = false;
            }
            if (!$eventMatches && $sourceFailureOptional) {
                $event = null;
            } elseif (!$eventMatches && $failedStage !== (string) ($rule['required_stage'] ?? '')) {
                return $this->decision(false, 'wrong_failed_stage', $oldChecksum, $newChecksum);
            } elseif (!$eventMatches && (string) ($event['driver_code'] ?? '') !== (string) ($rule['required_driver_code'] ?? '')) {
                return $this->decision(false, 'wrong_driver_code', $oldChecksum, $newChecksum);
            } elseif (!$eventMatches && $requiredSqlState !== '' && strcasecmp((string) ($event['sql_state'] ?? ''), $requiredSqlState) !== 0) {
                return $this->decision(false, 'wrong_sql_state', $oldChecksum, $newChecksum);
            } elseif (!$eventMatches) {
                return $this->decision(false, 'message_signature_mismatch', $oldChecksum, $newChecksum);
            }
        }
        if (empty($rule['allow_corrected_sql_reentry']) && $this->correctedSqlStarted($migrationKey, $newChecksum)) {
            return $this->decision(false, 'corrected_sql_already_started', $oldChecksum, $newChecksum);
        }
        if (!$this->schemaMatches($rule)) {
            return $this->decision(false, 'unexpected_schema', $oldChecksum, $newChecksum);
        }
        if (empty($rule['skip_live_module_job_check']) && $this->hasLiveModuleJob()) {
            return $this->decision(false, 'active_module_job', $oldChecksum, $newChecksum);
        }

        $sourceDiagnosticId = is_array($event) ? (string) ($event['diagnostic_id'] ?? '') : '';
        $authorizationId = (string) ($rule['authorization_id'] ?? '');
        if (!$persistAuthorization) {
            return [
                'authorized' => true,
                'reason' => 'authorized',
                'old_checksum' => $oldChecksum,
                'new_checksum' => $newChecksum,
                'source_diagnostic_id' => $sourceDiagnosticId !== '' ? $sourceDiagnosticId : null,
                'authorization_id' => $authorizationId !== '' ? $authorizationId : null,
                'checks' => [
                    'source_failure_found' => $event !== null,
                    'source_failure_ignored_by_rule' => $ignoreSourceFailure,
                    'partial_schema_valid' => true,
                    'corrected_sql_not_started' => true,
                    'no_active_module_job' => !empty($rule['skip_live_module_job_check']) ? null : true,
                    'not_applied' => true,
                ],
            ];
        }
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO system_update_migration_replacements
             (migration_key,old_checksum_sha256,new_checksum_sha256,authorization_id,source_diagnostic_id,authorized_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $insert->execute([
            $migrationKey,
            $oldChecksum,
            $newChecksum,
            $authorizationId,
            $sourceDiagnosticId !== '' ? $sourceDiagnosticId : $diagnosticId,
        ]);

        if ($insert->rowCount() !== 1 && !$this->wasAuthorized($migrationKey, $oldChecksum, $newChecksum)) {
            return $this->decision(false, 'authorization_write_failed', $oldChecksum, $newChecksum);
        }

        return [
            'authorized' => true,
            'reason' => 'authorized',
            'old_checksum' => $oldChecksum,
            'new_checksum' => $newChecksum,
            'source_diagnostic_id' => $sourceDiagnosticId !== '' ? $sourceDiagnosticId : null,
            'authorization_id' => $authorizationId !== '' ? $authorizationId : null,
            'checks' => [
                'source_failure_found' => $event !== null,
                'source_failure_ignored_by_rule' => $ignoreSourceFailure,
                'partial_schema_valid' => true,
                'corrected_sql_not_started' => true,
                'no_active_module_job' => !empty($rule['skip_live_module_job_check']) ? null : true,
                'not_applied' => true,
            ],
        ];
    }

    /**
     * @return array{
     *   authorized:bool,
     *   reason:string,
     *   old_checksum:?string,
     *   new_checksum:string,
     *   source_diagnostic_id:?string,
     *   authorization_id:?string
     * }
     */
    private function decision(bool $authorized, string $reason, ?string $oldChecksum, string $newChecksum): array
    {
        return [
            'authorized' => $authorized,
            'reason' => $reason,
            'old_checksum' => $oldChecksum,
            'new_checksum' => $newChecksum,
            'source_diagnostic_id' => null,
            'authorization_id' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function rule(
        string $migrationKey,
        string $registeredChecksum,
        string $observedChecksum,
        string $state
    ): ?array {
        $document = json_decode((string) @file_get_contents($this->manifestPath), true);
        foreach ((array) ($document['replacements'] ?? []) as $rule) {
            if (!is_array($rule)
                || !hash_equals((string) ($rule['migration_key'] ?? ''), $migrationKey)
                || !hash_equals((string) ($rule['new_checksum'] ?? ''), $observedChecksum)) {
                continue;
            }
            if (hash_equals((string) ($rule['old_checksum'] ?? ''), $registeredChecksum)
                || ($state === 'drifted' && hash_equals((string) ($rule['new_checksum'] ?? ''), $registeredChecksum))) {
                return $rule;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function sourceFailure(string $migrationKey, string $checksum): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT diagnostic_id,stage,sql_state,driver_code,exception_class,safe_message,context_json
                 FROM system_update_migration_events
                 WHERE migration_key=? AND checksum_sha256=? AND status='failed'
                   AND stage IN ('migration_failed','run_failed')
                 ORDER BY CASE WHEN stage='migration_failed' THEN 0 ELSE 1 END,id DESC LIMIT 1"
            );
            $stmt->execute([$migrationKey, $checksum]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $rule */
    private function messageMatches(string $message, array $rule): bool
    {
        $normalized = $this->normalizeMessage($message);
        $needles = (array) ($rule['message_contains_all'] ?? []);
        if ($needles === [] && isset($rule['message_contains'])) {
            $needles = [(string) $rule['message_contains']];
        }
        if ($needles === []) {
            return false;
        }
        foreach ($needles as $needle) {
            if (!str_contains($normalized, $this->normalizeMessage((string) $needle))) {
                return false;
            }
        }
        return true;
    }

    private function normalizeMessage(string $message): string
    {
        $message = mb_strtolower($message);
        $message = str_replace(['`', '"', "'"], '', $message);
        return trim((string) preg_replace('/\s+/u', ' ', $message));
    }

    private function isApplied(string $migrationKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
        $stmt->execute([$migrationKey]);
        return (bool) $stmt->fetchColumn();
    }

    private function hasDriftAttempt(string $migrationKey, string $checksum): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM system_update_migration_events
             WHERE migration_key=? AND checksum_sha256=? AND stage='checksum_drifted' AND status='drifted'
             LIMIT 1"
        );
        $stmt->execute([$migrationKey, $checksum]);
        return (bool) $stmt->fetchColumn();
    }

    private function correctedSqlStarted(string $migrationKey, string $checksum): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM system_update_migration_events
             WHERE migration_key=? AND checksum_sha256=? AND stage='sql_execution_started'
             LIMIT 1"
        );
        $stmt->execute([$migrationKey, $checksum]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $rule */
    private function schemaMatches(array $rule): bool
    {
        foreach ((array) ($rule['required_columns_present'] ?? []) as $column) {
            if (!$this->columnExists('system_module_jobs', (string) $column)) {
                return false;
            }
        }
        foreach ((array) ($rule['required_table_columns_absent'] ?? []) as $qualifiedColumn) {
            $parts = explode('.', (string) $qualifiedColumn, 2);
            if (count($parts) !== 2) {
                return false;
            }
            if ((new InformationSchemaGateway($this->pdo))->hasColumn($parts[0], $parts[1])) {
                return false;
            }
        }
        $activeColumn = (string) ($rule['active_column'] ?? '');
        if ($activeColumn !== '' && $this->columnExists('system_module_jobs', $activeColumn)) {
            if (str_contains(strtoupper((string) (new InformationSchemaGateway($this->pdo))->columnExtra('system_module_jobs', $activeColumn)), 'GENERATED')) {
                return false;
            }
        }
        $forbiddenIndex = (string) ($rule['required_index_absent'] ?? '');
        if ($forbiddenIndex !== '' && (new InformationSchemaGateway($this->pdo))->hasIndex('system_module_jobs', $forbiddenIndex)) {
            return false;
        }
        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (new InformationSchemaGateway($this->pdo))->hasColumn($table, $column);
    }

    private function hasLiveModuleJob(): bool
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT 1 FROM system_module_jobs
                 WHERE status='running' AND lock_expires_at IS NOT NULL AND lock_expires_at>=UTC_TIMESTAMP()
                 LIMIT 1"
            );
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return true;
        }
    }

    private function wasAuthorized(string $migrationKey, string $oldChecksum, string $newChecksum): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM system_update_migration_replacements
             WHERE migration_key=? AND old_checksum_sha256=? AND new_checksum_sha256=? LIMIT 1'
        );
        $stmt->execute([$migrationKey, $oldChecksum, $newChecksum]);
        return (bool) $stmt->fetchColumn();
    }
}
