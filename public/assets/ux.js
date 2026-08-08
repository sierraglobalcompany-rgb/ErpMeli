(() => {
  const normalize = (value) => String(value || '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

  const search = document.querySelector('[data-settings-search]');
  if (search) {
    const cards = Array.from(document.querySelectorAll('[data-settings-card]'));
    const filter = () => {
      const query = normalize(search.value.trim());
      let visible = 0;
      cards.forEach((card) => {
        const matches = query === '' || normalize(card.dataset.search).includes(query);
        card.hidden = !matches;
        if (matches) visible += 1;
      });
      document.querySelector('[data-settings-grid]')?.toggleAttribute('data-empty', visible === 0);
    };
    search.addEventListener('input', filter);
  }

  document.querySelectorAll('[data-dirty-form]').forEach((form) => {
    let baseline = new URLSearchParams(new FormData(form)).toString();
    const message = form.querySelector('[data-dirty-message]');
    const refresh = () => {
      const dirty = new URLSearchParams(new FormData(form)).toString() !== baseline;
      form.dataset.dirty = dirty ? 'true' : 'false';
      if (message) message.textContent = dirty ? 'Tiene cambios sin guardar.' : 'Sin cambios pendientes.';
    };
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('submit', () => { baseline = new URLSearchParams(new FormData(form)).toString(); });
    window.addEventListener('beforeunload', (event) => {
      if (form.dataset.dirty !== 'true') return;
      event.preventDefault();
      event.returnValue = '';
    });
  });

  document.querySelectorAll('.data-table').forEach((table) => {
    if (!table.querySelector('caption')) {
      const caption = document.createElement('caption');
      caption.className = 'sr-only';
      caption.textContent = table.closest('.panel')?.querySelector('h2')?.textContent?.trim() || 'Datos del sistema';
      table.prepend(caption);
    }
    const headers = Array.from(table.querySelectorAll('thead th')).map((cell) => cell.textContent.trim());
    if (headers.length === 0) return;
    table.dataset.responsive = 'cards';
    table.querySelectorAll('tbody tr').forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        if (!cell.dataset.label && headers[index]) cell.dataset.label = headers[index];
      });
    });
  });

  document.querySelectorAll('button:not([type])').forEach((button) => {
    button.type = button.closest('form') ? 'submit' : 'button';
  });

  // El resumen humano de Cron sigue al panel "Ahora" sin duplicar su polling.
  const cronNow = document.querySelector('[data-cron-now]');
  const cronHumanNow = document.querySelector('[data-cron-human-now]');
  if (cronNow && cronHumanNow && 'MutationObserver' in window) {
    const refreshCronHumanNow = () => {
      const value = normalize(cronNow.textContent);
      cronHumanNow.textContent = /esperando|sin trabajo|entre ciclos/.test(value)
        ? 'Cron está entre ciclos; no hay un lease activo.'
        : 'Hay un trabajo con actividad comprobada.';
    };
    new MutationObserver(refreshCronHumanNow).observe(cronNow, { childList: true, subtree: true, characterData: true });
    refreshCronHumanNow();
  }

  // Completa asociaciones accesibles en vistas heredadas sin cambiar su diseño.
  let generatedControlId = 0;
  document.querySelectorAll('label:not([for])').forEach((label) => {
    const control = label.querySelector('input,select,textarea');
    if (!control) return;
    if (!control.id) control.id = `erp-control-${++generatedControlId}`;
    label.htmlFor = control.id;
  });
  document.querySelectorAll('table:not([aria-label]):not([aria-labelledby])').forEach((table) => {
    if (table.querySelector(':scope > caption')) return;
    const section = table.closest('section,.panel');
    const heading = section?.querySelector('h1,h2,h3');
    table.setAttribute('aria-label', heading?.textContent?.trim() || 'Listado de información');
  });

  const dialogTriggers = new WeakMap();
  document.querySelectorAll('[data-dialog-open]').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById(button.dataset.dialogOpen);
      if (!dialog) return;
      const accountInput = dialog.querySelector('[name="account_id"]');
      if (accountInput) accountInput.value = button.dataset.accountId || '';
      const accountName = dialog.querySelector('[data-dialog-account]');
      if (accountName) accountName.textContent = button.dataset.accountName || 'la cuenta seleccionada';
      dialogTriggers.set(dialog, button);
      dialog.showModal?.();
      window.requestAnimationFrame(() => {
        const target = dialog.querySelector('select:not([disabled]),input:not([type="hidden"]):not([disabled]),button:not([disabled])');
        target?.focus();
      });
    });
  });
  document.querySelectorAll('[data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });
  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => dialogTriggers.get(dialog)?.focus());
    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dialog.close();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'))
        .filter((element) => element.getClientRects().length > 0);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    dialog.addEventListener('click', (event) => {
      const rect = dialog.getBoundingClientRect();
      const inside = event.clientX >= rect.left && event.clientX <= rect.right
        && event.clientY >= rect.top && event.clientY <= rect.bottom;
      if (!inside) dialog.close();
    });
  });

  document.addEventListener('click', (event) => {
    document.querySelectorAll('.api-row-menu[open],.api-more-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  // 2.18.1 · Ayuda contextual accesible, sin alterar el layout.
  let contextHelp = null;
  let contextOwner = null;
  let contextTimer = null;
  const helpHoverDelay = 3000;
  const helpFocusDelay = 300;
  const clearHelpTimer = () => {
    if (contextTimer) window.clearTimeout(contextTimer);
    contextTimer = null;
  };
  const closeContextHelp = (restoreFocus = false) => {
    clearHelpTimer();
    if (contextHelp) contextHelp.remove();
    if (contextOwner) contextOwner.removeAttribute('aria-describedby');
    const owner = contextOwner;
    contextHelp = null;
    contextOwner = null;
    if (restoreFocus) owner?.focus();
  };
  const positionContextHelp = (owner, popover) => {
    const anchor = owner.getBoundingClientRect();
    const box = popover.getBoundingClientRect();
    const gap = 10;
    let left = anchor.left + (anchor.width / 2) - (box.width / 2);
    left = Math.max(gap, Math.min(window.innerWidth - box.width - gap, left));
    let top = anchor.bottom + gap;
    if (top + box.height > window.innerHeight - gap) top = Math.max(gap, anchor.top - box.height - gap);
    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
  };
  const openContextHelp = (owner) => {
    if (!owner?.isConnected) return;
    closeContextHelp(false);
    const id = `context-help-${Date.now()}`;
    const popover = document.createElement('div');
    popover.id = id;
    popover.className = 'context-help-popover';
    popover.setAttribute('role', 'tooltip');
    const title = document.createElement('strong');
    title.textContent = owner.dataset.helpTitle || 'Ayuda';
    const text = document.createElement('p');
    text.textContent = owner.dataset.helpText || '';
    popover.append(title, text);
    document.body.appendChild(popover);
    contextOwner = owner;
    contextHelp = popover;
    owner.setAttribute('aria-describedby', id);
    positionContextHelp(owner, popover);
    popover.addEventListener('mouseenter', clearHelpTimer);
    popover.addEventListener('mouseleave', () => closeContextHelp(false));
  };
  const scheduleContextHelp = (owner, delay) => {
    clearHelpTimer();
    const expected = owner;
    contextTimer = window.setTimeout(() => {
      if (!expected.isConnected) return;
      openContextHelp(expected);
    }, delay);
  };
  document.addEventListener('pointerover', (event) => {
    if (event.pointerType === 'touch') return;
    const owner = event.target.closest?.('[data-context-help]');
    if (!owner || owner.contains(event.relatedTarget)) return;
    scheduleContextHelp(owner, helpHoverDelay);
  });
  document.addEventListener('pointerout', (event) => {
    const owner = event.target.closest?.('[data-context-help]');
    if (!owner || owner.contains(event.relatedTarget)) return;
    if (contextHelp?.contains(event.relatedTarget)) return;
    closeContextHelp(false);
  });
  document.addEventListener('focusin', (event) => {
    const owner = event.target.closest?.('[data-context-help]');
    if (owner) scheduleContextHelp(owner, helpFocusDelay);
  });
  document.addEventListener('focusout', (event) => {
    const owner = event.target.closest?.('[data-context-help]');
    if (owner && !contextHelp?.contains(event.relatedTarget)) closeContextHelp(false);
  });
  document.addEventListener('click', (event) => {
    const owner = event.target.closest?.('[data-context-help]');
    if (!owner) return;
    event.preventDefault();
    contextOwner === owner && contextHelp ? closeContextHelp(false) : openContextHelp(owner);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && (contextHelp || contextTimer)) {
      event.preventDefault();
      closeContextHelp(true);
    }
  });
  window.addEventListener('scroll', () => closeContextHelp(false), { passive: true });
  window.addEventListener('resize', () => closeContextHelp(false));

  let workAttentionPopover = null;
  let workAttentionOwner = null;
  let workAttentionTimer = null;
  const clearWorkAttentionTimer = () => {
    if (workAttentionTimer) window.clearTimeout(workAttentionTimer);
    workAttentionTimer = null;
  };
  const closeWorkAttention = (restoreFocus = false) => {
    clearWorkAttentionTimer();
    if (workAttentionPopover) workAttentionPopover.remove();
    if (workAttentionOwner) workAttentionOwner.removeAttribute('aria-describedby');
    const owner = workAttentionOwner;
    workAttentionPopover = null;
    workAttentionOwner = null;
    if (restoreFocus) owner?.focus();
  };
  const positionWorkAttention = (owner, popover) => {
    const anchor = owner.getBoundingClientRect();
    const box = popover.getBoundingClientRect();
    const gap = 10;
    let left = anchor.left + (anchor.width / 2) - (box.width / 2);
    left = Math.max(gap, Math.min(window.innerWidth - box.width - gap, left));
    let top = anchor.bottom + gap;
    if (top + box.height > window.innerHeight - gap) {
      top = Math.max(gap, anchor.top - box.height - gap);
    }
    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
  };
  const openWorkAttention = (owner) => {
    if (!owner?.isConnected || !owner.matches('[data-work-attention]')) return;
    closeWorkAttention(false);
    const popover = document.createElement('div');
    const id = `work-attention-${Date.now()}`;
    popover.id = id;
    popover.className = 'work-attention-popover';
    popover.setAttribute('role', 'tooltip');
    const title = document.createElement('strong');
    title.textContent = owner.dataset.attentionTitle || 'Estado del trabajo';
    const text = document.createElement('p');
    text.textContent = owner.dataset.attentionText || '';
    const hint = document.createElement('small');
    hint.textContent = 'Haga clic para ver la causa y el siguiente paso.';
    popover.append(title, text, hint);
    document.body.appendChild(popover);
    workAttentionOwner = owner;
    workAttentionPopover = popover;
    owner.setAttribute('aria-describedby', id);
    positionWorkAttention(owner, popover);
    popover.addEventListener('pointerenter', clearWorkAttentionTimer);
    popover.addEventListener('pointerleave', () => closeWorkAttention(false));
  };
  const scheduleWorkAttention = (owner, delay) => {
    clearWorkAttentionTimer();
    const expectedOwner = owner;
    workAttentionTimer = window.setTimeout(() => {
      if (!expectedOwner.isConnected) return;
      const hovered = expectedOwner.matches(':hover');
      const focused = document.activeElement === expectedOwner;
      if (hovered || focused) openWorkAttention(expectedOwner);
    }, delay);
  };
  document.addEventListener('pointerover', (event) => {
    if (event.pointerType === 'touch') return;
    const owner = event.target.closest?.('[data-work-attention]');
    if (!owner || owner.contains(event.relatedTarget)) return;
    const delay = Math.max(500, Math.min(10000, Number(owner.dataset.hoverDelay) || 2000));
    scheduleWorkAttention(owner, delay);
  });
  document.addEventListener('pointerout', (event) => {
    const owner = event.target.closest?.('[data-work-attention]');
    if (!owner || owner.contains(event.relatedTarget)) return;
    if (workAttentionPopover?.contains(event.relatedTarget)) return;
    closeWorkAttention(false);
  });
  document.addEventListener('focusin', (event) => {
    const owner = event.target.closest?.('[data-work-attention]');
    if (!owner) return;
    const delay = Math.max(100, Math.min(2000, Number(owner.dataset.focusDelay) || 300));
    scheduleWorkAttention(owner, delay);
  });
  document.addEventListener('focusout', (event) => {
    const owner = event.target.closest?.('[data-work-attention]');
    if (owner && !workAttentionPopover?.contains(event.relatedTarget)) closeWorkAttention(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && (workAttentionPopover || workAttentionTimer)) {
      event.preventDefault();
      closeWorkAttention(true);
    }
  });
  window.addEventListener('scroll', () => closeWorkAttention(false), { passive: true });
  window.addEventListener('resize', () => closeWorkAttention(false));
})();
