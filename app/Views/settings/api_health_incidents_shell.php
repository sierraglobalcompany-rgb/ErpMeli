<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = $apiHealthSection ?? 'incidents';
$apiHealthHours = (int) ($apiHealthHours ?? 24);
$apiHealthCheckedAt = (string) ($apiHealthCheckedAt ?? gmdate('Y-m-d H:i:s'));
$operatorCheckedAtLabel = DateTimePresenter::formatQueue($apiHealthCheckedAt, 'd/m H:i');
$query = (string) ($query ?? 'hours=24');
$jsonUrl = $base . '/settings/api-health/incidents.json?' . $query;
$fullUrl = $base . '/settings/api-health/incidents?' . $query . '&full=1';

require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>
<div class="api-subpage-intro">
  <div>
    <span class="eyebrow">Incidentes</span>
    <h2>Incidentes de integración</h2>
    <p>La pantalla aparece primero y carga el detalle agrupado en segundo plano para no congelar el navegador.</p>
  </div>
</div>

<section class="api-command-section"
         data-api-incidents-shell
         data-json-url="<?= View::e($jsonUrl) ?>"
         data-full-url="<?= View::e($fullUrl) ?>"
         aria-busy="true">
  <header class="panel-head">
    <div>
      <h2 data-api-incidents-title>Lectura rápida disponible</h2>
      <p data-api-incidents-status>Consultando catálogo agrupado · última apertura <?= View::e($operatorCheckedAtLabel) ?>.</p>
    </div>
    <a class="btn" href="<?= View::e($fullUrl) ?>">Abrir lectura completa</a>
  </header>
  <div class="api-essential-metrics" aria-label="Resumen rápido de incidentes">
    <article><span>Críticos</span><strong data-api-incidents-metric="critical">—</strong><p>OAuth, permisos o bloqueo</p></article>
    <article><span>429 remoto</span><strong data-api-incidents-metric="rate_limit">—</strong><p>Rate limit confirmado</p></article>
    <article><span>5xx remoto</span><strong data-api-incidents-metric="remote">—</strong><p>Fallo Mercado Libre</p></article>
    <article><span>Total agrupado</span><strong data-api-incidents-metric="total">—</strong><p>No se muestra vacío hasta certificar</p></article>
  </div>
  <div class="incident-list" data-api-incidents-list>
    <div class="empty-state">
      <strong>Cargando incidentes agrupados…</strong>
      <span>Si la lectura tarda, esta tarjeta conserva el estado y deja una ruta completa acotada.</span>
    </div>
  </div>
</section>

<script>
(function(){
  const root = document.querySelector('[data-api-incidents-shell]');
  if (!root || !window.fetch) return;
  const list = root.querySelector('[data-api-incidents-list]');
  const title = root.querySelector('[data-api-incidents-title]');
  const status = root.querySelector('[data-api-incidents-status]');
  const metric = (key, value) => {
    const node = root.querySelector(`[data-api-incidents-metric="${key}"]`);
    if (node) node.textContent = String(value);
  };
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8000);
  fetch(root.dataset.jsonUrl, {credentials:'same-origin', signal: controller.signal})
    .then(r => r.json().then(data => ({ok:r.ok, data})))
    .then(({ok, data}) => {
      clearTimeout(timeout);
      root.setAttribute('aria-busy', 'false');
      const rows = Array.isArray(data.incidents) ? data.incidents : [];
      const total = data.pagination && Number.isFinite(Number(data.pagination.total)) ? Number(data.pagination.total) : rows.length;
      metric('total', total);
      metric('critical', rows.filter(i => i && i.severity === 'critical').length);
      metric('rate_limit', rows.filter(i => i && i.transport_class === 'REMOTE_HTTP_429').length);
      metric('remote', rows.filter(i => i && i.reached_remote && Number(i.http_status || 0) >= 500).length);
      title.textContent = ok ? 'Catálogo agrupado cargado' : 'Catálogo no certificado';
      status.textContent = ok ? `Mostrando ${rows.length} de ${total} grupos.` : 'No se informa como cero; abra la lectura completa si necesita detalle.';
      if (rows.length === 0) {
        list.innerHTML = '<div class="empty-state"><strong>No hay grupos para esta lectura certificada</strong><span>Si el catálogo no está al día, el sistema lo indicará arriba.</span></div>';
        return;
      }
      list.innerHTML = rows.slice(0, 10).map((incident) => {
        const key = encodeURIComponent(String(incident.incident_key || ''));
        const titleText = escapeHtml(String(incident.title || 'Incidente API'));
        const impact = escapeHtml(String(incident.impact || 'Requiere revisión operativa.'));
        const account = escapeHtml(String(incident.account_names || 'Aplicación'));
        const reps = Number(incident.repetitions || 1);
        return `<article class="incident-card"><div class="incident-card-main"><div class="incident-title-row"><strong>${titleText}</strong></div><p>${impact}</p><div class="incident-meta"><span>${reps} repeticiones</span><span>${account}</span></div></div><a class="btn" href="${root.dataset.fullUrl}#${key}">Ver detalle</a></article>`;
      }).join('');
    })
    .catch(() => {
      clearTimeout(timeout);
      root.setAttribute('aria-busy', 'false');
      title.textContent = 'Lectura completa recomendada';
      status.textContent = 'El JSON tardó demasiado o falló; no se informa como vacío.';
      list.innerHTML = `<div class="empty-state"><strong>No se pudo cargar en segundo plano</strong><span>Use “Abrir lectura completa” para una lectura acotada del servidor.</span></div>`;
    });

  function escapeHtml(value) {
    return value.replace(/[&<>"']/g, function(ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
    });
  }
})();
</script>
