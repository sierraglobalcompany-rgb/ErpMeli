<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$pageUrl = static function (int $page) use ($base, $catalog, $filters): string {
    $query = array_filter($filters + ['page' => $page], static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return $base . '/catalogs/' . (int) $catalog['id'] . '?' . http_build_query($query);
};
$money = static fn($value): string => $value === null ? '—' : '$ ' . number_format((float) $value, 0, ',', '.');
$shippingLabels = static function (array $item): array {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        return array_values(array_filter(array_map(static fn($row) => (string) ($row['label'] ?? ''), $decoded)));
    }
    return [(int) ($item['is_full'] ?? 0) === 1 ? 'FULL confirmado' : 'Logística no identificada'];
};
$primaryLogistic = static function (array $item): string {
    $type = trim((string) ($item['logistic_type'] ?? ''));
    $mode = trim((string) ($item['shipping_mode'] ?? ''));
    if ($type === '' && $mode === '') {
        return 'No identificado';
    }
    return trim(($mode !== '' ? $mode . ' / ' : '') . ($type !== '' ? $type : 'sin logistic_type'));
};
$shippingConfidence = static function (array $item): string {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        foreach ($decoded as $row) {
            if (($row['confidence'] ?? '') === 'Confirmado por API local') {
                return 'Confirmado por API local';
            }
        }
    }
    return 'No identificado';
};
$stockStatus = static fn(?string $status): string => [
    'confirmed' => 'Confirmado',
    'partial' => 'Parcial',
    'not_available' => 'No disponible',
    'unknown' => 'No confirmado',
][$status ?? 'unknown'] ?? 'No confirmado';
$shippingMethodOptions = is_array($shippingMethods ?? null) ? $shippingMethods : [];
$renderShippingOptions = static function (array $options, string $selected): void {
    foreach ($options as $option) {
        $code = (string) ($option['code'] ?? '');
        if ($code === '') {
            continue;
        }
        $label = (string) ($option['label'] ?? $code);
        $total = (int) ($option['total'] ?? 0);
        $suffix = $total > 0 ? ' (' . $total . ')' : ' (sin productos)';
        ?><option value="<?= View::e($code) ?>" <?= $selected === $code ? 'selected' : '' ?>><?= View::e($label . $suffix) ?></option><?php
    }
};
?>
<div class="page-head catalog-admin-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs">← Volver a catálogos</a>
    <h1><?= View::e($catalog['name']) ?></h1>
    <p>Administración privada. Los productos vienen de snapshots locales; esta pantalla no consulta Mercado Libre.</p>
  </div>
  <div class="catalog-admin-toolbar">
    <a class="btn primary" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/edit">Editar catálogo</a>
    <details class="catalog-action-menu">
      <summary class="btn">Compartir</summary>
      <div class="catalog-action-menu-popover">
        <?php if (($catalog['visibility'] ?? '') === 'public'): ?>
          <a class="catalog-action-menu-item" href="<?= View::e($base) ?>/catalogo/<?= View::e($catalog['slug']) ?>" target="_blank" rel="noopener">Abrir catálogo público</a>
        <?php elseif (($catalog['visibility'] ?? '') === 'private_token' && Auth::role() === 'admin'): ?>
          <form method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/token/regenerate">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
            <button class="catalog-action-menu-item">Generar enlace privado</button>
          </form>
        <?php else: ?>
          <span class="catalog-action-menu-item muted">Catálogo sin enlace compartible</span>
        <?php endif; ?>
      </div>
    </details>
    <details class="catalog-action-menu">
      <summary class="btn">Exportar</summary>
      <div class="catalog-action-menu-popover">
        <a class="catalog-action-menu-item" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/print?<?= View::e(http_build_query($filters)) ?>" target="_blank">Imprimir catálogo</a>
        <a class="catalog-action-menu-item" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/export?<?= View::e(http_build_query($filters)) ?>">Exportar CSV</a>
      </div>
    </details>
    <?php if (in_array(Auth::role(), ['admin', 'operador'], true)): ?>
      <details class="catalog-action-menu">
        <summary class="btn">Actualizar datos</summary>
        <div class="catalog-action-menu-popover catalog-action-menu-popover-wide">
          <?php if (Auth::role() === 'admin'): ?>
            <form method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/categories/resolve">
              <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
              <button class="catalog-action-menu-item">Categorías Mercado Libre</button>
            </form>
            <form method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/stock/resolve">
              <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
              <button class="catalog-action-menu-item">Stock por origen</button>
            </form>
          <?php endif; ?>
          <a class="catalog-action-menu-item" href="#catalog-description-jobs">Descripciones de productos</a>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php if ($tokenUrl = \App\Core\Session::flash('success')): ?>
  <div class="alert success"><?= View::e($tokenUrl) ?></div>
<?php endif; ?>
<?php if ($error = \App\Core\Session::flash('error')): ?>
  <div class="alert danger"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (!empty($schemaWarning)): ?>
  <div class="alert warning"><?= View::e($schemaWarning) ?></div>
<?php endif; ?>
<?php if ($privateLink = \App\Core\Session::flash('private_link')): ?>
  <section class="panel panel-body catalog-private-link-box">
    <h2>Enlace privado del catálogo</h2>
    <p class="muted">Cópielo ahora. Por seguridad el token completo no queda guardado en texto plano y no se podrá volver a ver; si lo pierde, genere otro enlace.</p>
    <div class="copy-link-row">
      <input class="input" readonly value="<?= View::e($privateLink) ?>" data-copy-source>
      <button type="button" class="btn primary" data-copy-text="<?= View::e($privateLink) ?>">Copiar enlace</button>
    </div>
  </section>
<?php elseif (($catalog['visibility'] ?? '') === 'private_token'): ?>
  <div class="alert warning">Este catálogo es privado por enlace. La URL pública sin token no abre; genere un enlace privado si necesita compartirlo.</div>
<?php endif; ?>

<?php if (($catalog['visibility'] ?? '') === 'private_token' && Auth::role() === 'admin'): ?>
  <section class="panel panel-body">
    <h2>Diagnóstico del enlace privado</h2>
    <div class="table-wrap">
      <table class="table compact">
        <tbody>
          <tr><th>Estado catálogo</th><td><?= View::e($accessDiagnostics['enabled'] ?? '—') ?></td></tr>
          <tr><th>Visibilidad</th><td><?= View::e($accessDiagnostics['visibility'] ?? '—') ?></td></tr>
          <tr><th>Tiene token guardado</th><td><?= View::e($accessDiagnostics['has_token'] ?? '—') ?></td></tr>
          <tr><th>Longitud hash guardado</th><td><?= View::e($accessDiagnostics['token_hash_length'] ?? '—') ?> caracteres</td></tr>
          <tr><th>Vencimiento</th><td><?= View::e($accessDiagnostics['expires_at'] ?? '—') ?></td></tr>
          <tr><th>Estado actual sin token</th><td><?= View::e(($accessDiagnostics['current_message'] ?? '—') . ' (' . ($accessDiagnostics['current_status'] ?? '—') . ')') ?></td></tr>
          <tr><th>Último rechazo</th><td><?= View::e(($accessDiagnostics['last_error'] ?? '—') . (!empty($accessDiagnostics['last_error_at']) ? ' · ' . $accessDiagnostics['last_error_at'] : '')) ?></td></tr>
          <tr><th>Última regeneración</th><td><?= View::e($accessDiagnostics['last_token_regenerated_at'] ?: '—') ?></td></tr>
        </tbody>
      </table>
    </div>
    <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/token/test">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input class="input" name="token" placeholder="Pegar token privado para probar coincidencia">
      <button class="btn">Probar token</button>
    </form>
    <p class="muted">Esta prueba no guarda ni muestra el token; solo verifica si coincide con el hash actual.</p>
  </section>
<?php endif; ?>

<section class="metrics catalog-metrics">
  <?php foreach ([
    'total'=>'Total interno','public_visible'=>'Visible público','active'=>'Activos','paused'=>'Pausados','out_stock'=>'Sin stock',
    'full_items'=>'FULL','without_sku'=>'Sin SKU','without_image'=>'Sin imagen','without_link'=>'Sin vínculo',
  ] as $key => $label): ?>
    <div class="metric-card compact"><div><div class="metric-label"><?= View::e($label) ?></div><div class="metric-value"><?= (int) ($metrics[$key] ?? 0) ?></div></div></div>
  <?php endforeach; ?>
</section>

<section class="panel panel-body mt-2" id="catalog-description-jobs">
  <div class="section-head">
    <div>
      <h2>Descripciones Mercado Libre</h2>
      <p class="muted">Se cachean desde Mercado Libre con acción interna; el catálogo público solo lee la caché local.</p>
    </div>
  </div>
  <div class="metrics catalog-metrics">
    <?php foreach ([
      'total'=>'Publicaciones ML',
      'confirmed'=>'Con descripción',
      'missing'=>'Pendientes',
      'unavailable'=>'Sin descripción ML',
      'error'=>'Con error',
      'pending'=>'En espera',
    ] as $key => $label): ?>
      <div class="metric-card compact"><div><div class="metric-label"><?= View::e($label) ?></div><div class="metric-value"><?= (int) ($descriptionSummary[$key] ?? 0) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($catalog['last_description_sync_at']) || !empty($catalog['last_description_sync_message'])): ?>
    <p class="muted">
      Última actualización:
      <?= View::e($catalog['last_description_sync_at'] ?? '—') ?>
      <?= !empty($catalog['last_description_sync_status']) ? ' · ' . View::e($catalog['last_description_sync_status']) : '' ?>
      <?= !empty($catalog['last_description_sync_message']) ? ' · ' . View::e($catalog['last_description_sync_message']) : '' ?>
    </p>
  <?php endif; ?>
  <?php if (in_array(Auth::role(), ['admin', 'operador'], true)): ?>
    <form class="catalog-description-create mt-2" method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/description-jobs">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <div class="field">
        <label for="description-job-mode">Qué actualizar</label>
        <select class="input" id="description-job-mode" name="mode">
          <option value="missing">Completar descripciones faltantes</option>
          <option value="retry">Reintentar pendientes y errores</option>
          <?php if (Auth::role() === 'admin'): ?><option value="refresh">Actualizar nuevamente todas las existentes</option><?php endif; ?>
        </select>
      </div>
      <button class="btn primary">Crear trabajo y ver progreso</button>
      <a class="btn" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/description-jobs">Ver historial</a>
    </form>
          <p class="muted">El trabajo procesa 20 productos por etapa, espera 30 segundos y conserva el avance. Si cierras la pantalla, el lanzador único puede retomarlo.</p>
  <?php endif; ?>
  <?php if (!empty($descriptionJobs)): ?>
    <div class="catalog-description-recent">
      <?php foreach ($descriptionJobs as $descriptionJob): ?>
        <a href="<?= View::e($base) ?>/catalogs/description-jobs/<?= (int) $descriptionJob['id'] ?>">
          <strong>Trabajo #<?= (int) $descriptionJob['id'] ?></strong>
          <span><?= View::e((string) $descriptionJob['status']) ?></span>
          <span><?= (int) $descriptionJob['processed_items'] ?> / <?= (int) $descriptionJob['total_items'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ((int) ($metrics['total'] ?? 0) !== (int) ($metrics['public_visible'] ?? 0)): ?>
  <div class="alert info">
    El total interno incluye snapshots pausados, sin stock u ocultos. La página pública muestra <?= (int) ($metrics['public_visible'] ?? 0) ?> productos según la visibilidad y estados permitidos del catálogo.
  </div>
<?php endif; ?>

<section class="panel filter-bar">
  <form class="filters<?= !empty($progressive) ? ' js-async-filter' : '' ?>" method="get"<?= !empty($progressive) ? ' data-section-target="catalog-items"' : '' ?>>
    <div class="field"><label>Buscar</label><input class="input" name="q" value="<?= View::e($filters['q'] ?? '') ?>" placeholder="Título, SKU o ID ML"></div>
    <div class="field"><label>Fuente</label><select class="input" name="source_type"><option value="">Todas</option><option value="meli" <?= ($filters['source_type'] ?? '') === 'meli' ? 'selected' : '' ?>>Mercado Libre</option><option value="internal" <?= ($filters['source_type'] ?? '') === 'internal' ? 'selected' : '' ?>>Bodega interna</option></select></div>
    <div class="field"><label>Empresa</label><select class="input" name="company_id"><option value="0">Todas</option><?php foreach (($companies ?? []) as $company): ?><option value="<?= (int) $company['id'] ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= View::e($company['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Categoría</label><select class="input" name="category"><option value="">Todas</option><?php foreach ($categories as $cat): ?><option value="<?= View::e($cat['slug']) ?>" <?= ($filters['category_slug'] ?? '') === $cat['slug'] ? 'selected' : '' ?>><?= View::e($cat['display_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Estado</label><select class="input" name="status"><option value="">Todos</option><?php foreach (['active'=>'Activo','paused'=>'Pausado','closed'=>'Finalizado'] as $v=>$l): ?><option value="<?= $v ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Stock</label><select class="input" name="stock"><option value="">Todos</option><option value="in" <?= ($filters['stock'] ?? '') === 'in' ? 'selected' : '' ?>>Con stock</option><option value="out" <?= ($filters['stock'] ?? '') === 'out' ? 'selected' : '' ?>>Sin stock</option></select></div>
    <div class="field"><label>FULL</label><select class="input" name="full"><option value="">Todos</option><option value="yes" <?= ($filters['full'] ?? '') === 'yes' ? 'selected' : '' ?>>FULL confirmado</option><option value="no" <?= ($filters['full'] ?? '') === 'no' ? 'selected' : '' ?>>No FULL / sin identificar</option></select></div>
    <div class="field"><label>Métodos disponibles</label><select class="input" name="shipping_method"><option value="">Todos</option><?php $renderShippingOptions($shippingMethodOptions, (string) ($filters['shipping_method'] ?? '')); ?></select></div>
    <div class="field"><label>Origen stock</label><select class="input" name="stock_origin"><option value="">Todos</option><option value="full" <?= ($filters['stock_origin'] ?? '') === 'full' ? 'selected' : '' ?>>Stock FULL</option><option value="non_full" <?= ($filters['stock_origin'] ?? '') === 'non_full' ? 'selected' : '' ?>>Stock no FULL/local</option><option value="unknown" <?= ($filters['stock_origin'] ?? '') === 'unknown' ? 'selected' : '' ?>>Stock sin detalle</option></select></div>
    <div class="field"><label>SKU</label><select class="input" name="sku_state"><option value="">Todos</option><option value="missing" <?= ($filters['sku_state'] ?? '') === 'missing' ? 'selected' : '' ?>>Sin SKU</option></select></div>
    <div class="field"><label>Imagen</label><select class="input" name="image_state"><option value="">Todos</option><option value="missing" <?= ($filters['image_state'] ?? '') === 'missing' ? 'selected' : '' ?>>Sin imagen</option></select></div>
    <div class="field"><label>Orden</label><select class="input" name="sort"><option value="relevance">Manual / título</option><option value="price_asc">Menor precio</option><option value="price_desc">Mayor precio</option><option value="stock">Stock primero</option><option value="sold">Más vendidos</option><option value="recent">Más recientes</option></select></div>
    <button class="btn primary">Filtrar</button>
  </form>
</section>

<?php if (!empty($progressive)): ?>
  <?php
  $catalogSectionQuery = http_build_query([
      'q' => $filters['q'] ?? '',
      'category' => $filters['category_slug'] ?? '',
      'account_id' => $filters['account_id'] ?? 0,
      'company_id' => $filters['company_id'] ?? 0,
      'source_type' => $filters['source_type'] ?? '',
      'status' => $filters['status'] ?? '',
      'stock' => $filters['stock'] ?? '',
      'full' => $filters['full'] ?? '',
      'shipping_method' => $filters['shipping_method'] ?? '',
      'stock_origin' => $filters['stock_origin'] ?? '',
      'sku_state' => $filters['sku_state'] ?? '',
      'image_state' => $filters['image_state'] ?? '',
      'sort' => $filters['sort'] ?? 'relevance',
      'page' => $filters['page'] ?? 1,
  ]);
  ?>
  <section
    class="panel async-section"
    data-async-section="catalog-items"
    data-async-url="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/section.json?<?= View::e($catalogSectionQuery) ?>"
    data-async-fallback="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>?<?= View::e($catalogSectionQuery) ?>&amp;full=1"
    aria-busy="true"
  >
    <div class="async-section-status" data-async-status hidden></div>
    <div class="async-skeleton product-skeleton" data-async-skeleton aria-hidden="true">
      <div class="skeleton-table"><?php for ($i = 0; $i < 6; $i++): ?><span></span><?php endfor; ?></div>
    </div>
    <noscript><p class="empty">Active JavaScript o <a href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>?<?= View::e($catalogSectionQuery) ?>&amp;full=1">cargue el catálogo completo</a>.</p></noscript>
  </section>
<?php else: ?>
<section class="panel panel-body">
  <div class="catalog-private-grid">
    <?php foreach ($items['items'] as $item): ?>
      <article class="catalog-private-card">
        <div class="catalog-thumb">
          <?php if (!empty($item['thumbnail_url'])): ?><img src="<?= View::e($item['thumbnail_url']) ?>" alt=""><?php else: ?><span>Sin imagen</span><?php endif; ?>
        </div>
        <div class="catalog-private-info">
          <h3><?= View::e($item['title_snapshot']) ?></h3>
          <p class="muted">
            <?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'ID ML ' . View::e($item['external_item_id']) ?>
            · <?= View::e($item['account_name'] ?: ($item['company_name'] ?: 'Sin empresa')) ?>
          </p>
          <div class="catalog-tags">
            <span class="badge blue"><?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Interno' : 'Mercado Libre' ?></span>
            <span class="badge <?= ($item['status'] ?? '') === 'active' ? 'green' : 'amber' ?>"><?= View::e($item['status'] ?? '—') ?></span>
            <span class="badge blue"><?= View::e($item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría') ?></span>
            <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?><?php foreach ($shippingLabels($item) as $label): ?><span class="badge"><?= View::e($label) ?></span><?php endforeach; ?><?php endif; ?>
            <?php if ((int) $item['has_variations'] === 1): ?><span class="badge amber">Variaciones disponibles</span><?php endif; ?>
          </div>
          <div class="mini-grid catalog-mini">
            <div><span>SKU</span><strong><?= View::e($item['sku_snapshot'] ?: '—') ?></strong></div>
            <div><span>Precio</span><strong><?= View::e($money($item['price_snapshot'])) ?></strong></div>
            <div><span>Stock</span><strong><?= (int) $item['stock_available'] ?></strong></div>
            <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?>
              <div><span>FULL</span><strong><?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?></strong></div>
              <div><span>No FULL/local</span><strong><?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></strong></div>
              <div><span>Principal</span><strong><?= View::e($primaryLogistic($item)) ?></strong></div>
              <div><span>Disponibles</span><strong><?= View::e(implode(', ', $shippingLabels($item))) ?></strong></div>
              <div><span>Confianza envío</span><strong><?= View::e($shippingConfidence($item)) ?></strong></div>
              <div><span>Confianza stock</span><strong><?= View::e($stockStatus($item['stock_detail_status'] ?? null)) ?></strong></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="catalog-card-actions">
          <?php if (!empty($item['permalink'])): ?><a class="btn small" href="<?= View::e($item['permalink']) ?>" target="_blank" rel="noopener">Mercado Libre</a><?php endif; ?>
          <a class="btn small" href="<?= View::e($base) ?>/catalogo/<?= View::e($catalog['slug']) ?>/producto/<?= View::e($item['public_item_key']) ?>" target="_blank" rel="noopener">Ficha</a>
          <form method="post" action="<?= View::e($base) ?>/catalogs/items/toggle">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="catalog_id" value="<?= (int) $catalog['id'] ?>">
            <input type="hidden" name="catalog_item_id" value="<?= (int) $item['id'] ?>">
            <input type="hidden" name="source_type" value="<?= View::e($item['source_type'] ?? 'meli') ?>">
            <input type="hidden" name="visible" value="<?= (int) $item['is_visible'] === 1 ? 0 : 1 ?>">
            <button class="btn small <?= (int) $item['is_visible'] === 1 ? 'danger' : '' ?>"><?= (int) $item['is_visible'] === 1 ? 'Ocultar' : 'Mostrar' ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$items['items']): ?><div class="empty">No hay productos con estos filtros.</div><?php endif; ?>
  </div>
  <div class="pagination mt-2">
    <?php if ($items['page'] > 1): ?><a class="btn small" href="<?= View::e($pageUrl($items['page'] - 1)) ?>">Anterior</a><?php endif; ?>
    <span class="muted">Página <?= (int) $items['page'] ?> de <?= (int) $items['pages'] ?> · <?= (int) $items['total'] ?> productos</span>
    <?php if ($items['page'] < $items['pages']): ?><a class="btn small" href="<?= View::e($pageUrl($items['page'] + 1)) ?>">Siguiente</a><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel panel-body mt-2">
  <h2>Actualizar snapshot</h2>
  <p class="muted">Regenera el catálogo desde publicaciones locales preservando productos ocultos, orden manual y categorías renombradas.</p>
  <form method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/refresh">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <button class="btn primary">Refrescar desde datos locales</button>
  </form>
</section>
