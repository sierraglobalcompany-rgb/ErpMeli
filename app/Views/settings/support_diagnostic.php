<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$diagnostic = is_array($diagnostic ?? null) ? $diagnostic : null;
$reference = (string) ($reference ?? '');
?>
<div class="page-head">
  <div>
    <span class="eyebrow">Soporte</span>
    <h1>Diagnóstico seguro</h1>
    <p>Consulte una referencia <code>ERR-...</code> sin exponer SQL completo, rutas privadas ni secretos.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/diagnostics">Diagnóstico general</a>
  </div>
</div>

<section class="panel">
  <form method="get" action="<?= View::e($base) ?>/settings/diagnostics/error" class="inline-form">
    <label for="reference"><strong>Referencia</strong></label>
    <input id="reference" name="reference" value="<?= View::e($reference) ?>" placeholder="ERR-20260803-013450-1abd08" style="min-width:320px">
    <button class="btn primary" type="submit">Buscar diagnóstico</button>
  </form>
</section>

<?php if ($diagnostic !== null): ?>
  <section class="panel mt-3">
    <div class="panel-head">
      <div>
        <h2><?= View::e((string) $diagnostic['reference']) ?></h2>
        <p>Resultado sanitizado para soporte administrativo.</p>
      </div>
    </div>
    <dl class="settings-list">
      <div><dt>Fecha</dt><dd><?= View::e(DateTimePresenter::formatQueue((string) $diagnostic['created_at'])) ?></dd></div>
      <div><dt>Ruta</dt><dd><code><?= View::e((string) $diagnostic['route']) ?></code></dd></div>
      <div><dt>Método</dt><dd><?= View::e((string) $diagnostic['method']) ?></dd></div>
      <div><dt>Tipo</dt><dd><?= View::e((string) $diagnostic['exception']) ?></dd></div>
      <div><dt>SQLSTATE</dt><dd><?= View::e((string) ($diagnostic['sqlstate'] ?: 'No aplica')) ?></dd></div>
      <div><dt>Código driver</dt><dd><?= View::e((string) ($diagnostic['driver_code'] ?: 'No aplica')) ?></dd></div>
      <div><dt>Causa segura</dt><dd><?= View::e((string) $diagnostic['safe_message']) ?></dd></div>
      <div><dt>Acción recomendada</dt><dd><?= View::e((string) $diagnostic['recommended_action']) ?></dd></div>
    </dl>
  </section>
<?php endif; ?>
