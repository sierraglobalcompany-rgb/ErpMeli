<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliCategoryService
{
    public function cached(string $categoryId): ?array
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM meli_categories
             WHERE external_category_id=:category_id
             ORDER BY last_synced_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['category_id' => $categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function resolve(string $categoryId, int $accountId): ?array
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return null;
        }
        $cached = $this->cached($categoryId);
        if ($cached && !empty($cached['name'])) {
            return $cached;
        }
        if ($accountId < 1) {
            return $cached;
        }
        $path = '/categories/' . rawurlencode($categoryId);
        if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
            // La categoría local sigue siendo utilizable. No se crea un error
            // ni un reintento para un contrato que aún no está aprobado.
            return $cached;
        }
        try {
            $payload = (new MeliApiClient($accountId))->get($path, [], ['job_type' => 'items_sync']);
            return $this->store($payload, $categoryId);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo resolver categoría Mercado Libre.', [
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return $cached;
        }
    }

    /**
     * @param array<int,array{category_id?:string,account_id?:int}> $pairs
     * @return array<string,array>
     */
    public function resolveMany(array $pairs): array
    {
        $resolved = [];
        foreach ($pairs as $pair) {
            $categoryId = trim((string) ($pair['category_id'] ?? ''));
            if ($categoryId === '' || isset($resolved[$categoryId])) {
                continue;
            }
            $category = $this->resolve($categoryId, (int) ($pair['account_id'] ?? 0));
            if ($category) {
                $resolved[$categoryId] = $category;
            }
        }
        return $resolved;
    }

    public function leafName(array $category): ?string
    {
        $path = json_decode((string) ($category['path_from_root_json'] ?? ''), true);
        if (is_array($path) && $path !== []) {
            $last = end($path);
            if (is_array($last) && !empty($last['name'])) {
                return (string) $last['name'];
            }
        }
        return !empty($category['name']) ? (string) $category['name'] : null;
    }

    private function store(array $payload, string $fallbackId): array
    {
        $externalId = (string) ($payload['id'] ?? $fallbackId);
        $siteId = preg_match('/^([A-Z]{2,4})/', $externalId, $m) ? $m[1] : 'MCO';
        $path = is_array($payload['path_from_root'] ?? null) ? $payload['path_from_root'] : [];
        $parentId = null;
        if (count($path) > 1) {
            $parent = $path[count($path) - 2];
            $parentId = is_array($parent) ? (string) ($parent['id'] ?? '') : null;
        } elseif (!empty($payload['parent_category_id'])) {
            $parentId = (string) $payload['parent_category_id'];
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = $externalId;
        }
        Database::connection()->prepare(
            'INSERT INTO meli_categories
             (external_category_id,site_id,name,path_from_root_json,parent_category_id,raw_json,last_synced_at)
             VALUES (:external,:site,:name,:path,:parent,:raw,NOW())
             ON DUPLICATE KEY UPDATE
                name=VALUES(name),
                path_from_root_json=VALUES(path_from_root_json),
                parent_category_id=VALUES(parent_category_id),
                raw_json=VALUES(raw_json),
                last_synced_at=NOW()'
        )->execute([
            'external' => $externalId,
            'site' => $siteId,
            'name' => mb_substr($name, 0, 190),
            'path' => json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parent' => $parentId ?: null,
            'raw' => json_encode(Logger::redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $this->cached($externalId) ?: [
            'external_category_id' => $externalId,
            'site_id' => $siteId,
            'name' => $name,
            'path_from_root_json' => json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parent_category_id' => $parentId,
        ];
    }
}
