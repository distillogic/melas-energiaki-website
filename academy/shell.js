(() => {
  'use strict';
  const root = document.documentElement;
  try { if (localStorage.getItem('melas-academy-theme') === 'dark') root.dataset.scheme = 'dark'; } catch (_) {}
  document.querySelector('[data-school-theme]')?.addEventListener('click', () => {
    root.dataset.scheme = root.dataset.scheme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('melas-academy-theme', root.dataset.scheme); } catch (_) {}
  });
  document.querySelectorAll('[data-school-confirm]').forEach(form => {
    form.addEventListener('submit', event => { if (!confirm(form.dataset.schoolConfirm)) event.preventDefault(); });
  });
})();
