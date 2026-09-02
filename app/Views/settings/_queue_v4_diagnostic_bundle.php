<?php

use App\Core\View;

$queueDiagnosticBase = rtrim((string) ($base ?? ''), '/');
$queueDiagnosticCsrf = (string) ($csrfToken ?? '');
?>
<section id="queue-v4-diagnostic" class="cron-task-section queue-v4-diagnostic"
         data-queue-v4-diagnostic
         data-status-url="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/status.json">
  <div class="section-heading">
    <div>
      <span class="eyebrow">DIAGNÓSTICO</span>
      <h2>Diagnóstico de Queue</h2>
      <p>Paquete sanitizado para auditoría normal desde el panel. SSH queda sólo para casos excepcionales.</p>
    </div>
  </div>

  <section class="cron-primary-grid" aria-label="Resumen diagnóstico Queue V4">
    <article>
      <span>Debug extendido</span>
      <strong data-qv4diag-debug>Verificando…</strong>
      <p>Base receipt siempre activo; detalle extendido con vencimiento automático.</p>
    </article>
    <article>
      <span>Último paquete</span>
      <strong data-qv4diag-latest>—</strong>
      <p>ZIP sanitizado con manifest y hashes internos.</p>
    </article>
    <article>
      <span>Unidad de control</span>
      <strong>PHYSICAL_API_CALL</strong>
      <p>Incluye CALL_BUDGET, llamadas usadas, restante y motivo de parada.</p>
    </article>
    <article>
      <span>Retención</span>
      <strong>24h / 48h</strong>
      <p>ZIP 24h, debug 48h, link firmado 30 min.</p>
    </article>
  </section>

  <div class="page-actions" style="margin-top:1rem;flex-wrap:wrap">
    <form method="post" action="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/debug">
      <input type="hidden" name="_token" value="<?= View::e($queueDiagnosticCsrf) ?>">
      <input type="hidden" name="minutes" value="15">
      <button class="btn" type="submit">Activar 15 min</button>
    </form>
    <form method="post" action="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/debug">
      <input type="hidden" name="_token" value="<?= View::e($queueDiagnosticCsrf) ?>">
      <input type="hidden" name="minutes" value="30">
      <button class="btn" type="submit">Activar 30 min</button>
    </form>
    <form method="post" action="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/debug">
      <input type="hidden" name="_token" value="<?= View::e($queueDiagnosticCsrf) ?>">
      <input type="hidden" name="minutes" value="60">
      <button class="btn" type="submit">Activar 60 min</button>
    </form>
    <form method="post" action="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/debug">
      <input type="hidden" name="_token" value="<?= View::e($queueDiagnosticCsrf) ?>">
      <input type="hidden" name="minutes" value="0">
      <button class="btn btn-secondary" type="submit">Apagar debug</button>
    </form>
    <form method="post" action="<?= View::e($queueDiagnosticBase) ?>/settings/cron/queue-diagnostic/generate">
      <input type="hidden" name="_token" value="<?= View::e($queueDiagnosticCsrf) ?>">
      <button class="btn btn-primary" type="submit">Generar paquete diagnóstico</button>
    </form>
  </div>

  <div class="callout" data-qv4diag-download style="margin-top:1rem;display:none">
    <strong>Paquete listo para auditoría</strong>
    <p data-qv4diag-download-meta>ZIP sanitizado generado.</p>
    <a class="btn" data-qv4diag-download-link href="#">Descargar ZIP</a>
  </div>

  <details class="technical-details" style="margin-top:1rem">
    <summary>Diagnóstico técnico</summary>
    <pre data-qv4diag-raw>{}</pre>
  </details>
</section>

<script>
(function () {
  const root = document.querySelector('[data-queue-v4-diagnostic]');
  if (!root) return;
  const statusUrl = root.getAttribute('data-status-url');
  const debugNode = root.querySelector('[data-qv4diag-debug]');
  const latestNode = root.querySelector('[data-qv4diag-latest]');
  const rawNode = root.querySelector('[data-qv4diag-raw]');
  const downloadBox = root.querySelector('[data-qv4diag-download]');
  const downloadMeta = root.querySelector('[data-qv4diag-download-meta]');
  const downloadLink = root.querySelector('[data-qv4diag-download-link]');

  fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((response) => response.ok ? response.json() : Promise.reject(new Error('status_http_' + response.status)))
    .then((data) => {
      const debug = data.debug || {};
      const latest = data.latest_bundle || null;
      debugNode.textContent = debug.enabled ? ('Activo hasta ' + (debug.expires_at || '—')) : 'Apagado';
      latestNode.textContent = latest ? (latest.created_at || 'Disponible') : 'Sin paquete';
      rawNode.textContent = JSON.stringify(data, null, 2);
      if (latest && latest.signed_url) {
        downloadBox.style.display = '';
        downloadMeta.textContent = 'SHA-256 ' + (latest.zip_sha256 || '—') + ' · vence ' + (latest.signed_url_expires_at || '—');
        downloadLink.href = latest.signed_url;
      }
    })
    .catch((error) => {
      debugNode.textContent = 'No disponible';
      latestNode.textContent = 'Revisar';
      rawNode.textContent = JSON.stringify({ ok: false, error: String(error && error.message ? error.message : error) }, null, 2);
    });
})();
</script>
