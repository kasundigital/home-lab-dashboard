(() => {
  const root = document.documentElement;
  const storedTheme = localStorage.getItem('homelab-theme');
  const preferred = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  root.dataset.theme = storedTheme || preferred;

  const toggle = document.getElementById('themeToggle');
  const syncIcon = () => { if (toggle) toggle.textContent = root.dataset.theme === 'dark' ? '☀' : '☾'; };
  syncIcon();

  toggle?.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('homelab-theme', root.dataset.theme);
    syncIcon();
  });

  document.querySelectorAll('[data-dialog-open]').forEach((button) => {
    button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal());
  });

  document.querySelectorAll('[data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });

  // Keep the daily dashboard fresh (new bills from n8n, Telegram messages) without
  // interrupting a dialog or a half-typed form.
  if (document.body.dataset.autoRefresh) {
    setInterval(() => {
      const busy = document.querySelector('dialog[open]') || document.activeElement?.matches('input,textarea,select');
      if (!document.hidden && !busy) location.reload();
    }, Number(document.body.dataset.autoRefresh) * 1000);
  }
})();
