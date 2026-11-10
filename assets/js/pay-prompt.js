/*
 * Payment reminder pop-up — shown once on every visit (each new browser session).
 * A participant this device knows goes straight to their own payment page; anyone else is asked to log in and pay.
 * What to say comes from api/pay-prompt.php, so the page itself stays the same for everyone.
 */
(() => {
  const script = document.currentScript;
  const api = (script && script.dataset.api) || 'api/pay-prompt.php';
  const SEEN = 'ktc_pay_prompt_seen';
  try { if (sessionStorage.getItem(SEEN)) return; } catch (_) { /* private mode: show it */ }
  // Someone registering or sponsoring (from a link, the emailed "continue" link or Google sign-in) — don't get in the way
  const busy = () => /^#(register|regForm|sponsor|donate)/i.test(location.hash) || (document.activeElement && document.activeElement.closest && document.activeElement.closest('#regForm, form.js-donate, .js-donate-form'));
  if (busy()) return;

  const track = (name, params) => { try { window.ktTrack && window.ktTrack(name, params || {}); } catch (_) { /* analytics is optional */ } };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const CSS = `
.ktp-back{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:16px;background:rgba(10,26,63,.55);backdrop-filter:blur(3px);animation:ktpFade .25s ease}
.ktp{position:relative;width:100%;max-width:440px;max-height:calc(100vh - 32px);overflow:auto;background:#fff;border-radius:22px;padding:30px 26px 20px;box-shadow:0 30px 80px rgba(10,26,63,.35);font-family:inherit;color:#1B2340;text-align:center;animation:ktpUp .3s cubic-bezier(.2,.9,.3,1.2)}
.ktp-x{position:absolute;top:12px;right:12px;width:36px;height:36px;border:0;border-radius:50%;background:#F2F4F9;color:#4A5372;font-size:22px;line-height:1;cursor:pointer}
.ktp-x:hover{background:#E4E8F1}
.ktp-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;background:#FFF2F2;color:#C4121E;font-weight:800;font-size:13px;letter-spacing:.01em}
.ktp h2{margin:14px 0 8px;font-size:24px;line-height:1.25;color:#0F2557;font-weight:800}
.ktp p{margin:0 0 14px;font-size:15px;line-height:1.6;color:#4A5372}
.ktp-amount{margin:4px 0 16px;padding:14px 16px;border-radius:16px;background:linear-gradient(135deg,#0F2557,#1F4FD1);color:#fff}
.ktp-amount span{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.08em;opacity:.8}
.ktp-amount b{display:block;font-size:28px;font-weight:800;margin:2px 0}
.ktp-amount small{font-size:13px;opacity:.9}
.ktp-deadline{margin:-6px 0 14px!important;font-weight:700;color:#B54708!important}
.ktp-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:15px 18px;margin:0 0 10px;border-radius:14px;font-weight:800;font-size:16px;text-decoration:none;border:0;cursor:pointer;box-sizing:border-box}
.ktp-main{background:#E11D2A;color:#fff;box-shadow:0 10px 24px rgba(225,29,42,.3)}
.ktp-main:hover{background:#C4121E;color:#fff}
.ktp-alt{background:#F2F4F9;color:#0F2557}
.ktp-alt:hover{background:#E4E8F1;color:#0F2557}
.ktp-ways{margin:2px 0 10px;font-size:12.5px;color:#7A829E}
.ktp-foot{display:flex;flex-wrap:wrap;justify-content:center;gap:6px 16px;margin-top:4px;font-size:13.5px}
.ktp-foot a,.ktp-foot button{background:none;border:0;padding:4px;color:#7A829E;font:inherit;cursor:pointer;text-decoration:underline}
.ktp-foot .ktp-reg{color:#E11D2A;font-weight:700}
@keyframes ktpFade{from{opacity:0}}
@keyframes ktpUp{from{opacity:0;transform:translateY(24px) scale(.97)}}
@media (max-width:520px){.ktp-back{place-items:end center;padding:0}.ktp{border-radius:22px 22px 0 0;max-height:92vh;padding:24px 18px 14px}.ktp h2{font-size:21px;margin:10px 0 6px}.ktp p{font-size:14.5px;margin-bottom:10px}.ktp-amount{padding:10px 14px;margin-bottom:12px}.ktp-amount b{font-size:24px}.ktp-btn{padding:13px 16px}}
@media (max-height:640px){.ktp-ways{display:none}}
@media (prefers-reduced-motion:reduce){.ktp-back,.ktp{animation:none}}`;

  const view = (d) => {
    const days = d.days_left === 0 ? 'Camp starts today' : d.days_left === 1 ? '1 day to camp' : `${d.days_left} days to camp`;
    const badge = `<div class="ktp-badge">⏳ ${esc(days)} · ${esc(d.dates)}</div>`;
    const ways = '<p class="ktp-ways">MTN or Airtel Mobile Money · Visa / Mastercard</p>';
    if (d.mode === 'pay') {
      const lead = d.part_paid
        ? 'Thank you for paying part of your camp package! Clear your balance to get your camp ticket — time is running out.'
        : 'Places at Kakebe Tech Camp 2026 are confirmed on payment, and time is running out. Pay now to secure your place in Kitgum.';
      const deadline = d.deadline ? `<p class="ktp-deadline">${d.overdue ? `Your payment date (${esc(d.deadline)}) has passed — pay today to keep your place.` : `Please pay by ${esc(d.deadline)}.`}</p>` : '';
      return `${badge}
        <h2 id="ktpTitle">Hi ${esc(d.name)}, ready to make your payment?</h2>
        <p>${lead}</p>
        <div class="ktp-amount"><span>${d.part_paid ? 'Balance to pay' : 'Your camp package'}</span><b>${esc(d.balance)}</b>${d.half ? '<small>pay in full, or 50% to start</small>' : ''}</div>
        ${deadline}
        <a class="ktp-btn ktp-main" href="${esc(d.pay_url)}" data-ktp="pay">Yes, pay now →</a>
        ${d.half_url ? `<a class="ktp-btn ktp-alt" href="${esc(d.half_url)}" data-ktp="pay_half">Pay 50% now (${esc(d.half)})</a>` : ''}
        ${ways}
        <div class="ktp-foot"><button type="button" data-ktp="later">Not now</button>${d.remembered ? `<button type="button" data-ktp="forget">Not ${esc(d.name)}?</button>` : ''}</div>`;
    }
    return `${badge}
      <h2 id="ktpTitle">Ready to make your camp payment?</h2>
      <p>Places at Kakebe Tech Camp 2026 are confirmed on payment, and time is running out. Already registered? Log in and pay now to secure your place.</p>
      <a class="ktp-btn ktp-main" href="${esc(d.login_url)}" data-ktp="login">Yes — log in &amp; pay →</a>
      <a class="ktp-btn ktp-alt" href="${esc(d.find_url)}" data-ktp="find">Find my registration with email &amp; phone</a>
      ${ways}
      <div class="ktp-foot"><a class="ktp-reg" href="${esc(d.register_url)}" data-ktp="register">Not registered yet? Register now</a><button type="button" data-ktp="later">Not now</button></div>`;
  };

  const open = (d) => {
    if (!document.getElementById('ktpStyle')) {
      const style = document.createElement('style');
      style.id = 'ktpStyle';
      style.textContent = CSS;
      document.head.appendChild(style);
    }
    const back = document.createElement('div');
    back.className = 'ktp-back';
    back.innerHTML = `<div class="ktp" role="dialog" aria-modal="true" aria-labelledby="ktpTitle"><button type="button" class="ktp-x" aria-label="Close">×</button><div class="ktp-body">${view(d)}</div></div>`;
    const lastFocus = document.activeElement;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.body.appendChild(back);
    track('pay_popup_shown', { mode: d.mode });

    const close = () => {
      back.remove();
      document.body.style.overflow = overflow;
      document.removeEventListener('keydown', onKey);
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      const items = [...back.querySelectorAll('a[href], button')].filter((el) => el.offsetParent !== null);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !back.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || !back.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    back.addEventListener('click', async (e) => {
      if (e.target === back || e.target.closest('.ktp-x')) { close(); return; }
      const el = e.target.closest('[data-ktp]');
      if (!el) return;
      const action = el.dataset.ktp;
      track('pay_popup_click', { mode: d.mode, action });
      if (action === 'later') { close(); return; }
      if (action === 'register') { close(); return; }   // the link scrolls to the form
      if (action === 'forget') {
        e.preventDefault();
        try {
          await fetch(api, { method: 'POST', credentials: 'same-origin' });
          const res = await fetch(api, { credentials: 'same-origin' });
          const next = await res.json();
          if (next && next.show) {
            back.querySelector('.ktp-body').innerHTML = view(next);
            d = next;
            const m = back.querySelector('.ktp-main');
            if (m) m.focus({ preventScroll: true });
          } else close();
        } catch (_) { close(); }
      }
    });
    const main = back.querySelector('.ktp-main');
    if (main) main.focus({ preventScroll: true });
  };

  setTimeout(async () => {
    let d;
    try {
      const res = await fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      d = await res.json();
    } catch (_) { return; }
    if (!d || !d.show || busy()) return;
    try { sessionStorage.setItem(SEEN, '1'); } catch (_) { /* fine */ }
    open(d);
  }, 2000);
})();
