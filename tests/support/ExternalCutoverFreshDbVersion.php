<?php

declare(strict_types=1);

final class ExternalCutoverFreshDbVersion
{
    /** @param Closure():PDO $connect */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $lockName = 'erp_meli_external_cutover_2_36_2',
    ) {
    }

    /** @return array{connection_id:int,target_history:?array<string,mixed>} */
    public function promote(string $source, string $target): array
    {
        $pdo = $this->freshVerifiedConnection();
        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $history = $pdo->prepare('SELECT id,version,notes,installed_at FROM app_versions WHERE version=? LIMIT 1');
        $history->execute([$target]);
        $targetHistory = $history->fetch(PDO::FETCH_ASSOC);
        $this->mutate($pdo, $source, $target, static function (PDO $pdo) use ($target): void {
            $statement = $pdo->prepare(
                'INSERT INTO app_versions(version,notes,installed_at) VALUES (?,?,UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=VALUES(installed_at)'
            );
            $statement->execute([$target, 'External managed cutover 2.36.2.']);
        });

        return ['connection_id' => $connectionId, 'target_history' => is_array($targetHistory) ? $targetHistory : null];
    }

    /** @param array{connection_id:int,target_history:?array<string,mixed>} $receipt */
    public function rollback(string $target, string $source, array $receipt): int
    {
        // Deliberately open a new connection here. No PDO from promote() is
        // retained across filesystem/FPM waits or rollback signalling.
        $pdo = $this->freshVerifiedConnection();
        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->mutate($pdo, $target, $source, static function (PDO $pdo) use ($target, $receipt): void {
            $history = $receipt['target_history'];
            if ($history === null) {
                $delete = $pdo->prepare('DELETE FROM app_versions WHERE version=?');
                $delete->execute([$target]);
                return;
            }
            $restore = $pdo->prepare('UPDATE app_versions SET notes=?,installed_at=? WHERE id=? AND version=?');
            $restore->execute([$history['notes'], $history['installed_at'], $history['id'], $target]);
            if ($restore->rowCount() !== 1) {
                throw new RuntimeException('target_history_restore_failed');
            }
        });

        return $connectionId;
    }

    /** @param Closure(PDO):void $historyMutation */
    private function mutate(PDO $pdo, string $expected, string $next, Closure $historyMutation): void
    {
        $lockAcquired = (int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($this->lockName) . ",0)")->fetchColumn() === 1;
        if (!$lockAcquired) {
            throw new RuntimeException('fresh_db_lock_unavailable');
        }
        try {
            $pdo->beginTransaction();
            $current = trim((string) $pdo->query(
                "SELECT setting_value FROM app_settings WHERE setting_key='app.version' FOR UPDATE"
            )->fetchColumn());
            if ($current !== $expected) {
                throw new RuntimeException('unexpected_app_version');
            }
            $update = $pdo->prepare(
                "UPDATE app_settings SET setting_value=?,is_encrypted=0,setting_group='app' "
                . "WHERE setting_key='app.version'"
            );
            $update->execute([$next]);
            $historyMutation($pdo);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($this->lockName) . ")");
            } catch (Throwable) {
            }
        }
    }

    private function freshVerifiedConnection(): PDO
    {
        $pdo = ($this->connect)();
        $server = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        if (!str_contains($server, 'mariadb')) {
            throw new RuntimeException('mariadb_required');
        }
        $rows = (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations "
            . "WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED) BETWEEN 280 AND 293"
        )->fetchColumn();
        $maximum = (int) $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)) FROM schema_migrations"
        )->fetchColumn();
        if ($rows !== 14 || $maximum !== 293) {
            throw new RuntimeException('schema_293_exact_required');
        }
        return $pdo;
    }
}
