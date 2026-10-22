/* "I will be there" flyers: copy the X post (and download the flyer), and keep track of who has been posted */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const csrf = ($('input[name="csrf"]') || {}).value || '';

  // X counts most emojis and some symbols as 2 characters
  const xLength = (t) => Array.from(t).reduce((n, ch) => n + (ch.codePointAt(0) > 0x2FFF ? 2 : 1), 0);

  const copy = async (text, area) => {
    try { await navigator.clipboard.writeText(text); return true; } catch (_) {
      area.removeAttribute('readonly'); area.select(); const ok = document.execCommand('copy'); area.setAttribute('readonly', ''); return ok;
    }
  };

  const mark = async (card, posted) => {
    const fd = new FormData();
    fd.set('csrf', csrf); fd.set('action', 'x_mark'); fd.set('id', card.dataset.id); fd.set('ajax', '1');
    if (posted) fd.set('posted', '1');
    try {
      const res = await fetch('flyers.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!json.ok) return;
      card.classList.toggle('is-posted', json.posted);
      const who = $('.js-xwho', card);
      if (who) who.textContent = json.posted ? ` by ${json.by} · just now` : '';
      $$('.js-x-done').forEach((el) => { el.textContent = json.done.toLocaleString('en-US'); });
      $$('.js-x-left').forEach((el) => { el.textContent = json.left.toLocaleString('en-US'); });
    } catch (_) { /* the form below still works without JavaScript */ }
  };

  $$('.js-xpost').forEach((card) => {
    const area = $('.js-xtext', card);
    const count = $('.js-xcount', card);
    const n = xLength(area.value);
    count.textContent = `${n} / 280`;
    count.classList.toggle('over', n > 280);

    $$('.js-xcopy', card).forEach((btn) => btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const ok = await copy(area.value, area);
      if (btn.dataset.download) {
        const a = document.createElement('a');
        a.href = btn.getAttribute('href'); a.download = ''; document.body.appendChild(a); a.click(); a.remove();
      }
      const old = btn.innerHTML;
      btn.innerHTML = ok ? '<i class="fa-solid fa-check"></i> Copied' + (btn.dataset.download ? ' — paste it on X' : '') : 'Copy failed — select the text';
      setTimeout(() => { btn.innerHTML = old; }, 2200);
      if (ok) mark(card, true);
    }));

    const form = $('.js-xmark', card);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      mark(card, (e.submitter || {}).value === '1');
    });
  });
})();
