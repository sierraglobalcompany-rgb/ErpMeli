<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Observabilidad mínima anterior al contrato de release.
 *
 * Nunca lanza excepciones: si la instalación aún no tiene la tabla, el
 * lanzador continúa con su comportamiento seguro habitual.
 */
final class CronBootstrapJournalService
{
    /** @return array{id:int,token:string} */
    public function begin(
        string $component,
        string $version,
        string $build,
        string $source
    ): array {
        $token = bin2hex(random_bytes(16));
        try {
            if (!(new SchemaInspectorService())->hasTable('system_cron_boot_attempts')) {
                return ['id' => 0, 'token' => $token];
            }
            $pid = function_exists('getmypid') ? (string) (getmypid() ?: '') : '';
            $stmt = Database::connectionFresh()->prepare(
                'INSERT INTO system_cron_boot_attempts
                 (run_token,component_key,release_version,release_build_id,execution_source,
                  stage,result_state,process_id_hash)
                 VALUES (?,?,?,?,?,"invoked","running",?)'
            );
            $stmt->execute([
                $token,
                mb_substr($component, 0, 80),
                mb_substr($version, 0, 30) ?: null,
                mb_substr($build, 0, 80) ?: null,
                in_array($source, ['scheduled_cli', 'manual_cli'], true) ? $source : 'unknown',
                $pid !== '' ? substr(hash('sha256', $pid . '|' . $token), 0, 16) : null,
            ]);
            return ['id' => (int) Database::connectionFresh()->lastInsertId(), 'token' => $token];
        } catch (Throwable) {
            return ['id' => 0, 'token' => $token];
        }
    }

    public function stage(int $id, string $stage, ?string $message = null): void
    {
        if ($id <= 0 || !in_array($stage, [
            'invoked', 'validating_installation', 'preparing_queues', 'processing',
            'finished', 'stopped_before_queues',
        ], true)) {
            return;
        }
        try {
            Database::connectionFresh()->prepare(
                'UPDATE system_cron_boot_attempts
                 SET stage=?,heartbeat_at=UTC_TIMESTAMP(3),
                     safe_message=COALESCE(?,safe_message)
                 WHERE id=? AND result_state="running"'
            )->execute([
                $stage,
                $message !== null ? mb_substr(Logger::redactString($message), 0, 500) : null,
                $id,
            ]);
        } catch (Throwable) {
        }
    }

    public function finish(
        int $id,
        string $result,
        int $processed = 0,
        int $errors = 0,
        bool $reachedRemote = false,
        ?string $message = null,
        ?string $diagnosticId = null
    ): void {
        if ($id <= 0) {
            return;
        }
        $result = in_array($result, ['success', 'partial', 'skipped', 'error'], true)
            ? $result
            : 'error';
        try {
            Database::connectionFresh()->prepare(
                'UPDATE system_cron_boot_attempts
                 SET stage=IF(?="error","stopped_before_queues","finished"),
                     result_state=?,processed_count=?,error_count=?,reached_remote=?,
                     safe_message=?,diagnostic_id=?,heartbeat_at=UTC_TIMESTAMP(3),
                     finished_at=UTC_TIMESTAMP(3),
                     duration_ms=TIMESTAMPDIFF(MICROSECOND,started_at,UTC_TIMESTAMP(3)) DIV 1000
                 WHERE id=? AND result_state="running"'
            )->execute([
                $result,
                $result,
                max(0, $processed),
                max(0, $errors),
                $reachedRemote ? 1 : 0,
                $message !== null ? mb_substr(Logger::redactString($message), 0, 500) : null,
                $diagnosticId !== null ? mb_substr($diagnosticId, 0, 80) : null,
                $id,
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array<string,mixed>|null */
    public function latest(string $component = 'process_sync_queue'): ?array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_cron_boot_attempts')) {
                return null;
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT * FROM system_cron_boot_attempts
                 WHERE component_key=? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$component]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }
}
