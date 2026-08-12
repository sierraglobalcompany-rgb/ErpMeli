<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
$items = $pageData['items'] ?? [];
$canWrite = in_array((string) Auth::role(), ['admin','operador','operator'], true) && !Auth::isTemporary();
$number = static fn($value): string => number_format((float) $value, 2, ',', '.');
$types = [
    'opening'=>'Saldo inicial','receipt'=>'Entrada por compra','adjustment_in'=>'Ajuste de entrada',
    'adjustment_out'=>'Ajuste de salida','reserve'=>'Reservar','release'=>'Liberar reserva',
];
$reviewLabels = [
    'UNLINKED_PRODUCT'=>'Producto vendido sin vínculo',
    'DEFAULT_WAREHOUSE_MISSING'=>'Falta bodega predeterminada',
    'INSUFFICIENT_STOCK'=>'Existencia insuficiente',
    'PARTIAL_RETURN_AUTHORITY_REQUIRED'=>'Devolución parcial por revisar',
];
?>
<div class="page-head"><div><h1>Inventario / Bodega real</h1><p>Existencias locales, reservas, costo promedio y revisiones de ventas Mercado Libre.</p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/inventory/kardex">Ver kardex</a><a class="btn" href="<?= View::e($base) ?>/products/links">Vinculación ML</a></div></div>

<section class="panel filter-bar">
  <form method="get" class="inline-form">
    <div class="field"><label for="inventory-company">Empresa</label><select class="input" id="inventory-company" name="company_id"><option value="0">Todas</option><?php foreach($companies as $company):?><option value="<?= (int)$company['id'] ?>" <?= (int)$filters['company_id']===(int)$company['id']?'selected':'' ?>><?= View::e($company['name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="inventory-warehouse">Bodega</label><select class="input" id="inventory-warehouse" name="warehouse_id"><option value="0">Todas</option><?php foreach($warehouses as $warehouse):?><option value="<?= (int)$warehouse['id'] ?>" <?= (int)$filters['warehouse_id']===(int)$warehouse['id']?'selected':'' ?>><?= View::e($warehouse['company_name'].' · '.$warehouse['name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="inventory-q">Producto</label><input class="input" id="inventory-q" name="q" value="<?= View::e($filters['q']) ?>" placeholder="SKU o nombre"></div>
    <div class="field"><label for="inventory-per-page">Resultados</label><select class="input" id="inventory-per-page" name="per_page"><?php foreach([25,50,100] as $size):?><option value="<?= $size ?>" <?= (int)$pageData['per_page']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach;?></select></div>
    <button class="btn" type="submit">Filtrar</button>
  </form>
</section>

<?php if($canWrite):?>
<div class="settings-grid">
  <details class="panel" <?= $warehouses===[]?'open':'' ?>><summary><strong>Crear bodega</strong></summary>
    <form method="post" action="<?= View::e($base) ?>/inventory/warehouses" class="form-grid mt-2">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <div class="field"><label for="new-warehouse-company">Empresa</label><select class="input" id="new-warehouse-company" name="company_id" required><option value="">Seleccione</option><?php foreach($companies as $company):?><option value="<?= (int)$company['id'] ?>" <?= (int)$filters['company_id']===(int)$company['id']?'selected':'' ?>><?= View::e($company['name']) ?></option><?php endforeach;?></select></div>
      <div class="field"><label for="new-warehouse-code">Código</label><input class="input" id="new-warehouse-code" name="code" maxlength="64" required placeholder="PRINCIPAL"></div>
      <div class="field"><label for="new-warehouse-name">Nombre</label><input class="input" id="new-warehouse-name" name="name" maxlength="160" required></div>
      <label class="check"><input type="checkbox" name="is_default" value="1"> Usar como predeterminada</label>
      <div class="field field-action"><button class="btn primary" type="submit">Crear bodega</button></div>
    </form>
  </details>

  <details class="panel"><summary><strong>Registrar movimiento manual</strong></summary>
    <?php if((int)$filters['company_id']<1):?><p class="muted mt-2">Seleccione una empresa en el filtro para cargar sus productos.</p><?php else:?>
    <form method="post" action="<?= View::e($base) ?>/inventory/movements" class="form-grid mt-2">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="company_id" value="<?= (int)$filters['company_id'] ?>"><input type="hidden" name="request_id" value="<?= View::e($requestId) ?>">
      <div class="field"><label for="movement-warehouse">Bodega</label><select class="input" id="movement-warehouse" name="warehouse_id" required><option value="">Seleccione</option><?php foreach($warehouses as $warehouse):if((int)$warehouse['company_id']!==(int)$filters['company_id']||$warehouse['status']!=='active')continue;?><option value="<?= (int)$warehouse['id'] ?>"><?= View::e($warehouse['name'].((int)$warehouse['is_default']===1?' · Predeterminada':'')) ?></option><?php endforeach;?></select></div>
      <div class="field"><label for="movement-product">Producto</label><select class="input" id="movement-product" name="internal_product_id" required><option value="">Seleccione</option><?php foreach($products as $product):?><option value="<?= (int)$product['id'] ?>"><?= View::e($product['internal_sku'].' · '.$product['name']) ?></option><?php endforeach;?></select></div>
      <div class="field"><label for="movement-type">Movimiento</label><select class="input" id="movement-type" name="movement_type" required><?php foreach($types as $value=>$label):?><option value="<?= View::e($value) ?>"><?= View::e($label) ?></option><?php endforeach;?></select></div>
      <div class="field"><label for="movement-quantity">Cantidad</label><input class="input" id="movement-quantity" name="quantity" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,6})?" required></div>
      <div class="field"><label for="movement-cost">Costo unitario (entradas)</label><input class="input" id="movement-cost" name="unit_cost" inputmode="decimal" value="0" pattern="[0-9]+([.,][0-9]{1,6})?"></div>
      <div class="field"><label for="movement-reason">Motivo</label><input class="input" id="movement-reason" name="reason" maxlength="500" required></div>
      <div class="field field-action"><button class="btn primary" type="submit">Registrar</button></div>
    </form><?php endif;?>
  </details>
</div>
<?php endif;?>

<section class="panel table-panel"><header class="panel-head"><div><h2>Existencias</h2><p><?= number_format((int)$pageData['total'],0,',','.') ?> combinaciones producto/bodega.</p></div></header><div class="table-scroll"><table class="data-table" data-responsive="cards"><thead><tr><th>Producto</th><th>Bodega</th><th>Disponible</th><th>Reservado</th><th>Existencia</th><th>Costo prom.</th><th>Valor</th><th>Estado</th></tr></thead><tbody>
<?php if(!$items):?><tr><td colspan="8"><div class="empty">Cree una bodega explícita para comenzar el inventario.</div></td></tr><?php endif;?>
<?php foreach($items as $row):?><tr><td data-label="Producto"><strong><?= View::e($row['internal_sku']) ?></strong><br><span class="muted"><?= View::e($row['product_name']) ?></span></td><td data-label="Bodega"><?= View::e($row['warehouse_name']) ?><?= (int)$row['is_default']===1?' · Predeterminada':'' ?><br><span class="muted"><?= View::e($row['company_name']) ?></span></td><td data-label="Disponible"><strong><?= $number($row['available']) ?></strong></td><td data-label="Reservado"><?= $number($row['reserved']) ?></td><td data-label="Existencia"><?= $number($row['on_hand']) ?></td><td data-label="Costo prom.">$ <?= $number($row['average_unit_cost']) ?></td><td data-label="Valor">$ <?= $number($row['inventory_value']) ?></td><td data-label="Estado"><span class="badge <?= $row['stock_status']==='available'?'green':'amber' ?>"><?= View::e(['available'=>'Disponible','out_of_stock'=>'Agotado','not_initialized'=>'Sin saldo','invalid'=>'Inválido'][$row['stock_status']]??$row['stock_status']) ?></span></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if((int)$pageData['pages']>1):?><nav class="pagination" aria-label="Paginación de inventario"><?php for($page=1;$page<=$pageData['pages'];$page++):if($page>1&&$page<$pageData['page']-1||$page<$pageData['pages']&&$page>$pageData['page']+1)continue;$query=array_merge($_GET,['page'=>$page]);?><a class="btn small <?= $page===$pageData['page']?'primary':'' ?>" href="<?= View::e($base.'/inventory?'.http_build_query($query)) ?>"><?= $page ?></a><?php endfor;?></nav><?php endif;?></section>

<section class="panel"><header class="panel-head"><div><h2>Bodegas</h2><p>La bodega predeterminada siempre se elige de forma explícita.</p></div></header><div class="table-scroll"><table class="data-table"><thead><tr><th>Empresa</th><th>Código</th><th>Nombre</th><th>Estado</th><th>Autoridad</th><?php if($canWrite):?><th>Acción</th><?php endif;?></tr></thead><tbody><?php if(!$warehouses):?><tr><td colspan="6"><div class="empty">No hay bodegas configuradas.</div></td></tr><?php endif;?><?php foreach($warehouses as $warehouse):?><tr><td><?= View::e($warehouse['company_name']) ?></td><td><strong><?= View::e($warehouse['code']) ?></strong></td><td><?= View::e($warehouse['name']) ?></td><td><?= View::e($warehouse['status']==='active'?'Activa':'Inactiva') ?></td><td><?= (int)$warehouse['is_default']===1?'<span class="badge green">Predeterminada</span>':'—' ?></td><?php if($canWrite):?><td><div class="inline-actions"><?php if($warehouse['status']==='active'&&(int)$warehouse['is_default']!==1):?><form method="post" action="<?= View::e($base) ?>/inventory/warehouses/default"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="warehouse_id" value="<?= (int)$warehouse['id'] ?>"><button class="btn small" type="submit">Hacer predeterminada</button></form><?php endif;?><form method="post" action="<?= View::e($base) ?>/inventory/warehouses/status"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="warehouse_id" value="<?= (int)$warehouse['id'] ?>"><input type="hidden" name="status" value="<?= $warehouse['status']==='active'?'inactive':'active' ?>"><button class="btn small" type="submit"><?= $warehouse['status']==='active'?'Inactivar':'Activar' ?></button></form></div></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></section>

<section class="panel"><header class="panel-head"><div><h2>Revisiones pendientes</h2><p>Las ventas sin vínculo, sin bodega o sin existencia nunca alteran saldos.</p></div></header><div class="table-scroll"><table class="data-table"><thead><tr><th>Orden</th><th>Cuenta</th><th>Motivo</th><th>Producto</th><th>Requerido</th><th>Disponible</th><?php if($canWrite):?><th>Acción segura</th><?php endif;?></tr></thead><tbody><?php if(!$reviews):?><tr><td colspan="7"><div class="empty">No hay revisiones de inventario abiertas.</div></td></tr><?php endif;?><?php foreach($reviews as $review):?><tr><td><strong><?= View::e($review['external_order_id']) ?></strong></td><td><?= View::e($review['company_name'].' · '.$review['account_name']) ?></td><td><span class="badge amber"><?= View::e($reviewLabels[$review['reason_code']]??$review['reason_code']) ?></span></td><td><?= View::e(($review['internal_sku']??'').' '.($review['product_name']??'Sin vínculo')) ?></td><td><?= $review['required_quantity']!==null?$number($review['required_quantity']):'—' ?></td><td><?= $review['available_quantity']!==null?$number($review['available_quantity']):'—' ?></td><?php if($canWrite):?><td><form method="post" action="<?= View::e($base) ?>/inventory/reviews/retry"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="review_id" value="<?= (int)$review['id'] ?>"><button class="btn small" type="submit">Comprobar y aplicar</button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></section>
