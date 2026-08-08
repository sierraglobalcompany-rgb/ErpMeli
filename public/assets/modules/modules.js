(() => {
  'use strict';
  document.querySelectorAll('[data-module]').forEach((module) => {
    module.querySelectorAll('form').forEach((form) => {
      form.addEventListener('submit', () => form.setAttribute('aria-busy', 'true'));
    });
  });
})();
