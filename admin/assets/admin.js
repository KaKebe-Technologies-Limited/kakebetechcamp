/* Kakebe Tech Camp — control panel interactions */
(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

  // Sidebar (mobile)
  const toggle = $('#sbToggle');
  if (toggle) toggle.addEventListener('click', () => document.body.classList.toggle('sb-open'));
  const overlay = $('#sbOverlay');
  if (overlay) overlay.addEventListener('click', () => document.body.classList.remove('sb-open'));

  // Auto-hide success alerts
  $$('.alert-success[data-autohide]').forEach((el) => setTimeout(() => {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  }, 6000));

  // Confirmations
  $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
  $$('[data-confirm-click]').forEach((b) => b.addEventListener('click', (e) => { if (!confirm(b.dataset.confirmClick)) e.preventDefault(); }));

  // Clickable table rows
  $$('tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a, button, input, label, form')) location.href = tr.dataset.href;
  }));

  // Show/hide sections
  $$('[data-toggle]').forEach((btn) => btn.addEventListener('click', () => {
    const el = $(btn.dataset.toggle);
    if (el) { el.hidden = !el.hidden; if (!el.hidden) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }));

  // Max two learning tracks
  $$('.js-max2').forEach((box) => {
    const inputs = $$('input[type="checkbox"]', box);
    const sync = () => { const n = inputs.filter((i) => i.checked).length; inputs.forEach((i) => { i.disabled = !i.checked && n >= 2; }); };
    inputs.forEach((i) => i.addEventListener('change', sync));
    sync();
  });

  // Photo preview for team uploads
  $$('.js-autopreview').forEach((input) => input.addEventListener('change', () => {
    const file = input.files[0];
    if (!file) return;
    const holder = input.closest('.mc-photo');
    let img = $('img', holder);
    const span = $('span', holder);
    if (!img) { img = document.createElement('img'); holder.prepend(img); }
    if (span) span.remove();
    img.src = URL.createObjectURL(file);
  }));

  // Bulk selection
  const bulkForm = $('#bulkForm');
  if (bulkForm) {
    const all = $('#checkAll');
    const rows = $$('.row-check', bulkForm);
    const action = $('#bulkAction');
    const apply = $('#bulkApply');
    const refresh = () => {
      const n = rows.filter((r) => r.checked).length;
      apply.disabled = !n || !action.value;
      apply.textContent = n ? `Apply to ${n}` : 'Apply';
      if (all) all.checked = n > 0 && n === rows.length;
    };
    if (all) all.addEventListener('change', () => { rows.forEach((r) => { r.checked = all.checked; }); refresh(); });
    rows.forEach((r) => r.addEventListener('change', refresh));
    action.addEventListener('change', refresh);
    bulkForm.addEventListener('submit', (e) => {
      const n = rows.filter((r) => r.checked).length;
      const label = action.options[action.selectedIndex].text;
      if (!confirm(action.value === 'delete' ? `Permanently delete ${n} participant(s)?` : `${label} for ${n} participant(s)?`)) e.preventDefault();
    });
    refresh();
  }

  // Copy to clipboard
  $$('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
    const input = $(btn.dataset.copy);
    try { await navigator.clipboard.writeText(input.value); } catch (_) { input.select(); document.execCommand('copy'); }
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    setTimeout(() => { btn.innerHTML = old; }, 1500);
  }));

  // Mark a message as read when opened
  const markForm = $('#markReadForm');
  $$('details.msg.unread').forEach((d) => d.addEventListener('toggle', () => {
    if (!d.open || !markForm || !d.classList.contains('unread')) return;
    const fd = new FormData(markForm);
    fd.set('id', d.dataset.id);
    fetch('messages.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(() => d.classList.remove('unread'));
  }));
})();
