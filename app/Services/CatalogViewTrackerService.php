<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use Throwable;

final class CatalogViewTrackerService
{
    public function track(int $catalogId, ?int $catalogItemId = null): void
    {
        if (!(new AppSettingsService())->bool('catalog.tracking_enabled', false)) {
            return;
        }
        try {
            $ipHash = $this->hash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $uaHash = $this->hash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $day = gmdate('Y-m-d');
            $exists = Database::connection()->prepare(
                'SELECT id FROM catalog_views
                 WHERE catalog_id=:catalog AND ((catalog_item_id IS NULL AND :item_is_null=1) OR catalog_item_id=:item)
                   AND ip_hash=:ip_hash AND user_agent_hash=:ua_hash AND view_day=:day
                 LIMIT 1'
            );
            $exists->execute([
                'catalog' => $catalogId,
                'item_is_null' => $catalogItemId === null ? 1 : 0,
                'item' => $catalogItemId,
                'ip_hash' => $ipHash,
                'ua_hash' => $uaHash,
                'day' => $day,
            ]);
            if ($exists->fetchColumn()) {
                return;
            }
            Database::connection()->prepare(
                'INSERT INTO catalog_views (catalog_id,catalog_item_id,viewed_at,view_day,ip_hash,user_agent_hash,referrer)
                 VALUES (:catalog,:item,UTC_TIMESTAMP(),:day,:ip_hash,:ua_hash,:referrer)'
            )->execute([
                'catalog' => $catalogId,
                'item' => $catalogItemId,
                'day' => $day,
                'ip_hash' => $ipHash,
                'ua_hash' => $uaHash,
                'referrer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500) ?: null,
            ]);
        } catch (Throwable) {
            // El tracking nunca debe romper la experiencia pública.
        }
    }

    private function hash(string $value): string
    {
        return hash('sha256', Env::get('APP_KEY', 'catalog') . '|' . $value);
    }
}
