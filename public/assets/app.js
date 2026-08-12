(() => {
  const applyShellSnapshot = (payload, form, link, companies, accounts, selectedCompany, selectedAccount) => {
    const context = payload.context || {};
    const status = payload.status || {};
    if (companies && context.ok) {
      companies.replaceChildren(new Option('Todas las empresas', '0'));
      (context.companies || []).forEach((row) => companies.add(new Option(row.name, String(row.id), false, Number(row.id) === selectedCompany)));
    }
    if (accounts && context.ok) {
      accounts.replaceChildren(new Option('Todas las cuentas', '0'));
      (context.accounts || []).forEach((row) => accounts.add(new Option(row.name, String(row.id), false, Number(row.id) === selectedAccount)));
    }
    if (!context.ok) {
      if (companies) companies.replaceChildren(new Option('Empresas no disponibles', String(selectedCompany)));
      if (accounts) accounts.replaceChildren(new Option('Cuentas no disponibles', String(selectedAccount)));
    }
    const unread = link?.querySelector('[data-shell-unread]');
    if (unread && status.ok && Number(status.unread || 0) > 0) {
      unread.textContent = String(status.unread);
      unread.hidden = false;
    }
    const alert = document.querySelector('[data-shell-api-alert]');
    if (alert && status.ok && status.api_alert) {
      alert.classList.add(`is-${status.api_alert.level || 'warning'}`);
      alert.querySelector('[data-shell-api-title]').textContent = status.api_alert.title || 'Salud API requiere revisión';
      alert.querySelector('[data-shell-api-message]').textContent = status.api_alert.message || '';
      alert.hidden = false;
    }
  };

  const loadShellSnapshot = async () => {
    const form = document.querySelector('[data-shell-snapshot-url]');
    if (!form || !window.fetch) return;
    const link = document.querySelector('[data-shell-notification]');
    const companies = form.querySelector('[data-shell-companies]');
    const accounts = form.querySelector('[data-shell-accounts]');
    const selectedCompany = Number(form.dataset.selectedCompany || 0);
    const selectedAccount = Number(form.dataset.selectedAccount || 0);
    try {
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 7000);
      const response = await fetch(form.dataset.shellSnapshotUrl, {
        credentials: 'same-origin',
        headers: {Accept: 'application/json'},
        cache: 'no-store',
        signal: controller.signal
      });
      window.clearTimeout(timeout);
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error('shell_unavailable');
      applyShellSnapshot(payload, form, link, companies, accounts, selectedCompany, selectedAccount);
    } catch (_) {
      if (companies) companies.replaceChildren(new Option('Empresas no disponibles', String(selectedCompany)));
      if (accounts) accounts.replaceChildren(new Option('Cuentas no disponibles', String(selectedAccount)));
    }
  };

  loadShellSnapshot();

  const previewForm = document.querySelector('[data-manual-preview-form]');
  if (previewForm) {
    previewForm.addEventListener('submit', () => {
      const button = previewForm.querySelector('[data-manual-preview-submit]');
      if (!button || button.disabled) return;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      const ready = button.querySelector('[data-ready-label]');
      const busy = button.querySelector('[data-busy-label]');
      if (ready) ready.hidden = true;
      if (busy) busy.hidden = false;
    });
  }
  if (window.location.hash === '#resultado-calculo') {
    const result = document.getElementById('resultado-calculo');
    if (result) {
      window.requestAnimationFrame(() => {
        result.focus({ preventScroll: true });
        result.scrollIntoView({
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
          block: 'start'
        });
      });
    }
  }
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const closeSidebar = () => { sidebar?.classList.remove('open'); overlay?.classList.remove('open'); };
  const openSidebar = () => { sidebar?.classList.add('open'); overlay?.classList.add('open'); };
  document.getElementById('menuToggle')?.addEventListener('click', () => sidebar?.classList.contains('open') ? closeSidebar() : openSidebar());
  overlay?.addEventListener('click', closeSidebar);
  document.addEventListener('click', (event) => {
    if (window.innerWidth <= 820 && sidebar?.classList.contains('open') && !sidebar.contains(event.target) && !event.target.closest('#menuToggle')) closeSidebar();
  });
  window.addEventListener('resize', () => { if (window.innerWidth > 820) closeSidebar(); });

  const storageKey = 'erpMeliOpenNavSection';
  const sections = Array.from(document.querySelectorAll('[data-nav-section]'));
  const openSection = (targetKey, persist = true) => {
    sections.forEach((section) => {
      const key = section.dataset.navSection;
      const button = section.querySelector('[data-nav-toggle]');
      const items = section.querySelector('.nav-section-items');
      const isOpen = key === targetKey;
      section.classList.toggle('open', isOpen);
      button?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (items) items.hidden = !isOpen;
    });
    if (persist && targetKey) {
      try { window.localStorage.setItem(storageKey, targetKey); } catch (_) {}
    }
  };
  const closeSection = (targetKey, persist = true) => {
    const section = sections.find((item) => item.dataset.navSection === targetKey);
    if (!section) return;
    const button = section.querySelector('[data-nav-toggle]');
    const items = section.querySelector('.nav-section-items');
    section.classList.remove('open');
    button?.setAttribute('aria-expanded', 'false');
    if (items) items.hidden = true;
    if (persist) {
      try { window.localStorage.removeItem(storageKey); } catch (_) {}
    }
  };
  const activeSection = sections.find((section) => section.querySelector('.nav-item.active'));
  if (activeSection) {
    openSection(activeSection.dataset.navSection, false);
  } else {
    try {
      const stored = window.localStorage.getItem(storageKey);
      if (stored && sections.some((section) => section.dataset.navSection === stored)) openSection(stored, false);
    } catch (_) {
      // La navegación sigue funcionando aunque el almacenamiento del navegador esté bloqueado.
    }
  }
  document.querySelectorAll('[data-nav-toggle]').forEach((button) => button.addEventListener('click', () => {
    const key = button.dataset.navToggle;
    const isOpen = button.getAttribute('aria-expanded') === 'true';
    if (isOpen) closeSection(key);
    else openSection(key);
  }));

  // Delegado para cubrir también formularios insertados por las secciones
  // progresivas. Un formulario se confirma una sola vez, al enviarse.
  document.addEventListener('submit', (event) => {
    const element = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
    if (element && !window.confirm(element.dataset.confirm || '¿Desea continuar?')) event.preventDefault();
  });
  document.addEventListener('click', (event) => {
    const element = event.target instanceof Element ? event.target.closest('[data-confirm]:not(form)') : null;
    if (!element || event.target?.closest?.('select,input,textarea,option,label')) return;
    if (!window.confirm(element.dataset.confirm || '¿Desea continuar?')) event.preventDefault();
  });
  document.querySelectorAll('[data-copy-text]').forEach((button) => {
    button.addEventListener('click', async () => {
      const text = button.dataset.copyText || button.closest('.copy-link-row')?.querySelector('[data-copy-source]')?.value || '';
      const original = button.textContent;
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const input = button.closest('.copy-link-row')?.querySelector('[data-copy-source]');
          input?.select();
          document.execCommand('copy');
        }
        button.textContent = 'Copiado';
        window.setTimeout(() => { button.textContent = original; }, 1800);
      } catch (_) {
        const input = button.closest('.copy-link-row')?.querySelector('[data-copy-source]');
        input?.focus();
        input?.select();
        button.textContent = 'Seleccione y copie';
        window.setTimeout(() => { button.textContent = original; }, 2200);
      }
    });
  });
  document.querySelectorAll('[data-context-select]').forEach((select) => select.addEventListener('change', () => select.form.submit()));
  const updateScheduleControls = (form) => {
    const mode = form.querySelector('[name="schedule_mode"]')?.value || '';
    const delay = form.querySelector('[name="schedule_delay_minutes"]');
    const at = form.querySelector('[name="schedule_at"]');
    if (delay) delay.style.display = mode === 'delay' ? '' : 'none';
    if (at) {
      at.style.display = mode === 'custom' ? '' : 'none';
      at.required = mode === 'custom';
    }
  };
  Array.from(document.querySelectorAll('[name="schedule_mode"]')).map((field) => field.form).filter(Boolean).forEach((form) => {
    updateScheduleControls(form);
    form.querySelector('[name="schedule_mode"]')?.addEventListener('change', () => updateScheduleControls(form));
  });

  const refreshMonthMonitor = async (monitor) => {
    const url = monitor.dataset.statusUrl;
    if (!url) return;
    try {
      const res = await fetch(url, {headers: {'Accept': 'application/json'}});
      if (!res.ok) return;
      const data = await res.json();
      const percent = Number(data.percent || 0);
      const bar = monitor.querySelector('[data-sync-progress-bar]');
      if (bar) bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
      setText(monitor, '[data-sync-progress-text]', `${percent.toFixed(1)}%`);
      setText(monitor, '[data-sync-complete]', `${data.chunks_complete || 0} / ${data.chunks_total || 0}`);
      setText(monitor, '[data-sync-processed]', `${data.processed || 0} / ${data.estimated || 0}`);
      setText(monitor, '[data-sync-running]', `${data.chunks_running || 0}`);
      setText(monitor, '[data-sync-errors]', `${data.chunks_error || 0}`);
      setText(monitor, '[data-sync-updated]', `Actualizado ${new Date().toLocaleTimeString()}`);
    } catch (_) {}
  };
  document.querySelectorAll('[data-sync-monitor]').forEach((monitor) => {
    monitor.addEventListener('sync:refresh', () => refreshMonthMonitor(monitor));
  });

  const renderUpcoming = (monitor, items) => {
    const body = monitor.querySelector('[data-schedule-upcoming]');
    if (!body) return;
    const top = (items || []).slice(0, 3);
    body.innerHTML = top.length
      ? top.map((item) => `<tr><td>${escapeHtml(item.account_name || '')}</td><td>${escapeHtml(String(item.date_from || '').slice(0,10))} a ${escapeHtml(String(item.date_to || '').slice(0,10))}</td><td>${escapeHtml(item.next_run_at_local || item.next_run_at || 'Ahora')}</td><td>${escapeHtml(item.status || '')}</td></tr>`).join('')
      : '<tr><td><div class="empty">No hay bloques próximos.</div></td></tr>';
  };
  const renderOverdue = (monitor, items) => {
    const body = monitor.querySelector('[data-schedule-overdue-list]');
    if (!body) return;
    const rows = items || [];
    body.innerHTML = rows.length
      ? rows.map((item) => `<tr><td>${escapeHtml(item.account_name || '')}</td><td>${escapeHtml(String(item.date_from || '').slice(0,10))} a ${escapeHtml(String(item.date_to || '').slice(0,10))}</td><td>${escapeHtml(item.next_run_at_local || item.next_run_at || 'Ahora')}</td><td><span class="badge amber">${escapeHtml(item.status || '')}</span></td></tr>`).join('')
      : '<tr><td><div class="empty">No hay bloques atrasados.</div></td></tr>';
  };
  const refreshScheduleMonitor = async (monitor) => {
    const url = monitor.dataset.scheduleUrl;
    if (!url) return;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const res = await fetch(url, {headers: {'Accept': 'application/json'}, signal: controller.signal});
      if (!res.ok) return;
      const data = await res.json();
      const status = data.status || {};
      const has = (key) => Object.prototype.hasOwnProperty.call(status, key);
      if (has('percent')) {
        const percent = Number(status.percent || 0);
        const bar = monitor.querySelector('[data-schedule-progress-bar]');
        if (bar) bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
        setText(monitor, '[data-schedule-progress-text]', `${percent.toFixed(1)}%`);
      }
      if (has('chunks_complete') && has('chunks_total')) setText(monitor, '[data-schedule-complete]', `${status.chunks_complete} / ${status.chunks_total}`);
      if (has('processed') && has('estimated')) setText(monitor, '[data-schedule-processed]', `${status.processed} / ${status.estimated}`);
      if (has('chunks_running')) setText(monitor, '[data-schedule-running]', `${status.chunks_running}`);
      if (has('chunks_overdue')) setText(monitor, '[data-schedule-overdue]', `${status.chunks_overdue}`);
      if (has('chunks_error')) setText(monitor, '[data-schedule-errors]', `${status.chunks_error}`);
      if (Array.isArray(data.overdue)) renderOverdue(monitor, data.overdue);
      if (Array.isArray(data.items) || Array.isArray(status.upcoming)) renderUpcoming(monitor, data.items || status.upcoming);
    } catch (_) {
      // El siguiente ciclo vuelve a intentar; una lectura colgada nunca detiene el monitor.
    } finally {
      window.clearTimeout(timeout);
    }
  };
  const initScheduleMonitors = (root = document) => {
    root.querySelectorAll('[data-sync-schedule-monitor]').forEach((monitor) => {
      if (monitor.dataset.monitorInitialized === '1') return;
      monitor.dataset.monitorInitialized = '1';
      monitor.addEventListener('sync:refresh', () => refreshScheduleMonitor(monitor));
      const loop = async () => {
        if (!monitor.isConnected) return;
        if (!document.hidden) await refreshScheduleMonitor(monitor);
        const requested = Number(monitor.dataset.refresh || 20);
        const delay = document.hidden ? 30000 : Math.max(10000, Math.min(60000, requested * 1000));
        window.setTimeout(loop, delay);
      };
      loop();
    });
  };
  initScheduleMonitors();
  document.addEventListener('erp:content-loaded', (event) => initScheduleMonitors(event.detail?.root || document));

  // Los formularios de cola se envían de forma normal y solo crean trabajo CLI.

    document.querySelectorAll('[data-cron-test-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('[data-cron-test-button]');
      const status = form.querySelector('[data-cron-test-status]');
      const started = Date.now();
      const modal = form.dataset.processOverlay === '0' ? null : showProcessOverlay('Comprobando PHP, base de datos, storage y archivo del job...', started, 'Comprobando instalación...');
      const abortController = new AbortController();
      const timeout = window.setTimeout(() => abortController.abort(), 10000);
      if (button) button.disabled = true;
      if (status) status.textContent = 'Comprobando instalación... 0s';
      const ticker = window.setInterval(() => {
        const seconds = Math.floor((Date.now() - started) / 1000);
        if (status) status.textContent = `Comprobando instalación... ${seconds}s`;
        if (modal) updateProcessOverlay(modal, 'Esta prueba no procesa la cola ni confirma el cron automático.', seconds);
      }, 1000);
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: {'Accept': 'application/json'},
          signal: abortController.signal
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) throw new Error(data.message || 'No se pudo comprobar la instalación.');
        const message = data.message || 'Prueba rápida completada.';
        if (status) status.textContent = message;
        if (modal) finishProcessOverlay(modal, message, true);
        const health = document.querySelector('[data-cron-health]');
        const statusUrl = health?.dataset.statusUrl || '';
        if (statusUrl) {
          const healthResponse = await fetch(statusUrl, {headers: {'Accept': 'application/json'}});
          const healthData = await healthResponse.json().catch(() => ({}));
          if (healthResponse.ok && healthData.cron) {
            const label = health.querySelector('[data-cron-health-label]');
            const detail = health.querySelector('[data-cron-health-message]');
            if (label) label.textContent = healthData.cron.label || 'Sin información';
            if (detail) detail.textContent = healthData.cron.message || '';
          }
        }
        window.setTimeout(() => window.location.reload(), 1200);
      } catch (error) {
        const message = error?.name === 'AbortError'
          ? 'La comprobación superó 10 segundos. Revise la conexión y vuelva a intentarlo.'
          : (error.message || 'Error comprobando la instalación.');
        if (status) status.textContent = message;
        if (modal) finishProcessOverlay(modal, message, false);
      } finally {
        window.clearTimeout(timeout);
        window.clearInterval(ticker);
        if (button) button.disabled = false;
      }
    });
  });

  // El modo asistido web fue retirado; no existen bucles de procesamiento en el navegador.

    const refreshFinancialMonitor = async (monitor) => {
    const url = monitor.dataset.financialStatusUrl;
    if (!url) return;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const res = await fetch(url, {headers: {'Accept': 'application/json'}, signal: controller.signal});
      if (!res.ok) return;
      const data = await res.json();
      const summary = data.summary || {};
      const has = (key) => Object.prototype.hasOwnProperty.call(summary, key);
      if (has('percent')) {
        const percent = Number(summary.percent || 0);
        const bar = monitor.querySelector('[data-financial-progress-bar]');
        if (bar) bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
        setText(monitor, '[data-financial-progress-text]', `${percent.toFixed(1)}%`);
      }
      if (has('pending_jobs')) setText(monitor, '[data-financial-pending]', `${summary.pending_jobs}`);
      if (has('running_jobs')) setText(monitor, '[data-financial-running]', `${summary.running_jobs}`);
      if (has('complete_items') && has('total_items')) setText(monitor, '[data-financial-processed]', `${summary.complete_items} / ${summary.total_items}`);
      if (has('active_items')) setText(monitor, '[data-financial-active]', `${summary.active_items}`);
      if (has('failed_items')) setText(monitor, '[data-financial-errors]', `${summary.failed_items}`);
    } catch (_) {
      // El siguiente ciclo vuelve a intentar sin borrar el último progreso válido.
    } finally {
      window.clearTimeout(timeout);
    }
  };
  const initFinancialMonitors = (root = document) => {
    root.querySelectorAll('[data-financial-recalc-monitor]').forEach((monitor) => {
      if (monitor.dataset.financialMonitorInitialized === '1') return;
      monitor.dataset.financialMonitorInitialized = '1';
      monitor.addEventListener('financial:refresh', () => refreshFinancialMonitor(monitor));
      const loop = async () => {
        if (!monitor.isConnected) return;
        if (!document.hidden) await refreshFinancialMonitor(monitor);
        window.setTimeout(loop, document.hidden ? 30000 : 20000);
      };
      loop();
    });
  };
  initFinancialMonitors();
  document.addEventListener('erp:content-loaded', (event) => initFinancialMonitors(event.detail?.root || document));

  // Los recálculos financieros se crean como trabajos y los ejecuta exclusivamente CLI.

    function setText(root, selector, value) {
    const el = root.querySelector(selector);
    if (el) el.textContent = value;
  }
  document.querySelectorAll('[data-column-target]').forEach((target) => {
    const form = target.closest('form');
    if (!form) return;
    const syncColumns = () => {
      const values = Array.from(form.querySelectorAll('[data-column-choice]:checked')).map((input) => input.value);
      target.value = values.join(',');
    };
    form.querySelectorAll('[data-column-choice]').forEach((input) => input.addEventListener('change', syncColumns));
    form.addEventListener('submit', syncColumns);
    syncColumns();
  });
  document.querySelectorAll('[data-catalog-gallery]').forEach((gallery) => {
    const media = gallery.closest('.catalog-product-media');
    const mainImage = media?.querySelector('[data-catalog-main-image]');
    if (!mainImage) return;
    gallery.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) return;
      const button = event.target.closest('[data-catalog-gallery-image]');
      if (!button || !gallery.contains(button)) return;
      const imageUrl = button.dataset.catalogGalleryImage || '';
      if (!imageUrl) return;
      mainImage.src = imageUrl;
      mainImage.dataset.catalogCurrentImage = imageUrl;
      gallery.querySelectorAll('[data-catalog-gallery-image]').forEach((thumb) => {
        const selected = thumb === button;
        thumb.classList.toggle('active', selected);
        thumb.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
    });
  });
  document.querySelectorAll('[data-catalog-lightbox-open]').forEach((button) => {
    const media = button.closest('.catalog-product-media');
    const mainImage = media?.querySelector('[data-catalog-main-image]');
    if (!mainImage) return;
    button.addEventListener('click', () => {
      const thumbs = Array.from(media.querySelectorAll('[data-catalog-gallery-image]'));
      const images = thumbs.map((thumb) => thumb.dataset.catalogGalleryImage || '').filter(Boolean);
      const current = mainImage.dataset.catalogCurrentImage || mainImage.currentSrc || mainImage.src || images[0] || '';
      const uniqueImages = images.length ? Array.from(new Set(images)) : [current].filter(Boolean);
      const start = Math.max(0, uniqueImages.indexOf(current));
      openCatalogLightbox(uniqueImages, start, button);
    });
  });
  function openCatalogLightbox(images, startIndex, opener) {
    if (!images.length) return;
    let index = Math.max(0, Math.min(images.length - 1, startIndex || 0));
    const overlay = document.createElement('div');
    overlay.className = 'catalog-lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = `<div class="catalog-lightbox-inner"><button type="button" class="catalog-lightbox-close" aria-label="Cerrar imagen ampliada">×</button><button type="button" class="catalog-lightbox-nav catalog-lightbox-prev" aria-label="Imagen anterior">‹</button><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Imagen ampliada del producto"><button type="button" class="catalog-lightbox-nav catalog-lightbox-next" aria-label="Imagen siguiente">›</button><div class="catalog-lightbox-count"></div></div>`;
    const image = overlay.querySelector('img');
    const count = overlay.querySelector('.catalog-lightbox-count');
    const prev = overlay.querySelector('.catalog-lightbox-prev');
    const next = overlay.querySelector('.catalog-lightbox-next');
    const close = overlay.querySelector('.catalog-lightbox-close');
    const render = () => {
      if (image) image.src = images[index];
      if (count) count.textContent = images.length > 1 ? `${index + 1} / ${images.length}` : '';
      if (prev) prev.hidden = images.length < 2;
      if (next) next.hidden = images.length < 2;
    };
    const closeLightbox = () => {
      document.removeEventListener('keydown', onKey);
      document.body.classList.remove('catalog-lightbox-open');
      overlay.remove();
      opener?.focus?.();
    };
    const move = (direction) => {
      index = (index + direction + images.length) % images.length;
      render();
    };
    const onKey = (event) => {
      if (event.key === 'Escape') closeLightbox();
      if (event.key === 'ArrowLeft' && images.length > 1) move(-1);
      if (event.key === 'ArrowRight' && images.length > 1) move(1);
    };
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeLightbox();
    });
    close?.addEventListener('click', closeLightbox);
    prev?.addEventListener('click', () => move(-1));
    next?.addEventListener('click', () => move(1));
    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
    document.body.classList.add('catalog-lightbox-open');
    render();
    close?.focus?.();
  }
  function showProcessOverlay(message, started, title = 'Comprobando...') {
    const overlay = document.createElement('div');
    overlay.className = 'process-overlay';
    overlay.innerHTML = `<div class="process-modal" role="status" aria-live="polite"><div class="process-spinner"></div><h2>${escapeHtml(title)}</h2><p data-process-message>${escapeHtml(message)}</p><div class="process-bar"><span></span></div><p class="muted">Tiempo transcurrido: <strong data-process-seconds>0s</strong></p></div>`;
    document.body.appendChild(overlay);
    document.body.classList.add('processing-sync');
    updateProcessOverlay(overlay, message, Math.floor((Date.now() - started) / 1000));
    return overlay;
  }
  function updateProcessOverlay(overlay, message, seconds) {
    const msg = overlay.querySelector('[data-process-message]');
    const sec = overlay.querySelector('[data-process-seconds]');
    if (msg) msg.textContent = message;
    if (sec) sec.textContent = `${seconds}s`;
  }
  function finishProcessOverlay(overlay, message, ok) {
    overlay.classList.toggle('success', ok);
    overlay.classList.toggle('error', !ok);
    const spinner = overlay.querySelector('.process-spinner');
    if (spinner) spinner.textContent = ok ? '✓' : '!';
    updateProcessOverlay(overlay, message, Number((overlay.querySelector('[data-process-seconds]')?.textContent || '0').replace(/\D/g, '')) || 0);
    window.setTimeout(() => {
      overlay.remove();
      document.body.classList.remove('processing-sync');
    }, ok ? 1600 : 2600);
  }
  function formatDuration(seconds) {
    seconds = Math.max(0, Number(seconds || 0));
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return rest ? `${minutes}m ${rest}s` : `${minutes}m`;
  }
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  }
})();

(() => {
  const root = document.querySelector('[data-queue-v4-clean]');
  if (!root) return;
  const password = root.querySelector('[data-qv4-password]');
  const feedback = root.querySelector('[data-qv4-feedback]');
  let snapshot = null;
  const actions = [...root.querySelectorAll('[data-qv4-action]')];
  const labels = {
    NOT_READY: 'No preparado', READY_TO_TEST: 'Listo para comprobar', TESTING: 'Comprobando',
    CERTIFIED: 'Certificado', FAILED: 'Falló la comprobación'
  };
  const syncButtons = () => {
    const hasPassword = Boolean(password?.value);
    actions.forEach((form) => {
      const action = form.dataset.qv4Action;
      const button = form.querySelector('button');
      const enabled = action === 'readiness'
        ? snapshot?.state === 'READY_TO_TEST'
        : action === 'activate'
          ? snapshot?.state === 'CERTIFIED'
            && ['CERTIFIED', 'STOPPED'].includes(snapshot?.engine)
            && (snapshot?.issues || []).length === 0
          : snapshot?.engine === 'ACTIVE';
      if (button) button.disabled = !(hasPassword && enabled);
    });
  };
  const render = (data) => {
    snapshot = data;
    root.querySelector('[data-qv4-state]').textContent = labels[data.state] || data.state || 'No preparado';
    root.querySelector('[data-qv4-message]').textContent = (data.issues || []).length
      ? `Bloqueado: ${(data.issues || []).join(', ')}.`
      : 'La autoridad nueva está coherente y no depende del estado legacy.';
    root.querySelector('[data-qv4-engine]').textContent = data.engine || 'STOPPED';
    root.querySelector('[data-qv4-oauth]').textContent = `${Number(data.accounts_oauth || 0)}/3`;
    root.querySelector('[data-qv4-readiness]').textContent = `${Number(data.readiness_get_passed || 0)}/3`;
    root.querySelector('[data-qv4-scheduler]').textContent = data.scheduler === 'active' ? 'Activo' : 'Inactivo';
    Object.entries(data.queue || {}).forEach(([key, value]) => {
      const node = root.querySelector(`[data-qv4-count="${key}"]`);
      if (node) node.textContent = String(value);
    });
    root.querySelector('[data-qv4-legacy]').textContent = data.legacy_state_consulted ? 'Error: legado consultado' : 'Legado no consultado';
    syncButtons();
  };
  const refresh = async () => {
    try {
      const response = await fetch(root.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.message || 'Queue V4 no disponible.');
      render(data);
      feedback.textContent = 'Estado actualizado sin mutaciones.';
    } catch (error) {
      feedback.textContent = error?.message || 'No se pudo leer Queue V4.';
    }
  };
  password?.addEventListener('input', syncButtons);
  actions.forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button');
    if (!button || button.disabled) return;
    form.querySelector('[name="admin_password"]').value = password.value;
    const previous = button.textContent;
    button.disabled = true;
    button.textContent = 'Procesando…';
    try {
      const response = await fetch(form.action, {
        method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(form)
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok === false) throw new Error(data.message || 'Operación bloqueada.');
      password.value = '';
      feedback.textContent = data.state ? `Queue V4: ${data.state}.` : 'Operación completada.';
      await refresh();
    } catch (error) {
      feedback.textContent = error?.message || 'La operación se bloqueó sin cambiar el motor.';
    } finally {
      button.textContent = previous;
      syncButtons();
    }
  }));
  refresh();
})();

// Centro de Automatización 2.28.10: polling ligero, sin crear ni modificar trabajo.
(() => {
  const root = document.querySelector('[data-cron-control]');
  if (!root) return;
  const baseUrl = root.dataset.operationalUrl
    ? root.dataset.operationalUrl.replace(/\/settings\/cron\/operational-snapshot\.json.*$/, '')
    : '';
  let stopped = false;
  let overviewInFlight = false;
  let taskInFlight = false;
  let setupInFlight = false;
  let timer = null;
  let lastOverview = null;
  let lastTasks = [];
  let lastSetup = null;
  let overviewController = null;
  let operationalController = null;
  let taskController = null;
  let setupController = null;
  let canaryController = null;
  let runtimeController = null;
  let overviewFailures = 0;
  let operationalFailures = 0;
  let taskFailures = 0;
  let setupFailures = 0;
  let canaryFailures = 0;
  let runtimeFailures = 0;
  let canaryInFlight = false;
  let runtimeInFlight = false;
  let lastCanary = null;
  let lastRuntime = null;
  let operationalInFlight = false;

  const fetchJson = async (url, previousController) => {
    previousController?.abort();
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false || payload.snapshot_state === 'unavailable') {
        throw new Error(payload.message || 'unavailable');
      }
      return { controller, payload };
    } finally {
      window.clearTimeout(timeout);
    }
  };

  const append = (parent, tag, value, className) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = value;
    parent.appendChild(node);
    return node;
  };

  const mergeDefined = (previous, incoming) => {
    const merged = { ...(previous || {}) };
    Object.entries(incoming || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null) merged[key] = value;
    });
    return merged;
  };

  const isRuntimeOperational = (payload) => {
    const runtime = payload?.runtime || payload || {};
    return String(runtime.state || '') === 'operational'
      || String(payload?.state || '').startsWith('operational')
      || String(runtime.cutover?.state || '') === 'operational_active';
  };

  const syncLegacyV3PanelsForOperationalState = () => {
    const operationalMode = isRuntimeOperational(lastRuntime);
    root.querySelectorAll('[data-cron-v3-setup]').forEach((panel) => {
      panel.hidden = false;
      panel.setAttribute('aria-hidden', 'false');
    });
    root.querySelectorAll('[data-cron-v3-canary]').forEach((panel) => {
      panel.hidden = operationalMode;
      panel.setAttribute('aria-hidden', operationalMode ? 'true' : 'false');
    });
    root.querySelectorAll('[data-cron-v3-shadow-action]').forEach((form) => {
      form.hidden = operationalMode;
      form.setAttribute('aria-hidden', operationalMode ? 'true' : 'false');
    });
    return operationalMode;
  };

  const renderOverview = (payload) => {
    if (!payload || payload.ok === false) return;
    const snapshotState = payload.snapshot_state || 'complete';
    if (!['complete', 'partial'].includes(snapshotState)) return;
    if (snapshotState === 'complete') {
      lastOverview = { ...payload };
    } else {
      const previousWorkload = lastOverview?.workload || {};
      const partialPayload = { ...payload };
      if (payload.section_availability?.history === false) delete partialPayload.history;
      if (payload.section_availability?.selector === false) delete partialPayload.next;
      lastOverview = mergeDefined(lastOverview, partialPayload);
      lastOverview.workload = mergeDefined(previousWorkload, payload.workload || {});
    }
    const state = root.querySelector('[data-cron-state]');
    const signal = root.querySelector('[data-cron-signal]');
    if (state && lastOverview.state_label) state.textContent = lastOverview.state_label;
    if (signal && lastOverview.last_signal_label) signal.textContent = `Última señal: ${lastOverview.last_signal_label} hora Bogotá`;
    const workload = lastOverview.workload || {};
    const launcher = root.querySelector('[data-cron-launcher]');
    const remoteHour = root.querySelector('[data-cron-remote-hour]');
    const finalizedHour = root.querySelector('[data-cron-finalized-hour]');
    const backlog = root.querySelector('[data-cron-backlog]');
    const trend = root.querySelector('[data-cron-trend]');
    if (launcher && lastOverview.state_label) launcher.textContent = lastOverview.state_label;
    if (remoteHour && workload.remote_calls_last_hour !== undefined) remoteHour.textContent = workload.remote_calls_last_hour === null
      ? 'No se pudo medir'
      : `${new Intl.NumberFormat('es-CO').format(Number(workload.remote_calls_last_hour))} transportes iniciados`;
    if (finalizedHour && workload.finalized_last_hour !== undefined) finalizedHour.textContent = workload.finalized_last_hour === null
      ? 'No se pudo medir'
      : `${new Intl.NumberFormat('es-CO').format(Number(workload.finalized_last_hour))} recursos`;
    if (backlog && workload.pending !== undefined) backlog.textContent = `${new Intl.NumberFormat('es-CO').format(Number(workload.pending || 0))} pendientes`;
    if (trend && workload.trend_label) trend.textContent = workload.trend_label;

    const now = root.querySelector('[data-cron-now]');
    if (now) {
      now.replaceChildren();
      if (lastOverview.now) {
        append(now, 'strong', lastOverview.now.label || 'Trabajo en curso');
        append(now, 'p', `Inicio: ${lastOverview.now.started_label || 'por comprobar'} · Consulta remota: ${Number(lastOverview.now.remote_calls || 0) > 0 ? 'sí' : 'todavía no'}`);
      } else {
        append(now, 'strong', 'Esperando la próxima señal');
        append(now, 'p', 'No hay un trabajo con lease y heartbeat vigentes en este instante.');
      }
    }

    const last = root.querySelector('[data-cron-last]');
    if (last && lastOverview.last_run) {
      const run = lastOverview.last_run;
      last.replaceChildren();
      append(last, 'strong', `${run.selected || 0} funciones reclamadas · ${run.started || 0} funciones iniciadas`);
      append(last, 'p', `${run.completed || 0} recursos finalizados · ${run.deferred || 0} recursos aplazados`);
      append(last, 'p', `${run.attempted_remote_calls ?? run.remote_calls ?? 0} intentos HTTP · ${run.remote_calls || 0} transportes iniciados · ${run.blocked_remote_calls || 0} bloqueados antes de salir`);
      if (Number(run.not_started || 0) > 0) append(last, 'p', `${run.not_started} ${Number(run.not_started) === 1 ? 'siguiente función no cupo' : 'funciones planeadas no cupieron'} en la ventana segura.`, 'text-warning');
    }

    const next = root.querySelector('[data-cron-next]');
    if (next && Array.isArray(lastOverview.next)) {
      next.replaceChildren();
      (lastOverview.next.length ? lastOverview.next : [{ label: 'Por comprobar', next_label: 'Se actualizará sin crear trabajos.' }]).forEach((task) => {
        const li = document.createElement('li');
        append(li, 'strong', task.label || 'Trabajo');
        append(li, 'span', task.next_label || 'Siguiente ciclo');
        next.appendChild(li);
      });
    }

    const history = root.querySelector('[data-cron-history]');
    if (history && Array.isArray(lastOverview.history)) {
      history.replaceChildren();
      if (!lastOverview.history.length) {
        append(history, 'p', 'Todavía no hay ciclos cerrados para mostrar.', 'api-inline-empty');
      } else {
        lastOverview.history.forEach((run) => {
          const article = document.createElement('article');
          const heading = document.createElement('div');
          append(heading, 'strong', run.finished_label || 'Ciclo');
          append(heading, 'span', run.status || '');
          article.appendChild(heading);
          append(article, 'p', `${run.selected || 0} funciones reclamadas · ${run.started || 0} iniciadas · ${run.completed || 0} recursos finalizados`);
          append(article, 'p', `${run.remote_calls || 0} transportes HTTP iniciados · ${run.deferred || 0} aplazados · ${Number(Number(run.duration_ms || 0) / 1000).toLocaleString('es-CO', { maximumFractionDigits: 1 })} s`);
          history.appendChild(article);
        });
      }
    }

    renderCronV3(lastOverview.cron_v3);
    if (lastOverview.cron_v3_operational) {
      renderCapabilities(lastOverview.cron_v3_operational);
      renderRuntime({ runtime: lastOverview.cron_v3_operational.runtime });
    }
  };

  const renderOperationalSnapshot = (payload) => {
    if (!payload || payload.ok === false) return;

    const operationalMode = isRuntimeOperational(payload);
    if (payload.runtime) {
      lastRuntime = mergeDefined(lastRuntime, payload.runtime);
    }
    if (operationalMode) {
      lastRuntime = mergeDefined(lastRuntime, {
        state: 'operational',
        state_label: payload.runtime?.state_label || payload.state_label || 'V3 operativo',
        v3: payload.runtime?.v3 || payload.v3 || lastRuntime?.v3,
        cutover: payload.runtime?.cutover || lastRuntime?.cutover,
        v2: payload.runtime?.v2 || lastRuntime?.v2,
      });
    }
    syncLegacyV3PanelsForOperationalState();

    renderCronV3(payload.v3);
    renderRuntime({ runtime: payload.runtime });
    renderCapabilities(payload);
    renderRateLimitSignals(payload.api_rate_limit_signals);
    renderTasks({
      ok: true,
      snapshot_state: payload.snapshot_state || payload.protocol || 'partial',
      rows: Array.isArray(payload.queues) ? payload.queues : [],
    });

    const state = root.querySelector('[data-cron-state]');
    const signal = root.querySelector('[data-cron-signal]');
    const launcher = root.querySelector('[data-cron-launcher]');
    const launcherDetail = root.querySelector('[data-cron-launcher-detail]');
    const remoteHour = root.querySelector('[data-cron-remote-hour]');
    const finalizedHour = root.querySelector('[data-cron-finalized-hour]');
    const backlog = root.querySelector('[data-cron-backlog]');
    const trend = root.querySelector('[data-cron-trend]');
    const totals = payload.totals || {};
    if (state) state.textContent = payload.state_label || payload.runtime?.state_label || 'V3 por comprobar';
    const localSignal = payload.v3?.lanes?.local?.last_signal_label || payload.runtime?.v3?.lanes?.local?.last_signal_label;
    const remoteSignal = payload.v3?.lanes?.remote?.last_signal_label || payload.runtime?.v3?.lanes?.remote?.last_signal_label;
    if (signal) {
      signal.textContent = localSignal || remoteSignal
        ? `V3 local ${localSignal || 'sin señal'} · V3 remoto ${remoteSignal || 'sin señal'}`
        : (payload.state_message || 'Snapshot operativo V3 sin señal comprobable.');
    }
    if (launcher) launcher.textContent = payload.runtime?.state_label || payload.state_label || 'V3 por comprobar';
    if (launcherDetail) {
      launcherDetail.textContent = localSignal || remoteSignal
        ? `Señal V3 local ${localSignal || 'sin señal'} · remoto ${remoteSignal || 'sin señal'}`
        : 'Snapshot V3 disponible; señales en verificación.';
    }
    if (remoteHour) remoteHour.textContent = `${new Intl.NumberFormat('es-CO').format(Number(totals.v3_http_last_hour || 0))} transportes iniciados`;
    if (finalizedHour) finalizedHour.textContent = `${new Intl.NumberFormat('es-CO').format(Number(totals.v3_completed_last_hour || 0))} recursos`;
    if (backlog) backlog.textContent = `${new Intl.NumberFormat('es-CO').format(Number(totals.legacy_pending_visible || 0))} pendientes visibles`;
    if (trend) {
      trend.textContent = payload.drainage?.explanation
        || 'Drenaje V3 medido por snapshot operativo; legacy solo respalda backlog visible.';
    }

    const now = root.querySelector('[data-cron-human-now]');
    if (now) {
      now.textContent = payload.state_message || 'V3 es la fuente operativa principal.';
    }
    const updated = root.querySelector('[data-cron-overview-updated]');
    if (updated) {
      updated.textContent = `Snapshot V3 actualizado ${payload.measured_at || 'ahora'} UTC · lectura sin mutaciones`;
    }
  };

  const renderRateLimitSignals = (signals) => {
    const panel = root.querySelector('[data-cron-rate-limit-signals]');
    if (!panel) return;
    const rows = Array.isArray(signals) ? signals : [];
    const list = panel.querySelector('[data-cron-rate-limit-list]');
    const summary = panel.querySelector('[data-cron-rate-limit-summary]');
    panel.hidden = rows.length === 0;
    if (summary) {
      summary.textContent = rows.length
        ? `${rows.length} señal(es) 429 en las últimas 24 horas. Cron debe respetar Retry-After y mantener limitada la cuenta/endpoint afectado.`
        : 'Sin señales 429 recientes.';
    }
    if (!list) return;
    list.replaceChildren();
    rows.slice(0, 5).forEach((incident) => {
      const key = String(incident.incident_key || '');
      const link = document.createElement('a');
      link.href = `${baseUrl}/settings/api-health/incidents/show?key=${encodeURIComponent(key)}`;
      const title = append(link, 'strong', `${incident.account_names || 'Aplicación'} · ${incident.operation_label || 'Operación API'}`);
      title.setAttribute('data-kind', 'rate-limit');
      append(link, 'span', `${Number(incident.repetitions || 0).toLocaleString('es-CO')} repeticiones · activo ahora: ${incident.active_now ? 'Sí' : 'No'} · última vez: ${incident.last_seen_at || 'por comprobar'}`);
      append(link, 'b', 'Revisar protección');
      list.appendChild(link);
    });
  };

  const renderCapabilities = (payload) => {
    const panel = root.querySelector('[data-cron-v3-capabilities]');
    const rows = Array.isArray(payload?.capabilities) ? payload.capabilities : [];
    if (!panel) return;
    const state = panel.querySelector('[data-cron-v3-capability-state]');
    const waiting = rows.filter((row) => row.capability_state === 'waiting_capability').length;
    const unsupported = rows.filter((row) => row.capability_state === 'review_unsupported').length;
    if (state) {
      state.className = `status-badge ${waiting || unsupported ? 'is-warning' : 'is-success'}`;
      state.textContent = waiting || unsupported
        ? `${waiting + unsupported} brechas visibles`
        : 'Sin brechas';
    }
    const body = panel.querySelector('[data-cron-v3-capability-rows]');
    if (body) {
      body.replaceChildren();
      if (!rows.length) {
        const tr = document.createElement('tr');
        const td = append(tr, 'td', 'No se pudo comprobar la matriz V3.');
        td.colSpan = 5;
        body.appendChild(tr);
      } else {
        rows.forEach((row) => {
          const tr = document.createElement('tr');
          const name = append(tr, 'td', row.label || row.queue_key || 'Función');
          name.dataset.label = 'Función';
          const status = append(tr, 'td', '');
          status.dataset.label = 'Estado V3';
          const cssState = row.capability_state === 'v3_active' || row.capability_state === 'v3_local_only'
            ? 'ready'
            : (row.capability_state === 'review_unsupported' ? 'action_required' : 'waiting_capability');
          append(status, 'span', row.human_state || row.capability_state || 'Por comprobar', `status-badge is-${cssState}`);
          const lane = append(tr, 'td', row.lane || 'Por comprobar');
          lane.dataset.label = 'Carril';
          const types = append(tr, 'td', Array.isArray(row.work_types) ? row.work_types.join(', ') : 'Sin tipos');
          types.dataset.label = 'Tipos exactos';
          const reason = append(tr, 'td', row.reason || 'Sin causa publicada');
          reason.dataset.label = 'Causa';
          body.appendChild(tr);
        });
      }
    }
    const updated = panel.querySelector('[data-cron-v3-capability-updated]');
    if (updated) updated.textContent = `Matriz actualizada ${payload?.measured_at || 'ahora'} UTC · lectura sin mutaciones`;
  };

  const renderSetup = (payload) => {
    const panel = root.querySelector('[data-cron-v3-setup]');
    const setup = payload?.setup || payload;
    if (!panel || !setup) return;
    syncLegacyV3PanelsForOperationalState();
    lastSetup = mergeDefined(lastSetup, setup);
    const stateMap = {
      needs_safe_config: ['is-warning', 'Preparar config'],
      doctor_blocked: ['is-warning', 'Doctor pendiente'],
      ready_for_shadow: ['is-success', 'Listo para Shadow'],
      shadow_running: ['is-success', 'Shadow en marcha'],
      shadow_complete: ['is-success', 'Shadow aprobado'],
      canary_controlled: ['is-success', 'Canario controlado']
    };
    const [stateClass, stateLabel] = stateMap[lastSetup.state] || ['is-unavailable', 'Por comprobar'];
    const state = panel.querySelector('[data-cron-v3-setup-state]');
    if (state) {
      state.className = `status-badge ${stateClass}`;
      state.textContent = stateLabel;
    }
    const now = panel.querySelector('[data-cron-v3-setup-now]');
    if (now) {
      if (lastSetup.state === 'canary_controlled') {
        now.textContent = `Shadow aprobado · ${lastSetup.shadow?.cycles || 0}/60 ciclos. El canario real se controla en la tarjeta siguiente.`;
      } else if (lastSetup.blocking?.length) {
        now.textContent = lastSetup.blocking[0];
      } else if (lastSetup.shadow_enabled) {
        now.textContent = `Shadow V3 habilitado · ${lastSetup.shadow?.cycles || 0}/60 ciclos verificados.`;
      } else if (lastSetup.safe_config_applied) {
        now.textContent = 'Configuración segura aplicada. Puede activar Shadow cuando Doctor apruebe.';
      } else {
        now.textContent = 'Listo para preparar config.env seguro desde el ERP.';
      }
    }
    const steps = panel.querySelector('[data-cron-v3-setup-steps]');
    if (steps && Array.isArray(lastSetup.steps)) {
      steps.replaceChildren();
      lastSetup.steps.forEach((step) => {
        const li = document.createElement('li');
        append(li, 'strong', step.label || step.key || 'Paso');
        append(li, 'span', step.done ? 'Listo' : 'Pendiente');
        if (step.done) li.classList.add('is-complete');
        steps.appendChild(li);
      });
    }
    const commands = panel.querySelector('[data-cron-v3-commands]');
    if (commands && lastSetup.commands) {
      const labels = {
        v2_real: 'V2 real actual',
        v3_local_shadow: 'V3 local shadow',
        v3_remote_shadow: 'V3 remoto shadow'
      };
      commands.replaceChildren();
      Object.entries(labels).forEach(([key, label]) => {
        const article = document.createElement('article');
        append(article, 'span', label);
        append(article, 'code', lastSetup.commands[key] || 'No disponible');
        commands.appendChild(article);
      });
    }
    const updated = panel.querySelector('[data-cron-v3-setup-updated]');
    if (updated) {
      updated.textContent = lastSetup.state === 'canary_controlled'
        ? 'Asistente actualizado · el canario real se controla en la tarjeta siguiente'
        : 'Asistente actualizado · V3 real sigue apagado';
    }
    const retirement = lastSetup.retirement_preflight || {};
    const effectiveFlags = retirement.effective_flags || {};
    const sources = retirement.sources || {};
    Object.keys(retirement.required_state || {}).forEach((key) => {
      const value = panel.querySelector(`[data-cron-v3-retirement-flag="${key}"]`);
      if (!value) return;
      const effective = effectiveFlags[key];
      const label = effective === false ? 'Apagado' : (effective === true ? 'ACTIVO' : 'Inválido');
      value.textContent = `${label} · ${sources[key] || 'desconocido'}`;
      value.className = effective === false ? 'is-success' : 'is-danger';
    });
    const conflicts = Array.isArray(retirement.process_override_conflicts)
      ? retirement.process_override_conflicts
      : [];
    const retirementState = panel.querySelector('[data-cron-v3-retirement-state]');
    if (retirementState) {
      retirementState.textContent = retirement.ok
        ? 'Configuración efectiva segura: 4/4 flags apagados y sin overrides contradictorios.'
        : `Bloqueado: ${conflicts.length ? conflicts.join(', ') : 'uno o más flags no están apagados'}.`;
      retirementState.className = retirement.ok ? 'notice success' : 'notice error';
    }
    const retirementAction = lastSetup.retirement_action || {};
    const actionPanel = panel.querySelector('[data-cron-v3-retirement-action]');
    const actionReason = panel.querySelector('[data-cron-v3-retirement-reason]');
    const retirementForm = panel.querySelector('[data-cron-v3-retirement-form]');
    const receipt = panel.querySelector('[data-cron-v3-retirement-receipt]');
    const reasonLabels = {
      ready: 'Listo: versión, schema, flags, Queue Engine y ownership cumplen el contrato.',
      v3_retirement_already_completed: 'V3 ya fue retirado. La operación no puede repetirse.',
      v3_retirement_app_version_invalid: 'La versión instalada no corresponde al hotfix 2.36.5.',
      v3_retirement_schema_invalid: 'El schema no es exactamente 293.',
      v3_retirement_engine_not_idle: 'Queue Engine debe permanecer disabled/idle.',
      v3_retirement_process_override_conflict: 'Existe un override de proceso contradictorio.',
      v3_retirement_effective_flags_invalid: 'Los cuatro flags efectivos deben estar apagados.',
      v3_retirement_foreign_ownership: 'Existe ownership habilitado fuera de V3.',
      v3_retirement_no_active_ownership: 'No existe ownership V3 activo para retirar.'
    };
    if (actionPanel) {
      actionPanel.className = `${actionPanel.className.replace(/\b(success|warning|error)\b/g, '').trim()} ${retirementAction.ok ? 'success' : 'warning'}`;
    }
    if (actionReason) {
      actionReason.textContent = reasonLabels[retirementAction.reason]
        || `Bloqueado: ${retirementAction.reason || 'preflight no disponible'}.`;
    }
    if (retirementForm) {
      retirementForm.dataset.preflightOk = retirementAction.ok ? '1' : '0';
      retirementForm.dataset.confirmationPhrase = retirementAction.required_confirmation_phrase || '';
      const phrase = retirementForm.querySelector('[name="confirmation_phrase"]');
      const password = retirementForm.querySelector('[name="admin_password"]');
      const button = retirementForm.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = !(retirementAction.ok
          && phrase?.value.trim() === retirementForm.dataset.confirmationPhrase
          && Boolean(password?.value));
      }
    }
    if (receipt && retirementAction.receipt) {
      receipt.hidden = false;
      receipt.textContent = JSON.stringify(retirementAction.receipt, null, 2);
    }
    const v4 = lastSetup.v4_readiness_bootstrap || {};
    const v4Panel = panel.querySelector('[data-v4-readiness-action]');
    const v4Reason = panel.querySelector('[data-v4-readiness-reason]');
    const v4Form = panel.querySelector('[data-v4-readiness-form]');
    const v4Receipt = panel.querySelector('[data-v4-readiness-receipt]');
    if (v4Panel) {
      v4Panel.className = `${v4Panel.className.replace(/\b(success|warning|error)\b/g, '').trim()} ${v4.state === 'certified' ? 'success' : (v4.ok ? 'warning' : 'error')}`;
    }
    if (v4Reason) {
      v4Reason.textContent = v4.state === 'certified'
        ? 'Readiness V4 certificado. Queue Engine sigue disabled y no existe scheduler.'
        : (v4.ok
          ? `Estado: ${v4.state || 'listo'}. Siguiente paso manual: continuar la acción acotada.`
          : `Bloqueado: ${v4.reason || 'preflight no disponible'}.`);
    }
    if (v4Form) {
      v4Form.dataset.preflightOk = v4.ok ? '1' : '0';
      const button = v4Form.querySelector('button[type="submit"]');
      if (button) button.hidden = v4.state === 'certified';
    }
    if (v4Receipt && v4.certified_receipt) {
      v4Receipt.hidden = false;
      v4Receipt.textContent = JSON.stringify(v4.certified_receipt, null, 2);
    }
    panel.querySelectorAll('[data-cron-v3-setup-action]').forEach((form) => {
      const action = form.action || '';
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;
      if (action.includes('/prepare-safe-config')) {
        button.disabled = Boolean(lastSetup.safe_config_applied);
      }
      if (action.includes('/enable-shadow')) {
        button.disabled = Boolean(lastSetup.shadow?.complete || lastSetup.active_enabled || lastSetup.blocking?.length);
      }
    });
    if (lastOverview?.cron_v3) renderCronV3(lastOverview.cron_v3);
  };

  const renderCanary = (payload) => {
    const panel = root.querySelector('[data-cron-v3-canary]');
    const canary = payload?.canary || payload;
    if (!panel || !canary) return;
    if (syncLegacyV3PanelsForOperationalState()) return;
    lastCanary = mergeDefined(lastCanary, canary);
    const stateMap = {
      blocked: ['is-warning', 'Bloqueado'],
      ready_for_prepare: ['is-success', 'Listo para preparar'],
      ready_for_local: ['is-success', 'Listo para local'],
      ready_for_remote: ['is-success', 'Listo para remoto'],
      remote_canary_running: ['is-success', 'Canario remoto activo']
    };
    const [stateClass, stateLabel] = stateMap[lastCanary.state] || ['is-unavailable', 'Por comprobar'];
    const state = panel.querySelector('[data-cron-v3-canary-state]');
    if (state) {
      state.className = `status-badge ${stateClass}`;
      const healthyRemote = lastCanary.state === 'remote_canary_running'
        && lastCanary.canary_health?.state === 'healthy'
        && Number(lastCanary.metrics?.http_calls || 0) > 0
        && Number(lastCanary.metrics?.resources_finalized || 0) > 0;
      state.textContent = healthyRemote ? 'Canario remoto activo · sano' : stateLabel;
    }
    const now = panel.querySelector('[data-cron-v3-canary-now]');
    if (now) {
      if (lastCanary.blocking?.length) {
        now.textContent = lastCanary.blocking[0];
      } else if (lastCanary.state === 'remote_canary_running') {
        const httpCalls = Number(lastCanary.metrics?.http_calls || 0).toLocaleString('es-CO');
        const finalized = Number(lastCanary.metrics?.resources_finalized || 0).toLocaleString('es-CO');
        const cutover = lastCanary.certified_cutover;
        const cutoverLabel = cutover?.state === 'certified_active'
          ? 'Familias certificadas ya pasaron a V3'
          : (cutover?.state === 'ready_to_cutover' ? 'Cron aplicará el corte certificado automáticamente' : 'V3 completo sigue bloqueado');
        now.textContent = `Canario remoto sano · ${httpCalls} HTTP reales · ${finalized} recursos finalizados. ${cutoverLabel}.`;
      } else if (lastCanary.state === 'ready_for_remote') {
        now.textContent = 'Canario local habilitado. Puede pasar al remoto cuando lo decida.';
      } else if (lastCanary.state === 'ready_for_local') {
        now.textContent = 'Canario preparado. Cambie el comando local V3 en Hostinger cuando lo indique esta tarjeta.';
      } else {
        const shadowLabel = lastCanary.shadow?.approved ? 'Shadow aprobado por evidencia' : `Shadow pendiente · ${lastCanary.shadow?.cycles || 0}/60 ciclos`;
        now.textContent = `${shadowLabel}. V3 real sigue apagado hasta preparar.`;
      }
    }
    const steps = panel.querySelector('[data-cron-v3-canary-steps]');
    if (steps && Array.isArray(lastCanary.steps)) {
      steps.replaceChildren();
      lastCanary.steps.forEach((step) => {
        const li = document.createElement('li');
        append(li, 'strong', step.label || step.key || 'Paso');
        append(li, 'span', step.done ? 'Listo' : 'Pendiente');
        if (step.done) li.classList.add('is-complete');
        steps.appendChild(li);
      });
    }
    const metrics = lastCanary.metrics || {};
    const cycles = panel.querySelector('[data-canary-metric="cycles"]');
    if (cycles) cycles.textContent = `${Number(metrics.cycles?.local || 0).toLocaleString('es-CO')} local · ${Number(metrics.cycles?.remote || 0).toLocaleString('es-CO')} remoto`;
    const http = panel.querySelector('[data-canary-metric="http_calls"]');
    if (http) http.textContent = Number(metrics.http_calls || 0).toLocaleString('es-CO');
    const finalized = panel.querySelector('[data-canary-metric="resources_finalized"]');
    if (finalized) finalized.textContent = Number(metrics.resources_finalized || 0).toLocaleString('es-CO');
    const safety = panel.querySelector('[data-canary-metric="safety"]');
    if (safety) safety.textContent = `${Number(metrics.errors || 0).toLocaleString('es-CO')} / ${Number(metrics.rate_429 || 0).toLocaleString('es-CO')} / ${Number(metrics.lease_lost || 0).toLocaleString('es-CO')}`;
    const commands = panel.querySelector('[data-cron-v3-canary-commands]');
    if (commands && lastCanary.commands) {
      const labels = {
        v2_real: 'V2 real actual',
        v3_local_active: 'V3 local activo',
        v3_remote_active: 'V3 remoto activo'
      };
      commands.replaceChildren();
      Object.entries(labels).forEach(([key, label]) => {
        const article = document.createElement('article');
        append(article, 'span', label);
        append(article, 'code', lastCanary.commands[key] || 'No disponible');
        commands.appendChild(article);
      });
    }
    const updated = panel.querySelector('[data-cron-v3-canary-updated]');
    if (updated) {
      const cutover = lastCanary.certified_cutover;
      updated.textContent = cutover?.state === 'certified_active'
        ? 'Canario actualizado · V3 drena familias certificadas; V3 completo sigue bloqueado'
        : (lastCanary.state === 'remote_canary_running'
          ? 'Canario actualizado · corte certificado pendiente/automático; V3 completo sigue bloqueado'
          : 'Canario actualizado · V3 completo sigue bloqueado');
    }
    panel.querySelectorAll('[data-cron-v3-canary-action]').forEach((form) => {
      const action = form.action || '';
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;
      const blocked = Boolean(lastCanary.blocking?.length);
      let enabled = false;
      if (action.endsWith('/prepare')) {
        enabled = !blocked && lastCanary.state === 'ready_for_prepare';
      } else if (action.endsWith('/enable-local')) {
        enabled = !blocked && lastCanary.state === 'ready_for_local';
      } else if (action.endsWith('/enable-remote')) {
        enabled = !blocked && lastCanary.state === 'ready_for_remote';
      } else if (action.endsWith('/rollback')) {
        enabled = Boolean(lastCanary.active_enabled || lastCanary.ownership?.financial_recalc?.enabled || lastCanary.ownership?.pack_exact?.enabled || lastCanary.ownership?.shipment_exact?.enabled);
      }
      button.disabled = !enabled;
      button.setAttribute('aria-disabled', String(!enabled));
      form.classList.toggle('is-current-action', enabled && !action.endsWith('/rollback'));
    });
  };

  const renderRuntime = (payload) => {
    const panel = root.querySelector('[data-cron-v3-runtime]');
    const runtime = payload?.runtime || payload;
    if (!panel || !runtime) return;
    lastRuntime = mergeDefined(lastRuntime, runtime);
    syncLegacyV3PanelsForOperationalState();
    const stateMap = {
      operational: ['is-success', 'V3 operativo'],
      hostinger_still_v2: ['is-warning', 'Hostinger todavía llama V2'],
      v3_no_signal: ['is-warning', 'Hostinger no está llamando V3'],
      cutover_incomplete: ['is-warning', 'Corte V3 incompleto'],
      blocked: ['is-danger', 'V3 bloqueado'],
      not_operational: ['is-unavailable', 'V3 no operativo']
    };
    const [stateClass, stateLabel] = stateMap[lastRuntime.state] || ['is-unavailable', lastRuntime.state_label || 'Por comprobar'];
    const state = panel.querySelector('[data-cron-v3-runtime-state]');
    if (state) {
      state.className = `status-badge ${stateClass}`;
      state.textContent = lastRuntime.state_label || stateLabel;
    }
    const now = panel.querySelector('[data-cron-v3-runtime-now]');
    if (now) {
      if (Array.isArray(lastRuntime.blocking) && lastRuntime.blocking.length) {
        now.textContent = lastRuntime.blocking[0];
      } else if (lastRuntime.state === 'operational') {
        now.textContent = 'V3 está operativo. V2 queda apagado por corte y responderá skip si Hostinger lo llama.';
      } else if (lastRuntime.state === 'hostinger_still_v2') {
        now.textContent = 'V2 todavía recibe señal. El ERP lo bloquea con skip, pero conviene pausar esa tarea en Hostinger.';
      } else if (lastRuntime.state === 'v3_no_signal') {
        now.textContent = 'No hay señal fresca de V3. Revise que las dos tareas V3 activas estén en Hostinger.';
      } else {
        now.textContent = lastRuntime.state_label || 'Revisando corte V3.';
      }
    }
    const next = panel.querySelector('[data-cron-v3-runtime-next]');
    if (next) {
      next.textContent = lastRuntime.state === 'operational'
        ? 'Seguir drenando con V3 local/remoto; V2 no procesa.'
        : 'Mostrar causa exacta y comandos de Hostinger.';
    }
    const hostinger = panel.querySelector('[data-cron-v3-runtime-hostinger]');
    if (hostinger) {
      hostinger.textContent = lastRuntime.state === 'operational'
        ? 'Debe conservar solo las dos tareas V3 activas.'
        : 'Use los comandos V3 activos mostrados abajo.';
    }
    const lanes = lastRuntime.v3?.lanes || {};
    const setMetric = (key, value) => {
      const node = panel.querySelector(`[data-runtime-metric="${key}"]`);
      if (node) node.textContent = value;
    };
    setMetric('local_signal', lanes.local?.last_signal_label || 'Sin señal');
    setMetric('remote_signal', lanes.remote?.last_signal_label || 'Sin señal');
    setMetric('v2_signal', lastRuntime.v2?.fresh ? 'Llamado reciente · debe saltarse' : (lastRuntime.v2?.label || 'Sin señal'));
    const missing = Array.isArray(lastRuntime.cutover?.missing_transferable) ? lastRuntime.cutover.missing_transferable.length : 0;
    setMetric('ownership', lastRuntime.cutover?.state === 'operational_active'
      ? 'Transferido/bloqueado explícitamente'
      : `${missing} pendientes de ownership`);
    const commands = panel.querySelector('[data-cron-v3-runtime-commands]');
    if (commands && lastRuntime.commands) {
      const labels = {
        remove_v2: 'Eliminar o pausar V2',
        v3_local_active: 'V3 local activo',
        v3_remote_active: 'V3 remoto activo'
      };
      commands.replaceChildren();
      Object.entries(labels).forEach(([key, label]) => {
        const article = document.createElement('article');
        append(article, 'span', label);
        append(article, 'code', lastRuntime.commands[key] || 'No disponible');
        commands.appendChild(article);
      });
    }
    const updated = panel.querySelector('[data-cron-v3-runtime-updated]');
    if (updated) {
      updated.textContent = `Corte actualizado ${lastRuntime.observed_at || 'ahora'} UTC · lectura sin mutaciones`;
    }
  };

  const renderCronV3 = (snapshot) => {
    const panel = root.querySelector('[data-cron-v3]');
    if (!panel || !snapshot) return;
    const allowedStates = ['healthy', 'attention', 'stale', 'disabled', 'unavailable'];
    const stateName = allowedStates.includes(snapshot.state) ? snapshot.state : 'unavailable';
    const state = panel.querySelector('[data-cron-v3-state]');
    if (state) {
      state.className = `status-badge is-${stateName}`;
      state.textContent = snapshot.state_label || 'Cron V3 no disponible';
    }
    const message = panel.querySelector('[data-cron-v3-message]');
    if (message) message.textContent = snapshot.state_message || 'No se pudo verificar Cron V3.';
    ['local', 'remote'].forEach((lane) => {
      const row = panel.querySelector(`[data-cron-v3-lane="${lane}"]`);
      const values = snapshot.lanes?.[lane] || {};
      const setupLane = lastSetup?.shadow?.lanes?.[lane];
      if (!row) return;
      row.querySelectorAll('[data-cron-v3-metric]').forEach((cell) => {
        const key = cell.dataset.cronV3Metric;
        const value = values[key] ?? (key === 'last_signal_label' && setupLane ? `${setupLane.mode || 'shadow'} · ciclo ${setupLane.generation || 0}` : undefined);
        if (['oldest_age_label', 'last_signal_label'].includes(key)) {
          cell.textContent = value || (key === 'last_signal_label' ? 'Sin señal' : 'Sin trabajo activo');
        } else {
          cell.textContent = new Intl.NumberFormat('es-CO').format(Number(value || 0));
        }
      });
    });
    const updated = panel.querySelector('[data-cron-v3-updated]');
    if (updated) updated.textContent = `Actualizado ${snapshot.observed_at || 'ahora'} UTC · lectura sin mutaciones`;
  };

  const renderTasks = (payload) => {
    const incoming = Array.isArray(payload?.rows) ? payload.rows : [];
    const snapshotState = payload?.snapshot_state || 'complete';
    if (snapshotState === 'authoritative_empty' || (snapshotState === 'complete' && incoming.length === 0)) {
      lastTasks = [];
    } else if (snapshotState === 'complete') {
      lastTasks = incoming;
    } else if (snapshotState === 'partial' && incoming.length) {
      const byKey = new Map(lastTasks.map((row) => [String(row.key || ''), row]));
      incoming.forEach((row) => {
        const key = String(row.key || '');
        byKey.set(key, mergeDefined(byKey.get(key), row));
      });
      lastTasks = Array.from(byKey.values());
    }
    const body = root.querySelector('[data-cron-tasks]');
    if (!body) return;
    body.replaceChildren();
    if (!lastTasks.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 7;
      td.textContent = snapshotState === 'authoritative_empty' || snapshotState === 'complete'
        ? 'Todas las colas se comprobaron y no hay trabajo pendiente.'
        : 'El listado no se pudo comprobar. Se reintentará sin crear tareas.';
      tr.appendChild(td);
      body.appendChild(tr);
      return;
    }
    lastTasks.forEach((task) => {
      const tr = document.createElement('tr');
      const name = document.createElement('td');
      name.dataset.label = 'Función';
      if (task.url) {
        const link = document.createElement('a');
        link.href = task.url;
        link.textContent = task.label || task.key || 'Trabajo';
        name.appendChild(link);
      } else {
        append(name, 'strong', task.label || task.key || 'Trabajo');
      }
      if (task.detail_message) append(name, 'small', task.detail_message);
      if (task.batch_summary_label) append(name, 'small', task.batch_summary_label, 'cron-batch-summary');
      if (task.v3_human_state) append(name, 'small', `V3: ${task.v3_human_state}`, 'cron-batch-summary');
      if (task.v3_reason && !['v3_active', 'v3_local_only'].includes(task.capability_state || '')) {
        append(name, 'small', task.v3_reason, 'text-warning');
      }
      tr.appendChild(name);
      const pending = append(tr, 'td', task.pending_label || `${new Intl.NumberFormat('es-CO').format(Number(task.pending || 0))} recursos por atender`);
      pending.dataset.label = 'Pendientes';
      if (task.observed_label) append(pending, 'small', `Medido: ${task.observed_label} hora Bogotá`);
      const finalized = append(tr, 'td', task.finalized_last_hour === null || task.finalized_last_hour === undefined
        ? 'No se pudo medir'
        : new Intl.NumberFormat('es-CO').format(Number(task.finalized_last_hour)));
      finalized.dataset.label = 'Finalizados/h';
      const remote = append(tr, 'td', task.remote
        ? (task.remote_calls_last_hour === null || task.remote_calls_last_hour === undefined
          ? 'No se pudo medir'
          : new Intl.NumberFormat('es-CO').format(Number(task.remote_calls_last_hour)))
        : 'Trabajo local');
      remote.dataset.label = 'Salidas HTTP/h';
      const state = append(tr, 'td', '');
      state.dataset.label = 'Estado';
      append(state, 'span', task.state_label || 'Por comprobar', `status-badge is-${task.state || 'unknown'}`);
      if (Number(task.attention_count || 0) > 0) {
        append(state, 'small', `${new Intl.NumberFormat('es-CO').format(Number(task.attention_count))} requieren revisión; los demás continúan`);
      }
      const next = append(tr, 'td', task.next_label || 'Por comprobar');
      next.dataset.label = 'Próxima oportunidad';
      if (task.last_action_label) append(next, 'small', `Última acción útil: ${task.last_action_label}`);
      const eta = append(tr, 'td', task.eta_label || 'Todavía no se puede estimar');
      eta.dataset.label = 'ETA';
      body.appendChild(tr);
    });
  };

  const fetchOverview = async () => {
    if (overviewInFlight || stopped) return;
    overviewInFlight = true;
    try {
      const result = await fetchJson(root.dataset.overviewUrl, overviewController);
      overviewController = result.controller;
      renderOverview(result.payload);
      overviewFailures = 0;
      const updated = root.querySelector('[data-cron-overview-updated]');
      if (updated) updated.textContent = 'Resumen actualizado ahora · lectura sin mutaciones';
    } catch (_) {
      overviewFailures = Math.min(4, overviewFailures + 1);
      const updated = root.querySelector('[data-cron-overview-updated]');
      if (updated) updated.textContent = 'No se pudo actualizar. Se conserva el último estado visible.';
    } finally {
      overviewInFlight = false;
    }
  };

  const fetchOperational = async () => {
    if (operationalInFlight || stopped || !root.dataset.operationalUrl) return false;
    operationalInFlight = true;
    try {
      const result = await fetchJson(root.dataset.operationalUrl, operationalController);
      operationalController = result.controller;
      renderOperationalSnapshot(result.payload);
      operationalFailures = 0;
      return true;
    } catch (_) {
      operationalFailures = Math.min(4, operationalFailures + 1);
      const updated = root.querySelector('[data-cron-overview-updated]');
      if (updated) updated.textContent = 'No se pudo leer la verdad operativa V3. Se conserva el último estado visible.';
      return false;
    } finally {
      operationalInFlight = false;
    }
  };

  const fetchTasks = async () => {
    if (taskInFlight || stopped) return;
    taskInFlight = true;
    try {
      const result = await fetchJson(root.dataset.tasksUrl, taskController);
      taskController = result.controller;
      renderTasks(result.payload);
      taskFailures = 0;
      const updated = root.querySelector('[data-cron-tasks-updated]');
      if (updated) updated.textContent = 'Actualizado ahora · no se crearon tareas';
    } catch (_) {
      taskFailures = Math.min(4, taskFailures + 1);
      const updated = root.querySelector('[data-cron-tasks-updated]');
      if (updated) updated.textContent = 'El listado no respondió. Reintentaremos automáticamente.';
    } finally {
      taskInFlight = false;
    }
  };

  const fetchSetup = async () => {
    if (setupInFlight || stopped || !root.dataset.v3SetupUrl) return;
    setupInFlight = true;
    try {
      const result = await fetchJson(root.dataset.v3SetupUrl, setupController);
      setupController = result.controller;
      renderSetup(result.payload);
      setupFailures = 0;
    } catch (_) {
      setupFailures = Math.min(4, setupFailures + 1);
      const updated = root.querySelector('[data-cron-v3-setup-updated]');
      if (updated) updated.textContent = 'El asistente no respondió. No se modificó nada.';
    } finally {
      setupInFlight = false;
    }
  };

  const fetchCanary = async () => {
    if (canaryInFlight || stopped || !root.dataset.v3CanaryUrl) return;
    canaryInFlight = true;
    try {
      const result = await fetchJson(root.dataset.v3CanaryUrl, canaryController);
      canaryController = result.controller;
      renderCanary(result.payload);
      canaryFailures = 0;
    } catch (_) {
      canaryFailures = Math.min(4, canaryFailures + 1);
      const updated = root.querySelector('[data-cron-v3-canary-updated]');
      if (updated) updated.textContent = 'El canario no respondió. No se modificó nada.';
    } finally {
      canaryInFlight = false;
    }
  };

  const fetchRuntime = async () => {
    if (runtimeInFlight || stopped || !root.dataset.v3RuntimeUrl) return;
    runtimeInFlight = true;
    try {
      const result = await fetchJson(root.dataset.v3RuntimeUrl, runtimeController);
      runtimeController = result.controller;
      renderRuntime(result.payload);
      runtimeFailures = 0;
    } catch (_) {
      runtimeFailures = Math.min(4, runtimeFailures + 1);
      const updated = root.querySelector('[data-cron-v3-runtime-updated]');
      if (updated) updated.textContent = 'El corte operativo no respondió. No se modificó nada.';
    } finally {
      runtimeInFlight = false;
    }
  };

  root.querySelectorAll('[data-cron-v3-setup-action]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const previous = button?.textContent || '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Aplicando…';
      }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: new FormData(form)
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.message || 'No se pudo aplicar.');
        await fetchSetup();
      } catch (error) {
        const updated = root.querySelector('[data-cron-v3-setup-updated]');
        if (updated) updated.textContent = error?.message || 'No se pudo aplicar. No se activó V3 real.';
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = previous;
        }
      }
    });
  });

  root.querySelectorAll('[data-cron-v3-retirement-form]').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    const phrase = form.querySelector('[name="confirmation_phrase"]');
    const password = form.querySelector('[name="admin_password"]');
    const syncButton = () => {
      if (!button) return;
      button.disabled = !(form.dataset.preflightOk === '1'
        && phrase?.value.trim() === (form.dataset.confirmationPhrase || '')
        && Boolean(password?.value));
    };
    phrase?.addEventListener('input', syncButton);
    password?.addEventListener('input', syncButton);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      syncButton();
      if (button?.disabled) return;
      const previous = button?.textContent || '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Retirando autoridad V3…';
      }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: new FormData(form)
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.message || 'No se pudo retirar V3.');
        password.value = '';
        const receipt = root.querySelector('[data-cron-v3-retirement-receipt]');
        if (receipt && payload.receipt) {
          receipt.hidden = false;
          receipt.textContent = JSON.stringify(payload.receipt, null, 2);
        }
        await fetchSetup();
      } catch (error) {
        const reason = root.querySelector('[data-cron-v3-retirement-reason]');
        if (reason) reason.textContent = error?.message || 'No se pudo retirar V3. No se modificó nada.';
      } finally {
        if (button) button.textContent = previous;
        syncButton();
      }
    });
  });

  root.querySelectorAll('[data-v4-readiness-form]').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    const password = form.querySelector('[name="admin_password"]');
    const syncButton = () => {
      if (!button) return;
      button.disabled = !(form.dataset.preflightOk === '1'
        && Boolean(password?.value));
    };
    password?.addEventListener('input', syncButton);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      syncButton();
      if (button?.disabled) return;
      const previous = button?.textContent || '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Ejecutando una etapa…';
      }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: new FormData(form)
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.message || 'No se pudo avanzar readiness V4.');
        password.value = '';
        const receipt = root.querySelector('[data-v4-readiness-receipt]');
        if (receipt) {
          receipt.hidden = false;
          receipt.textContent = JSON.stringify(payload.receipt || payload, null, 2);
        }
        await fetchSetup();
      } catch (error) {
        const reason = root.querySelector('[data-v4-readiness-reason]');
        if (reason) reason.textContent = error?.message || 'Readiness V4 bloqueado; se intentó rollback fail-closed.';
      } finally {
        if (button) button.textContent = previous;
        syncButton();
      }
    });
  });

  root.querySelectorAll('[data-cron-v3-canary-action]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const previous = button?.textContent || '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Aplicando…';
      }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: new FormData(form)
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.message || 'No se pudo aplicar.');
        await fetchSetup();
        await fetchCanary();
      } catch (error) {
        const updated = root.querySelector('[data-cron-v3-canary-updated]');
        if (updated) updated.textContent = error?.message || 'No se pudo aplicar. V3 completo no se activó.';
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = previous;
        }
      }
    });
  });

  const tick = async () => {
    const operationalOk = await fetchOperational();
    // Legacy fallback remains sequential when the V3 snapshot is unavailable:
    /* await fetchOverview();
    await fetchTasks(); */
    // await fetchOverview();
    //     await fetchTasks();
    if (!operationalOk) {
      await fetchOverview();
      await fetchTasks();
    }
    const operationalMode = isRuntimeOperational(lastRuntime);
    if (!operationalMode) {
      await fetchSetup();
      await fetchCanary();
    }
    await fetchRuntime();
    clearTimeout(timer);
    const normalDelay = document.hidden ? 30000 : 10000;
    const failures = Math.max(operationalFailures, overviewFailures, taskFailures, setupFailures, canaryFailures, runtimeFailures);
    timer = window.setTimeout(tick, Math.min(60000, normalDelay * Math.max(1, 2 ** failures)));
  };
  document.addEventListener('visibilitychange', () => {
    clearTimeout(timer);
    timer = window.setTimeout(tick, document.hidden ? 30000 : 250);
  });
  window.addEventListener('pagehide', () => {
    stopped = true;
    clearTimeout(timer);
    overviewController?.abort();
    operationalController?.abort();
    taskController?.abort();
    setupController?.abort();
    canaryController?.abort();
    runtimeController?.abort();
  }, { once: true });
  tick();
})();

// Configuración de ritmo: confirma en lenguaje humano el techo elegido.
(() => {
  const root = document.querySelector('[data-rhythm-editor]');
  if (!root) return;
  const cards = Array.from(root.querySelectorAll('.rhythm-profile'));
  const savedProfile = root.dataset.savedProfile || 'fast';
  const refresh = () => {
    const input = root.querySelector('input[name="profile"]:checked');
    const selected = input?.value || 'fast';
    const card = input?.closest('.rhythm-profile');
    const target = Number(card?.dataset.rhythmTarget || 30);
    const ramp = card?.dataset.rhythmRamp || '15 → 20 → 25 → 30';
    const label = card?.querySelector('strong')?.textContent?.trim() || 'Recuperación agresiva';
    cards.forEach((card) => card.classList.toggle('is-selected', card.querySelector('input')?.checked === true));
    const setText = (selector, value) => {
      const node = root.querySelector(selector);
      if (node) node.textContent = value;
    };
    setText('[data-rhythm-preview-profile]', label);
    setText('[data-rhythm-preview-target]', `${target} HTTP/min`);
    setText('[data-rhythm-preview-ramp]', ramp);
    setText('[data-rhythm-preview-note]', selected === savedProfile
      ? 'Configuración actualmente guardada.'
      : `Se aplicará al guardar. Techo teórico: ${(target * 60).toLocaleString('es-CO')} HTTP/h; el ritmo real puede ser menor.`);
  };
  root.addEventListener('change', refresh);
  refresh();
})();

(() => {
  document.querySelectorAll('.manual-choice-grid').forEach((grid) => {
    const refresh = () => {
      grid.querySelectorAll('.manual-choice-card').forEach((card) => {
        card.classList.toggle('is-selected', Boolean(card.querySelector('input:checked')));
      });
    };
    grid.addEventListener('change', refresh);
    refresh();
  });
})();

(() => {
  document.querySelectorAll('[data-manual-delay]').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('form');
      const input = form?.querySelector('[name="interval_seconds"]');
      if (!input) return;
      input.value = button.dataset.manualDelay || '0';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      form.querySelectorAll('[data-manual-delay]').forEach((item) => item.classList.toggle('is-selected', item === button));
    });
  });
})();

// Monitores manuales heredados retirados en 2.26.0.

// El ejecutor web heredado fue retirado en 2.26.0. Las campañas solo se
// configuran y supervisan desde el navegador; el trabajo pertenece al lanzador CLI.

// Monitor de campañas dirigido: solo lectura. El navegador nunca ejecuta trabajos.
(() => {
  const root = document.querySelector('[data-manual-controller="directed-cli-v1"]');
  if (!root) return;
  const terminal = new Set(['completed', 'completed_with_issues', 'failed']);
  const labels = {
    processing: 'Procesando',
    ready: 'Listo para continuar',
    waiting_interval: 'Esperando intervalo',
    waiting_selection: 'Esperando selección de Cron',
    waiting_launcher: 'Esperando automatización',
    pausing: 'Pausando después del actual',
    paused: 'Campaña pausada',
    returning: 'Devolviendo pendientes',
    completed: 'Campaña completada',
    completed_with_issues: 'Terminó con diferencias',
    maintenance: 'En mantenimiento',
    error: 'Necesita intervención',
  };
  const eventLabels = { success: 'Correcto', warning: 'Esperando', error: 'Revisar', info: 'En curso', neutral: 'Información' };
  let status = root.dataset.sessionStatus || 'active';
  let display = root.dataset.displayState || 'waiting_launcher';
  let version = Number(root.dataset.knownVersion || 0);
  let eventCursor = Number(root.dataset.nextEventId || 0);
  let serverOffset = 0;
  let nextAt = root.dataset.nextActionAt ? Date.parse(`${root.dataset.nextActionAt.replace(' ', 'T')}Z`) : null;
  let engineLive = root.dataset.engineLive === '1';
  let launcherRecent = engineLive;
  let campaignSelectedRecently = false;
  let itemLeaseLive = false;
  let statusInFlight = false;
  // Durante el freno de mano el HTML ya contiene el estado autoritativo.
  // No dejamos polling ni reloj residuales consultando MariaDB en segundo plano.
  let stopped = display === 'maintenance' || terminal.has(status);
  let timer = null;

  const one = (selector) => root.querySelector(selector);
  const text = (selector, value) => { const node = one(selector); if (node) node.textContent = String(value); };
  const dateValue = (value) => value ? Date.parse(`${String(value).replace(' ', 'T')}Z`) : null;
  const clockText = (milliseconds) => {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
  };
  const eta = (seconds) => seconds < 60 ? `${Math.ceil(seconds)} s`
    : (seconds < 3600 ? `${Math.ceil(seconds / 60)} min` : `${(seconds / 3600).toFixed(1).replace('.', ',')} h`);

  const setState = (state, message = null) => {
    display = state;
    root.dataset.displayState = state;
    const stateNode = one('[data-manual-state]');
    if (stateNode) stateNode.dataset.manualState = state;
    text('[data-manual-status]', labels[state] || 'Estado por comprobar');
    if (message !== null) text('[data-manual-message]', message);
  };

  const addEvents = (events) => {
    const list = one('[data-manual-events]');
    if (!list) return;
    events.forEach((event) => {
      const eventId = Number(event.id || 0);
      if (!eventId || list.querySelector(`[data-event-id="${eventId}"]`)) return;
      const row = document.createElement('li');
      row.className = `is-${event.severity || 'info'} is-new`;
      row.dataset.eventId = String(eventId);
      const time = document.createElement('time');
      time.dateTime = event.created_at || '';
      time.textContent = event.display_time || String(event.created_at || '').slice(11, 19);
      const message = document.createElement('span');
      message.textContent = event.safe_message || 'Actividad actualizada.';
      const result = document.createElement('b');
      result.textContent = eventLabels[event.severity] || 'Información';
      row.append(time, message, result);
      list.prepend(row);
      while (list.children.length > 10) list.lastElementChild?.remove();
      requestAnimationFrame(() => row.classList.remove('is-new'));
    });
  };

  const render = (session, serverTime = null) => {
    if (!session) return;
    if (serverTime) serverOffset = Date.parse(serverTime) - Date.now();
    status = session.status || status;
    display = session.display_state || display;
    if (Object.prototype.hasOwnProperty.call(session, 'engine_live')) {
      engineLive = Boolean(session.engine_live);
    }
    if (Object.prototype.hasOwnProperty.call(session, 'launcher_recent')) launcherRecent = Boolean(session.launcher_recent);
    if (Object.prototype.hasOwnProperty.call(session, 'campaign_selected_recently')) campaignSelectedRecently = Boolean(session.campaign_selected_recently);
    if (Object.prototype.hasOwnProperty.call(session, 'item_lease_live')) itemLeaseLive = Boolean(session.item_lease_live);
    version = Number(session.version_no || version);
    eventCursor = Number(session.next_event_id || eventCursor);
    if (Object.prototype.hasOwnProperty.call(session, 'next_action_at')) {
      nextAt = dateValue(session.next_action_at);
    }
    root.dataset.waitKind = session.wait_kind || root.dataset.waitKind || 'interval';
    root.dataset.intervalMs = String(Math.max(1, Number(
      root.dataset.waitKind === 'block_pause'
        ? session.configuration?.block_pause_ms || root.dataset.intervalMs
        : session.configuration?.interval_ms || root.dataset.intervalMs
    )));
    root.classList.toggle('is-browser-live', engineLive);
    setState(display, session.safe_message || null);

    if (session.job_progress || Object.prototype.hasOwnProperty.call(session, 'resolved_items')
      || Object.prototype.hasOwnProperty.call(session, 'total_items')) {
      const jobs = session.job_progress || { resolved: session.resolved_items || 0, total: session.total_items || 0 };
      const resolved = Number(jobs.resolved || 0);
      const total = Number(jobs.total || 0);
      const percent = total > 0 ? Math.min(100, resolved * 100 / total) : 0;
      text('[data-manual-job-progress]', `${resolved} de ${total} trabajos resueltos`);
      text('[data-manual-percent]', `${percent.toFixed(1).replace('.', ',')} %`);
      const bar = one('[data-manual-progress-fill]');
      const track = bar?.parentElement;
      if (bar) bar.style.transform = `scaleX(${Math.max(0, percent / 100)})`;
      if (track) {
        track.setAttribute('aria-valuenow', String(resolved));
        track.setAttribute('aria-valuemax', String(Math.max(1, total)));
        track.setAttribute('aria-valuetext', `${percent.toFixed(1)} % completado`);
      }
    }
    const work = session.current_work_progress || {};
    text('[data-manual-work-progress]', work.known && Number(work.total || 0) > 0
      ? `${Number(work.resolved || 0)} de ${Number(work.total)} unidades`
      : 'Por calcular');
    const rhythm = session.rhythm || {};
    text('[data-manual-block]', Number(rhythm.block || session.current_block || 1));
    text('[data-manual-call-in-block]', Number(rhythm.calls || session.current_call_in_block || 0));
    text('[data-manual-block-size]', Number(rhythm.size || session.current_block_size || 1));
    text('[data-manual-outbound]', Number(rhythm.outbound || session.outbound_calls || 0));
    const updateCounter = (selector, key) => {
      if (Object.prototype.hasOwnProperty.call(session, key)) text(selector, Number(session[key] || 0));
    };
    updateCounter('[data-manual-completed]', 'completed_items');
    updateCounter('[data-manual-pending]', 'open_items');
    updateCounter('[data-manual-running]', 'running_items');
    updateCounter('[data-manual-skipped]', 'skipped_items');
    updateCounter('[data-manual-failed]', 'failed_items');
    updateCounter('[data-manual-error-count]', 'failed_items');
    text('[data-manual-current]', session.current_item?.human_label
      || (launcherRecent
        ? (campaignSelectedRecently ? 'Campaña seleccionada; esperando ventana segura' : 'Esperando que Cron seleccione la campaña')
        : 'Esperando señal del lanzador'));
    text('[data-manual-current-detail]', session.current_item?.content_summary || 'El progreso permanece guardado entre ciclos.');
    text('[data-manual-next-label]', session.next_item?.human_label || (terminal.has(status) ? 'Campaña terminada' : 'Comprobar pendientes'));
    text('[data-manual-side-next]', session.next_item?.human_label || (terminal.has(status) ? 'Ver resultado' : 'Comprobar pendientes'));
    text('[data-manual-next-detail]', session.next_item?.content_summary || 'No hay otro trabajo listo en este momento.');
    text('[data-manual-last-result]', session.last_result_message || 'Aún no hay resultados.');
    const trace = session.cron_trace || {};
    text('[data-manual-cron-selected]', trace.last_selected_label || session.last_cron_selected_label || 'Sin selección registrada');
    text('[data-manual-cron-reason]', trace.reason_label || session.waiting_reason_label || 'Aún no hay causa registrada por Cron.');
    text('[data-manual-cron-next]', trace.next_label
      ? `${trace.next_label} Bogotá`
      : 'El próximo ciclo decidirá si hay recurso listo.');
    text('[data-manual-eta]', Number(session.estimated_remaining_seconds || 0) > 0
      ? eta(Number(session.estimated_remaining_seconds))
      : 'Todavía no se puede estimar');
    addEvents(session.events || []);

    const attention = one('[data-manual-attention]');
    const latestError = session.attention;
    const errorLinks = root.querySelectorAll('[data-manual-error-link],[data-manual-attention-link]');
    if (attention) {
      attention.hidden = !latestError;
      if (latestError) {
        const failedCount = Object.prototype.hasOwnProperty.call(session, 'failed_items')
          ? Number(session.failed_items || 0)
          : Number((one('[data-manual-failed]')?.textContent || '0').replace(/\D/g, '') || 0);
        const continues = Object.prototype.hasOwnProperty.call(session, 'continues_with_attention')
          ? Boolean(session.continues_with_attention)
          : attention.dataset.continuesWithAttention === '1';
        const paused = Object.prototype.hasOwnProperty.call(session, 'processing_paused')
          ? Boolean(session.processing_paused)
          : attention.dataset.processingPaused === '1';
        attention.dataset.continuesWithAttention = continues ? '1' : '0';
        attention.dataset.processingPaused = paused ? '1' : '0';
        attention.classList.toggle('is-continuing', continues);
        text('[data-manual-attention-title]', paused
          ? 'Campaña pausada'
          : `${failedCount} ${failedCount === 1 ? 'trabajo requiere revisión' : 'trabajos requieren revisión'}`);
        text('[data-manual-attention-message]', continues
          ? 'Cron continúa con los demás trabajos. Los errores están aislados y no cuentan como completados.'
          : (latestError.result_summary || 'Un trabajo necesita revisión.'));
        text('[data-manual-error-message]', latestError.result_summary || 'Un trabajo necesita revisión.');
        const review = one('[data-manual-review-errors]');
        if (review) review.textContent = `Revisar ${failedCount} ${failedCount === 1 ? 'trabajo' : 'trabajos'}`;
        const queueKey = String(latestError.queue_key || '');
        const sourceId = String(latestError.source_id || '');
        const params = new URLSearchParams({ queue_key: queueKey, source_id: sourceId });
        errorLinks.forEach((link) => {
          link.hidden = !(queueKey && sourceId);
          if (queueKey && sourceId) link.href = `${root.dataset.workDetailBase}?${params.toString()}`;
        });
      } else {
        text('[data-manual-error-message]', 'No hay errores accionables.');
        errorLinks.forEach((link) => { link.hidden = true; });
      }
    }
    root.querySelectorAll('[data-manual-action]').forEach((form) => {
      const action = form.dataset.manualAction;
      form.hidden = terminal.has(status)
        || (action === 'open' ? !['active', 'pausing', 'paused'].includes(status) : action !== status);
    });
    if (terminal.has(status)) stopped = true;
  };

  const poll = async () => {
    if (statusInFlight || stopped) return;
    statusInFlight = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const separator = root.dataset.statusUrl.includes('?') ? '&' : '?';
      const response = await fetch(`${root.dataset.statusUrl}${separator}after_event_id=${eventCursor}&known_version=${version}`, {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      const payload = await response.json();
      if (payload.ok && payload.session) render(payload.session, payload.server_time);
    } catch (_) {
      // Un fallo del polling no demuestra que el lanzador esté detenido. Se
      // conserva el último estado comprobado y solo se informa la desconexión.
      text('[data-manual-message]', 'No se pudo actualizar el monitor. Se conserva el último estado comprobado.');
    } finally {
      window.clearTimeout(timeout);
      statusInFlight = false;
      schedule();
    }
  };

  const paintClock = () => {
    const node = one('[data-manual-countdown-value]');
    const caption = one('[data-manual-countdown-caption]');
    if (!node || !caption) return;
    if (terminal.has(status)) {
      node.textContent = 'Finalizada';
      caption.textContent = status === 'completed' ? 'Todos los trabajos terminaron' : 'Revise el resultado';
      return;
    }
    if (status === 'paused' || status === 'pausing') {
      node.textContent = 'Pausada';
      caption.textContent = 'El progreso permanece guardado';
      root.style.setProperty('--manual-time-progress', '0');
      return;
    }
    if (!launcherRecent) {
      node.textContent = '—';
      caption.textContent = 'Esperando la próxima señal automática';
      root.style.setProperty('--manual-time-progress', '0');
      return;
    }
    if (!itemLeaseLive && (!nextAt || nextAt <= Date.now() + serverOffset)) {
      node.textContent = 'Listo';
      caption.textContent = campaignSelectedRecently
        ? 'Cron la seleccionó; espera una ventana segura'
        : 'Cron está operativo; espera selección';
      root.style.setProperty('--manual-time-progress', '0');
      return;
    }
    const remaining = Math.max(0, (nextAt || 0) - (Date.now() + serverOffset));
    if (!nextAt || remaining <= 0) {
      node.textContent = 'Listo';
      caption.textContent = engineLive
        ? 'Esperando que Cron tome la campaña'
        : 'Esperando la próxima señal automática';
      root.style.setProperty('--manual-time-progress', '1');
      return;
    }
    node.textContent = clockText(remaining);
    caption.textContent = root.dataset.waitKind === 'block_pause' ? 'Pausa segura entre bloques' : 'Esperando hasta la hora segura';
    const configured = Math.max(1000, Number(root.dataset.intervalMs || 2000));
    root.style.setProperty('--manual-time-progress', String(1 - Math.min(1, remaining / configured)));
  };

  const schedule = () => {
    clearTimeout(timer);
    if (stopped) return;
    const delay = document.hidden ? 30000 : (engineLive ? 3000 : 15000);
    timer = window.setTimeout(poll, delay);
  };

  root.querySelectorAll('[data-manual-tab]').forEach((tab) => {
    tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
    tab.addEventListener('click', () => {
      root.querySelectorAll('[data-manual-tab]').forEach((item) => {
        item.setAttribute('aria-selected', String(item === tab));
        item.tabIndex = item === tab ? 0 : -1;
      });
      root.querySelectorAll('[data-manual-panel]').forEach((panel) => { panel.hidden = panel.dataset.manualPanel !== tab.dataset.manualTab; });
    });
    tab.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      const tabs = Array.from(root.querySelectorAll('[data-manual-tab]'));
      const current = tabs.indexOf(tab);
      const target = event.key === 'Home' ? tabs[0]
        : event.key === 'End' ? tabs[tabs.length - 1]
          : tabs[(current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
      event.preventDefault();
      target?.focus();
      target?.click();
    });
  });

  const clockTimer = stopped ? null : window.setInterval(paintClock, 1000);
  if (!stopped) poll();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); else schedule(); });
  window.addEventListener('pagehide', () => {
    stopped = true;
    clearTimeout(timer);
    clearInterval(clockTimer);
  }, { once: true });
})();

// Confirmación breve para decisiones exactas. Sin JavaScript el formulario
// conserva su acción explícita y sigue siendo operable.
(() => {
  document.querySelectorAll('[data-confirm-exact-action]').forEach((button) => {
    button.addEventListener('click', (event) => {
      const message = button.getAttribute('data-confirm-exact-action') || '¿Desea aplicar esta acción?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });
})();

// Tooltip exclusivo de Productos ML: solo existe cuando el título está recortado.
(() => {
  const selector = '.products-ml-table [data-product-title-tooltip]';
  let tooltip = null;
  let owner = null;
  let timer = null;
  let scheduledOwner = null;
  let hoveredOwner = null;
  let tooltipHovered = false;

  const overflowTarget = (element) =>
    element.querySelector('.product-title-text') || element;

  const overflowed = (element) => {
    const target = overflowTarget(element);
    return target.scrollWidth > target.clientWidth + 1 || target.scrollHeight > target.clientHeight + 1;
  };

  const cancelSchedule = () => {
    clearTimeout(timer);
    timer = null;
    scheduledOwner = null;
  };

  const destroy = () => {
    cancelSchedule();
    if (owner) owner.removeAttribute('aria-describedby');
    if (tooltip) tooltip.remove();
    tooltip = null;
    owner = null;
    tooltipHovered = false;
  };

  const position = () => {
    if (!tooltip || !owner) return;
    const anchor = owner.getBoundingClientRect();
    const box = tooltip.getBoundingClientRect();
    const gap = 8;
    let left = Math.min(anchor.left, window.innerWidth - box.width - 12);
    left = Math.max(12, left);
    let top = anchor.bottom + gap;
    if (top + box.height > window.innerHeight - 12) top = Math.max(12, anchor.top - box.height - gap);
    tooltip.style.left = `${Math.round(left)}px`;
    tooltip.style.top = `${Math.round(top)}px`;
  };

  const show = (element, source) => {
    cancelSchedule();
    const pointerStillPresent = source === 'pointer' && hoveredOwner === element;
    const focusStillPresent = source === 'focus' && document.activeElement === element;
    if (!element.isConnected || (!pointerStillPresent && !focusStillPresent) || !overflowed(element)) return;
    destroy();
    owner = element;
    tooltip = document.createElement('div');
    tooltip.id = `overflow-tooltip-${Date.now()}`;
    tooltip.className = 'overflow-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = element.dataset.tooltipText || element.textContent.trim();
    tooltip.addEventListener('mouseenter', () => { tooltipHovered = true; });
    tooltip.addEventListener('mouseleave', destroy);
    document.body.appendChild(tooltip);
    owner.setAttribute('aria-describedby', tooltip.id);
    position();
    requestAnimationFrame(() => tooltip?.classList.add('is-visible'));
  };

  const schedule = (element, source) => {
    if (scheduledOwner === element || owner === element) return;
    cancelSchedule();
    scheduledOwner = element;
    const attribute = source === 'focus' ? 'tooltipFocusDelay' : 'tooltipHoverDelay';
    const fallback = source === 'focus' ? 300 : 2000;
    const configured = Number(element.dataset[attribute]);
    const delay = Number.isFinite(configured)
      ? Math.max(source === 'focus' ? 0 : 500, Math.min(10000, configured))
      : fallback;
    timer = setTimeout(() => show(element, source), delay);
  };

  document.addEventListener('pointerover', (event) => {
    if (event.pointerType === 'touch') return;
    const element = event.target.closest(selector);
    if (!element || element.contains(event.relatedTarget)) return;
    hoveredOwner = element;
    schedule(element, 'pointer');
  });
  document.addEventListener('pointerout', (event) => {
    const element = event.target.closest(selector);
    if (!element || element.contains(event.relatedTarget)) return;
    hoveredOwner = null;
    if (scheduledOwner === element) cancelSchedule();
    if (tooltip?.contains(event.relatedTarget)) return;
    setTimeout(() => {
      if (!tooltipHovered && owner === element && hoveredOwner !== element) destroy();
    }, 80);
  });
  document.addEventListener('focusin', (event) => {
    const element = event.target.closest(selector);
    if (element) schedule(element, 'focus');
  });
  document.addEventListener('focusout', (event) => {
    const element = event.target.closest(selector);
    if (!element) return;
    if (scheduledOwner === element) cancelSchedule();
    if (!tooltipHovered) destroy();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') destroy();
  });
  window.addEventListener('resize', destroy, { passive: true });
  window.addEventListener('scroll', destroy, { passive: true, capture: true });
  new MutationObserver(() => {
    if ((owner && !owner.isConnected) || (scheduledOwner && !scheduledOwner.isConnected)) destroy();
  }).observe(document.body, { childList: true, subtree: true });
})();
