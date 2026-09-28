/* Kakebe Tech Camp — payments (checkout, portal and sponsorship forms) */
(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const money = (n, cur = 'UGX') => cur + ' ' + Number(n || 0).toLocaleString('en-US');
  const digits = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function setAlert(form, msg, ok) {
    const el = $('.form-alert', form);
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || '';
    el.classList.toggle('ok', !!ok);
  }
  function clearErrors(form) {
    $$('.field.invalid', form).forEach((f) => f.classList.remove('invalid'));
    $$('.err', form).forEach((e) => { e.textContent = ''; });
  }
  function showErrors(form, errors) {
    let first = null;
    Object.entries(errors || {}).forEach(([k, msg]) => {
      const holder = $(`[data-err="${k}"]`, form);
      if (!holder) return;
      holder.textContent = msg;
      const f = holder.closest('.field');
      if (f) f.classList.add('invalid');
      first = first || f;
    });
    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  async function post(url, fd) {
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
    try { return await res.json(); } catch (_) { throw new Error('Unexpected server response. Please try again.'); }
  }

  /* ---------- Pay forms ---------- */
  $$('.js-pay-form').forEach((form) => {
    const amount = $('input[name="amount"]', form);
    const btnAmt = $('.js-amt', form);
    const btn = $('.js-pay-btn', form);
    const body = $('.pay-body', form) || form;
    const state = $('.pay-state', form);
    const statusUrl = form.dataset.status;
    const isDonation = form.dataset.kind === 'donation';

    const syncLabel = () => { if (btnAmt && amount) btnAmt.textContent = money(digits(amount.value)); };
    if (amount) {
      amount.addEventListener('input', () => {
        const n = digits(amount.value);
        amount.value = n ? n.toLocaleString('en-US') : '';
        $$('.chip-amt', form).forEach((c) => c.classList.toggle('active', digits(c.dataset.amount) === n));
        syncLabel();
      });
    }
    $$('.chip-amt', form).forEach((chip) => chip.addEventListener('click', () => {
      if (!amount) return;
      amount.value = digits(chip.dataset.amount).toLocaleString('en-US');
      amount.dispatchEvent(new Event('input'));
    }));

    const methodSync = () => {
      const m = ($('input[name="method"]:checked', form) || {}).value;
      $$('.js-mm', form).forEach((el) => { el.hidden = m !== 'mobile_money'; });
      $$('.js-card', form).forEach((el) => { el.hidden = m !== 'card'; });
    };
    $$('input[name="method"]', form).forEach((r) => r.addEventListener('change', methodSync));
    methodSync();

    const showState = (html) => { body.hidden = true; state.hidden = false; state.innerHTML = html; state.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
    const restore = () => { state.hidden = true; state.innerHTML = ''; body.hidden = false; };

    const successHtml = (p) => {
      const bal = p.balance !== undefined
        ? `<div class="ps-grid"><div><small>Paid now</small><b>${money(p.amount, p.currency)}</b></div><div><small>Total paid</small><b>${money(p.paid, p.currency)}</b></div><div><small>Balance</small><b class="${p.balance ? 'due' : 'ok'}">${money(p.balance, p.currency)}</b></div></div>
           <p class="ps-status"><i class="fa-solid fa-circle-check"></i> ${esc(p.booking)}</p>`
        : `<p class="big">${money(p.amount, p.currency)}</p>`;
      return `<div class="ps ok"><span class="ps-icon"><i class="fa-solid fa-check"></i></span>
        <h3>${isDonation ? 'Thank you for your support! ❤️' : 'Payment received! 🎉'}</h3>
        ${bal}
        <p class="muted">A PDF receipt (${esc(p.receipt_no)}) has been emailed to you.</p>
        <div class="btn-row">${p.receipt ? `<a class="btn btn-primary" href="${esc(p.receipt)}" target="_blank"><i class="fa-solid fa-file-pdf"></i> Download receipt</a>` : ''}
        <button type="button" class="btn btn-ghost js-done">${isDonation ? 'Close' : 'Done'}</button></div></div>`;
    };
    const item = { item_name: isDonation ? 'Sponsor an innovator' : 'Camp package' };
    const paid = (p) => {
      window.ktTrack && window.ktTrack('purchase', { transaction_id: p.receipt_no, value: p.amount, currency: p.currency, items: [item] });
      return successHtml(p);
    };
    const failedHtml = (msg) => `<div class="ps bad"><span class="ps-icon"><i class="fa-solid fa-xmark"></i></span>
        <h3>Payment not completed</h3><p class="muted">${esc(msg || 'The payment was declined or cancelled. No money was taken.')}</p>
        <div class="btn-row"><button type="button" class="btn btn-primary js-retry">Try again</button></div></div>`;
    const waitingHtml = (p) => `<div class="ps wait"><span class="ps-icon"><span class="spinner dark"></span></span>
        <h3>Check your phone 📱</h3>
        <p>We've sent a payment request of <b>${money(p.amount, p.currency)}</b>. Enter your Mobile Money PIN to approve it.</p>
        <p class="muted small js-wait-note">Waiting for confirmation…</p>
        <div class="btn-row"><button type="button" class="btn btn-ghost js-cancel">Cancel</button></div></div>`;

    state && state.addEventListener('click', (e) => {
      if (e.target.closest('.js-retry') || e.target.closest('.js-cancel')) { polling = false; restore(); }
      if (e.target.closest('.js-done')) {
        if (isDonation) { form.reset(); restore(); } else { location.reload(); }
      }
    });

    let polling = false;
    const poll = async (id, token) => {
      polling = true;
      const started = Date.now();
      let tries = 0;
      while (polling && Date.now() - started < 4 * 60 * 1000) {
        await new Promise((r) => setTimeout(r, tries < 5 ? 3000 : 5000));
        if (!polling) return;
        tries++;
        try {
          const res = await fetch(`${statusUrl}?id=${id}&t=${token}`, { credentials: 'same-origin' });
          const json = await res.json();
          if (!json.ok) continue;
          const p = json.payment;
          if (p.status === 'success') { polling = false; showState(paid(p)); return; }
          if (p.status === 'failed') { polling = false; showState(failedHtml(p.message)); return; }
          const note = $('.js-wait-note', state);
          if (note && tries > 8) note.textContent = 'Still waiting… if you did not get a prompt, cancel and try again.';
        } catch (_) { /* network blip — keep polling */ }
      }
      if (polling) { polling = false; showState(failedHtml('We did not receive a confirmation in time. If you approved the payment, it will still be recorded and your receipt emailed — otherwise please try again.')); }
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      setAlert(form, '');
      if (amount) {
        const n = digits(amount.value), min = digits(amount.dataset.min), max = digits(amount.dataset.max);
        if (min && max && (n < min || n > max)) { showErrors(form, { amount: `Enter an amount between ${money(min)} and ${money(max)}.` }); return; }
      }
      btn.classList.add('loading');
      if (amount) window.ktTrack && window.ktTrack('begin_checkout', { value: digits(amount.value), currency: 'UGX', items: [item] });
      try {
        const fd = new FormData(form);
        if (amount) fd.set('amount', String(digits(amount.value)));
        const json = await post(form.action, fd);
        if (!json.ok) {
          if (json.errors) showErrors(form, json.errors);
          setAlert(form, json.message || 'Please check the form and try again.');
          return;
        }
        if (json.redirect) { location.href = json.redirect; return; }
        const p = json.payment;
        if (p.status === 'success') { showState(paid(p)); return; }
        if (p.status === 'failed') { showState(failedHtml(p.message)); return; }
        showState(waitingHtml(p));
        poll(p.id, json.token);
      } catch (err) {
        setAlert(form, err.message || 'Network error — please try again.');
      } finally {
        btn.classList.remove('loading');
      }
    });
  });

  /* ---------- Sponsor amount helpers ---------- */
  $$('.js-sponsor').forEach((form) => {
    const per = digits(form.dataset.perChild);
    const kids = $('select[name="children"]', form);
    const amount = $('input[name="amount"]', form);
    const wrap = $('.js-custom-amount', form);
    const label = $('.js-amt', form);
    const sync = () => {
      const k = parseInt(kids.value, 10) || 0;
      wrap.hidden = k > 0;
      if (k > 0) amount.value = (k * per).toLocaleString('en-US');
      if (label) label.textContent = money(digits(amount.value));
    };
    kids.addEventListener('change', sync);
    amount.addEventListener('input', () => { const n = digits(amount.value); amount.value = n ? n.toLocaleString('en-US') : ''; if (label) label.textContent = money(n); });
    sync();
  });

  /* ---------- "Pay later" lookup ---------- */
  $$('.js-lookup').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      setAlert(form, '');
      const btn = $('button[type="submit"]', form);
      btn.classList.add('loading');
      try {
        const json = await post(form.action, new FormData(form));
        if (json.ok && json.redirect) { location.href = json.redirect; return; }
        setAlert(form, json.message || 'Not found.');
      } catch (err) {
        setAlert(form, err.message);
      } finally {
        btn.classList.remove('loading');
      }
    });
  });
})();
