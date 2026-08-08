<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\ContextHelpPresenter;
use App\Services\DateTimePresenter;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div><h1>Productos vendidos sin vincular</h1><p>Ventas que todavía no están relacionadas con un producto de bodega.</p></div>
  <div class="page-actions">
    <form method="post" action="<?= View::e($base) ?>/products/unlinked/suggest"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn primary" type="submit">Generar sugerencias</button><?= ContextHelpPresenter::button('products.unlinked.suggest') ?></form>
  </div>
</div>

<section class="panel filter-bar">
  <form method="get" action="<?= View::e($base) ?>/products/unlinked" class="inline-form">
    <div class="field"><label for="unlinked-page-size">Resultados</label><select id="unlinked-page-size" class="input" name="per_page"><?php foreach([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int)$pageData['per_page']===$size?'selected':'' ?>><?= $size ?> por página</option><?php endforeach; ?></select></div>
    <button class="btn" type="submit">Aplicar</button>
  </form>
</section>

<form method="post" action="<?= View::e($base) ?>/products/unlinked/ignore-batch">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <section class="panel table-panel">
    <header class="panel-head"><div><h2>Necesitan decisión</h2><p class="muted"><?= (int)$pageData['total'] ?> encontrados · página <?= (int)$pageData['page'] ?> de <?= (int)$pageData['pages'] ?></p></div></header>
    <div class="bulk-action-bar"><span>Seleccione publicaciones que no desea vincular.</span><button class="btn" type="submit" data-confirm="¿Omitir los productos seleccionados? Permanecerán en el historial de ventas.">Omitir seleccionados</button></div>
    <div class="table-scroll"><table class="data-table responsive-table">
      <caption>Productos vendidos sin vínculo con bodega</caption>
      <thead><tr><th><span class="sr-only">Seleccionar</span></th><th>Cuenta</th><th>Publicación</th><th>SKU</th><th>Ventas</th><th>Unidades</th><th>Última venta</th><th>Acciones</th></tr></thead><tbody>
      <?php if(!$rows):?><tr><td colspan="8"><div class="empty-state compact"><h3>Todo está vinculado</h3><p>No hay productos vendidos que requieran una decisión.</p></div></td></tr><?php endif;?>
      <?php foreach($rows as $row): $value=(int)$row['meli_account_id'].'|'.$row['external_item_id'].'|'.(string)$row['external_variation_id']; ?><tr>
        <td data-label="Seleccionar"><input type="checkbox" name="selected[]" value="<?= View::e($value) ?>" aria-label="Seleccionar <?= View::e($row['title']) ?>"></td>
        <td data-label="Cuenta"><?= View::e($row['account_name']) ?></td>
        <td data-label="Publicación"><strong><?= View::e($row['title']) ?></strong><small><?= View::e($row['external_item_id']) ?></small></td>
        <td data-label="SKU"><?= View::e($row['seller_sku']) ?></td>
        <td data-label="Ventas"><?= (int)$row['orders_count'] ?></td>
        <td data-label="Unidades"><?= (int)$row['units_sold'] ?></td>
        <td data-label="Última venta"><?= View::e(DateTimePresenter::formatQueue($row['last_sale_at'],'d/m/Y')) ?></td>
        <td data-label="Acciones"><a class="btn small" href="<?= View::e($base) ?>/products/links?account_id=<?= (int)$row['meli_account_id'] ?>&q=<?= rawurlencode((string)($row['seller_sku'] ?: $row['external_item_id'])) ?>&link_state=unlinked">Abrir vinculación</a></td>
      </tr><?php endforeach;?>
      </tbody>
    </table></div>
  </section>
</form>

<section class="panel table-panel mt-2">
  <header class="panel-head"><div><h2>Sugerencias</h2><p class="muted">Propuestas locales; nunca se aplican automáticamente.</p></div></header>
  <div class="table-scroll"><table class="data-table responsive-table"><caption>Sugerencias de vinculación pendientes</caption><thead><tr><th>Cuenta</th><th>Vendido</th><th>Sugerido</th><th>Confianza</th><th>Razón</th></tr></thead><tbody><?php if(!$suggestions):?><tr><td colspan="5"><div class="empty">Sin sugerencias pendientes.</div></td></tr><?php endif;?><?php foreach(array_slice($suggestions,0,50) as $s):?><tr><td data-label="Cuenta"><?= View::e($s['account_name']) ?></td><td data-label="Vendido"><?= View::e(($s['seller_sku'] ?: '—').' · '.$s['order_title']) ?></td><td data-label="Sugerido"><?= View::e($s['internal_sku'].' · '.$s['internal_name']) ?></td><td data-label="Confianza"><?= View::e($s['confidence']) ?>%</td><td data-label="Razón"><?= View::e($s['reason']) ?></td></tr><?php endforeach;?></tbody></table></div>
</section>
