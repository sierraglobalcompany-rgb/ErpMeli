<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$isEdit = is_array($catalog);
$action = $isEdit ? $base . '/catalogs/' . (int) $catalog['id'] . '/update' : $base . '/catalogs';
$checked = static fn(string $key, bool $default = false): string => (int) ($catalog[$key] ?? ($default ? 1 : 0)) === 1 ? 'checked' : '';
$publicStatuses = json_decode((string) ($catalog['public_statuses_json'] ?? '["active"]'), true);
if (!is_array($publicStatuses) || $publicStatuses === []) {
    $publicStatuses = ['active'];
}
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs">← Volver a catálogos</a>
    <h1><?= $isEdit ? 'Editar catálogo' : 'Crear catálogo' ?></h1>
    <p>Configura visibilidad, filtros y campos del catálogo. El público siempre lee datos locales.</p>
  </div>
</div>

<?php if ($privateLink = \App\Core\Session::flash('private_link')): ?>
  <section class="panel panel-body catalog-private-link-box">
    <h2>Enlace privado del catálogo</h2>
    <p class="muted">Cópielo ahora. Por seguridad el token completo no queda guardado en texto plano y no se podrá volver a ver; si lo pierde, genere otro enlace.</p>
    <div class="copy-link-row">
      <input class="input" readonly value="<?= View::e($privateLink) ?>" data-copy-source>
      <button type="button" class="btn primary" data-copy-text="<?= View::e($privateLink) ?>">Copiar enlace</button>
    </div>
  </section>
<?php endif; ?>

<form class="panel panel-body" method="post" action="<?= View::e($action) ?>">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <div class="form-grid">
    <div class="field"><label>Nombre</label><input class="input" name="name" required value="<?= View::e($catalog['name'] ?? '') ?>"></div>
    <div class="field"><label>Slug URL</label><input class="input" name="slug" required pattern="[a-z0-9-]+" value="<?= View::e($catalog['slug'] ?? '') ?>" placeholder="catalogo-general"></div>
    <div class="field">
      <label>Visibilidad</label>
      <select class="input" name="visibility">
        <?php foreach (['internal_only'=>'Solo interno','public'=>'Público','private_token'=>'Privado por enlace','private_password'=>'Clave para empleados'] as $value => $label): ?>
          <option value="<?= View::e($value) ?>" <?= ($catalog['visibility'] ?? 'internal_only') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Tema</label>
      <select class="input" name="layout_type">
        <?php foreach (['ml_blue'=>'Azul tipo ML','clean_white'=>'Blanco limpio','compact'=>'Compacto'] as $value => $label): ?>
          <option value="<?= View::e($value) ?>" <?= ($catalog['layout_type'] ?? 'ml_blue') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field mt-2"><label>Descripción</label><textarea class="input" name="description"><?= View::e($catalog['description'] ?? '') ?></textarea></div>

  <div class="form-grid mt-2">
    <div class="field">
      <label>Fuente del catálogo</label>
      <select class="input" name="source_type">
        <?php foreach (['meli'=>'Mercado Libre','internal'=>'Bodega interna','combined'=>'Combinado'] as $value => $label): ?>
          <option value="<?= View::e($value) ?>" <?= ($catalog['source_type'] ?? 'meli') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="muted">Combinado evita duplicar publicaciones ya vinculadas a productos internos.</small>
    </div>
    <div class="field">
      <label>Alcance de cuenta</label>
      <select class="input" name="account_scope">
        <option value="all" <?= ($catalog['account_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todas las cuentas</option>
        <option value="single_account" <?= ($catalog['account_scope'] ?? '') === 'single_account' ? 'selected' : '' ?>>Cuenta específica</option>
      </select>
    </div>
    <div class="field">
      <label>Cuenta Mercado Libre</label>
      <select class="input" name="meli_account_id">
        <option value="0">Todas</option>
        <?php foreach ($accounts as $account): ?>
          <option value="<?= (int) $account['id'] ?>" <?= (int) ($catalog['meli_account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Categorías</label>
      <select class="input" name="category_scope">
        <option value="all" <?= ($catalog['category_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todas</option>
        <option value="selected" <?= ($catalog['category_scope'] ?? '') === 'selected' ? 'selected' : '' ?>>Seleccionadas</option>
      </select>
    </div>
    <div class="field">
      <label>Alcance de empresa</label>
      <select class="input" name="company_scope">
        <option value="all" <?= ($catalog['company_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todas las empresas</option>
        <option value="single_company" <?= ($catalog['company_scope'] ?? '') === 'single_company' ? 'selected' : '' ?>>Empresa específica</option>
      </select>
    </div>
    <div class="field">
      <label>Empresa interna</label>
      <select class="input" name="company_id">
        <option value="0">Todas</option>
        <?php foreach (($companies ?? []) as $company): ?>
          <option value="<?= (int) $company['id'] ?>" <?= (int) ($catalog['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= View::e($company['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Clave para empleados inicial</label>
      <input class="input" name="password" type="password" autocomplete="new-password" placeholder="<?= $isEdit ? 'Use el panel inferior para cambiarla' : 'Solo para clave de empleados' ?>">
      <small class="muted">Es una clave propia del catálogo; no usa ni muestra APP_KEY.</small>
    </div>
  </div>

  <div class="catalog-options mt-2">
    <?php foreach ([
      'is_enabled'=>'Catálogo habilitado',
      'allow_indexing'=>'Permitir indexación',
      'allow_public_print'=>'Permitir impresión pública',
      'allow_private_print'=>'Permitir impresión privada',
      'include_out_of_stock'=>'Mostrar sin stock',
      'show_prices'=>'Mostrar precios',
      'show_stock'=>'Mostrar stock',
      'show_stock_breakdown'=>'Mostrar desglose de stock',
      'show_full_badge'=>'Mostrar FULL confirmado',
      'show_shipping_methods'=>'Mostrar métodos de envío',
      'show_stock_detail_public'=>'Mostrar detalle de stock en público',
      'show_sold_count'=>'Mostrar vendidos',
      'show_meli_link'=>'Mostrar link Mercado Libre',
      'show_internal_sku'=>'Mostrar SKU en público',
      'show_status'=>'Mostrar estados avanzados en tarjetas públicas',
      'show_created_date'=>'Mostrar creación',
      'show_updated_date'=>'Mostrar actualización',
      'show_public_advanced_filters'=>'Mostrar filtros avanzados en catálogo público',
    ] as $key => $label): ?>
      <label><input type="checkbox" name="<?= View::e($key) ?>" value="1" <?= $checked($key, in_array($key, ['is_enabled','allow_private_print','include_out_of_stock','show_prices','show_stock','show_full_badge','show_shipping_methods','show_meli_link','show_updated_date'], true)) ?>> <?= View::e($label) ?></label>
    <?php endforeach; ?>
  </div>

  <section class="panel-soft mt-2 catalog-public-status-settings">
    <h2>Estados visibles en el catálogo público</h2>
    <p class="muted">Por seguridad el catálogo público muestra solo publicaciones activas. Si necesitas ver pausadas o finalizadas en un enlace privado para empleados, puedes habilitarlas aquí.</p>
    <div class="catalog-options">
      <?php foreach ([
        'active' => 'Activos',
        'paused' => 'Pausados',
        'closed' => 'Finalizados',
        'inactive' => 'Inactivos',
        'under_review' => 'En revisión',
      ] as $value => $label): ?>
        <label><input type="checkbox" name="public_statuses[]" value="<?= View::e($value) ?>" <?= in_array($value, $publicStatuses, true) ? 'checked' : '' ?>> <?= View::e($label) ?></label>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="quick-actions mt-2">
    <button class="btn primary"><?= $isEdit ? 'Guardar cambios' : 'Crear catálogo' ?></button>
    <?php if ($isEdit): ?><a class="btn" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>">Ver catálogo</a><?php endif; ?>
  </div>
</form>

<?php if ($isEdit): ?>
<section class="panel panel-body mt-2">
  <h2>Seguridad privada</h2>
  <p class="muted">El enlace privado completo solo se muestra al crear o regenerar. La clave para empleados se guarda con hash y nunca en texto plano.</p>
  <div class="quick-actions">
    <form method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/token/regenerate">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <button class="btn">Regenerar y copiar enlace privado</button>
    </form>
    <form class="inline-form" method="post" action="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/password">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input class="input compact-datetime" type="password" name="password" placeholder="Nueva clave privada">
      <button class="btn">Cambiar clave</button>
    </form>
  </div>
</section>
<?php endif; ?>
