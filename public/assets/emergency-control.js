(() => {
  const modal = document.getElementById('confirm-backdrop');
  if (!modal) return;

  const message = document.getElementById('confirm-message');
  const cancel = document.getElementById('confirm-cancel');
  const submit = document.getElementById('confirm-submit');
  let pendingForm = null;
  let pendingSubmitter = null;
  let returnFocus = null;

  const pageChildren = Array.from(document.body.children).filter((element) => element !== modal);
  const focusable = () => Array.from(modal.querySelectorAll(
    'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
  )).filter((element) => element.getClientRects().length > 0);

  const setPageInactive = (inactive) => {
    pageChildren.forEach((element) => {
      if ('inert' in element) element.inert = inactive;
      if (inactive) element.setAttribute('aria-hidden', 'true');
      else element.removeAttribute('aria-hidden');
    });
  };

  const close = () => {
    modal.classList.remove('is-open');
    modal.hidden = true;
    setPageInactive(false);
    pendingForm = null;
    pendingSubmitter = null;
    returnFocus?.focus();
    returnFocus = null;
  };

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.confirmed === '1') return;
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter || document.activeElement;
      returnFocus = document.activeElement;
      message.textContent = pendingSubmitter?.getAttribute?.('data-confirm-override')
        || form.getAttribute('data-confirm')
        || '¿Seguro que desea continuar?';
      modal.hidden = false;
      modal.classList.add('is-open');
      setPageInactive(true);
      submit.focus();
    });
  });

  cancel.addEventListener('click', close);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  submit.addEventListener('click', () => {
    if (!pendingForm) return close();
    pendingForm.dataset.confirmed = '1';
    if (pendingSubmitter && typeof pendingForm.requestSubmit === 'function') {
      pendingForm.requestSubmit(pendingSubmitter);
    } else {
      pendingForm.requestSubmit();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = focusable();
    if (controls.length === 0) return;
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
})();
