/* Volunteer trainers → AI shortlist: scoring workers shared by both the advanced step UI and the one-click button */
(() => {
  const card = document.querySelector('#ai');
  if (!card) return;
  const csrf = card.dataset.csrf;

  const post = async (data) => {
    const fd = new FormData();
    fd.set('csrf', csrf);
    Object.entries(data).forEach(([k, v]) => fd.set(k, v));
    const res = await fetch('volunteers.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  };

  /* ---- Advanced (manual two-step) ---- */
  const run     = card.querySelector('.js-ai-run');
  const stopBtn = card.querySelector('.js-ai-stop');
  const status  = card.querySelector('.js-ai-status');
  const log     = card.querySelector('.js-ai-log');

  if (run && stopBtn && status) {
    let stopped = false;
    let running = false;
    const note = (text) => {
      const li = document.createElement('li');
      li.textContent = text;
      log.hidden = false;
      log.prepend(li);
    };
    stopBtn.addEventListener('click', () => { stopped = true; stopBtn.disabled = true; status.textContent = 'Stopping after the applications being read now…'; });
    run.addEventListener('click', async () => {
      if (running) return;
      running = true; run.disabled = true;
      const cancel = (text) => { status.textContent = text; running = false; run.disabled = false; };
      const rescore = card.querySelector('.js-ai-rescore')?.checked;
      let queue;
      try { queue = await post({ action: 'ai_queue', rescore: rescore ? '1' : '' }); } catch (_) { cancel('Could not start — check your connection and try again.'); return; }
      if (!queue.ok) { cancel(queue.error || 'Could not start.'); return; }
      const ids = queue.ids;
      const total = ids.length;
      if (!total) { cancel('Nothing left to score.'); return; }
      if (!confirm(`Score ${total} application${total === 1 ? '' : 's'} with AI? This uses your Anthropic account (roughly 5–10 US cents per application). You can stop at any time.`)) { cancel(''); return; }
      stopped = false; stopBtn.hidden = false; stopBtn.disabled = false;
      let done = 0, failed = 0;
      const show = () => { status.textContent = `${done} of ${total} done${failed ? ` · ${failed} not scored` : ''}…`; };
      show();
      const worker = async () => {
        while (!stopped && ids.length) {
          const id = ids.shift();
          let r;
          try { r = await post({ action: 'ai_score', id: String(id) }); } catch (_) { r = { ok: false, error: 'Network error — it will be tried again next time.', name: `Application ${id}` }; }
          done++;
          if (r.ok) {
            const s = card.querySelector('.js-ai-scored'); if (s && r.scored) s.textContent = r.scored.toLocaleString('en-US');
            const c = card.querySelector('.js-ai-cost');   if (c && r.cost)   c.textContent = r.cost;
          } else { failed++; note(`${r.name || 'An application'}: ${r.error || 'not scored'}`); if (r.fatal) stopped = true; }
          show();
        }
      };
      await Promise.all([worker(), worker(), worker()]);
      stopBtn.hidden = true; run.disabled = false;
      status.textContent = `${done - failed} scored${failed ? `, ${failed} not scored (press Score to try them again)` : ''}${ids.length ? `, ${ids.length} left` : ''}. Refreshing…`;
      setTimeout(() => { location.href = 'volunteers.php?sort=score#ai'; }, 1800);
    });
  }

  /* ---- One-click: Run AI Scoring & Generate Top 30 ---- */
  const runAllBtn = card.querySelector('.js-ai-run-all');
  const modal     = document.getElementById('aiRunModal');
  if (!runAllBtn || !modal) return;

  const $ = (s) => modal.querySelector(s);
  const confirmPane  = document.getElementById('aiRunConfirm');
  const progressPane = document.getElementById('aiRunProgress');
  const donePane     = document.getElementById('aiRunDone');

  const show = (pane) => {
    [confirmPane, progressPane, donePane].forEach(p => { if (p) p.hidden = p !== pane; });
    modal.hidden = false;
  };
  const hide = () => { modal.hidden = true; };

  modal.addEventListener('click', (e) => { if (e.target === modal) hide(); });
  modal.querySelectorAll('.js-ai-modal-cancel').forEach(b => b.addEventListener('click', hide));

  runAllBtn.addEventListener('click', () => show(confirmPane));

  const startBtn = modal.querySelector('.js-ai-modal-start');
  if (!startBtn) return;

  startBtn.addEventListener('click', async () => {
    startBtn.disabled = true;
    const rescore = card.querySelector('.js-ai-rescore-all')?.checked;
    let startData;
    try { startData = await post({ action: 'ai_run_start', rescore: rescore ? '1' : '' }); }
    catch (_) { alert('Could not start — check your connection and try again.'); startBtn.disabled = false; return; }
    if (!startData.ok) { alert(startData.error || 'Could not start.'); startBtn.disabled = false; return; }

    const ids     = startData.ids;
    const runId   = startData.run_id || 0;
    const total   = startData.total || ids.length;
    if (!ids.length) { alert('All applications have already been scored. Check "Score everyone again" to rescore.'); startBtn.disabled = false; return; }

    show(progressPane);
    const setT  = (s, v) => { const el = $(s); if (el) el.textContent = v; };
    const logEl = $('.js-run-log');
    const note  = (text) => { if (!logEl) return; const li = document.createElement('li'); li.textContent = text; logEl.hidden = false; logEl.prepend(li); };
    const setBar = (pct) => { const b = $('.js-run-bar'); if (b) b.style.width = pct + '%'; setT('.js-run-pct', pct + '%'); };

    setT('.js-run-total', total.toLocaleString('en-US'));
    let done = 0, ok = 0, failed = 0;
    let stopped = false;

    const stopBtn2 = $('.js-run-stop');
    if (stopBtn2) stopBtn2.addEventListener('click', () => { stopped = true; stopBtn2.disabled = true; }, { once: true });

    const updateCounts = () => {
      setT('.js-run-done', done);
      setT('.js-run-ok',   ok);
      setT('.js-run-fail', failed);
      setBar(total ? Math.round(done / total * 100) : 0);
      const s = card.querySelector('.js-ai-scored'); if (s) s.textContent = ok.toLocaleString('en-US');
    };

    const worker = async () => {
      while (!stopped && ids.length) {
        const id = ids.shift();
        setT('.js-run-current', `Assessing application ${id}…`);
        let r;
        try { r = await post({ action: 'ai_score', id: String(id) }); }
        catch (_) { r = { ok: false, error: 'Network error.', name: `Application ${id}` }; }
        done++;
        if (r.ok) {
          ok++;
          const c = card.querySelector('.js-ai-cost'); if (c && r.cost) c.textContent = r.cost;
        } else {
          failed++;
          note(`${r.name || 'Application ' + id}: ${r.error || 'not scored'}`);
          if (r.fatal) stopped = true;
        }
        updateCounts();
      }
    };

    await Promise.all([worker(), worker(), worker()]);

    setT('.js-run-current', stopped ? 'Stopped early. Running shortlist on scored applications…' : 'All done. Generating Top 30 shortlist…');

    // Auto-shortlist after scoring
    let finData;
    try { finData = await post({ action: 'ai_run_finish', run_id: String(runId), scored: String(ok), failed: String(failed) }); }
    catch (_) { finData = {}; }

    setT('.js-done-scored',      finData.all_scored ?? ok);
    setT('.js-done-shortlisted', finData.shortlisted ?? '—');
    setT('.js-done-flagged',     finData.flagged ?? '—');
    setT('.js-done-failed',      failed || 0);

    show(donePane);
    startBtn.disabled = false;
    // Update the main KPIs on the page behind the modal
    const bar = document.querySelector('.js-ai-bar');
    if (bar) bar.style.width = (finData.all_scored && total ? Math.round(finData.all_scored / total * 100) : 0) + '%';
  });
})();
