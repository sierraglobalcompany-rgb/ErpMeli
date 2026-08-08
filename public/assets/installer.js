(() => {
  'use strict';
  const form = document.getElementById('installer-form');
  const action = document.getElementById('installer-action');
  const testButton = document.getElementById('test-connection');
  const installButton = document.getElementById('install-button');
  const status = document.getElementById('connection-status');
  const advancedToggle = document.getElementById('toggle-database-advanced');
  const advancedFields = document.getElementById('database-advanced-fields');
  if (!form || !action || !testButton || !installButton || !status) return;

  if (advancedToggle && advancedFields) {
    advancedToggle.addEventListener('change', () => {
      advancedFields.hidden = !advancedToggle.checked;
      advancedToggle.setAttribute('aria-expanded', advancedToggle.checked ? 'true' : 'false');
      if (advancedToggle.checked) advancedFields.querySelector('input')?.focus();
    });
  }

  const passwordInput = form.elements.admin_password;
  const passwordIsValid = (password) => password.length >= 6
    && /\p{Lu}/u.test(password)
    && /\p{N}/u.test(password)
    && /[^\p{L}\p{N}\s]/u.test(password);
  passwordInput.addEventListener('input', () => passwordInput.setCustomValidity(''));

  testButton.addEventListener('click', async () => {
    status.className = 'connection-status';
    status.textContent = 'Comprobando conexión…';
    testButton.disabled = true;
    const data = new FormData(form);
    data.set('action', 'test');
    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      const payload = await response.json();
      status.classList.add(payload.ok ? 'success' : 'error');
      status.textContent = payload.message || 'La prueba no entregó una respuesta válida.';
    } catch (_error) {
      status.classList.add('error');
      status.textContent = 'No fue posible completar la prueba. Revise la conexión HTTPS.';
    } finally {
      testButton.disabled = false;
    }
  });

  form.addEventListener('submit', (event) => {
    action.value = 'install';
    const password = form.elements.admin_password.value;
    const confirmation = form.elements.admin_password_confirm.value;
    if (!passwordIsValid(password)) {
      event.preventDefault();
      passwordInput.setCustomValidity('Use mínimo 6 caracteres e incluya una mayúscula, un número y un carácter especial.');
      passwordInput.reportValidity();
      return;
    }
    if (password !== confirmation) {
      event.preventDefault();
      status.className = 'connection-status error';
      status.textContent = 'Las contraseñas del administrador no coinciden.';
      form.elements.admin_password_confirm.focus();
      return;
    }
    installButton.disabled = true;
    installButton.textContent = 'Instalando…';
  });
})();
