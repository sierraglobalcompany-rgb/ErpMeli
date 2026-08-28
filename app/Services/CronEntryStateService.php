<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class CronEntryStateService
{
    /** @param array<string,mixed> $extra */
    public function record(string $stage, string $version, string $build, array $extra = []): void
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_cron_entry_states')) {
                return;
            }
            $allowed = [
                'php_opened', 'bootstrap_loaded', 'database_connected', 'installation_validated',
                'lock_acquired', 'queues_prepared', 'work_selected', 'finished',
                'duplicate_skipped', 'failed_before_bootstrap',
            ];
            $stage = in_array($stage, $allowed, true) ? $stage : 'failed_before_bootstrap';
            Database::connectionFresh()->prepare(
                'INSERT INTO system_cron_entry_states
                 (component_key,release_version,release_build_id,stage,result_state,
                  processed_count,reached_remote,diagnostic_id,observed_at)
                 VALUES ("queue_v4_clean",?,?,?,?,?,?,?,UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    release_version=VALUES(release_version),
                    release_build_id=VALUES(release_build_id),
                    stage=VALUES(stage),
                    result_state=VALUES(result_state),
                    processed_count=VALUES(processed_count),
                    reached_remote=VALUES(reached_remote),
                    diagnostic_id=VALUES(diagnostic_id),
                    observed_at=VALUES(observed_at)'
            )->execute([
                mb_substr($version, 0, 30),
                mb_substr($build, 0, 80),
                $stage,
                mb_substr((string) ($extra['result'] ?? 'running'), 0, 40),
                max(0, (int) ($extra['processed'] ?? 0)),
                !empty($extra['remote']) ? 1 : 0,
                !empty($extra['diagnostic']) ? mb_substr((string) $extra['diagnostic'], 0, 80) : null,
            ]);
        } catch (Throwable) {
            // La observabilidad nunca detiene el lanzador.
        }
    }
}
