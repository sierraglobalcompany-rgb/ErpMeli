<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\ContextHelpPresenter;
$base = rtrim(Env::get('APP_URL', ''), '/');
$money = static fn($v): string => '$ ' . number_format((float)$v, 0, ',', '.');
?>
<div class="page-head">
  <div><h1>Importaciones a bodega</h1><p>Cree productos internos desde publicaciones ya importadas de Mercado Libre, sin escribir nada en Mercado Libre.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/products/meli">Productos ML</a><a class="btn" href="<?= View::e($base) ?>/products/internal">Bodega</a></div>
</div>
<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/products/imports">
    <div class="field"><label for="import-account">Cuenta origen ML</label><select id="import-account" class="input" name="account_id"><option value="0">Todas</option><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="import-company">Empresa / bodega destino</label><select id="import-company" class="input" name="company_id"><option value="0">Seleccione al importar</option><?php foreach($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $companyId===(int)$c['id']?'selected':'' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="import-search">Buscar</label><input id="import-search" class="input" name="q" value="<?= View::e($q) ?>" placeholder="ID, SKU o título"></div>
    <div class="field"><label for="import-page-size">Resultados</label><select id="import-page-size" class="input" name="per_page"><?php foreach([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int)$pageData['per_page']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <button class="btn" type="submit">Filtrar</button>
  </form>
</section>
<form method="post" action="<?= View::e($base) ?>/products/imports/from-meli">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
  <section class="panel filter-bar">
    <div class="inline-form">
      <div class="field"><label for="import-target-company">Destino para seleccionados</label><select id="import-target-company" class="input" name="company_id" required><option value="">Seleccione empresa</option><?php foreach($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $companyId===(int)$c['id']?'selected':'' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
      <label class="check-row"><input type="checkbox" name="overwrite" value="1"> Solo actualizar metadatos de origen si ya existe</label>
      <?php if (Auth::isTemporary()): ?><span class="muted">Los accesos temporales no pueden importar.</span><?php else: ?><button class="btn primary" type="submit">Importar seleccionados</button><?= ContextHelpPresenter::button('imports.to_warehouse') ?><?php endif; ?>
    </div>
  </section>
  <section class="panel table-panel">
    <header class="panel-head"><div><h2>Publicaciones disponibles</h2><p class="muted"><?= (int)$pageData['total'] ?> resultados · página <?= (int)$pageData['page'] ?> de <?= (int)$pageData['pages'] ?></p></div></header>
    <div class="table-scroll"><table class="data-table">
      <caption>Publicaciones disponibles para importar a bodega</caption>
      <thead><tr><th></th><th>Publicación</th><th>Cuenta</th><th>SKU / referencia</th><th>Precio ML</th><th>Disponible</th><th>Estado importación</th></tr></thead>
      <tbody>
        <?php if (!$items): ?><tr><td colspan="7"><div class="empty">No hay publicaciones importadas desde ML. Primero use Productos ML → Importar publicaciones.</div></td></tr><?php endif; ?>
        <?php foreach($items as $item): ?><tr>
          <td><input type="checkbox" name="meli_item_ids[]" value="<?= (int)$item['id'] ?>" <?= $item['imported_product_id'] && !$item['already_imported_to_target'] ? '' : '' ?>></td>
          <td><strong><?= View::e($item['title']) ?></strong><br><small class="muted"><?= View::e($item['external_item_id']) ?></small></td>
          <td><?= View::e($item['account_name']) ?></td>
          <td><?= View::e($item['seller_sku'] ?: $item['external_item_id']) ?></td>
          <td><?= $money($item['price']) ?></td>
          <td><?= (int)$item['available_quantity'] ?></td>
          <td>
            <?php if ($item['imported_product_id']): ?>
              <span class="badge <?= !empty($item['already_imported_to_target']) ? 'green' : 'amber' ?>">Importado</span>
              <small class="muted"><?= View::e($item['imported_product_name'] ?? '') ?></small>
            <?php else: ?>
              <span class="badge amber">Nuevo</span>
            <?php endif; ?>
          </td>
        </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
</form>
