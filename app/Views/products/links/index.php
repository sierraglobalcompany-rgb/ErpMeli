<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
?>
<div class="page-head">
  <div><h1>Vinculación ML ↔ Bodega</h1><p>Relacione publicaciones de Mercado Libre con productos internos sin repetir vínculos activos.</p></div>
</div>

<section class="panel filter-bar">
  <form method="get" action="<?= View::e($base) ?>/products/links" class="inline-form">
    <div class="field"><label for="links-query">Buscar</label><input class="input" id="links-query" name="q" value="<?= View::e($q) ?>" placeholder="Producto, SKU, publicación o ID"></div>
    <div class="field"><label for="links-account">Cuenta ID</label><input class="input" id="links-account" type="number" name="account_id" value="<?= (int) $accountId ?>" placeholder="0 = todas"></div>
    <div class="field"><label for="links-state">Estado de vinculación</label><select class="input" id="links-state" name="link_state">
      <?php foreach (['unlinked'=>'Sin vincular','linked'=>'Vinculadas','all'=>'Todas'] as $value=>$label): ?><option value="<?= View::e($value) ?>" <?= $linkState === $value ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
    </select></div>
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn" href="<?= View::e($base) ?>/products/unlinked">Ver vendidos sin vincular</a>
  </form>
</section>

<section class="panel filter-bar">
  <header class="panel-head"><h2>Crear vínculo</h2><span class="muted">Seleccione un producto interno y una publicación filtrada.</span></header>
  <form method="post" action="<?= View::e($base) ?>/products/links" class="form-grid" data-confirm="Confirme que esta publicación descuenta el producto interno seleccionado.">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field"><label for="link-internal-product">Producto interno</label><select class="input" id="link-internal-product" name="internal_product_id" required>
      <option value="">Seleccione producto interno</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= View::e(($p['name'] ?: 'Sin nombre') . ' · SKU ' . ($p['internal_sku'] ?: '—') . ' · costo ' . $money($p['manual_cost'])) ?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label for="link-meli-item">Publicación Mercado Libre</label><select class="input" id="link-meli-item" name="meli_item_id" required>
      <option value="">Seleccione publicación</option>
      <?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>" <?= !empty($i['active_link_id']) ? 'disabled' : '' ?>><?= View::e($i['account_name'] . ' · ' . $i['external_item_id'] . ' · ' . ($i['seller_sku'] ?: 'Sin SKU') . ' · ' . $i['title'] . (!empty($i['active_link_id']) ? ' (ya vinculada)' : '')) ?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label for="link-variation">Variación</label><input class="input" id="link-variation" name="meli_variation_id" type="number" min="0" value="0"><small>Use 0 si la publicación no tiene variación.</small></div>
    <div class="field"><label for="link-factor">Cantidad de bodega que descuenta cada venta</label><input class="input" id="link-factor" name="conversion_factor" type="number" min="0.0001" step="0.0001" value="1" required><small>Ejemplo: 1 venta descuenta 1 unidad. Si vende pack x3, use 3.</small></div>
    <div class="field field-action"><button class="btn primary" type="submit">Crear vínculo</button></div>
  </form>
</section>

<section class="panel table-panel">
  <header class="panel-head"><h2>Publicaciones encontradas</h2><span class="muted"><?= count($items) ?> resultados</span></header>
  <div class="table-scroll"><table class="data-table" data-responsive="cards"><caption class="sr-only">Publicaciones Mercado Libre encontradas</caption><thead><tr><th>Imagen</th><th>Cuenta</th><th>Publicación</th><th>SKU ML</th><th>Precio</th><th>Estado</th><th>Vínculo</th></tr></thead><tbody>
  <?php if (!$items): ?><tr><td colspan="7"><div class="empty">No hay publicaciones para este filtro.</div></td></tr><?php endif; ?>
  <?php foreach ($items as $item): ?><tr>
    <td data-label="Imagen"><?php if (!empty($item['thumbnail'])): ?><img src="<?= View::e($item['thumbnail']) ?>" alt="" style="width:44px;height:44px;object-fit:contain;border-radius:8px"><?php else: ?>—<?php endif; ?></td>
    <td data-label="Cuenta"><?= View::e($item['account_name']) ?></td>
    <td data-label="Publicación"><strong><?= View::e($item['title']) ?></strong><br><small><?= View::e($item['external_item_id']) ?></small></td>
    <td data-label="SKU ML"><?= View::e($item['seller_sku'] ?: 'Sin SKU') ?></td>
    <td data-label="Precio"><?= $money($item['price']) ?></td>
    <td data-label="Estado"><span class="badge"><?= View::e($item['status'] ?: '—') ?></span></td>
    <td data-label="Vínculo"><?= !empty($item['active_link_id']) ? '<span class="badge green">Vinculada</span>' : '<span class="badge amber">Sin vínculo</span>' ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Vínculos activos</h2><span class="muted"><?= count($links) ?> activos</span></header>
  <div class="table-scroll"><table class="data-table" data-responsive="cards"><caption class="sr-only">Vínculos activos entre bodega y Mercado Libre</caption><thead><tr><th>Producto interno</th><th>Cuenta</th><th>Publicación ML</th><th>SKU ML</th><th>Factor</th><th>Fuente</th><th>Acciones</th></tr></thead><tbody>
  <?php if (!$links): ?><tr><td colspan="7"><div class="empty">No hay vínculos activos.</div></td></tr><?php endif; ?>
  <?php foreach ($links as $link): ?><tr>
    <td data-label="Producto interno"><strong><?= View::e($link['internal_name']) ?></strong><br><small>SKU <?= View::e($link['internal_sku']) ?> · costo <?= $money($link['manual_cost']) ?></small></td>
    <td data-label="Cuenta"><?= View::e($link['account_name']) ?></td>
    <td data-label="Publicación ML"><strong><?= View::e($link['title']) ?></strong><br><small><?= View::e($link['external_item_id']) ?> · variación <?= View::e((string) ((int) $link['meli_variation_id'] === 0 ? 'Sin variación' : $link['meli_variation_id'])) ?></small></td>
    <td data-label="SKU ML"><?= View::e($link['seller_sku'] ?: '—') ?></td>
    <td data-label="Factor"><form method="post" action="<?= View::e($base) ?>/products/links/factor" class="inline-form"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int) $link['id'] ?>"><label class="sr-only" for="link-factor-<?= (int) $link['id'] ?>">Factor de conversión</label><input class="input compact-input" id="link-factor-<?= (int) $link['id'] ?>" name="conversion_factor" type="number" min="0.0001" step="0.0001" value="<?= View::e($link['conversion_factor']) ?>"><button class="btn small" type="submit">Guardar</button></form></td>
    <td data-label="Fuente"><?= View::e(($link['link_source'] ?? 'manual') === 'manual' ? 'Manual' : 'Sugerida') ?></td>
    <td data-label="Acciones"><form method="post" action="<?= View::e($base) ?>/products/links/delete"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int) $link['id'] ?>"><button class="btn small danger" type="submit" data-confirm="Desactivar este vínculo no borra ventas, pero dejará el producto sin relación activa.">Desactivar</button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
