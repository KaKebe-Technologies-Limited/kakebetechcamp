/* Volunteer trainers → AI shortlist: score the applications one by one (3 at a time), with progress and a stop button */
(() => {
  const card = document.querySelector('#ai');
  const run = card && card.querySelector('.js-ai-run');
  if (!run) return;
  const $ = (s) => card.querySelector(s);
  const stopBtn = $('.js-ai-stop');
  const status = $('.js-ai-status');
  const log = $('.js-ai-log');
  let stopped = false;
  let running = false;

  const post = async (data) => {
    const fd = new FormData();
    fd.set('csrf', card.dataset.csrf);
    Object.entries(data).forEach(([k, v]) => fd.set(k, v));
    const res = await fetch('volunteers.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  };
  const note = (text) => {
    const li = document.createElement('li');
    li.textContent = text;
    log.hidden = false;
    log.prepend(li);
  };

  stopBtn.addEventListener('click', () => { stopped = true; stopBtn.disabled = true; status.textContent = 'Stopping after the applications being read now…'; });

  run.addEventListener('click', async () => {
    if (running) return;   // a double click must not start a second run (it would pay twice)
    running = true;
    run.disabled = true;
    const cancel = (text) => { status.textContent = text; running = false; run.disabled = false; };
    const rescore = $('.js-ai-rescore').checked;
    let queue;
    try { queue = await post({ action: 'ai_queue', rescore: rescore ? '1' : '' }); } catch (_) { cancel('Could not start — check your connection and try again.'); return; }
    if (!queue.ok) { cancel(queue.error || 'Could not start.'); return; }
    const ids = queue.ids;
    const total = ids.length;
    if (!total) { cancel('Nothing left to score.'); return; }
    if (!confirm(`Score ${total} application${total === 1 ? '' : 's'} with AI? This uses your Anthropic account (roughly 5–10 US cents per application). You can stop at any time.`)) { cancel(''); return; }

    stopped = false;
    stopBtn.hidden = false;
    stopBtn.disabled = false;
    let done = 0;
    let failed = 0;
    const show = () => { status.textContent = `${done} of ${total} done${failed ? ` · ${failed} not scored` : ''}…`; };
    show();

    const worker = async () => {
      while (!stopped && ids.length) {
        const id = ids.shift();
        let r;
        try { r = await post({ action: 'ai_score', id: String(id) }); } catch (_) { r = { ok: false, error: 'Network error — it will be tried again next time.', name: `Application ${id}` }; }
        done++;
        if (r.ok) {
          const s = card.querySelector('.js-ai-scored');
          if (s && r.scored) s.textContent = r.scored.toLocaleString('en-US');
          const c = card.querySelector('.js-ai-cost');
          if (c && r.cost) c.textContent = r.cost;
        } else {
          failed++;
          note(`${r.name || 'An application'}: ${r.error || 'not scored'}`);
          if (r.fatal) stopped = true;
        }
        show();
      }
    };
    await Promise.all([worker(), worker(), worker()]);

    stopBtn.hidden = true;
    run.disabled = false;
    status.textContent = `${done - failed} scored${failed ? `, ${failed} not scored (press Score to try them again)` : ''}${ids.length ? `, ${ids.length} left` : ''}. Refreshing…`;
    setTimeout(() => { location.href = 'volunteers.php?sort=score#ai'; }, 1800);
  });
})();
