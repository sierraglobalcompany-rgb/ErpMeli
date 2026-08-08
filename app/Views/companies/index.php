<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$company = $editing ?? null;
$usageLabels = [
    'accounts' => 'Cuentas ML',
    'orders' => 'Órdenes',
    'monthly_reports' => 'Cierres mensuales',
    'date_billings' => 'Facturación por fechas',
    'settings' => 'Configuración',
];
$duplicateCompanies = $duplicateCompanies ?? [];
$duplicateNameKeys = [];
foreach ($duplicateCompanies as $duplicateGroup) {
    $duplicateNameKeys[(string) ($duplicateGroup['name_key'] ?? '')] = $duplicateGroup;
}
$companyNameKey = static fn(array $row): string => mb_strtolower(trim((string) ($row['name'] ?? '')), 'UTF-8');
?>
<div class="page-head"><div><h1>Empresas</h1><p>Entidades internas, datos fiscales y relación con cuentas Mercado Libre.</p></div></div>
<?php if ($duplicateCompanies): ?>
  <div class="alert warning">
    <strong>Hay empresas duplicadas por nombre; revise asociaciones antes de eliminar.</strong>
    <div class="muted mt-1">
      <?php foreach ($duplicateCompanies as $group): ?>
        <span class="badge amber"><?= View::e($group['display_name'] ?? 'Empresa') ?> · IDs <?= View::e((string) ($group['ids'] ?? '—')) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<section class="panel filter-bar">
  <header class="panel-head"><h2><?= $company ? 'Editar empresa' : 'Crear empresa' ?></h2></header>
  <form method="post" action="<?= View::e($base) ?>/companies<?= $company ? '/update' : '' ?>">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><?php if ($company): ?><input type="hidden" name="id" value="<?= (int) $company['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label>Nombre comercial</label><input class="input" name="name" value="<?= View::e($company['name'] ?? '') ?>" required></div>
      <div class="field"><label>Razón social</label><input class="input" name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>" required></div>
      <div class="field"><label>NIT</label><input class="input" name="nit" value="<?= View::e($company['nit'] ?? '') ?>"></div>
      <div class="field"><label>Tipo persona</label><select class="input" name="person_type"><option value="juridica" <?= ($company['person_type'] ?? 'juridica') === 'juridica' ? 'selected' : '' ?>>Jurídica</option><option value="natural" <?= ($company['person_type'] ?? '') === 'natural' ? 'selected' : '' ?>>Natural</option></select></div>
      <div class="field"><label>Correo</label><input class="input" name="email" type="email" value="<?= View::e($company['email'] ?? '') ?>"></div>
      <div class="field"><label>Teléfono</label><input class="input" name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></div>
      <div class="field"><label>Dirección</label><input class="input" name="address" value="<?= View::e($company['address'] ?? '') ?>"></div>
      <div class="field"><label>Ciudad</label><input class="input" name="city" value="<?= View::e($company['city'] ?? '') ?>"></div>
      <div class="field"><label>Departamento</label><input class="input" name="department" value="<?= View::e($company['department'] ?? '') ?>"></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary"><?= $company ? 'Guardar cambios' : 'Crear empresa' ?></button></div>
      <?php if ($company): ?><div class="field"><label>&nbsp;</label><a class="btn" href="<?= View::e($base) ?>/companies">Cancelar edición</a></div><?php endif; ?>
    </div>
  </form>
</section>
<?php if ($detail): ?>
  <section class="panel table-panel">
    <header class="panel-head"><h2>Detalle: <?= View::e($detail['company']['name']) ?></h2></header>
    <div class="panel-body detail-grid">
      <?php foreach ($detail['usage'] as $label => $count): ?><div class="detail-block"><h3><?= View::e($usageLabels[$label] ?? ucfirst($label)) ?></h3><strong><?= (int) $count ?></strong></div><?php endforeach; ?>
    </div>
    <div class="table-scroll"><table class="data-table"><caption class="sr-only">Cuentas Mercado Libre asociadas</caption><thead><tr><th>Cuenta ML</th><th>Estado</th><th>Última sincronización</th></tr></thead><tbody><?php if (!$detail['accounts']): ?><tr><td colspan="3"><div class="empty">Sin cuentas Mercado Libre asociadas.</div></td></tr><?php endif; ?><?php foreach ($detail['accounts'] as $a): ?><tr><td><?= View::e($a['account_name']) ?></td><td><?= View::e($a['status']) ?></td><td><?= View::e($a['last_sync_at'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
  </section>
  <?php if (!empty($detail['date_reports'])): ?>
    <section class="panel table-panel mt-2"><header class="panel-head"><h2>Facturación por fechas asociada</h2></header><div class="table-scroll"><table class="data-table"><thead><tr><th>Rango</th><th>Estado</th><th>Neto</th><th>Base manual</th></tr></thead><tbody><?php foreach ($detail['date_reports'] as $run): ?><tr><td><a class="link" href="<?= View::e($base) ?>/billing/date/show?id=<?= (int) $run['id'] ?>"><?= View::e($run['date_from'] . ' a ' . $run['date_to']) ?></a></td><td><span class="badge <?= in_array($run['status'], ['aprobado', 'facturado'], true) ? 'green' : 'amber' ?>"><?= View::e($run['status']) ?></span></td><td><?= number_format((float) $run['estimated_net'], 0, ',', '.') ?></td><td><?= number_format((float) $run['manual_base'], 0, ',', '.') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php endif; ?>
<?php endif; ?>
<section class="panel table-panel mt-2"><div class="table-scroll"><table class="data-table"><thead><tr><th>Empresa</th><th>Razón social</th><th>NIT</th><th>Correo</th><th>Estado</th><th>Asociaciones</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($companies as $c): ?>
  <?php $isDuplicateName = isset($duplicateNameKeys[$companyNameKey($c)]); ?>
  <tr>
    <td>
      <strong><?= View::e($c['name']) ?></strong>
      <br><small>ID #<?= (int) $c['id'] ?> · NIT <?= View::e($c['nit'] ?: '—') ?> · <?= $c['status'] ? 'Activa' : 'Inactiva' ?></small>
      <?php if ($isDuplicateName): ?><br><span class="badge amber">Nombre duplicado</span><?php endif; ?>
    </td>
    <td><?= View::e($c['legal_name'] ?: '—') ?></td>
    <td><?= View::e($c['nit'] ?: '—') ?></td>
    <td><?= View::e($c['email'] ?: '—') ?></td>
    <td><span class="badge <?= $c['status'] ? 'green' : 'amber' ?>"><?= $c['status'] ? 'Activa' : 'Inactiva' ?></span></td>
    <td><?= (int) ($c['accounts_count'] ?? 0) ?> cuentas · <?= (int) ($c['reports_count'] ?? 0) ?> reportes/facturaciones</td>
    <td class="actions-cell">
      <a class="btn small" href="<?= View::e($base) ?>/companies/show?id=<?= (int) $c['id'] ?>">Ver/editar</a>
      <?php if ($c['status']): ?>
        <form method="post" action="<?= View::e($base) ?>/companies/deactivate"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn small">Desactivar</button></form>
      <?php else: ?>
        <form method="post" action="<?= View::e($base) ?>/companies/reactivate"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn small">Reactivar</button></form>
      <?php endif; ?>
      <form method="post" action="<?= View::e($base) ?>/companies/delete" data-confirm="¿Eliminar empresa? Si tiene datos asociados se desactivará."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn small danger">Eliminar</button></form>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table></div></section>
