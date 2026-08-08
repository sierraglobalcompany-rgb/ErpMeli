<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class NotificationLegacyNormalizationService
{
    /** @return array<string,mixed> */
    public function preview(int $limit = 5000): array
    {
        return $this->process($limit, false);
    }

    /** @return array<string,mixed> */
    public function normalizeBatch(int $limit = 500): array
    {
        $result = $this->process($limit, true);
        if ((int) ($result['errors'] ?? 0) > 0) {
            throw new RuntimeException(
                'Una notificación histórica no pudo clasificarse. '
                . 'La sesión se pausó sin omitirla ni reactivarla.'
            );
        }
        return $result;
    }

    /** @return array{reviewed:int,updated:int,complete:bool,errors:int} */
    public function compactMessageBatch(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        try {
            $settings = new AppSettingsService();
            $cursor = max(
                0,
                $settings->int('notifications.legacy_message_cursor_id', 0)
            );
            $pdo = Database::connection();
            $ids = $pdo->query(
                'SELECT id FROM meli_notification_events
                 WHERE id>' . $cursor . '
                   AND disposition IN (
                    "unknown_topic_legacy_local",
                    "recognized_ignored",
                    "satisfied_local",
                    "recognized_local_missing_no_replay"
                 )
                   AND error_message IS NOT NULL
                 ORDER BY id ASC
                 LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($ids === []) {
                return ['reviewed' => 0, 'updated' => 0, 'complete' => true, 'errors' => 0];
            }
            $maxId = max(array_map('intval', $ids));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                'UPDATE meli_notification_events SET error_message=NULL
                 WHERE id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_map('intval', $ids));
            $updated = max(0, $stmt->rowCount());
            $settings->set(
                'notifications.legacy_message_cursor_id',
                (string) $maxId,
                'notifications'
            );
            return [
                'reviewed' => count($ids),
                'updated' => $updated,
                'complete' => count($ids) < $limit,
                'errors' => 0,
            ];
        } catch (Throwable) {
            return [
                'reviewed' => 0,
                'updated' => 0,
                'complete' => false,
                'errors' => 1,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function process(int $limit, bool $apply): array
    {
        $limit = max(1, min(5000, $limit));
        $settings = new AppSettingsService();
        $cursor = $apply
            ? max(0, $settings->int('notifications.legacy_normalization_cursor_id', 0))
            : 0;
        $events = Database::connection()->query(
            'SELECT id,meli_account_id,meli_user_id,topic,actions_json,resource,
                    sent_at,erp_received_at,status
             FROM meli_notification_events
             WHERE id>' . $cursor . '
               AND (canonical_topic="" OR canonical_topic IS NULL)
               AND status IN ("queued","unknown_topic","waiting_retry")
             ORDER BY id ASC
             LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);

        $registry = new MeliNotificationTopicRegistry();
        $summary = $this->emptySummary();
        $classified = [];
        foreach ($events as $event) {
            $summary['reviewed']++;
            try {
                $classification = $registry->classify(
                    (string) $event['topic'],
                    isset($event['resource']) ? (string) $event['resource'] : null,
                    json_decode((string) ($event['actions_json'] ?? ''), true)
                );
                $classified[] = [
                    'event' => $event,
                    'classification' => $classification,
                ];
            } catch (Throwable) {
                $summary['errors']++;
            }
        }

        $localFreshness = $this->prefetchLocalFreshness($classified);
        $decisions = [];
        foreach ($classified as $row) {
            $event = $row['event'];
            $classification = $row['classification'];
            try {
                $decision = $this->decision($event, $classification, $localFreshness);
                $summary[$decision['bucket']]++;
                $key = (string) $event['topic'] . '|' . $decision['bucket'];
                $summary['topics'][$key] = ($summary['topics'][$key] ?? 0) + 1;
                $decisions[] = [
                    'event_id' => (int) $event['id'],
                    'classification' => $classification,
                    'decision' => $decision,
                ];
            } catch (Throwable) {
                $summary['errors']++;
            }
        }
        if ($apply && $decisions !== [] && $summary['errors'] === 0) {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $statement = $this->decisionStatement($pdo);
                foreach ($decisions as $row) {
                    $this->applyDecision(
                        $statement,
                        $row['event_id'],
                        $row['classification'],
                        $row['decision']
                    );
                    $summary['updated']++;
                }
                $lastId = max(array_map(
                    static fn (array $row): int => (int) $row['event_id'],
                    $decisions
                ));
                $settings->set(
                    'notifications.legacy_normalization_cursor_id',
                    (string) $lastId,
                    'notifications'
                );
                $pdo->commit();
            } catch (Throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $summary['updated'] = 0;
                $summary['errors']++;
            }
        }
        ksort($summary['topics']);
        $summary['complete'] = count($events) < $limit && $summary['errors'] === 0;
        $summary['applied'] = $apply;
        return $summary;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $classification
     * @param array<string,array<int,array<string,string>>> $localFreshness
     * @return array{bucket:string,status:string,disposition:string,validation:string,processed:bool,message:string}
     */
    private function decision(array $event, array $classification, array $localFreshness): array
    {
        if (empty($classification['valid'])) {
            return [
                'bucket' => 'invalid_unknown',
                'status' => 'unknown_topic',
                'disposition' => 'unknown_topic_legacy_local',
                'validation' => 'unknown_topic',
                'processed' => false,
                'message' => 'Topico no reconocido; normalizado sin reactivar trabajo remoto.',
            ];
        }

        if (empty($classification['actionable'])) {
            return [
                'bucket' => 'recognized_ignored',
                'status' => 'ignored',
                'disposition' => 'recognized_ignored',
                'validation' => 'valid',
                'processed' => true,
                'message' => 'Topico reconocido no accionable; conservado para rollup local.',
            ];
        }

        if ($this->satisfiedLocally($event, $classification, $localFreshness)) {
            return [
                'bucket' => 'satisfied_local',
                'status' => 'processed',
                'disposition' => 'satisfied_local',
                'validation' => 'valid',
                'processed' => true,
                'message' => 'Evento legacy reconocido y satisfecho por datos locales.',
            ];
        }

        return [
            'bucket' => 'recognized_local_missing',
            'status' => 'failed',
            'disposition' => 'recognized_local_missing_no_replay',
            'validation' => 'valid',
            'processed' => false,
            'message' => 'Evento legacy reconocido sin evidencia local; clasificado sin cerrar ni consultar API.',
        ];
    }

    private function decisionStatement(PDO $pdo): PDOStatement
    {
        return $pdo->prepare(
            'UPDATE meli_notification_events
             SET canonical_topic=:canonical_topic,
                 resource_type=:resource_type,
                 remote_resource_id=:remote_resource_id,
                 validation_status=:validation_status,
                 disposition=:disposition,
                 status=:status,
                 processed_at=CASE WHEN :processed=1 THEN COALESCE(processed_at,UTC_TIMESTAMP()) ELSE processed_at END,
                 error_message=NULL
             WHERE id=:id
               AND (canonical_topic="" OR canonical_topic IS NULL)
               AND status IN ("queued","unknown_topic","waiting_retry")'
        );
    }

    /** @param array<string,mixed> $classification @param array<string,mixed> $decision */
    private function applyDecision(
        PDOStatement $statement,
        int $eventId,
        array $classification,
        array $decision
    ): void
    {
        $canonicalTopic = trim((string) ($classification['canonical_topic'] ?? ''));
        if ($canonicalTopic === '') {
            $canonicalTopic = 'legacy_unknown';
        }
        $resourceType = trim((string) ($classification['resource_type'] ?? ''));
        if ($resourceType === '') {
            $resourceType = 'unknown';
        }
        $resourceId = trim((string) ($classification['resource_id'] ?? ''));
        if ($resourceId === '') {
            $resourceId = hash('sha256', $canonicalTopic . '|' . $eventId);
        }
        $statement->execute([
            'canonical_topic' => $canonicalTopic,
            'resource_type' => $resourceType,
            'remote_resource_id' => $resourceId,
            'validation_status' => $decision['validation'],
            'disposition' => $decision['disposition'],
            'status' => $decision['status'],
            'processed' => $decision['processed'] ? 1 : 0,
            'id' => $eventId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'La notificación cambió durante el lote y no fue sobrescrita.'
            );
        }
    }

    /**
     * @param list<array{event:array<string,mixed>,classification:array<string,mixed>}> $classified
     * @return array<string,array<int,array<string,string>>>
     */
    private function prefetchLocalFreshness(array $classified): array
    {
        $keys = [];
        foreach ($classified as $row) {
            $classification = $row['classification'];
            if (empty($classification['valid']) || empty($classification['actionable'])) {
                continue;
            }
            $accountId = (int) ($row['event']['meli_account_id'] ?? 0);
            $resourceType = (string) ($classification['resource_type'] ?? '');
            $resourceId = (string) ($classification['resource_id'] ?? '');
            if ($accountId <= 0 || $resourceId === '' || $this->localMap($resourceType) === null) {
                continue;
            }
            $keys[$resourceType][$accountId][$resourceId] = true;
        }

        $freshness = [];
        $schema = new SchemaInspectorService();
        foreach ($keys as $resourceType => $accounts) {
            $map = $this->localMap((string) $resourceType);
            if ($map === null) {
                continue;
            }
            [$table, $externalColumn, $freshColumn, $fallbackColumn] = $map;
            if (!$schema->hasTable($table)) {
                continue;
            }
            $columns = $schema->columns($table);
            $dateColumn = isset($columns[$freshColumn]) ? $freshColumn : $fallbackColumn;
            if (!isset($columns[$externalColumn], $columns['meli_account_id'], $columns[$dateColumn])) {
                continue;
            }
            foreach ($accounts as $accountId => $resourceIds) {
                foreach (array_chunk(array_keys($resourceIds), 1000) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = Database::connection()->prepare(
                        'SELECT `' . $externalColumn . '` resource_id,`' . $dateColumn . '` local_at
                         FROM `' . $table . '`
                         WHERE meli_account_id=?
                           AND `' . $externalColumn . '` IN (' . $placeholders . ')'
                    );
                    $stmt->execute([(int) $accountId, ...$chunk]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $local) {
                        $freshness[(string) $resourceType][(int) $accountId][(string) $local['resource_id']]
                            = (string) ($local['local_at'] ?? '');
                    }
                }
            }
        }
        return $freshness;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $classification
     * @param array<string,array<int,array<string,string>>> $localFreshness
     */
    private function satisfiedLocally(array $event, array $classification, array $localFreshness): bool
    {
        $accountId = (int) ($event['meli_account_id'] ?? 0);
        if ($accountId <= 0) {
            return false;
        }
        $resourceType = (string) ($classification['resource_type'] ?? '');
        $resourceId = (string) ($classification['resource_id'] ?? '');
        $localAt = $localFreshness[$resourceType][$accountId][$resourceId] ?? null;
        if ($localAt === null || $localAt === '') {
            return false;
        }
        $clock = new SystemDatabaseUtcClock();
        $eventAt = $clock->timestamp((string) (($event['sent_at'] ?? null) ?: ($event['erp_received_at'] ?? null))) ?? 0;
        return $eventAt <= 0 || ($clock->timestamp($localAt) ?? 0) >= $eventAt;
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function localMap(string $resourceType): ?array
    {
        return match ($resourceType) {
            'order' => ['meli_orders', 'external_order_id', 'last_updated_utc', 'synced_at'],
            'shipment' => ['meli_shipments', 'external_shipment_id', 'synced_at', 'synced_at'],
            'question' => ['meli_questions', 'external_question_id', 'synced_at', 'synced_at'],
            'item' => ['meli_items', 'external_item_id', 'updated_at', 'synced_at'],
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'reviewed' => 0,
            'updated' => 0,
            'satisfied_local' => 0,
            'recognized_ignored' => 0,
            'recognized_local_missing' => 0,
            'invalid_unknown' => 0,
            'errors' => 0,
            'topics' => [],
            'complete' => false,
            'applied' => false,
        ];
    }
}
