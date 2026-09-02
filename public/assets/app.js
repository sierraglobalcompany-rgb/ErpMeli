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
  const statusTimeoutMs = 8000;
  const password = root.querySelector('[data-qv4-password]');
  const feedback = root.querySelector('[data-qv4-feedback]');
  let snapshot = null;
  const actions = [...root.querySelectorAll('[data-qv4-action]')];
  const labels = {
    NOT_READY: 'No preparado', READY_TO_TEST: 'Listo para comprobar', TESTING: 'Comprobando',
    CERTIFIED: 'Certificado', FAILED: 'Falló la comprobación'
  };
  const safe = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
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
      : 'Automatización activa y sin atención crítica inmediata.';
    root.querySelector('[data-qv4-engine]').textContent = data.engine || 'STOPPED';
    root.querySelector('[data-qv4-oauth]').textContent = `${Number(data.accounts_oauth || 0)}/3`;
    root.querySelector('[data-qv4-readiness]').textContent = `${Number(data.readiness_get_passed || 0)}/3`;
    root.querySelector('[data-qv4-scheduler]').textContent = data.scheduler === 'active' ? 'Activo' : 'Inactivo';
    const heartbeat = root.querySelector('[data-qv4-heartbeat]');
    if (heartbeat) heartbeat.textContent = data.last_scheduler_heartbeat || 'Sin evidencia';
    const physical = root.querySelector('[data-qv4-physical]');
    if (physical) physical.textContent = data.physical_cron_observed === 'YES' ? 'Verificado' : 'No certificado';
    const recentWork = data.recent_work || data.worker_summary || {};
    const completed = Number(recentWork.completed || recentWork.completed_last_hour || 0);
    const deferred = Number(recentWork.deferred || 0);
    const claimed = Number(recentWork.claimed || completed + deferred);
    const ratio = claimed > 0 ? `${Math.round((completed / claimed) * 100)}%` : '—';
    const workValues = { completed, deferred, ratio };
    Object.entries(workValues).forEach(([key, value]) => {
      const node = root.querySelector(`[data-qv4-work="${key}"]`);
      if (node) node.textContent = String(value);
    });
    const review = data.review_forensics || {};
    const reviewValues = {
      recoverable: review.recoverable_count || 0,
      functional: review.functional_count || 0,
      ambiguous: review.ambiguous_count || 0,
      oldest: review.oldest || '—',
      unknown_reason: review.review_with_unknown_reason || 0,
      no_path: review.review_with_no_resolution_path || 0,
      dominant: review.dominant_review_class
        ? `${review.dominant_review_class} (${review.dominant_review_class_percent || 0}%)`
        : '—',
      human: (review.requires_human_counts && review.requires_human_counts.YES) || 0
    };
    Object.entries(reviewValues).forEach(([key, value]) => {
      const node = root.querySelector(`[data-qv4-review="${key}"]`);
      if (node) node.textContent = String(value);
    });
    const reviewActions = root.querySelector('[data-qv4-review-actions]');
    if (reviewActions) {
      const counts = review.review_class_counts || {};
      const paths = review.current_review_actions || {};
      const ages = review.review_age_matrix || {};
      const classes = Object.keys(counts).sort((a, b) => Number(counts[b] || 0) - Number(counts[a] || 0));
      reviewActions.innerHTML = classes.length
        ? classes.map((name) => {
            const path = paths[name] || {};
            const age = ages[name] || {};
            const ageText = ['<1h', '1-6h', '6-24h', '1-7d', '7-30d', '>30d']
              .map((bucket) => `${bucket}: ${Number(age[bucket] || 0)}`)
              .join(' · ');
            const actions = Array.isArray(path.available_operator_actions)
              ? path.available_operator_actions.join(' · ')
              : 'Inspección humana';
            return `<tr><td><span class="status-badge is-warning">${safe(name)}</span></td><td>${Number(counts[name] || 0)}</td><td><small>${safe(ageText)}</small></td><td><small>${safe(actions)}</small></td></tr>`;
          }).join('')
        : '<tr><td colspan="4"><div class="empty">No hay filas en Review.</div></td></tr>';
    }
    Object.entries(data.queue || {}).forEach(([key, value]) => {
      const node = root.querySelector(`[data-qv4-count="${key}"]`);
      if (node) node.textContent = String(value);
    });
    const oauthOperations = root.querySelector('[data-qv4-oauth-operations]');
    if (oauthOperations) {
      const rows = Array.isArray(data.oauth_control_plane) ? data.oauth_control_plane : [];
      oauthOperations.innerHTML = rows.length
        ? rows.map((row) => {
            const state = row.automatic_refresh_state || 'IDLE';
            const detail = state === 'RECONNECT_REQUIRED'
              ? 'Requiere reconexión administrativa; no habrá reintento automático.'
              : `Vence: ${safe(row.expires_at || '—')} · Próximo: ${safe(row.next_attempt_at || '—')}`;
            return `<article><span>${safe(row.account_name || `Cuenta ${row.meli_account_id}`)}</span><strong>${safe(state)}</strong><p>${detail}</p></article>`;
          }).join('')
        : '<article><span>Autoridad</span><strong>Sin cuentas certificadas</strong><p>No se programó renovación.</p></article>';
    }
    root.querySelector('[data-qv4-legacy]').textContent = data.legacy_state_consulted ? 'Error: legado consultado' : 'Legado no consultado';
    syncButtons();
  };
  const refresh = async () => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), statusTimeoutMs);
    try {
      const response = await fetch(root.dataset.statusUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: controller.signal
      });
      if (response.status === 504) throw new Error('El backend Queue V4 agotó el tiempo de respuesta.');
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.toLowerCase().includes('application/json')) {
        throw new Error('Queue V4 devolvió una respuesta no válida.');
      }
      const data = await response.json().catch(() => { throw new Error('Queue V4 devolvió JSON inválido.'); });
      if (!response.ok || data.ok === false) throw new Error(data.message || 'Queue V4 no disponible.');
      render(data);
      feedback.textContent = 'Estado actualizado sin mutaciones.';
    } catch (error) {
      snapshot = null;
      syncButtons();
      feedback.textContent = error?.name === 'AbortError'
        ? 'El backend Queue V4 agotó el tiempo de respuesta.'
        : (error?.message || 'No se pudo leer Queue V4.');
    } finally {
      clearTimeout(timeout);
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

// Riesgos API de Cron: evidencia directa, acotada y sin depender del catálogo materializado.
(() => {
  const root = document.querySelector('[data-cron-api-risks]');
  if (!root) return;
  const timeoutMs = 8000;
  const metric = (name) => root.querySelector(`[data-cron-api-risk="${name}"]`);
  const source = root.querySelector('[data-cron-api-risks-source]');
  const meta = root.querySelector('[data-cron-api-risks-meta]');
  const top = root.querySelector('[data-cron-api-risks-top]');
  const recent = root.querySelector('[data-cron-api-risks-recent]');
  const detail = root.querySelector('[data-cron-api-risks-detail]');
  const remoteLink = root.querySelector('[data-cron-api-risks-remote]');
  const localLink = root.querySelector('[data-cron-api-risks-local]');
  const technicalLink = root.querySelector('[data-cron-api-risks-technical]');
  const appBase = (root.dataset.appBase || '').replace(/\/$/, '');
  const context = (name) => root.querySelector(`[data-cron-api-risk-context="${name}"]`);
  // The API returns app-relative routes. Preserve the configured deployment
  // prefix (for example /erp-meli) instead of resolving them at host root.
  const appHref = (path) => {
    if (typeof path !== 'string' || !path.startsWith('/settings/')) return null;
    return `${appBase}${path}`;
  };
  const setAppHref = (element, path) => {
    const href = appHref(path);
    if (element && href) element.href = href;
  };
  const names = {
    REMOTE_HTTP_429: 'Mercado Libre respondió HTTP 429',
    LOCAL_RATE_LIMITED_PRETRANSPORT: 'Pausa preventiva local · sin HTTP remoto',
    OAUTH_CRITICAL: 'OAuth o permisos remotos',
    REMOTE_HTTP_5XX: 'Fallo HTTP 5xx remoto',
    REMOTE_UNCERTAIN: 'Resultado remoto incierto'
  };
  const clear = (node) => { while (node?.firstChild) node.removeChild(node.firstChild); };
  const append = (node, value, emphasis = false) => {
    if (!node) return;
    const item = document.createElement('li');
    const strong = document.createElement('strong');
    strong.textContent = value;
    if (emphasis) item.appendChild(strong); else item.textContent = value;
    node.appendChild(item);
  };
  const metaItem = (label, value) => {
    const item = document.createElement('span');
    const strong = document.createElement('strong');
    strong.textContent = `${label}: `;
    item.appendChild(strong);
    item.appendChild(document.createTextNode(value));
    meta.appendChild(item);
  };
  const render = (payload) => {
    root.setAttribute('aria-busy', 'false');
    const windows = payload.windows || {};
    const nowTotals = windows['60m'] || payload.totals || {};
    const dayTotals = windows['24h'] || {};
    const monthTotals = windows['30d'] || payload.totals || {};
    ['remote_http_429', 'local_rate_limited_pretransport', 'oauth_critical', 'http_5xx', 'remote_uncertain']
      .forEach((key) => {
        const node = metric(key);
        if (node) node.textContent = String(Number(nowTotals[key] || 0));
        const note = context(key);
        if (note) {
          note.textContent = key === 'local_rate_limited_pretransport'
            ? `${Number(dayTotals[key] || 0)} en 24h · ${Number(monthTotals[key] || 0)} en 30d histórico. Protección local antes de salir a Mercado Libre.`
            : `${Number(dayTotals[key] || 0)} en 24h · ${Number(monthTotals[key] || 0)} en 30d histórico.`;
        }
      });
    if (payload.ok !== true) {
      source.textContent = payload.message || 'NO_CERTIFICADO: no se pudo leer la telemetría directa.';
      clear(top); clear(recent);
      append(top, 'NO_CERTIFICADO');
      append(recent, 'NO_CERTIFICADO');
      return;
    }
    source.textContent = `CERTIFICADO · ${payload.source_label || 'Telemetría directa'} · ahora=60m · consultada ${payload.measured_at || 'ahora'}.`;
    setAppHref(detail, payload.links?.remote_429);
    setAppHref(remoteLink, payload.links?.remote_429);
    setAppHref(localLink, payload.links?.local_pretransport);
    setAppHref(technicalLink, payload.links?.technical);
    clear(meta);
    const materializer = payload.materializer || {};
    metaItem('Incidentes', materializer.current ? 'catálogo al día' : `catálogo atrasado (${Number(materializer.lag || 0)} eventos)`);
    const worker = payload.worker || {};
    if (worker.available) {
      metaItem('Worker', `${Number(worker.dead || 0)} Dead · ${Number(worker.stale_leases || 0)} leases vencidos · heartbeat ${worker.heartbeat_age_seconds ?? '—'} s`);
    } else {
      metaItem('Worker', 'NO_CERTIFICADO');
    }
    const billing = payload.billing || {};
    metaItem('Billing', billing.available
      ? (billing.state === 'ACTIVE' ? `bloqueado hasta ${billing.next_safe_at || 'hora segura'}` : 'sin breaker activo')
      : 'NO_CERTIFICADO');
    clear(top);
    const topRows = Array.isArray(payload.top) ? payload.top : [];
    if (topRows.length === 0) append(top, 'Sin señales de riesgo en el histórico 30d.');
    topRows.forEach((row) => append(top, `${row.account_alias || 'Aplicación'} · ${row.endpoint || 'endpoint no certificado'} · ${Number(row.repetitions || 0)} eventos · último ${row.last_seen_at || '—'}`));
    clear(recent);
    const recentRows = Array.isArray(payload.recent) ? payload.recent : [];
    if (recentRows.length === 0) append(recent, 'Sin señales de riesgo recientes.');
    recentRows.forEach((row) => append(recent, `${names[row.signal] || row.signal || 'Evento'} · ${row.account_alias || 'Aplicación'} · ${row.method || 'GET'} ${row.endpoint || 'endpoint no certificado'}${row.http_status ? ` · HTTP ${row.http_status}` : ''} · ${row.observed_at || '—'}`));
  };
  const refresh = async () => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(root.dataset.risksUrl, {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal
      });
      const payload = await response.json().catch(() => ({}));
      render(payload);
      if (!response.ok && source) source.textContent = payload.message || 'NO_CERTIFICADO: el resumen no respondió correctamente.';
    } catch (error) {
      root.setAttribute('aria-busy', 'false');
      if (source) source.textContent = error?.name === 'AbortError'
        ? 'NO_CERTIFICADO: la consulta de riesgos tardó más de 8 s.'
        : 'NO_CERTIFICADO: no se pudo leer Riesgos API.';
      clear(top); clear(recent);
      append(top, 'NO_CERTIFICADO');
      append(recent, 'NO_CERTIFICADO');
    } finally {
      window.clearTimeout(timeout);
    }
  };
  refresh();
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
