<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class CatalogService
{
    private const RESERVED_SLUGS = ['admin', 'api', 'assets', 'catalogs', 'login', 'logout', 'orders', 'products', 'public', 'settings', 'sync', 'users'];

    public function list(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(c.name LIKE :q_name OR c.slug LIKE :q_slug)';
            $params['q_name'] = '%' . trim((string) $filters['q']) . '%';
            $params['q_slug'] = '%' . trim((string) $filters['q']) . '%';
        }
        $stmt = Database::connection()->prepare(
            'SELECT c.*, a.account_name, u.name created_by_name,
                    COALESCE(stats.total_items, 0) total_items,
                    COALESCE(stats.visible_items, 0) visible_items
             FROM catalogs c
             LEFT JOIN meli_accounts a ON a.id=c.meli_account_id
             LEFT JOIN users u ON u.id=c.created_by
             LEFT JOIN (
                SELECT catalog_id, SUM(total_items) total_items, SUM(visible_items) visible_items
                FROM (
                    SELECT catalog_id, COUNT(*) total_items, SUM(CASE WHEN is_visible=1 THEN 1 ELSE 0 END) visible_items
                    FROM catalog_items
                    GROUP BY catalog_id
                    UNION ALL
                    SELECT catalog_id, COUNT(*) total_items, SUM(CASE WHEN is_visible=1 THEN 1 ELSE 0 END) visible_items
                    FROM catalog_internal_items
                    GROUP BY catalog_id
                ) u
                GROUP BY catalog_id
             ) stats ON stats.catalog_id=c.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accounts(): array
    {
        return Database::connection()->query('SELECT id,account_name FROM meli_accounts ORDER BY account_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function companies(): array
    {
        return (new CompanyOptionService())->active();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalogs WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalogs WHERE slug=:slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(array $data): array
    {
        $this->assertCanManage();
        $slug = $this->validateSlug((string) ($data['slug'] ?? ''), null);
        $visibility = $this->visibility((string) ($data['visibility'] ?? 'internal_only'));
        if ($visibility === 'public' && Auth::role() !== 'admin') {
            throw new RuntimeException('Solo admin puede habilitar catálogos públicos.');
        }
        $plainToken = null;
        $tokenHash = null;
        if ($visibility === 'private_token') {
            $plainToken = $this->newPrivateToken();
            $tokenHash = hash('sha256', $plainToken);
        }
        $passwordHash = null;
        if ($visibility === 'private_password' && trim((string) ($data['password'] ?? '')) !== '') {
            $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        if ($visibility === 'private_password' && $passwordHash === null) {
            throw new RuntimeException('Debe definir una clave inicial para el catálogo privado.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO catalogs
             (name,slug,description,visibility,access_token_hash,password_hash,is_public,is_enabled,allow_indexing,allow_public_print,allow_private_print,account_scope,meli_account_id,category_scope,source_type,company_scope,company_id,include_out_of_stock,show_prices,show_stock,show_stock_breakdown,show_full_badge,show_shipping_methods,show_stock_detail_public,show_sold_count,show_meli_link,show_internal_sku,show_status,show_created_date,show_updated_date,show_public_advanced_filters,layout_type,created_by,updated_by,public_statuses_json)
             VALUES
             (:name,:slug,:description,:visibility,:token_hash,:password_hash,:is_public,:is_enabled,:allow_indexing,:allow_public_print,:allow_private_print,:account_scope,:account_id,:category_scope,:source_type,:company_scope,:company_id,:include_out_of_stock,:show_prices,:show_stock,:show_stock_breakdown,:show_full,:show_shipping_methods,:show_stock_detail_public,:show_sold,:show_link,:show_sku,:show_status,:show_created,:show_updated,:show_public_advanced_filters,:layout,:created_user_id,:updated_user_id,:statuses)'
        );
        $stmt->execute($this->payload($data, $slug, $visibility, $tokenHash, $passwordHash) + [
            'created_user_id' => Auth::id(),
            'updated_user_id' => Auth::id(),
            'statuses' => json_encode($this->publicStatusesFromData($data), JSON_UNESCAPED_UNICODE),
        ]);
        return ['id' => (int) Database::connection()->lastInsertId(), 'slug' => $slug, 'plain_token' => $plainToken];
    }

    public function update(int $id, array $data): void
    {
        $this->assertCanManage();
        $catalog = $this->find($id);
        if (!$catalog) {
            throw new RuntimeException('Catálogo no encontrado.');
        }
        $visibility = $this->visibility((string) ($data['visibility'] ?? $catalog['visibility']));
        if (($visibility === 'public' || (int) ($data['is_public'] ?? 0) === 1) && Auth::role() !== 'admin') {
            throw new RuntimeException('Solo admin puede habilitar catálogos públicos.');
        }
        $slug = $this->validateSlug((string) ($data['slug'] ?? $catalog['slug']), $id);
        $stmt = Database::connection()->prepare(
            'UPDATE catalogs SET
                name=:name, slug=:slug, description=:description, visibility=:visibility,
                is_public=:is_public, is_enabled=:is_enabled, allow_indexing=:allow_indexing,
                allow_public_print=:allow_public_print, allow_private_print=:allow_private_print,
                account_scope=:account_scope, meli_account_id=:account_id, category_scope=:category_scope,
                source_type=:source_type, company_scope=:company_scope, company_id=:company_id,
                include_out_of_stock=:include_out_of_stock, show_prices=:show_prices, show_stock=:show_stock,
                show_stock_breakdown=:show_stock_breakdown, show_full_badge=:show_full,
                show_shipping_methods=:show_shipping_methods, show_stock_detail_public=:show_stock_detail_public,
                show_sold_count=:show_sold, show_meli_link=:show_link,
                show_internal_sku=:show_sku, show_status=:show_status, show_created_date=:show_created,
                show_updated_date=:show_updated, show_public_advanced_filters=:show_public_advanced_filters,
                public_statuses_json=:statuses, layout_type=:layout, updated_by=:user_id
             WHERE id=:id'
        );
        $payload = $this->payload($data, $slug, $visibility, null, null);
        unset($payload['token_hash'], $payload['password_hash']);
        if ($visibility === 'private_password' && empty($catalog['password_hash'])) {
            throw new RuntimeException('Debe crear una clave privada antes de usar esta visibilidad.');
        }
        if ($visibility === 'private_token' && empty($catalog['access_token_hash'])) {
            throw new RuntimeException('Debe regenerar el token privado antes de usar esta visibilidad.');
        }
        $stmt->execute($payload + [
            'user_id' => Auth::id(),
            'id' => $id,
            'statuses' => json_encode($this->publicStatusesFromData($data), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function regenerateToken(int $id): string
    {
        $this->assertCanManageSecurity();
        $plain = $this->newPrivateToken();
        Database::connection()->prepare(
            'UPDATE catalogs
             SET visibility="private_token",
                 is_enabled=1,
                 expires_at=NULL,
                 access_token_hash=:hash,
                 last_private_access_error=NULL,
                 last_private_access_error_at=NULL,
                 last_token_regenerated_at=NOW(),
                 updated_by=:user
             WHERE id=:id'
        )
            ->execute(['hash' => hash('sha256', $plain), 'user' => Auth::id(), 'id' => $id]);
        return $plain;
    }

    public function privateAccessDiagnostics(array $catalog): array
    {
        $access = new CatalogAccessService();
        $status = $access->publicAccessStatus($catalog, '');
        return [
            'enabled' => (int) ($catalog['is_enabled'] ?? 0) === 1 ? 'Habilitado' : 'Deshabilitado',
            'visibility' => $access->accessLabel($catalog),
            'has_token' => trim((string) ($catalog['access_token_hash'] ?? '')) !== '' ? 'Sí' : 'No',
            'token_hash_length' => trim((string) ($catalog['access_token_hash'] ?? '')) !== '' ? (string) mb_strlen(trim((string) $catalog['access_token_hash'])) : '0',
            'expires_at' => !empty($catalog['expires_at']) ? (string) $catalog['expires_at'] : 'Sin vencimiento',
            'last_error' => (string) ($catalog['last_private_access_error'] ?? $status['status']),
            'last_error_at' => (string) ($catalog['last_private_access_error_at'] ?? ''),
            'current_status' => (string) $status['status'],
            'current_message' => (string) $status['message'],
            'last_token_regenerated_at' => (string) ($catalog['last_token_regenerated_at'] ?? ''),
        ];
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->assertCanManageSecurity();
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('La clave privada debe tener al menos 6 caracteres.');
        }
        Database::connection()->prepare('UPDATE catalogs SET visibility="private_password", password_hash=:hash, updated_by=:user WHERE id=:id')
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'user' => Auth::id(), 'id' => $id]);
    }

    public function toggleItem(int $catalogItemId, bool $visible, ?string $reason = null, string $sourceType = 'meli'): void
    {
        $this->assertCanManage();
        $table = $sourceType === 'internal' ? 'catalog_internal_items' : 'catalog_items';
        Database::connection()->prepare(
            'UPDATE ' . $table . '
             SET visibility_override=:override, is_visible=:visible, hidden_by=:hidden_by, hidden_at=:hidden_at, hidden_reason=:reason
             WHERE id=:id'
        )->execute([
            'override' => $visible ? 'visible' : 'hidden',
            'visible' => $visible ? 1 : 0,
            'hidden_by' => $visible ? null : Auth::id(),
            'hidden_at' => $visible ? null : date('Y-m-d H:i:s'),
            'reason' => $visible ? null : mb_substr((string) $reason, 0, 255),
            'id' => $catalogItemId,
        ]);
    }

    public function updateCategory(int $categoryId, string $displayName, bool $visible, bool $featured = false, int $sortOrder = 0): void
    {
        $this->assertCanManage();
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new RuntimeException('El nombre visible de la categoría es obligatorio.');
        }
        Database::connection()->prepare(
            'UPDATE catalog_categories
             SET display_name=:display_name, is_visible=:visible, is_featured=:featured, sort_order=:sort_order,
                 renamed_at=CASE WHEN display_name<>:display_name_check THEN NOW() ELSE renamed_at END,
                 hidden_at=CASE WHEN :visible_check=0 THEN COALESCE(hidden_at,NOW()) ELSE NULL END
             WHERE id=:id'
        )->execute([
            'display_name' => mb_substr($displayName, 0, 190),
            'display_name_check' => mb_substr($displayName, 0, 190),
            'visible' => $visible ? 1 : 0,
            'visible_check' => $visible ? 1 : 0,
            'featured' => $featured ? 1 : 0,
            'sort_order' => $sortOrder,
            'id' => $categoryId,
        ]);
    }

    public function metrics(int $catalogId): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('catalog_items') && !$schema->hasTable('catalog_internal_items')) {
            return [
                'total' => 0,
                'active' => 0,
                'paused' => 0,
                'out_stock' => 0,
                'full_items' => 0,
                'without_sku' => 0,
                'without_image' => 0,
                'without_category' => 0,
                'without_link' => 0,
            ];
        }

        $branches = [];
        $params = [];
        if ($schema->hasTable('catalog_items')) {
            $linkSql = $schema->hasTable('product_meli_links')
                ? 'LEFT JOIN (
                    SELECT meli_item_id, MAX(id) active_link_id
                    FROM product_meli_links
                    WHERE status="active"
                    GROUP BY meli_item_id
                ) link ON link.meli_item_id=ci.meli_item_id'
                : 'LEFT JOIN (SELECT NULL meli_item_id, NULL active_link_id WHERE 1=0) link ON link.meli_item_id=ci.meli_item_id';
            $branches[] = 'SELECT COUNT(*) total,
                       SUM(CASE WHEN ci.status="active" THEN 1 ELSE 0 END) active,
                       SUM(CASE WHEN ci.status="paused" THEN 1 ELSE 0 END) paused,
                       SUM(CASE WHEN ci.stock_available<=0 THEN 1 ELSE 0 END) out_stock,
                       SUM(CASE WHEN ci.is_full=1 THEN 1 ELSE 0 END) full_items,
                       SUM(CASE WHEN ci.sku_snapshot IS NULL OR ci.sku_snapshot="" THEN 1 ELSE 0 END) without_sku,
                       SUM(CASE WHEN ci.thumbnail_url IS NULL OR ci.thumbnail_url="" THEN 1 ELSE 0 END) without_image,
                       SUM(CASE WHEN ci.category_id IS NULL OR ci.category_id="" THEN 1 ELSE 0 END) without_category,
                       SUM(CASE WHEN link.active_link_id IS NULL THEN 1 ELSE 0 END) without_link
                FROM catalog_items ci
                ' . $linkSql . '
                WHERE ci.catalog_id=:catalog_meli';
            $params['catalog_meli'] = $catalogId;
        }
        if ($schema->hasTable('catalog_internal_items')) {
            $branches[] = 'SELECT COUNT(*) total,
                       SUM(CASE WHEN status="active" THEN 1 ELSE 0 END) active,
                       SUM(CASE WHEN status="paused" THEN 1 ELSE 0 END) paused,
                       SUM(CASE WHEN stock_available<=0 THEN 1 ELSE 0 END) out_stock,
                       0 full_items,
                       SUM(CASE WHEN sku_snapshot IS NULL OR sku_snapshot="" THEN 1 ELSE 0 END) without_sku,
                       SUM(CASE WHEN thumbnail_url IS NULL OR thumbnail_url="" THEN 1 ELSE 0 END) without_image,
                       SUM(CASE WHEN category_id IS NULL OR category_id="" THEN 1 ELSE 0 END) without_category,
                       0 without_link
                FROM catalog_internal_items
                WHERE catalog_id=:catalog_internal';
            $params['catalog_internal'] = $catalogId;
        }

        $stmt = Database::connection()->prepare(
            'SELECT
                COALESCE(SUM(total),0) total,
                COALESCE(SUM(active),0) active,
                COALESCE(SUM(paused),0) paused,
                COALESCE(SUM(out_stock),0) out_stock,
                COALESCE(SUM(full_items),0) full_items,
                COALESCE(SUM(without_sku),0) without_sku,
                COALESCE(SUM(without_image),0) without_image,
                COALESCE(SUM(without_category),0) without_category,
                COALESCE(SUM(without_link),0) without_link
             FROM (' . implode(' UNION ALL ', $branches) . ') stats'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn($value) => (int) $value, $row);
    }

    public function assertCanManage(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar catálogos.');
        }
    }

    public function assertCanManageSecurity(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar seguridad del catálogo.');
        }
    }

    private function validateSlug(string $slug, ?int $ignoreId): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?: '';
        $slug = trim($slug, '-');
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new RuntimeException('El slug solo puede contener minúsculas, números y guiones.');
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new RuntimeException('Este slug está reservado.');
        }
        $sql = 'SELECT id FROM catalogs WHERE slug=:slug';
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id<>:id';
            $params['id'] = $ignoreId;
        }
        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Ya existe un catálogo con ese slug.');
        }
        return $slug;
    }

    private function visibility(string $visibility): string
    {
        return in_array($visibility, ['public', 'private_token', 'private_password', 'internal_only'], true) ? $visibility : 'internal_only';
    }

    private function newPrivateToken(): string
    {
        return strtolower(bin2hex(random_bytes(24)));
    }

    private function publicStatusesFromData(array $data): array
    {
        $allowed = ['active', 'paused', 'closed', 'inactive', 'under_review'];
        $selected = $data['public_statuses'] ?? ['active'];
        if (!is_array($selected)) {
            $selected = ['active'];
        }
        $statuses = array_values(array_intersect($allowed, array_map('strval', $selected)));
        return $statuses !== [] ? $statuses : ['active'];
    }

    private function payload(array $data, string $slug, string $visibility, ?string $tokenHash, ?string $passwordHash): array
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 160);
        if ($name === '') {
            throw new RuntimeException('El nombre del catálogo es obligatorio.');
        }
        $accountScope = (string) ($data['account_scope'] ?? 'all');
        $accountId = (int) ($data['meli_account_id'] ?? 0);
        if ($accountScope !== 'single_account') {
            $accountScope = 'all';
            $accountId = 0;
        }
        $sourceType = (string) ($data['source_type'] ?? 'meli');
        if (!in_array($sourceType, ['meli', 'internal', 'combined'], true)) {
            $sourceType = 'meli';
        }
        $companyScope = (string) ($data['company_scope'] ?? 'all');
        $companyId = (int) ($data['company_id'] ?? 0);
        if ($companyScope !== 'single_company') {
            $companyScope = 'all';
            $companyId = 0;
        }
        return [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'visibility' => $visibility,
            'token_hash' => $tokenHash,
            'password_hash' => $passwordHash,
            'is_public' => $visibility === 'public' ? 1 : (int) ($data['is_public'] ?? 0),
            'is_enabled' => $visibility === 'private_token' ? 1 : (!empty($data['is_enabled']) ? 1 : 0),
            'allow_indexing' => !empty($data['allow_indexing']) ? 1 : 0,
            'allow_public_print' => !empty($data['allow_public_print']) ? 1 : 0,
            'allow_private_print' => !empty($data['allow_private_print']) ? 1 : 0,
            'account_scope' => $accountScope,
            'account_id' => $accountId > 0 ? $accountId : null,
            'category_scope' => in_array(($data['category_scope'] ?? 'all'), ['all', 'selected'], true) ? (string) $data['category_scope'] : 'all',
            'source_type' => $sourceType,
            'company_scope' => $companyScope,
            'company_id' => $companyId > 0 ? $companyId : null,
            'include_out_of_stock' => !empty($data['include_out_of_stock']) ? 1 : 0,
            'show_prices' => !empty($data['show_prices']) ? 1 : 0,
            'show_stock' => !empty($data['show_stock']) ? 1 : 0,
            'show_stock_breakdown' => !empty($data['show_stock_breakdown']) ? 1 : 0,
            'show_full' => !empty($data['show_full_badge']) ? 1 : 0,
            'show_shipping_methods' => !empty($data['show_shipping_methods']) ? 1 : 0,
            'show_stock_detail_public' => !empty($data['show_stock_detail_public']) ? 1 : 0,
            'show_sold' => !empty($data['show_sold_count']) ? 1 : 0,
            'show_link' => !empty($data['show_meli_link']) ? 1 : 0,
            'show_sku' => !empty($data['show_internal_sku']) ? 1 : 0,
            'show_status' => !empty($data['show_status']) ? 1 : 0,
            'show_created' => !empty($data['show_created_date']) ? 1 : 0,
            'show_updated' => !empty($data['show_updated_date']) ? 1 : 0,
            'show_public_advanced_filters' => !empty($data['show_public_advanced_filters']) ? 1 : 0,
            'layout' => in_array(($data['layout_type'] ?? 'ml_blue'), ['ml_blue', 'clean_white', 'compact'], true) ? (string) $data['layout_type'] : 'ml_blue',
        ];
    }
}
