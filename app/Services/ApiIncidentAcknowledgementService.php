<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;
use Throwable;

final class ApiIncidentAcknowledgementService
{
    public function __construct(
        private readonly ApiHealthAccessScope $scope = new ApiHealthAccessScope(),
        private readonly ApiHealthSafeMessageService $messages = new ApiHealthSafeMessageService(),
    ) {}

    public function acknowledge(string $incidentKey, int $userId, string $note, ?string $scopeKey = null): int
    {
        $incidentKey = strtolower(trim($incidentKey));
        if (preg_match('/^[a-f0-9]{64}$/', $incidentKey) !== 1 || $userId <= 0) {
            throw new HttpException(404, 'No se encontró el incidente solicitado.');
        }
        $scopeKey = $scopeKey !== null ? trim($scopeKey) : null;
        if ($scopeKey !== null && !in_array($scopeKey, $this->scope->acknowledgementKeys(), true)) {
            throw new HttpException(404, 'No se encontró el incidente solicitado para ese alcance.');
        }

        $schema = new SchemaInspectorService();
        if (!$schema->hasColumn('api_request_logs', 'scope_kind')
            || !$schema->hasColumn('api_incident_acknowledgements', 'scope_key')) {
            throw new \RuntimeException('Complete la migración de incidentes antes de reconocer este evento.');
        }

        $predicate = $this->scope->predicate('l', 'ack');
        $stmt = Database::connection()->prepare(
            'SELECT CASE
                        WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                        WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("company:",l.company_id)
                        ELSE "application"
                    END scope_key,
                    MAX(l.created_at) acknowledged_through_at,
                    MAX(l.company_id) company_id,
                    MAX(l.meli_account_id) meli_account_id,
                    MAX(l.scope_kind) scope_kind
             FROM api_request_logs l
             WHERE l.incident_key=:incident_key AND ' . $predicate['sql'] . '
             GROUP BY scope_key'
        );
        $stmt->execute(array_merge(['incident_key' => $incidentKey], $predicate['params']));
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($scopeKey !== null) {
            $targets = array_values(array_filter(
                $targets,
                static fn (array $target): bool => hash_equals($scopeKey, (string) ($target['scope_key'] ?? ''))
            ));
        }
        if ($targets === []) {
            throw new HttpException(404, 'No se encontró el incidente solicitado.');
        }

        $safeNote = $this->messages->present($note)['safe_message'] ?: 'Revisado por administración';
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare(
                'INSERT INTO api_incident_acknowledgements
                    (incident_key,scope_key,scope_kind,company_id,meli_account_id,acknowledged_by,
                     acknowledged_at,acknowledged_through_at,note)
                 VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?,?)
                 ON DUPLICATE KEY UPDATE
                    acknowledged_by=VALUES(acknowledged_by),
                    acknowledged_at=UTC_TIMESTAMP(),
                    acknowledged_through_at=GREATEST(
                        COALESCE(acknowledged_through_at,"1970-01-01 00:00:00"),
                        VALUES(acknowledged_through_at)
                    ),
                    note=VALUES(note)'
            );
            $history = $pdo->prepare(
                'INSERT INTO api_incident_acknowledgement_events
                    (incident_key,scope_key,scope_kind,company_id,meli_account_id,acknowledged_by,
                     acknowledged_through_at,note,created_at)
                 VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
            );
            foreach ($targets as $target) {
                $values = [
                    $incidentKey,
                    (string) $target['scope_key'],
                    (string) $target['scope_kind'],
                    isset($target['company_id']) ? (int) $target['company_id'] : null,
                    isset($target['meli_account_id']) ? (int) $target['meli_account_id'] : null,
                    $userId,
                    (string) $target['acknowledged_through_at'],
                    $safeNote,
                ];
                $upsert->execute($values);
                $history->execute($values);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return count($targets);
    }
}
