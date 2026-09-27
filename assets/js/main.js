/* Kakebe Tech Camp 2026 — front-end interactions */
(function () {
  'use strict';

  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
  const body = document.body;
  const money = (n) => 'UGX ' + Number(n || 0).toLocaleString('en-US');

  /* ---------- Header, mobile menu, active links ---------- */
  const header = $('#header');
  const toTop = $('#toTop');
  const onScroll = () => {
    const y = window.scrollY;
    header.classList.toggle('scrolled', y > 30);
    toTop.classList.toggle('show', y > 700);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  const navToggle = $('#navToggle');
  const setNav = (open) => {
    body.classList.toggle('nav-open', open);
    navToggle.setAttribute('aria-expanded', String(open));
    navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  };
  navToggle.addEventListener('click', () => setNav(!body.classList.contains('nav-open')));
  $$('#mobileMenu a').forEach((a) => a.addEventListener('click', () => setNav(false)));
  document.addEventListener('click', (e) => {
    if (body.classList.contains('nav-open') && !e.target.closest('#mobileMenu') && !e.target.closest('#navToggle')) setNav(false);
  });

  const navLinks = $$('a[data-nav]');
  const sectionIds = [...new Set(navLinks.map((a) => a.getAttribute('href')))];
  if ('IntersectionObserver' in window) {
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        navLinks.forEach((a) => a.classList.toggle('active', a.getAttribute('href') === '#' + entry.target.id));
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sectionIds.map((id) => $(id)).filter(Boolean).forEach((s) => spy.observe(s));
  }

  /* ---------- Reveal on scroll + counters ---------- */
  const reveals = $$('.reveal').filter((el) => !el.closest('.hero'));
  const animateCount = (el) => {
    const target = parseInt(el.dataset.count, 10) || 0;
    const suffix = el.dataset.suffix || '';
    const start = performance.now();
    const step = (t) => {
      const p = Math.min(1, (t - start) / 1600);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString() + suffix;
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      let i = 0;
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const el = entry.target;
        setTimeout(() => el.classList.add('in'), i++ * 90);
        io.unobserve(el);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach((el) => io.observe(el));
    const co = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        animateCount(entry.target);
        co.unobserve(entry.target);
      });
    }, { threshold: 0.6 });
    $$('[data-count]').forEach((el) => co.observe(el));
  } else {
    reveals.forEach((el) => el.classList.add('in'));
  }

  /* ---------- Countdown ---------- */
  const cd = $('#countdown');
  if (cd) {
    const target = new Date(cd.dataset.target).getTime();
    const parts = { d: $('[data-cd="d"]', cd), h: $('[data-cd="h"]', cd), m: $('[data-cd="m"]', cd), s: $('[data-cd="s"]', cd) };
    const pad = (n) => String(n).padStart(2, '0');
    let timer;
    const tick = () => {
      let diff = Math.max(0, target - Date.now());
      if (diff === 0) {
        cd.classList.add('done');
        $('.cd-label', cd).innerHTML = '<i class="fa-solid fa-campground"></i> Tech Camp 2026 is live!';
        return clearInterval(timer);
      }
      const d = Math.floor(diff / 864e5); diff -= d * 864e5;
      const h = Math.floor(diff / 36e5); diff -= h * 36e5;
      const m = Math.floor(diff / 6e4); diff -= m * 6e4;
      parts.d.textContent = d; parts.h.textContent = pad(h); parts.m.textContent = pad(m); parts.s.textContent = pad(Math.floor(diff / 1e3));
    };
    timer = setInterval(tick, 1000);
    tick();
  }

  /* ---------- Toast ---------- */
  const toastEl = $('#toast');
  let toastTimer;
  const toast = (msg) => {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3800);
  };

  /* ---------- Program details modal ---------- */
  const modal = $('#programModal');
  const data = JSON.parse(($('#programData') || {}).textContent || '{}');
  let lastFocus = null;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const openModal = (key) => {
    const p = data[key];
    if (!p) return;
    lastFocus = document.activeElement;
    $('#pmIcon').className = 'fa-solid ' + (p.icon || 'fa-campground');
    $('#pmBadge').textContent = p.badge;
    $('#pmTitle').textContent = p.title;
    $('#pmDesc').textContent = p.desc;
    $('#pmOutput').textContent = p.output;
    $('#pmFacts').innerHTML = p.facts.map(([icon, label, value]) => `<div class="pm-fact"><i class="fa-solid ${esc(icon)}"></i><small>${esc(label)}</small><span>${esc(value)}</span></div>`).join('');
    $('#pmExpect').innerHTML = p.expect.map((x) => `<li><i class="fa-solid fa-circle-check"></i><span>${esc(x)}</span></li>`).join('');
    modal.hidden = false;
    body.style.overflow = 'hidden';
    $('.modal-close', modal).focus();
  };
  const closeModal = () => {
    modal.hidden = true;
    body.style.overflow = '';
    if (lastFocus) lastFocus.focus();
  };
  $$('.eco-card, .arch-card').forEach((c) => c.addEventListener('click', () => openModal(c.dataset.program)));
  $$('[data-close]', modal).forEach((el) => el.addEventListener('click', closeModal));
  $('#pmApply').addEventListener('click', closeModal);

  /* ---------- Gallery lightbox ---------- */
  const lb = $('#lightbox');
  const items = $$('.g-item');
  let lbIndex = 0;
  const showLb = (i) => {
    lbIndex = (i + items.length) % items.length;
    const it = items[lbIndex];
    $('#lbImg').src = it.dataset.full;
    $('#lbImg').alt = it.dataset.caption;
    $('#lbCap').textContent = `${it.dataset.caption}  ·  ${lbIndex + 1} / ${items.length}`;
  };
  items.forEach((it, i) => it.addEventListener('click', () => {
    lastFocus = it;
    showLb(i);
    lb.hidden = false;
    body.style.overflow = 'hidden';
    $('.lb-close', lb).focus();
  }));
  const closeLb = () => { lb.hidden = true; body.style.overflow = ''; if (lastFocus) lastFocus.focus(); };
  lb.addEventListener('click', (e) => {
    const action = e.target.closest('[data-lb]')?.dataset.lb;
    if (action === 'close' || e.target === lb) closeLb();
    if (action === 'prev') showLb(lbIndex - 1);
    if (action === 'next') showLb(lbIndex + 1);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (!modal.hidden) closeModal();
      if (!lb.hidden) closeLb();
      if (body.classList.contains('nav-open')) setNav(false);
    }
    if (!lb.hidden && e.key === 'ArrowLeft') showLb(lbIndex - 1);
    if (!lb.hidden && e.key === 'ArrowRight') showLb(lbIndex + 1);
  });

  /* ---------- FAQ accordion ---------- */
  $$('.acc-btn').forEach((btn) => btn.addEventListener('click', () => {
    const item = btn.closest('.acc-item');
    const open = !item.classList.contains('open');
    $$('.acc-item.open').forEach((o) => { if (o !== item) { o.classList.remove('open'); $('.acc-btn', o).setAttribute('aria-expanded', 'false'); } });
    item.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', String(open));
  }));

  /* ---------- Form helpers ---------- */
  const clearErrors = (form) => {
    $$('.field.invalid', form).forEach((f) => f.classList.remove('invalid'));
    $$('.err', form).forEach((e) => { e.textContent = ''; });
  };
  const showErrors = (form, errors) => {
    let first = null;
    Object.entries(errors).forEach(([name, msg]) => {
      const holder = $(`[data-err="${name}"]`, form);
      if (!holder) return;
      holder.textContent = msg;
      const field = holder.closest('.field');
      if (field) field.classList.add('invalid');
      if (!first) first = field || holder;
    });
    if (first) {
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const input = $('input:not([type=hidden]), select, textarea', first);
      if (input) setTimeout(() => input.focus({ preventScroll: true }), 350);
    }
  };
  const setAlert = (el, msg, ok = false) => {
    if (!msg) { el.hidden = true; return; }
    el.textContent = msg;
    el.classList.toggle('ok', ok);
    el.hidden = false;
  };
  const postForm = async (url, formData) => {
    const res = await fetch(url, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
    try { return await res.json(); } catch (_) { throw new Error('Unexpected server response. Please try again.'); }
  };
  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const clearFieldOnEdit = (form) => {
    const handler = (e) => {
      const field = e.target.closest('.field');
      if (field && field.classList.contains('invalid')) { field.classList.remove('invalid'); const err = $('.err', field); if (err) err.textContent = ''; }
    };
    form.addEventListener('input', handler);
    form.addEventListener('change', handler);
  };

  /* ---------- Registration ---------- */
  const form = $('#regForm');
  if (form) {
    clearFieldOnEdit(form);
    const summary = $('#orderSummary');
    const fees = { camp: +summary.dataset.camp, jersey: +summary.dataset.jersey, park: +summary.dataset.park, pct: +summary.dataset.pct };
    const park = $('#f_park');
    const updateTotals = () => {
      const total = fees.camp + fees.jersey + (park.checked ? fees.park : 0);
      $$('.js-total').forEach((el) => { el.textContent = money(total); });
      $$('.js-deposit').forEach((el) => { el.textContent = money(Math.ceil(total * fees.pct / 100)); });
    };
    park.addEventListener('change', updateTotals);
    updateTotals();

    const trackInputs = $$('#trackPick input');
    const trackHint = $('#trackHint');
    const syncTracks = () => {
      const n = trackInputs.filter((i) => i.checked).length;
      trackInputs.forEach((i) => { i.disabled = !i.checked && n >= 2; });
      trackHint.textContent = n >= 2 ? '2 of 2 selected — untick one to change' : `${n} of 2 selected`;
    };
    trackInputs.forEach((i) => i.addEventListener('change', syncTracks));
    syncTracks();

    /* Funding: self or sponsored */
    const sponsorPick = $('#sponsorPick');
    const sponsorSelect = $('#f_sponsor');
    const sponsorOtherWrap = $('#sponsorOtherWrap');
    const isSponsoredChoice = () => (form.querySelector('input[name="funding"]:checked') || {}).value === 'sponsored';
    const syncFunding = () => {
      sponsorPick.hidden = !isSponsoredChoice();
      sponsorOtherWrap.hidden = !(isSponsoredChoice() && sponsorSelect.value === 'other');
    };
    $$('input[name="funding"]', form).forEach((r) => r.addEventListener('change', syncFunding));
    sponsorSelect.addEventListener('change', syncFunding);
    syncFunding();

    const source = $('#f_source');
    const otherWrap = $('#sourceOtherWrap');
    source.addEventListener('change', () => {
      const other = source.value === 'Other';
      otherWrap.classList.toggle('is-hidden', !other);
      $('#f_source_other').required = other;
    });

    const photo = $('#f_photo');
    const preview = $('#photoPreview');
    const photoName = $('#photoName');
    photo.addEventListener('change', () => {
      const file = photo.files[0];
      preview.innerHTML = '<i class="fa-regular fa-image"></i>';
      photoName.textContent = 'Click to upload a photo';
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { showErrors(form, { photo: 'Please choose a JPG, PNG or WEBP image.' }); photo.value = ''; return; }
      if (file.size > 3 * 1024 * 1024) { showErrors(form, { photo: 'Photo is too large — maximum size is 3 MB.' }); photo.value = ''; return; }
      const img = document.createElement('img');
      img.alt = 'Selected photo preview';
      img.src = URL.createObjectURL(file);
      preview.innerHTML = '';
      preview.appendChild(img);
      photoName.textContent = file.name;
    });

    const validate = () => {
      const f = new FormData(form);
      const val = (k) => String(f.get(k) || '').trim();
      const errors = {};
      if (val('full_name').length < 3) errors.full_name = 'Please enter your full name.';
      const age = parseInt(val('age'), 10);
      if (!age) errors.age = 'Please enter your age.';
      else if (age < 14 || age > 30) errors.age = 'Tech Camp is open to ages 14 – 30.';
      if (!emailRe.test(val('email'))) errors.email = 'Please enter a valid email address.';
      const digits = val('phone').replace(/\D/g, '');
      if (digits.length < 9 || digits.length > 15) errors.phone = 'Please enter a valid phone number.';
      if (val('district').length < 2) errors.district = 'Please enter your district of origin.';
      if (val('country').length < 2) errors.country = 'Please enter your country.';
      const tracks = f.getAll('interests[]').length;
      if (!tracks) errors.interests = 'Choose at least one learning track.';
      if (!val('jersey_size')) errors.jersey_size = 'Please choose your jersey size.';
      if (isSponsoredChoice()) {
        if (!val('sponsor_id')) errors.sponsor_id = 'Please choose who is sponsoring you.';
        else if (val('sponsor_id') === 'other' && val('sponsor_other').length < 2) errors.sponsor_other = 'Please enter your sponsor\'s name.';
      }
      if (!val('source')) errors.source = 'Please tell us how you heard about the program.';
      if (val('source') === 'Other' && val('source_other').length < 2) errors.source_other = 'Please specify where you heard about us.';
      if (!f.get('consent')) errors.consent = 'Please confirm to continue.';
      return errors;
    };

    const alertEl = $('#formAlert');
    const submit = $('#regSubmit');
    const success = $('#regSuccess');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      setAlert(alertEl, '');
      const errors = validate();
      if (Object.keys(errors).length) { showErrors(form, errors); setAlert(alertEl, 'Please correct the highlighted fields.'); return; }
      submit.classList.add('loading');
      try {
        const fd = new FormData(form);
        fd.delete('interests[]');
        $$('#trackPick input:checked').forEach((i) => fd.append('interests[]', i.value));
        const json = await postForm('api/register.php', fd);
        if (json.ok) {
          $('#successName').textContent = json.name || '';
          $('#successEmail').textContent = json.email || '';
          $('#successRef').textContent = json.reference;
          $('#successTotal').textContent = money(json.total);
          $('#successDeposit').textContent = money(json.deposit);
          $('#payNowBtn').href = (json.pay_url || 'pay.php') + '&new=1';
          $('#laterNote').hidden = true;
          $('#selfPayBlock').hidden = !!json.sponsored;
          $('#reviewNote').hidden = !json.sponsored;
          $('#successSponsor').textContent = json.sponsor || 'your sponsor';
          const shareText = `I just registered for Kakebe Tech Camp 2026! 🚀 ${json.reference ? '' : ''}Join me — register here: ${location.origin + location.pathname}#register`;
          $('#shareWa').href = 'https://wa.me/?text=' + encodeURIComponent(shareText);
          form.hidden = true;
          success.hidden = false;
          success.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
          if (json.errors) showErrors(form, json.errors);
          setAlert(alertEl, json.message || 'Please check the form and try again.');
        }
      } catch (err) {
        setAlert(alertEl, err.message || 'Network error — please check your connection and try again.');
      } finally {
        submit.classList.remove('loading');
      }
    });

    $('#payLaterBtn').addEventListener('click', () => {
      $('#laterNote').hidden = false;
      toast('👍 Registration saved — the payment details are in your email.');
    });
  }

  /* ---------- Registration step 1: email link ---------- */
  const gateForm = $('#gateForm');
  if (gateForm) {
    const gateBtn = $('#gateBtn');
    const gateAlert = $('#gateAlert');
    const gateSent = $('#gateSent');
    const gateEmail = $('#g_email');
    const resend = $('#gateResend');
    let cooldown;
    const startCooldown = () => {
      let sec = 45;
      resend.disabled = true;
      resend.textContent = `resend in ${sec}s`;
      clearInterval(cooldown);
      cooldown = setInterval(() => {
        sec -= 1;
        if (sec <= 0) { clearInterval(cooldown); resend.disabled = false; resend.textContent = 'resend the link'; }
        else resend.textContent = `resend in ${sec}s`;
      }, 1000);
    };
    const sendLink = async () => {
      clearErrors(gateForm);
      setAlert(gateAlert, '');
      const email = gateEmail.value.trim();
      if (!emailRe.test(email)) { showErrors(gateForm, { email: 'Please enter a valid email address.' }); return false; }
      gateBtn.classList.add('loading');
      try {
        const json = await postForm('api/email-link.php', new FormData(gateForm));
        if (!json.ok) {
          if (json.errors) showErrors(gateForm, json.errors);
          else setAlert(gateAlert, json.message || 'Something went wrong. Please try again.');
          return false;
        }
        $('#gateEmailShow').textContent = json.email || email;
        gateForm.hidden = true;
        gateSent.hidden = false;
        startCooldown();
        return true;
      } catch (err) {
        setAlert(gateAlert, err.message || 'Network error — please try again.');
        return false;
      } finally {
        gateBtn.classList.remove('loading');
      }
    };
    gateForm.addEventListener('submit', (e) => { e.preventDefault(); sendLink(); });
    resend.addEventListener('click', async () => {
      gateForm.hidden = false;
      const ok = await sendLink();
      if (ok) toast('✉️ We sent you a new link.');
      else { gateSent.hidden = true; }
    });
    $('#gateChange').addEventListener('click', () => {
      gateSent.hidden = true;
      gateForm.hidden = false;
      gateEmail.value = '';
      gateEmail.focus();
    });
  }

  /* ---------- Contact form ---------- */
  const cForm = $('#contactForm');
  if (cForm) {
    clearFieldOnEdit(cForm);
    const cAlert = $('#contactAlert');
    const cBtn = $('#contactSubmit');
    cForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(cForm);
      setAlert(cAlert, '');
      const f = new FormData(cForm);
      const errors = {};
      if (String(f.get('name')).trim().length < 2) errors.name = 'Please enter your name.';
      if (!emailRe.test(String(f.get('email')).trim())) errors.email = 'Please enter a valid email address.';
      if (String(f.get('message')).trim().length < 5) errors.message = 'Please write a short message.';
      if (Object.keys(errors).length) { showErrors(cForm, errors); return; }
      cBtn.classList.add('loading');
      try {
        const json = await postForm('api/contact.php', f);
        if (json.ok) { cForm.reset(); setAlert(cAlert, json.message, true); toast('✅ Message sent — thank you!'); }
        else { if (json.errors) showErrors(cForm, json.errors); setAlert(cAlert, json.message || 'Please check the form and try again.'); }
      } catch (err) {
        setAlert(cAlert, err.message || 'Network error — please try again.');
      } finally {
        cBtn.classList.remove('loading');
      }
    });
  }
})();
