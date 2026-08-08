<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliItemDescriptionService
{
    public function __construct(private readonly int $accountId) {}

    public function fetchRemoteDescription(string $externalId): array
    {
        return (new MeliApiClient($this->accountId))->get('/items/' . rawurlencode($externalId) . '/description', [], ['job_type' => 'description_job', 'bulk' => true]);
    }

    public function fetchRemoteSnapshot(string $externalId): array
    {
        try {
            $raw = $this->fetchRemoteDescription($externalId);
            $plain = $this->sanitizeText((string) ($raw['plain_text'] ?? ''));
            $text = $this->sanitizeText((string) ($raw['text'] ?? ''));
            return [
                'source_status' => ($plain !== '' || $text !== '') ? 'confirmed' : 'unavailable',
                'plain_text' => $plain !== '' ? $plain : null,
                'description_text' => $text !== '' ? $text : null,
                'description_hash' => hash('sha256', $plain !== '' ? $plain : $text),
                'raw' => $raw,
                'safe_error_message' => null,
            ];
        } catch (Throwable $e) {
            $status = $e instanceof MeliApiException && $e->httpStatus === 404 ? 'unavailable' : 'error';
            $safe = SafeErrorPresenter::report(
                $e,
                $status === 'unavailable'
                    ? 'Mercado Libre no tiene una descripción disponible para esta publicación.'
                    : 'No fue posible actualizar la descripción de esta publicación.',
                ['module' => 'meli_item_description', 'account_id' => $this->accountId]
            );
            return [
                'source_status' => $status,
                'plain_text' => null,
                'description_text' => null,
                'description_hash' => null,
                'raw' => null,
                'safe_error_message' => mb_substr($safe['message'], 0, 500),
                'diagnostic_id' => $safe['reference'],
            ];
        }
    }

    public function syncByItemId(int $meliItemId, ?string $externalId = null): ?array
    {
        if (!$this->hasDescriptionTable() || $meliItemId < 1) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT meli_account_id, external_item_id
             FROM meli_items
             WHERE id=:id
             LIMIT 1'
        );
        $stmt->execute(['id' => $meliItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return null;
        }
        $external = $externalId ?: (string) $item['external_item_id'];
        $snapshot = $this->fetchRemoteSnapshot($external);
        $this->persistSnapshot($meliItemId, (int) $item['meli_account_id'], $external, $snapshot);
        return $snapshot;
    }

    public function persistSnapshot(int $meliItemId, int $accountId, string $externalId, array $snapshot): void
    {
        if (!$this->hasDescriptionTable() || $meliItemId < 1 || $externalId === '') {
            return;
        }
        $raw = is_array($snapshot['raw'] ?? null)
            ? json_encode(Logger::redact($snapshot['raw']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $parameters = [
            'item' => $meliItemId,
            'account' => $accountId,
            'external' => $externalId,
            'plain' => $snapshot['plain_text'] ?? null,
            'text' => $snapshot['description_text'] ?? null,
            'status' => $snapshot['source_status'] ?? 'pending',
            'error' => isset($snapshot['safe_error_message']) ? mb_substr((string) $snapshot['safe_error_message'], 0, 500) : null,
            'diagnostic' => !empty($snapshot['diagnostic_id']) ? mb_substr((string) $snapshot['diagnostic_id'], 0, 80) : null,
            'raw' => $raw,
        ];
        Database::executeWithReconnect(static function (PDO $pdo) use ($parameters): void {
            $stmt = $pdo->prepare(
                'INSERT INTO meli_item_descriptions
                 (meli_item_id,meli_account_id,external_item_id,plain_text,description_text,source_status,safe_error_message,diagnostic_id,raw_json,synced_at)
                 VALUES (:item,:account,:external,:plain,:text,:status,:error,:diagnostic,:raw,NOW())
                 ON DUPLICATE KEY UPDATE
                    meli_account_id=VALUES(meli_account_id),
                    external_item_id=VALUES(external_item_id),
                    plain_text=VALUES(plain_text),
                    description_text=VALUES(description_text),
                    source_status=VALUES(source_status),
                    safe_error_message=VALUES(safe_error_message),
                    diagnostic_id=VALUES(diagnostic_id),
                    raw_json=VALUES(raw_json),
                    synced_at=NOW()'
            );
            $stmt->execute($parameters);
        });
    }

    public static function cachedForItem(int $meliItemId): ?array
    {
        if ($meliItemId < 1 || !(new SchemaInspectorService())->hasTable('meli_item_descriptions')) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT *
             FROM meli_item_descriptions
             WHERE meli_item_id=:item
             LIMIT 1'
        );
        $stmt->execute(['item' => $meliItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function hasDescriptionTable(): bool
    {
        return (new SchemaInspectorService())->hasTable('meli_item_descriptions');
    }

    private function sanitizeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? $value;
        $value = preg_replace("/\n{4,}/", "\n\n\n", $value) ?? $value;
        return trim($value);
    }
}
