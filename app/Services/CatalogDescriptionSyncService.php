<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class CatalogDescriptionSyncService
{
    /**
     * @return array<string, int>
     */
    public function summary(int $catalogId): array
    {
        $empty = [
            'total' => 0,
            'confirmed' => 0,
            'missing' => 0,
            'unavailable' => 0,
            'error' => 0,
            'pending' => 0,
        ];
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('catalog_items') || !$schema->hasTable('meli_item_descriptions')) {
            return $empty;
        }

        $stmt = Database::connection()->prepare(
            'SELECT
                COUNT(*) total,
                SUM(CASE WHEN d.source_status="confirmed" THEN 1 ELSE 0 END) confirmed,
                SUM(CASE WHEN d.id IS NULL THEN 1 ELSE 0 END) missing,
                SUM(CASE WHEN d.source_status="unavailable" THEN 1 ELSE 0 END) unavailable,
                SUM(CASE WHEN d.source_status="error" THEN 1 ELSE 0 END) error,
                SUM(CASE WHEN d.source_status="pending" THEN 1 ELSE 0 END) pending
             FROM catalog_items ci
             LEFT JOIN meli_item_descriptions d ON d.meli_item_id=ci.meli_item_id
             WHERE ci.catalog_id=:catalog AND ci.meli_item_id IS NOT NULL'
        );
        $stmt->execute(['catalog' => $catalogId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'confirmed' => (int) ($row['confirmed'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
            'unavailable' => (int) ($row['unavailable'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function syncCatalog(int $catalogId, bool $retryErrors = false, bool $forceRefresh = false, ?int $limit = null): array
    {
        $settings = new AppSettingsService();
        $limit = $limit ?? $settings->int('catalog.description_batch_limit', 20);
        $limit = max(1, min(50, $limit));

        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('catalog_items') || !$schema->hasTable('meli_item_descriptions')) {
            return [
                'processed' => 0,
                'confirmed' => 0,
                'unavailable' => 0,
                'errors' => 0,
                'remaining' => 0,
                'message' => 'Falta la migración de descripciones del catálogo.',
            ];
        }

        $where = ['ci.catalog_id=:catalog', 'ci.meli_item_id IS NOT NULL'];
        if (!$forceRefresh) {
            $where[] = $retryErrors
                ? '(d.id IS NULL OR d.source_status IN ("pending","error"))'
                : '(d.id IS NULL OR d.source_status="pending")';
        }

        $stmt = Database::connection()->prepare(
            'SELECT ci.meli_item_id, ci.meli_account_id, ci.external_item_id
             FROM catalog_items ci
             LEFT JOIN meli_item_descriptions d ON d.meli_item_id=ci.meli_item_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY
                CASE
                    WHEN d.id IS NULL THEN 0
                    WHEN d.source_status="pending" THEN 1
                    WHEN d.source_status="error" THEN 2
                    ELSE 3
                END,
                ci.updated_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['catalog' => $catalogId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $confirmed = 0;
        $unavailable = 0;
        $errors = 0;
        $lastError = '';
        $lastDiagnosticId = null;

        foreach ($items as $item) {
            try {
                $snapshot = (new MeliItemDescriptionService((int) $item['meli_account_id']))
                    ->syncByItemId((int) $item['meli_item_id'], (string) $item['external_item_id']);
                $processed++;
                $status = (string) ($snapshot['source_status'] ?? 'error');
                if ($status === 'confirmed') {
                    $confirmed++;
                } elseif ($status === 'unavailable') {
                    $unavailable++;
                } else {
                    $errors++;
                    $lastError = (string) ($snapshot['safe_error_message'] ?? 'No se pudo sincronizar la descripción.');
                    $lastDiagnosticId = !empty($snapshot['diagnostic_id']) ? (string) $snapshot['diagnostic_id'] : null;
                    if ($this->shouldStopOnApiError($lastError)) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $processed++;
                $errors++;
                $safe = SafeErrorPresenter::report($e, 'No fue posible actualizar la descripción de esta publicación.', [
                    'module' => 'catalogs',
                    'catalog_id' => $catalogId,
                    'meli_item_id' => (int) ($item['meli_item_id'] ?? 0),
                ]);
                $lastError = mb_substr($safe['message'], 0, 500);
                $lastDiagnosticId = $safe['reference'];
                Logger::write('error', 'Error actualizando descripción de catálogo.', [
                    'module' => 'catalogs',
                    'catalog_id' => $catalogId,
                    'meli_item_id' => (int) ($item['meli_item_id'] ?? 0),
                    'diagnostic_id' => $lastDiagnosticId,
                ]);
                if ($this->shouldStopOnApiError($lastError)) {
                    break;
                }
            }
        }

        $summary = $this->summary($catalogId);
        $remaining = $forceRefresh
            ? max(0, $summary['total'] - $summary['confirmed'] - $summary['unavailable'])
            : $summary['missing'] + $summary['pending'] + ($retryErrors ? $summary['error'] : 0);
        $message = 'Descripciones actualizadas: ' . $processed . ' procesadas, ' . $confirmed . ' confirmadas, ' . $unavailable . ' sin descripción, ' . $errors . ' errores.';
        if ($lastError !== '') {
            $message .= ' Último error seguro: ' . mb_substr($lastError, 0, 180);
        }

        $this->markCatalog($catalogId, $errors > 0 ? 'partial' : 'success', $message, $lastDiagnosticId);

        return [
            'processed' => $processed,
            'confirmed' => $confirmed,
            'unavailable' => $unavailable,
            'errors' => $errors,
            'remaining' => $remaining,
            'message' => $message,
        ];
    }

    private function markCatalog(int $catalogId, string $status, string $message, ?string $diagnosticId = null): void
    {
        $schema = new SchemaInspectorService();
        $sets = [];
        $params = ['id' => $catalogId];
        if ($schema->hasColumn('catalogs', 'last_description_sync_at')) {
            $sets[] = 'last_description_sync_at=NOW()';
        }
        if ($schema->hasColumn('catalogs', 'last_description_sync_status')) {
            $sets[] = 'last_description_sync_status=:status';
            $params['status'] = $status;
        }
        if ($schema->hasColumn('catalogs', 'last_description_sync_message')) {
            $sets[] = 'last_description_sync_message=:message';
            $params['message'] = mb_substr($message, 0, 500);
        }
        if ($schema->hasColumn('catalogs', 'last_description_sync_diagnostic_id')) {
            $sets[] = 'last_description_sync_diagnostic_id=:diagnostic';
            $params['diagnostic'] = $diagnosticId !== null ? mb_substr($diagnosticId, 0, 80) : null;
        }
        if ($schema->hasColumn('catalogs', 'updated_by')) {
            $sets[] = 'updated_by=:user';
            $params['user'] = Auth::id();
        }
        if ($sets === []) {
            return;
        }
        $stmt = Database::connection()->prepare('UPDATE catalogs SET ' . implode(',', $sets) . ' WHERE id=:id');
        $stmt->execute($params);
    }

    private function shouldStopOnApiError(string $message): bool
    {
        $lower = mb_strtolower($message);
        return str_contains($lower, '429')
            || str_contains($lower, 'rate')
            || str_contains($lower, 'retry-after')
            || str_contains($lower, '403')
            || str_contains($lower, 'circuit');
    }
}
