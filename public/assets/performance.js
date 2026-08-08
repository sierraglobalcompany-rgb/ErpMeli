(() => {
  'use strict';

  const controllers = new WeakMap();
  const requestIds = new WeakMap();
  const baseUrl = document.querySelector('meta[name="app-base-url"]')?.content || '';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const navigationBar = document.querySelector('[data-navigation-progress]');
  const metricLastSentAt = new Map();
  let sequence = 0;

  const sectionLabels = {
    'dashboard-summary': 'el resumen del Inicio',
    'dashboard-operations': 'la actividad operativa',
    'dashboard-alerts': 'las alertas',
    'orders-list': 'el listado de órdenes',
    'shipments-list': 'el listado de envíos',
    'products-meli-list': 'los productos de Mercado Libre',
    'profitability-list': 'el reporte de rentabilidad',
    'financial-recalc': 'la cola financiera',
    'sales-control-overview': 'la evidencia de Control de ventas',
    'sync-overview': 'el estado de sincronización',
    'notifications-center': 'el Centro de notificaciones',
    'sales-control-detail': 'la evidencia mensual de Control de ventas',
    'technical-events': 'los eventos técnicos'
  };
  const sectionLabel = (section) => sectionLabels[section.dataset.asyncSection] || 'esta sección';
  const safeError = (value, section) => {
    const message = String(value || '').trim();
    if (!message) return `No fue posible cargar ${sectionLabel(section)}.`;
    if (/SQLSTATE|PDOException|unknown column|illegal mix of collations|\/home\/|[A-Z]:\\\\/i.test(message)) {
      return `No fue posible cargar ${sectionLabel(section)}. Abra Diagnóstico si el problema continúa.`;
    }
    return message.length > 220 ? `${message.slice(0, 217)}…` : message;
  };

  const mainPathForSection = (section) => {
    const name = section.dataset.asyncSection;
    if (name === 'orders-list') return `${baseUrl}/orders`;
    if (name === 'shipments-list') return `${baseUrl}/shipments`;
    if (name === 'products-meli-list') return `${baseUrl}/products/meli`;
    return window.location.pathname;
  };

  const sectionUrlWithSearch = (section, search) => {
    const url = new URL(section.dataset.url, window.location.origin);
    url.search = search;
    return url.toString();
  };

  const showStatus = (section, message, error = false) => {
    const status = section.querySelector('[data-async-status]');
    if (!status) return;
    status.hidden = message === '';
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };

  const loadSection = async (section, requestedUrl, updateHistory = false) => {
    controllers.get(section)?.abort();
    const controller = new AbortController();
    controllers.set(section, controller);
    const requestId = ++sequence;
    requestIds.set(section, requestId);
    const skeleton = section.querySelector('[data-async-skeleton]');
    const content = section.querySelector('[data-async-content]');
    const started = performance.now();
    section.setAttribute('aria-busy', 'true');
    showStatus(section, '');

    const skeletonTimer = window.setTimeout(() => skeleton?.classList.add('is-visible'), 150);
    const progressTimer = window.setTimeout(() => showStatus(section, `Cargando ${sectionLabel(section)}…`), 2000);
    const slowTimer = window.setTimeout(() => showStatus(section, `${sectionLabel(section)} está tardando. Puede usar las demás áreas mientras termina.`), 5000);
    const timeoutTimer = window.setTimeout(() => controller.abort('timeout'), 8000);

    try {
      const response = await fetch(requestedUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal
      });
      const payload = await response.json();
      if (requestIds.get(section) !== requestId) return;
      if (!response.ok || !payload.ok) throw new Error(safeError(payload.error, section));
      if (content) {
        content.innerHTML = payload.html;
        document.dispatchEvent(new CustomEvent('erp:content-loaded', { detail: { root: content } }));
      }
      if (skeleton) skeleton.hidden = true;
      showStatus(section, '');
      section.dataset.url = requestedUrl;
      if (updateHistory) {
        const sectionUrl = new URL(requestedUrl, window.location.origin);
        history.pushState({ asyncSection: section.dataset.asyncSection }, '', `${mainPathForSection(section)}${sectionUrl.search}`);
      }
      recordMetric('section_useful', performance.now() - started, {
        section: section.dataset.asyncSection,
        cache: payload.meta?.cache || ''
      });
    } catch (error) {
      if (requestIds.get(section) !== requestId) return;
      const timedOut = controller.signal.aborted && controller.signal.reason === 'timeout';
      const message = timedOut
        ? `La carga de ${sectionLabel(section)} superó 8 segundos y se detuvo. Puede reintentar sin bloquear el ERP.`
        : (controller.signal.aborted ? '' : safeError(error.message, section));
      if (message) {
        showStatus(section, message, true);
        const status = section.querySelector('[data-async-status]');
        if (status) {
          const retry = document.createElement('button');
          retry.type = 'button';
          retry.className = 'btn small';
          retry.textContent = 'Reintentar';
          retry.addEventListener('click', () => loadSection(section, requestedUrl));
          status.append(' ', retry);
        }
      }
      recordMetric(timedOut ? 'section_timeout' : 'section_cancelled', performance.now() - started, {
        section: section.dataset.asyncSection
      });
    } finally {
      [skeletonTimer, progressTimer, slowTimer, timeoutTimer].forEach(window.clearTimeout);
      skeleton?.classList.remove('is-visible');
      section.setAttribute('aria-busy', 'false');
    }
  };

  const initialSections = Array.from(document.querySelectorAll('[data-async-section]'))
    .filter((section) => section.querySelector('[data-async-skeleton]'));
  const loadInitialSections = async () => {
    // Máximo dos solicitudes: permite que el contenido principal no espere a
    // cada panel secundario sin saturar las conexiones del hosting compartido.
    let cursor = 0;
    const sectionRunner = async () => {
      while (cursor < initialSections.length) {
        const section = initialSections[cursor++];
        await loadSection(section, section.dataset.url);
      }
    };
    await Promise.all(Array.from(
      { length: Math.min(2, initialSections.length) },
      () => sectionRunner()
    ));
  };
  loadInitialSections();

  document.querySelectorAll('[data-module-queue-url]').forEach(async (panel) => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(panel.dataset.moduleQueueUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok || !payload.queue) throw new Error('No se pudo comprobar');
      const queue = payload.queue;
      panel.querySelector('[data-module-queue-recommendation]').textContent =
        queue.recommendation || 'La cola modular fue comprobada.';
      panel.querySelectorAll('[data-module-queue-value]').forEach((node) => {
        node.textContent = String(Number(queue[node.dataset.moduleQueueValue] || 0));
      });
      const needsAttention = Number(queue.duplicate_groups || 0) > 0
        || Number(queue.expired_leases || 0) > 0
        || Number(queue.orphan_events || 0) > 0;
      const badge = panel.querySelector('[data-module-queue-badge]');
      if (badge) {
        badge.textContent = queue.available ? (needsAttention ? 'Necesita atención' : 'Comprobado') : 'No se pudo comprobar';
        badge.classList.toggle('success', Boolean(queue.available) && !needsAttention);
        badge.classList.toggle('warning', !queue.available || needsAttention);
      }
      const reconcile = panel.querySelector('[data-module-reconcile-form]');
      if (reconcile) reconcile.hidden = !(queue.available && Number(queue.orphan_events || 0) > 0);
    } catch (_) {
      const recommendation = panel.querySelector('[data-module-queue-recommendation]');
      if (recommendation) recommendation.textContent = 'No se pudo comprobar la cola. Los módulos siguen disponibles.';
      const badge = panel.querySelector('[data-module-queue-badge]');
      if (badge) badge.textContent = 'No se pudo comprobar';
    } finally {
      window.clearTimeout(timeout);
    }
  });

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('.js-async-filter');
    if (!form) return;
    const targetName = form.dataset.sectionTarget;
    const section = document.querySelector(`[data-async-section="${targetName}"]`);
    if (!section || !window.fetch || !window.AbortController) return;
    event.preventDefault();
    const data = new FormData(form);
    data.delete('page');
    const search = new URLSearchParams(data).toString();
    loadSection(section, sectionUrlWithSearch(section, search ? `?${search}` : ''), true);
  });

  document.addEventListener('click', (event) => {
    const pageLink = event.target.closest('[data-async-page]');
    if (pageLink) {
      const section = pageLink.closest('[data-async-section]');
      if (section && window.fetch && window.AbortController) {
        event.preventDefault();
        const target = new URL(pageLink.href);
        loadSection(section, sectionUrlWithSearch(section, target.search), true);
      }
      return;
    }

    const copy = event.target.closest('[data-copy-target]');
    if (copy) {
      const target = document.querySelector(copy.dataset.copyTarget);
      if (target) {
        navigator.clipboard?.writeText(target.value || target.textContent || '');
        const original = copy.textContent;
        copy.textContent = 'Copiado';
        window.setTimeout(() => { copy.textContent = original; }, 1400);
      }
      return;
    }

    const link = event.target.closest('a[href]');
    if (!link || link.target || link.hasAttribute('download') || event.ctrlKey || event.metaKey || event.shiftKey) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || url.hash || link.closest('[data-async-section]')) return;
    navigationBar?.classList.add('is-active');
  });

  window.addEventListener('pageshow', () => {
    navigationBar?.classList.remove('is-active', 'is-complete');
  });
  window.addEventListener('popstate', () => {
    document.querySelectorAll('[data-async-section]').forEach((section) => {
      loadSection(section, sectionUrlWithSearch(section, window.location.search));
    });
  });

  function recordMetric(name, value, extra = {}) {
    if (!csrf || !Number.isFinite(value)) return;
    const slow = (name === 'lcp' && value > 2500) || (name === 'inp' && value > 200) || (name === 'cls' && value > 0.1) || value > 1200;
    if (!slow && Math.random() > 0.1) return;
    const metricKey = `${name}:${String(extra.section || '')}`;
    const now = Date.now();
    if (now - (metricLastSentAt.get(metricKey) || 0) < 30000) return;
    metricLastSentAt.set(metricKey, now);
    const payload = JSON.stringify({
      _token: csrf,
      name,
      value: Math.round(value * 1000) / 1000,
      route: window.location.pathname,
      ...extra
    });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(`${baseUrl}/performance/metrics`, new Blob([payload], { type: 'application/json' }));
    }
  }

  const navigation = performance.getEntriesByType('navigation')[0];
  if (navigation) recordMetric('ttfb', navigation.responseStart);
  if ('PerformanceObserver' in window) {
    try {
      new PerformanceObserver((list) => {
        const entries = list.getEntries();
        const last = entries[entries.length - 1];
        if (last) recordMetric('lcp', last.startTime);
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (_) {}
    try {
      let cls = 0;
      new PerformanceObserver((list) => {
        list.getEntries().forEach((entry) => {
          if (!entry.hadRecentInput) cls += entry.value;
        });
        recordMetric('cls', cls);
      }).observe({ type: 'layout-shift', buffered: true });
    } catch (_) {}
    try {
      new PerformanceObserver((list) => {
        list.getEntries().forEach((entry) => recordMetric('inp', entry.duration));
      }).observe({ type: 'event', buffered: true, durationThreshold: 40 });
    } catch (_) {}
  }
})();

(() => {
  const root = document.querySelector('[data-database-maintenance]');
  if (!root) return;

  const sessionId = Number(root.dataset.sessionId || 0);
  if (!sessionId) return;
  const storageKey = `erp-db-maintenance-owner-${sessionId}`;
  let ownerToken = sessionStorage.getItem(storageKey);
  if (!ownerToken) {
    ownerToken = root.dataset.tabTokenSeed || (
      window.crypto?.randomUUID
        ? `${crypto.randomUUID()}-${crypto.randomUUID()}`
        : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
    );
    sessionStorage.setItem(storageKey, ownerToken);
  }
  root.querySelectorAll('[data-control-token]').forEach((input) => {
    input.value = ownerToken;
  });

  const statusUrl = root.dataset.statusUrl || '';
  const stepUrl = root.dataset.stepUrl || '';
  const csrf = root.dataset.csrf || '';
  const activeSessionStates = new Set(['running', 'pausing', 'finishing']);
  const activePhysicalStates = new Set(['queued', 'running']);
  let currentSessionStatus = root.dataset.sessionStatus || '';
  let currentPhysicalStatus = root.dataset.physicalStatus || '';
  let active = activeSessionStates.has(currentSessionStatus)
    || activePhysicalStates.has(currentPhysicalStatus);
  let inFlight = false;
  let timer = 0;
  let lastStepSignature = '';
  let consecutiveFailures = 0;

  const phaseLabels = {
    analysis: 'Análisis',
    retention: 'Archivo y retención',
    legacy_notifications: 'Normalización histórica',
    legacy_messages: 'Reducción de texto técnico repetido',
    payloads: 'Payloads privados',
    orphans: 'Archivos huérfanos',
    cold_archives: 'Retención de archivos fríos',
    verify: 'Verificación de integridad',
    completed: 'Completado'
  };
  const datasetLabels = {
    notification_success: 'Notificaciones resueltas',
    notification_incidents: 'Incidentes técnicos',
    api_request_logs: 'Historial API',
    cron_health_checks: 'Historial de automatización',
    financial_job_items: 'Detalle de trabajos financieros',
    meli_orders: 'Órdenes',
    meli_shipments: 'Envíos',
    meli_payments: 'Pagos',
    meli_packs: 'Packs',
    meli_order_items: 'Productos vendidos'
  };
  const statusLabels = {
    analyzed: 'Análisis listo',
    running: 'Autorizado para tarea local',
    pausing: 'Terminando el lote actual',
    finishing: 'Preparando verificación final',
    verifying: 'Verificando integridad',
    paused: 'Pausado',
    completed: 'Saneamiento verificado',
    finished: 'Sesión finalizada',
    failed: 'Necesita revisión'
  };
  const number = (value) => new Intl.NumberFormat('es-CO').format(Number(value || 0));

  function update(session, action, duration, physical) {
    if (!session) return;
    root.dataset.sessionStatus = session.status || '';
    const visualStatus = session.status === 'running' && session.phase === 'verify'
      ? 'verifying'
      : session.status;
    root.querySelector('[data-maintenance-state]').textContent = statusLabels[visualStatus] || visualStatus;
    root.querySelector('[data-maintenance-message]').textContent = session.safe_message || 'Estado actualizado.';
    root.querySelector('[data-maintenance-next]').textContent = session.next_action || 'Por calcular';
    root.querySelector('[data-maintenance-phase]').textContent = phaseLabels[session.phase] || session.phase || '—';
    root.querySelector('[data-maintenance-dataset]').textContent = datasetLabels[session.dataset_key] || '—';
    root.querySelector('[data-maintenance-last]').textContent = session.last_step_at || 'Todavía no ejecutado';
    const progress = Math.max(0, Math.min(100, Number(session.progress_percent || 0)));
    const progressRoot = root.querySelector('[data-maintenance-progress]');
    progressRoot?.setAttribute('aria-valuenow', String(Math.round(progress)));
    const bar = root.querySelector('[data-maintenance-progress-bar]');
    if (bar) bar.style.width = `${progress}%`;
    Object.entries(session.counters || {}).forEach(([key, value]) => {
      const target = root.querySelector(`[data-counter="${CSS.escape(key)}"]`);
      if (target) target.textContent = number(value);
    });
    if (action?.message) {
      const log = root.querySelector('[data-maintenance-log]');
      if (log) {
        if (log.children.length === 1 && log.textContent.includes('Todavía no')) log.replaceChildren();
        const row = document.createElement('li');
        const at = document.createElement('span');
        const message = document.createElement('strong');
        const timing = document.createElement('small');
        at.textContent = new Date().toLocaleTimeString('es-CO');
        message.textContent = action.message;
        timing.textContent = `${number(duration)} ms`;
        row.append(at, message, timing);
        log.prepend(row);
        while (log.children.length > 10) log.lastElementChild?.remove();
      }
    }
    const previousSessionStatus = currentSessionStatus;
    const previousPhysicalStatus = currentPhysicalStatus;
    currentSessionStatus = session.status || '';
    currentPhysicalStatus = physical?.status || '';
    root.dataset.physicalStatus = currentPhysicalStatus;
    const physicalPanel = root.querySelector('[data-physical-recovery]');
    if (physicalPanel && physical) {
      const physicalLabels = {
        queued: 'Preparada para esta pestaña',
        running: 'Reconstrucción en curso',
        completed: 'Tabla reconstruida',
        not_required: 'Reconstrucción no necesaria',
        needs_review: 'Interrupción por revisar',
        failed: 'No se pudo reconstruir'
      };
      const title = physicalPanel.querySelector('strong');
      const message = physicalPanel.querySelector('span');
      if (title) title.textContent = physicalLabels[physical.status] || 'Estado por comprobar';
      if (message) message.textContent = physical.safe_message || '';
      physicalPanel.classList.toggle('is-error', ['failed', 'needs_review'].includes(physical.status));
    }
    active = activeSessionStates.has(currentSessionStatus)
      || activePhysicalStates.has(currentPhysicalStatus);
    const logicalJustEnded = activeSessionStates.has(previousSessionStatus)
      && !activeSessionStates.has(currentSessionStatus);
    const physicalJustEnded = activePhysicalStates.has(previousPhysicalStatus)
      && !activePhysicalStates.has(currentPhysicalStatus);
    if (logicalJustEnded || physicalJustEnded) window.location.reload();
  }

  function nextDelay() {
    const normal = document.hidden ? 10000 : 3000;
    return Math.min(30000, normal * Math.max(1, 2 ** consecutiveFailures));
  }

  function schedule() {
    if (!active || inFlight) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(refreshStatus, nextDelay());
  }

  async function refreshStatus() {
    if (!active || inFlight || !statusUrl) return;
    inFlight = true;
    try {
      if (!document.hidden && stepUrl && csrf && activeSessionStates.has(currentSessionStatus)) {
        const body = new URLSearchParams();
        body.set('_token', csrf);
        body.set('session_id', String(sessionId));
        body.set('control_token', ownerToken);
        const stepResponse = await fetch(stepUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body,
          cache: 'no-store'
        });
        const stepPayload = await stepResponse.json().catch(() => ({}));
        if (stepResponse.ok && stepPayload.ok && stepPayload.status) {
          const latest = Array.isArray(stepPayload.status.recent_steps) ? stepPayload.status.recent_steps[0] : null;
          update(
            stepPayload.status.session,
            latest ? { message: latest.safe_message || 'Lote registrado.' } : { message: 'Estado actualizado.' },
            latest?.duration_ms || 0,
            stepPayload.status.physical_recovery || null
          );
          consecutiveFailures = 0;
          schedule();
          return;
        }
      }
      const response = await fetch(statusUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'No se pudo actualizar el estado.');
      }
      const latest = Array.isArray(payload.recent_steps) ? payload.recent_steps[0] : null;
      const signature = latest
        ? `${latest.completed_at || latest.started_at || ''}:${latest.safe_message || ''}`
        : '';
      update(
        payload.session,
        latest && signature !== lastStepSignature ? { message: latest.safe_message } : null,
        latest?.duration_ms || 0,
        payload.physical_recovery || null
      );
      consecutiveFailures = 0;
      if (signature) lastStepSignature = signature;
    } catch (error) {
      consecutiveFailures = Math.min(4, consecutiveFailures + 1);
      const live = root.querySelector('[data-maintenance-live]');
      if (live) {
        live.textContent = 'La conexión se interrumpió. Se volverá a intentar sin repetir ningún lote.';
        live.classList.add('is-error');
      }
    } finally {
      inFlight = false;
      schedule();
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (active && !document.hidden) schedule();
  });
  window.addEventListener('pagehide', () => {
    active = false;
    window.clearTimeout(timer);
  }, { once: true });
  schedule();
})();

(() => {
  const root = document.querySelector('[data-backup-monitor]');
  if (!root) return;
  const statusUrl = root.dataset.statusUrl || '';
  const stepUrl = root.dataset.stepUrl || '';
  const csrf = root.dataset.csrf || '';
  let activeId = Number(root.dataset.activeId || 0);
  let activeStatus = root.dataset.activeStatus || '';
  if (!statusUrl || !activeId) return;

  const labels = {
    prepared: 'Preparada para continuar',
    queued: 'Lista para avanzar en esta pestaña',
    creating: 'Creando copia',
    verifying: 'Verificando copia',
    ready_pending_release: 'Liberando protección',
    ready: 'Lista y verificada',
    failed: 'Necesita revisión',
    cancel_requested: 'Cancelación solicitada',
    deleting: 'Limpiando restos'
  };
  const number = (value) => new Intl.NumberFormat('es-CO').format(Number(value || 0));
  const percent = (value) => `${new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(value || 0))} %`;
  let timer = 0;
  let inFlight = false;
  let stepping = false;
  let failures = 0;
  let stopped = false;
  let lastProgress = null;

  function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return 'Calculando';
    const value = Math.max(0, Math.floor(Number(seconds)));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const remaining = value % 60;
    if (hours > 0) return `${hours} h ${String(minutes).padStart(2, '0')} min`;
    if (minutes > 0) return `${minutes} min ${String(remaining).padStart(2, '0')} s`;
    return `${remaining} s`;
  }

  function formatEta(min, max) {
    if (min === null || min === undefined || max === null || max === undefined) return 'Calculando';
    return `${formatDuration(min)} – ${formatDuration(max)}`;
  }

  function delay() {
    const base = document.hidden
      ? 15000
      : (['creating', 'verifying', 'ready_pending_release', 'cancel_requested', 'deleting'].includes(activeStatus) ? 2000 : 5000);
    return Math.min(60000, base * Math.max(1, 2 ** failures));
  }

  function schedule() {
    if (stopped || inFlight) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, delay());
  }

  function mergeProgress(next) {
    if (!next || typeof next !== 'object') return lastProgress || {};
    const merged = { ...(lastProgress || {}) };
    Object.entries(next).forEach(([key, value]) => {
      const isEmpty = value === null || value === undefined || value === '';
      if (isEmpty && merged[key] !== undefined) return;
      if (['tables_total', 'tables_completed', 'rows_processed', 'chunks_completed'].includes(key)
        && Number(value || 0) === 0
        && Number(merged[key] || 0) > 0) {
        return;
      }
      merged[key] = value;
    });
    lastProgress = merged;
    return merged;
  }

  function setText(selector, text) {
    const node = root.querySelector(selector);
    if (node) node.textContent = text;
  }

  function renderActivity(items) {
    const list = root.querySelector('[data-backup-activity]');
    if (!list || !Array.isArray(items)) return;
    list.replaceChildren(...items.slice(0, 5).map((item) => {
      const li = document.createElement('li');
      const time = document.createElement('time');
      time.textContent = item?.time || '';
      const label = document.createElement('strong');
      label.textContent = item?.label || 'Avance';
      const detail = document.createElement('span');
      detail.textContent = item?.detail || '';
      li.append(time, label, detail);
      return li;
    }));
  }

  function renderProgress(progressData) {
    const progressInfo = mergeProgress(progressData);
    const value = Math.max(0, Math.min(100, Number(progressInfo.percent || 0)));
    setText('[data-backup-percent]', percent(value));
    setText('[data-backup-progress]', `${number(progressInfo.tables_completed)} de ${number(progressInfo.tables_total)} tablas`);
    setText('[data-backup-tables]', `${number(progressInfo.tables_completed)} / ${number(progressInfo.tables_total)}`);
    setText('[data-backup-rows]', number(progressInfo.rows_processed));
    setText('[data-backup-chunks]', number(progressInfo.chunks_completed));
    setText('[data-backup-elapsed]', formatDuration(progressInfo.elapsed_seconds));
    setText('[data-backup-eta]', formatEta(progressInfo.eta_seconds_min, progressInfo.eta_seconds_max));
    setText('[data-backup-now]', progressInfo.now || (progressInfo.current_table ? `Respaldando ${progressInfo.current_table}` : 'Preparando primera tabla'));
    setText('[data-backup-next]', progressInfo.next || 'Continuar con el siguiente micro-lote local.');
    setText('[data-backup-current-table]', progressInfo.current_table ? `Conjunto actual: ${progressInfo.current_table}` : 'Se abrirá el primer checkpoint aprobado.');
    setText('[data-backup-heartbeat]', progressInfo.last_advance_at ? `Último avance: ${progressInfo.last_advance_at}` : 'Esta pestaña todavía no confirmó un lote.');
    const bar = root.querySelector('[data-backup-progressbar]');
    const fill = root.querySelector('[data-backup-progressfill]');
    if (bar) bar.setAttribute('aria-valuenow', String(value));
    if (fill) fill.style.transform = `scaleX(${value / 100})`;
    if (progressInfo.status_label) setText('[data-backup-state]', progressInfo.status_label);
    if (progressInfo.message) setText('[data-backup-runtime]', progressInfo.message);
    renderActivity(progressInfo.activity);
  }

  function update(payload) {
    const active = payload?.active || null;
    if (!active || Number(active.id || 0) !== activeId) {
      window.location.reload();
      stopped = true;
      return;
    }
    activeStatus = active.status || activeStatus;
    const archive = Array.isArray(payload.archives)
      ? payload.archives.find((item) => Number(item.id || 0) === activeId)
      : null;
    const progressData = payload?.progress || active?.progress || archive?.progress || null;
    setText('[data-backup-state]', progressData?.status_label || labels[activeStatus] || activeStatus || 'Por comprobar');
    setText('[data-backup-runtime]', progressData?.message || payload?.runtime?.reason || 'Estado actualizado.');
    renderProgress(progressData || archive?.job || {});
    if (['ready', 'failed', 'cancelled', 'deleted'].includes(activeStatus)) {
      window.location.reload();
      stopped = true;
    }
  }

  async function processOneStep() {
    if (!stepUrl || !csrf || stepping || stopped) return false;
    if (!['prepared', 'queued', 'creating', 'verifying', 'ready_pending_release', 'cancel_requested', 'deleting'].includes(activeStatus)) {
      return false;
    }
    stepping = true;
    root.classList.add('is-working');
    setText('[data-backup-runtime]', 'Procesando lote local…');
    try {
      const body = new URLSearchParams();
      body.set('_token', csrf);
      body.set('backup_id', String(activeId));
      const response = await fetch(stepUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
        cache: 'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        const error = new Error(payload.message || 'No se pudo avanzar este lote.');
        error.payload = payload;
        throw error;
      }
      update(payload.overview || payload);
      return true;
    } catch (error) {
      const payload = error && typeof error === 'object' ? error.payload : null;
      const detail = payload && typeof payload === 'object'
        ? [payload.message || '', payload.action || ''].filter(Boolean).join(' ')
        : '';
      setText(
        '[data-backup-runtime]',
        detail || 'No se pudo avanzar este lote. Revise el estado de la copia, cancele una solicitud vacía o limpie restos si corresponde.'
      );
      return false;
    } finally {
      stepping = false;
      root.classList.remove('is-working');
    }
  }

  async function refresh() {
    if (stopped || inFlight) return;
    inFlight = true;
    try {
      const advanced = !document.hidden && await processOneStep();
      if (!advanced) {
        const response = await fetch(statusUrl, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store'
        });
        if (!response.ok) throw new Error('status_unavailable');
        update(await response.json());
      }
      failures = 0;
    } catch (_) {
      failures = Math.min(4, failures + 1);
      const runtime = root.querySelector('[data-backup-runtime]');
      if (runtime) {
        runtime.textContent = 'La conexión se interrumpió. El monitor volverá a intentarlo sin repetir trabajo.';
      }
    } finally {
      inFlight = false;
      schedule();
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      setText('[data-backup-runtime]', 'Pausado por pestaña en segundo plano.');
      root.classList.remove('is-working');
    } else {
      schedule();
    }
  });
  window.addEventListener('pagehide', () => {
    stopped = true;
    window.clearTimeout(timer);
  }, { once: true });
  window.setTimeout(refresh, 100);
})();
