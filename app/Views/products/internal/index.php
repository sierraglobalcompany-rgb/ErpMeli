<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$readiness = is_array($readiness ?? null) ? $readiness : [];
?>
<div class="page-head"><div><h1>Bodega / Productos internos</h1><p>Catálogo interno para consolidar ventas de varias cuentas Mercado Libre.</p></div></div>
<section class="panel filter-bar">
  <form method="get" class="inline-form">
    <div class="field"><label for="warehouse-company">Empresa</label><select class="input" id="warehouse-company" name="company_id"><option value="0">Todas</option><?php foreach($companies as $company):?><option value="<?= (int)$company['id'] ?>" <?= (int)$filters['company_id']===(int)$company['id']?'selected':'' ?>><?= View::e($company['name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="warehouse-query">Buscar</label><input class="input" id="warehouse-query" name="q" value="<?= View::e($filters['q']) ?>" placeholder="SKU o nombre"></div>
    <div class="field"><label for="warehouse-readiness">Completitud</label><select class="input" id="warehouse-readiness" name="readiness"><option value="">Todos</option><option value="missing_cost" <?= $filters['readiness']==='missing_cost'?'selected':'' ?>>Sin costo</option><option value="missing_sku" <?= $filters['readiness']==='missing_sku'?'selected':'' ?>>Sin SKU</option><option value="missing_link" <?= $filters['readiness']==='missing_link'?'selected':'' ?>>Sin vínculo</option><option value="margin_incomplete" <?= $filters['readiness']==='margin_incomplete'?'selected':'' ?>>Margen incompleto</option></select></div>
    <div class="field"><label for="warehouse-per-page">Resultados</label><select class="input" id="warehouse-per-page" name="per_page"><?php foreach([25,50,100] as $size):?><option value="<?= $size ?>" <?= (int)($pageData['per_page']??50)===$size?'selected':'' ?>><?= $size ?> por página</option><?php endforeach;?></select></div>
    <button class="btn" type="submit">Filtrar</button>
  </form>
</section>
<section class="commercial-readiness-grid" aria-label="Completitud comercial">
  <a class="commercial-readiness-stat" href="<?= View::e($base) ?>/products/internal?readiness=missing_cost"><span>Sin costo</span><strong><?= (int)($readiness['missing_cost']??0) ?></strong></a>
  <a class="commercial-readiness-stat" href="<?= View::e($base) ?>/products/internal?readiness=missing_link"><span>Sin vínculo</span><strong><?= (int)($readiness['missing_link']??0) ?></strong></a>
  <a class="commercial-readiness-stat" href="<?= View::e($base) ?>/products/internal?readiness=missing_sku"><span>Sin SKU</span><strong><?= (int)($readiness['missing_sku']??0) ?></strong></a>
  <a class="commercial-readiness-stat" href="<?= View::e($base) ?>/products/internal?readiness=margin_incomplete"><span>Margen incompleto</span><strong><?= (int)($readiness['margin_incomplete']??0) ?></strong></a>
</section>
<details class="panel">
  <summary><strong>Crear producto interno</strong></summary>
  <form method="post" action="<?= View::e($base) ?>/products/internal" class="form-grid mt-2">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field"><label for="new-product-company">Empresa</label><select class="input" id="new-product-company" name="company_id"><option value="0">General</option><?php foreach($companies as $company):?><option value="<?= (int)$company['id'] ?>"><?= View::e($company['name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="new-product-sku">SKU interno</label><input class="input" id="new-product-sku" name="internal_sku" required></div>
    <div class="field"><label for="new-product-name">Nombre</label><input class="input" id="new-product-name" name="name" required></div>
    <div class="field"><label for="new-product-unit">Unidad</label><input class="input" id="new-product-unit" name="unit" value="unidad"></div>
    <div class="field"><label for="new-product-cost">Costo manual</label><input class="input" id="new-product-cost" name="manual_cost" type="number" step="0.01" value="0"></div>
    <div class="field"><label for="new-product-profit">Utilidad %</label><input class="input" id="new-product-profit" name="default_profit_percent" type="number" step="0.01" value="10"></div>
    <div class="field"><label for="new-product-purchase">Compra sugerida</label><input class="input" id="new-product-purchase" name="suggested_purchase_value" type="number" step="0.01" value="0"></div>
    <div class="field field-action"><button class="btn primary" type="submit">Crear</button></div>
  </form>
</details>
<section class="panel table-panel"><div class="section-result-summary"><strong><?= number_format((int)($pageData['total']??count($products)),0,',','.') ?> productos</strong><span>Página <?= (int)($pageData['page']??1) ?> de <?= (int)($pageData['pages']??1) ?></span></div><div class="table-scroll"><table class="data-table" data-responsive="cards"><caption class="sr-only">Productos internos de bodega</caption><thead><tr><th>SKU</th><th>Producto</th><th>Empresa</th><th>Costo</th><th>Utilidad</th><th>Compra sugerida</th><th>Vínculos</th><th>Estado</th></tr></thead><tbody>
<?php if(!$products):?><tr><td colspan="8"><div class="empty">No hay productos internos.</div></td></tr><?php endif;?>
<?php foreach($products as $product):?><tr><td data-label="SKU"><strong><?= View::e($product['internal_sku']) ?></strong></td><td data-label="Producto"><?= View::e($product['name']) ?></td><td data-label="Empresa"><?= View::e($product['company_name'] ?: 'General') ?></td><td data-label="Costo"><?= $money($product['manual_cost']) ?></td><td data-label="Utilidad"><?= number_format((float)$product['default_profit_percent'],2,',','.') ?>%</td><td data-label="Compra sugerida"><?= $money($product['suggested_purchase_value'] ?? $product['manual_purchase_value']) ?></td><td data-label="Vínculos"><?= (int)$product['linked_items'] ?></td><td data-label="Estado"><span class="badge <?= $product['status']==='active'?'green':'amber' ?>"><?= View::e($product['status']==='active'?'Activo':'Inactivo') ?></span></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if((int)($pageData['pages']??1)>1):?><nav class="pagination" aria-label="Paginación de productos internos"><?php $current=(int)$pageData['page'];$pages=(int)$pageData['pages'];foreach(array_values(array_unique(array_filter([1,$current-1,$current,$current+1,$pages],static fn($p)=>$p>=1&&$p<=$pages))) as $page):$pageQuery=array_merge($_GET,['page'=>$page]);?><a class="btn small <?= $page===$current?'primary':'' ?>" href="<?= View::e($base) ?>/products/internal?<?= View::e(http_build_query($pageQuery)) ?>"><?= $page ?></a><?php endforeach;?></nav><?php endif;?>
</section>
