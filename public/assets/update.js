(() => {
  'use strict';
  document.querySelectorAll('[data-backup-choice]').forEach((form) => {
    const checkbox = form.querySelector('[data-backup-checkbox]');
    const waiver = form.querySelector('[data-backup-waiver]');
    if (!checkbox || !waiver) return;
    const sync = () => {
      waiver.hidden = checkbox.checked;
      waiver.required = !checkbox.checked;
      if (checkbox.checked) waiver.value = '';
    };
    checkbox.addEventListener('change', sync);
    sync();
  });

  const panel = document.querySelector('[data-update-run]');
  if (!panel) return;
  const statusUrl = panel.dataset.statusUrl;
  const terminal = new Set(['completed', 'failed', 'rolled_back', 'manual_intervention']);
  let lastState = panel.dataset.currentState || '';
  const poll = async () => {
    try {
      const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload.ok || !payload.run) return;
      const run = payload.run;
      const progress = panel.querySelector('[data-update-progress]');
      const percent = panel.querySelector('[data-update-percent]');
      const step = panel.querySelector('[data-update-step]');
      if (progress) progress.style.width = `${Math.max(0, Math.min(100, Number(run.progress_percent) || 0))}%`;
      if (percent) percent.textContent = `${Number(run.progress_percent || 0).toLocaleString('es-CO', {minimumFractionDigits: 1, maximumFractionDigits: 1})} %`;
      if (step) step.textContent = run.current_step || '—';
      if (lastState && lastState !== run.state) window.location.reload();
      lastState = run.state;
      if (terminal.has(run.state)) return;
    } catch (_) {}
    window.setTimeout(poll, 10000);
  };
  window.setTimeout(poll, 10000);
})();
