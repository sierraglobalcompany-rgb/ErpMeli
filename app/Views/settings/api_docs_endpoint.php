<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <a class="link" href="<?= View::e($base) ?>/settings/api-docs">← Volver a documentación API</a>
    <h1>Detalle de endpoint Mercado Libre</h1>
    <p><code><?= View::e($path ?: 'sin endpoint') ?></code></p>
  </div>
</div>

<?php if (!$endpoint): ?>
  <div class="alert warning">No se encontró contrato materializado para este endpoint.</div>
<?php else: ?>
  <section class="panel">
    <div class="form-grid two">
      <div><label>Método</label><div class="static-box"><?= View::e((string) ($endpoint['method'] ?? 'GET')) ?></div></div>
      <div><label>Estado</label><div class="static-box"><?= View::e((string) ($endpoint['status'] ?? 'unknown')) ?></div></div>
      <div><label>Ruta documentada</label><div class="static-box"><code><?= View::e((string) ($endpoint['documented_path'] ?? '—')) ?></code></div></div>
      <div><label>Ruta normalizada</label><div class="static-box"><code><?= View::e((string) ($endpoint['normalized_path'] ?? '—')) ?></code></div></div>
      <div><label>Contexto permitido</label><div class="static-box"><?= View::e(is_array($endpoint['allowed_contexts'] ?? null) ? implode(', ', $endpoint['allowed_contexts']) : (string) ($endpoint['context'] ?? '—')) ?></div></div>
      <div><label>Riesgo</label><div class="static-box"><?= View::e((string) ($endpoint['risk_level'] ?? '—')) ?></div></div>
    </div>
  </section>

  <section class="panel">
    <h2>Servicios ERP relacionados</h2>
    <?php $services = is_array($endpoint['erp_services'] ?? null) ? $endpoint['erp_services'] : []; ?>
    <div class="chips">
      <?php if (!$services): ?><span class="muted">No hay servicio asociado en el contrato.</span><?php endif; ?>
      <?php foreach ($services as $service): ?><span class="chip"><?= View::e((string) $service) ?></span><?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <h2>Contrato crudo sanitizado</h2>
    <pre class="code-block"><?= View::e(json_encode($endpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
  </section>
<?php endif; ?>
