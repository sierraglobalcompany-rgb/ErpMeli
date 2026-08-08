(() => {
  'use strict';

  const initializeLiveOverview = () => {
    const root = document.querySelector('[data-api-health-live]');
    if (!root || !window.fetch || !window.AbortController || root.dataset.liveReady === '1') return;
    root.dataset.liveReady = '1';

    const freshness = document.querySelector('[data-api-health-freshness]');
    let controller = null;
    let timer = null;
    let failures = 0;

  const text = (card, selector, value) => {
    if (value === undefined || value === null || value === '') return;
    const node = root.querySelector(`[data-health-card="${card}"] ${selector}`);
    if (node) node.textContent = String(value);
  };

  const render = (payload) => {
    const protocol = String(payload.snapshot_state || payload.protocol || 'unavailable');
    if (protocol === 'unavailable') {
      if (freshness) freshness.textContent = 'No se pudo actualizar. Se conserva el último estado comprobado.';
      return;
    }

    const status = payload.status || {};
    const protection = payload.protection || {};
    const automation = payload.automation || {};
    const safety = payload.system_safety || {};
    text('marketplace', '[data-health-label]', status.label);
    text('marketplace', '[data-health-message]', status.summary);

    if (Object.keys(protection).length) {
      const stopped = String(safety.api || '') === 'stopped';
      const pauses = Number(protection.pause_count || 0);
      const waits = Number(protection.erp_wait_count || 0);
      text('protection', '[data-health-label]', stopped ? 'Bloqueada por mantenimiento' : (pauses + waits > 0 ? 'Limitando el ritmo' : 'Sin pausas activas'));
      text('protection', '[data-health-message]', `${waits} esperas preventivas · ${pauses} pausas activas.`);
    }

    text('automation', '[data-health-label]', automation.label);
    text('automation', '[data-health-message]', automation.message);
    if (freshness) {
      const measured = payload.checked_at ? new Date(`${String(payload.checked_at).replace(' ', 'T')}Z`) : null;
      freshness.textContent = measured && !Number.isNaN(measured.getTime())
        ? `Actualizado ${measured.toLocaleString('es-CO', { timeZone: 'America/Bogota' })}.`
        : 'Estado actualizado.';
    }
  };

  const schedule = () => {
    window.clearTimeout(timer);
    const base = document.hidden ? 60000 : 30000;
    timer = window.setTimeout(refresh, Math.min(120000, base * Math.max(1, 2 ** failures)));
  };

  const refresh = async () => {
    controller?.abort();
    controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(root.dataset.overviewUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false || payload.snapshot_state === 'unavailable') {
        throw new Error('unavailable');
      }
      failures = 0;
      render(payload);
    } catch (error) {
      if (error?.name !== 'AbortError') failures = Math.min(4, failures + 1);
      if (freshness) freshness.textContent = 'No se pudo actualizar. Se conserva el último estado comprobado.';
    } finally {
      window.clearTimeout(timeout);
      schedule();
    }
  };

    document.addEventListener('visibilitychange', schedule);
    refresh();
  };

  const shell = document.querySelector('[data-api-health-shell]');
  if (!shell) {
    initializeLiveOverview();
    return;
  }
  if (!window.fetch || !window.AbortController) {
    const href = shell.dataset.sectionUrl || '/settings/api-health/section.html';
    shell.setAttribute('aria-busy', 'false');
    shell.innerHTML = `<section class="alert warning" data-api-health-section-error><strong>No se pudo cargar Salud API de forma progresiva.</strong> El navegador no expone la lectura asíncrona requerida. <a class="btn" href="${href}">Abrir lectura completa</a></section>`;
    return;
  }

  let sectionController = null;
  let operationalController = null;
  let sectionFailures = 0;
  let renderedOperational = false;

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const labelFrom = (payload, path, fallback) => {
    let value = payload;
    for (const key of path) value = value && typeof value === 'object' ? value[key] : undefined;
    return value === undefined || value === null || value === '' ? fallback : String(value);
  };

  const renderOperationalSnapshot = (payload) => {
    const state = String(payload?.snapshot_state || payload?.protocol || 'unavailable');
    const available = state !== 'unavailable' && payload?.ok !== false;
    const marketLabel = available
      ? labelFrom(payload, ['mercado_libre', 'label'], 'Mercado Libre se comprueba en Salud API')
      : 'No se pudo comprobar';
    const marketMessage = available
      ? labelFrom(payload, ['mercado_libre', 'message'], 'La disponibilidad remota queda separada de la automatización V3.')
      : 'La sección rápida no respondió. No se consultó Mercado Libre desde esta página.';
    const automationLabel = available
      ? labelFrom(payload, ['automation', 'label'], 'Automatización V3 por comprobar')
      : 'No se pudo comprobar';
    const automationMessage = available
      ? labelFrom(payload, ['automation', 'message'], 'Se conserva la última lectura válida si una sección falla.')
      : 'No se reemplazarán datos válidos por ceros mientras se reintenta.';
    const backlog = payload?.backlog || {};
    const pending = Number(backlog.legacy_pending_visible ?? backlog.pending ?? 0);
    const completed = Number(backlog.v3_completed_last_hour ?? backlog.completed_last_hour ?? 0);
    const http = Number(backlog.v3_http_last_hour ?? backlog.http_last_hour ?? 0);
    const measuredAt = labelFrom(payload, ['measured_at'], 'sin fecha reciente');

    shell.innerHTML = `
      <section class="api-health-triad" aria-label="Salud API y automatización V3">
        <article data-health-card="marketplace">
          <span>Mercado Libre</span>
          <strong data-health-label>${escapeHtml(marketLabel)}</strong>
          <p data-health-message>${escapeHtml(marketMessage)}</p>
        </article>
        <article data-health-card="automation">
          <span>Automatización</span>
          <strong data-health-label>${escapeHtml(automationLabel)}</strong>
          <p data-health-message>${escapeHtml(automationMessage)}</p>
        </article>
        <article data-health-card="diagnostic">
          <span>Backlog y diagnóstico</span>
          <strong>${Number.isFinite(pending) ? pending.toLocaleString('es-CO') : 'Por comprobar'} pendientes visibles</strong>
          <p>${Number.isFinite(http) ? http.toLocaleString('es-CO') : '0'} HTTP/h · ${Number.isFinite(completed) ? completed.toLocaleString('es-CO') : '0'} recursos/h · snapshot ${escapeHtml(measuredAt)} UTC.</p>
        </article>
      </section>
      <section class="api-command-section">
        <header><div><span class="eyebrow">ESTADO DEL DIAGNÓSTICO</span><h2>${available ? 'Lectura rápida V3 disponible' : 'No se pudo comprobar esta sección'}</h2></div></header>
        <p>${available ? 'Cargando detalle completo en segundo plano; si falla, esta lectura rápida se conserva.' : 'Puede reintentar sin tocar colas ni consultar Mercado Libre.'}</p>
        <p class="muted" data-api-health-shell-status>Snapshot operativo ${escapeHtml(measuredAt)} UTC · lectura sin mutaciones.</p>
      </section>`;
    renderedOperational = true;
    shell.setAttribute('aria-busy', 'false');
  };

  const loadOperational = async () => {
    if (!shell.dataset.operationalUrl) return false;
    operationalController?.abort();
    operationalController = new AbortController();
    const timeout = window.setTimeout(() => operationalController.abort(), 5000);
    try {
      const response = await fetch(shell.dataset.operationalUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: operationalController.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false || payload.snapshot_state === 'unavailable') {
        throw new Error('unavailable');
      }
      renderOperationalSnapshot(payload);
      return true;
    } catch (_) {
      renderOperationalSnapshot({ ok: false, snapshot_state: 'unavailable' });
      return false;
    } finally {
      window.clearTimeout(timeout);
    }
  };

  const loadSection = async () => {
    sectionController?.abort();
    sectionController = new AbortController();
    const timeout = window.setTimeout(() => sectionController.abort(), 8000);
    shell.setAttribute('aria-busy', renderedOperational ? 'false' : 'true');
    try {
      const response = await fetch(shell.dataset.sectionUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html' },
        signal: sectionController.signal,
      });
      const html = await response.text();
      if (!response.ok) throw new Error('unavailable');
      shell.innerHTML = html;
      shell.setAttribute('aria-busy', 'false');
      sectionFailures = 0;
      initializeLiveOverview();
    } catch (error) {
      if (error?.name === 'AbortError') return;
      sectionFailures = Math.min(4, sectionFailures + 1);
      shell.setAttribute('aria-busy', 'false');
      const href = shell.dataset.sectionUrl || '/settings/api-health/section.html';
      const warning = `<section class="alert warning" data-api-health-section-error><strong>No se pudo comprobar esta sección.</strong> Se conserva la lectura rápida disponible. <button class="btn" type="button" data-api-health-section-retry>Reintentar</button> <a class="btn" href="${href}">Abrir lectura completa</a></section>`;
      if (renderedOperational) {
        shell.querySelectorAll('[data-api-health-section-error]').forEach((node) => node.remove());
        shell.insertAdjacentHTML('beforeend', warning);
      } else {
        shell.innerHTML = warning;
      }
    } finally {
      window.clearTimeout(timeout);
    }
  };

  shell.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-api-health-section-retry]')) {
      loadSection();
    }
  });
  window.addEventListener('pagehide', () => {
    sectionController?.abort();
    operationalController?.abort();
  }, { once: true });
  loadOperational().finally(() => loadSection());
})();
