/* Kakebe Tech Camp — email campaigns & contacts */
(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const MK = window.MK || {};
  const fmt = (n) => Number(n || 0).toLocaleString('en-US');
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));

  async function api(data) {
    const fd = new FormData();
    fd.append('csrf', MK.csrf);
    Object.keys(data).forEach((k) => fd.append(k, data[k]));
    try {
      const res = await fetch(MK.api, { method: 'POST', body: fd, credentials: 'same-origin' });
      try { return await res.json(); } catch (e) { return { ok: false, message: 'The server gave an unexpected answer (' + res.status + '). Refresh the page and try again.' }; }
    } catch (e) {
      return { ok: false, network: true, message: 'No connection to the server.' };
    }
  }

  /* ---------- Pictures: shrink big photos before upload so emails load fast ---------- */
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return file;
    try {
      const bmp = await createImageBitmap(file);
      const max = 1200;
      if (bmp.width <= max && file.size < 700 * 1024) return file;
      const scale = Math.min(1, max / bmp.width);
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * scale);
      canvas.height = Math.round(bmp.height * scale);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      const blob = await new Promise((r) => canvas.toBlob(r, type, 0.85));
      return blob && blob.size < file.size ? new File([blob], 'image.' + (type === 'image/png' ? 'png' : 'jpg'), { type }) : file;
    } catch (e) {
      return file;
    }
  }

  async function uploadImage(file) {
    const note = $('#mkUploadNote');
    if (note) note.hidden = false;
    const res = await api({ action: 'upload', image: await shrink(file) });
    if (note) note.hidden = true;
    if (!res.ok) { alert(res.message || 'The picture could not be uploaded.'); return null; }
    return res.url;
  }

  /* ---------- Editor ---------- */
  const TEMPLATES = {
    program: {
      cta: 'Apply now',
      html: '<h2>[Program name] is now open</h2><p>Hello {first_name},</p>'
        + '<p>We are excited to invite you to <strong>[program name]</strong> — [one sentence on what it is and who it is for].</p>'
        + '<h3>What you get</h3><ul><li>[Benefit — e.g. weekly mentorship from people working in tech]</li><li>[Benefit — e.g. hands-on projects you can show employers]</li><li>[Benefit — e.g. a certificate at the end]</li></ul>'
        + '<h3>Key details</h3><ul><li><strong>Where:</strong> Online, and in person in Lira, Gulu and Kitgum</li><li><strong>When:</strong> [dates]</li><li><strong>Cost:</strong> [Free / amount]</li><li><strong>Apply by:</strong> [deadline]</li></ul>'
        + '<p>Places are limited, so apply early. Tap the button below to get started.</p><p>See you there,</p><p><strong>The Kakebe Technologies team</strong></p>'
    },
    event: {
      cta: 'Reserve my place',
      html: '<h2>You\'re invited: [event name]</h2><p>Hi {first_name},</p>'
        + '<p>Join us for <strong>[event name]</strong> — [short description of the event].</p>'
        + '<h3>Event details</h3><ul><li><strong>Date:</strong> [day and date]</li><li><strong>Time:</strong> [start – end]</li><li><strong>Venue:</strong> [venue, town]</li><li><strong>Entry:</strong> [free / fee]</li></ul>'
        + '<h3>What to expect</h3><ul><li>[Talks and workshops]</li><li>[Meeting mentors and other young innovators]</li><li>[Prizes, showcases or something fun]</li></ul>'
        + '<p>Reserve your place with the button below — and bring a friend who loves tech.</p><p>Warm regards,</p><p><strong>The Kakebe Technologies team</strong></p>'
    },
    news: {
      cta: 'Read more',
      html: '<h2>[Headline — the main news]</h2><p>Hello {first_name},</p><p>Here is what\'s new at Kakebe Technologies this [month].</p>'
        + '<h3>1. [First story]</h3><p>[Two or three sentences.]</p><h3>2. [Second story]</h3><p>[Two or three sentences.]</p>'
        + '<h3>Coming up</h3><ul><li>[Upcoming item — date]</li><li>[Upcoming item — date]</li></ul>'
        + '<p>Thank you for being part of our community.</p><p><strong>The Kakebe Technologies team</strong></p>'
    }
  };

  // Saved emails carry inline styles for email apps; the editor only needs the alignment back.
  function toEditorHtml(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html || '';
    $$('[style]', tpl.content).forEach((el) => {
      const align = el.style.textAlign;
      el.removeAttribute('style');
      if (align && align !== 'left' && /^(P|H1|H2|H3|LI|BLOCKQUOTE)$/.test(el.tagName)) el.classList.add('ql-align-' + align);
    });
    $$('a[target]', tpl.content).forEach((a) => a.removeAttribute('target'));
    return tpl.innerHTML;
  }

  const form = $('#mkForm');
  const editorEl = $('#mkEditor');
  if (form && editorEl && window.Quill) {
    const quill = new Quill(editorEl, {
      theme: 'snow',
      placeholder: 'Write your email here — or start from a template above…',
      modules: {
        toolbar: {
          container: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'bullet' }, { list: 'ordered' }], [{ align: [] }], ['link', 'image', 'blockquote'], ['clean']],
          handlers: {
            image() {
              const input = document.createElement('input');
              input.type = 'file';
              input.accept = 'image/png,image/jpeg,image/gif,image/webp';
              input.onchange = async () => {
                const file = input.files[0];
                if (!file) return;
                const range = quill.getSelection(true);
                const url = await uploadImage(file);
                if (url) {
                  quill.insertEmbed(range.index, 'image', url, 'user');
                  quill.setSelection(range.index + 1, 0);
                }
              };
              input.click();
            }
          }
        }
      }
    });
    const body = $('#mkBody');
    if (body.value.trim()) quill.setContents(quill.clipboard.convert({ html: toEditorHtml(body.value) }), 'silent');

    let dirty = false;
    let submitting = false;
    quill.on('text-change', () => { dirty = true; });
    $$('input, select', form).forEach((el) => el.addEventListener('input', () => { dirty = true; }));
    window.addEventListener('beforeunload', (e) => { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });
    window.mkIsDirty = () => dirty;

    $$('[data-merge]').forEach((b) => b.addEventListener('click', () => {
      const range = quill.getSelection(true);
      quill.insertText(range.index, b.dataset.merge, 'user');
      quill.setSelection(range.index + b.dataset.merge.length, 0);
    }));

    $$('[data-template]').forEach((b) => b.addEventListener('click', () => {
      const t = TEMPLATES[b.dataset.template];
      if (quill.getLength() > 1 && !confirm('Replace what you have written with this template?')) return;
      quill.setContents(quill.clipboard.convert({ html: t.html }), 'user');
      const cta = form.querySelector('[name="cta_label"]');
      if (cta && !cta.value) cta.value = t.cta;
      quill.focus();
    }));

    form.addEventListener('submit', async (e) => {
      if (submitting) return;
      e.preventDefault();
      const btn = $('#mkSave');
      btn.disabled = true;
      // Pasted pictures arrive as data inside the email: upload them so they become normal links
      for (const img of $$('img[src^="data:"]', quill.root)) {
        try {
          const blob = await (await fetch(img.src)).blob();
          const url = await uploadImage(new File([blob], 'pasted.' + (blob.type.split('/')[1] || 'png'), { type: blob.type }));
          if (url) img.src = url; else img.remove();
        } catch (err) { img.remove(); }
      }
      body.value = quill.getLength() > 1 || $('img', quill.root) ? quill.getSemanticHTML().replace(/&nbsp;/g, ' ') : '';
      submitting = true;
      form.submit();
    });
  }

  /* ---------- Preview: desktop / phone width ---------- */
  $$('[data-device]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    $$('[data-device]').forEach((x) => x.classList.toggle('active', x === a));
    const frame = $('#mkFrame');
    if (frame) frame.classList.toggle('mobile', a.dataset.device === 'mobile');
  }));

  /* ---------- Test email ---------- */
  const testBtn = $('#mkTest');
  if (testBtn) testBtn.addEventListener('click', async () => {
    const msg = $('#mkTestMsg');
    if (window.mkIsDirty && window.mkIsDirty() && !confirm('You have unsaved changes — the test will show the last saved version. Send anyway?')) return;
    testBtn.disabled = true;
    const res = await api({ action: 'test', campaign_id: testBtn.dataset.id, email: $('#mkTestTo').value.trim() });
    testBtn.disabled = false;
    msg.hidden = false;
    msg.className = 'small ' + (res.ok ? 'ok-text' : 'err-text');
    msg.textContent = res.message || (res.ok ? 'Sent.' : 'Failed.');
  });

  /* ---------- Send a group ---------- */
  let sending = false;
  let stop = false;
  window.addEventListener('beforeunload', (e) => { if (sending) { e.preventDefault(); e.returnValue = ''; } });
  const stopBtn = $('#mkStop');
  if (stopBtn) stopBtn.addEventListener('click', () => { stop = true; stopBtn.disabled = true; $('#mkLiveTitle').textContent = 'Pausing after this round…'; });

  $$('.js-send').forEach((btn) => btn.addEventListener('click', async () => {
    const batch = btn.dataset.batch;
    if (!confirm('Send group ' + batch + ' now? Each person receives their own copy of the email.')) return;
    sending = true;
    stop = false;
    $$('.js-send').forEach((b) => { b.disabled = true; });
    const live = $('#mkLive');
    const title = $('#mkLiveTitle');
    const text = $('#mkLiveText');
    const bar = $('#mkLiveBar');
    live.hidden = false;
    stopBtn.disabled = false;
    title.textContent = 'Sending group ' + batch + '…';
    live.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    let sent = 0, failed = 0, lastDone = -1, misses = 0, finished = false, problem = '';
    while (!stop) {
      const res = await api({ action: 'send', campaign_id: btn.dataset.id, batch });
      if (res.network) {
        if (++misses > 4) { problem = 'Lost the connection — check your internet and press Send again to continue.'; break; }
        title.textContent = 'Connection lost — retrying…';
        await wait(4000);
        continue;
      }
      misses = 0;
      if (!('batch_total' in res)) { problem = res.message || 'Sending stopped.'; break; }
      sent += res.sent || 0;
      failed += res.failed || 0;
      const pct = res.batch_total ? Math.round(res.batch_done / res.batch_total * 100) : 100;
      bar.style.width = pct + '%';
      text.textContent = fmt(res.batch_done) + ' of ' + fmt(res.batch_total) + ' done · ' + fmt(sent) + ' sent now' + (failed ? ' · ' + fmt(failed) + ' failed' : '') + ' · ' + fmt(res.daily_left) + ' left today';
      const card = $('.mk-group[data-batch="' + batch + '"] .js-done');
      if (card) card.textContent = fmt(res.batch_done);
      if (res.error) { problem = res.error; break; }
      if (res.batch_queued === 0) { finished = true; break; }
      if (res.batch_done === lastDone) { problem = 'Sending is not moving forward. Refresh the page and try again.'; break; }
      lastDone = res.batch_done;
      title.textContent = 'Sending group ' + batch + '…';
    }
    sending = false;
    stopBtn.disabled = true;
    if (finished) {
      title.innerHTML = '<span class="ok-text"><i class="fa-solid fa-circle-check"></i> Group ' + batch + ' done</span>';
      setTimeout(() => location.reload(), 1600);
    } else {
      title.innerHTML = problem ? '<span class="err-text"><i class="fa-solid fa-triangle-exclamation"></i> ' + problem.replace(/</g, '&lt;') + '</span>' : 'Paused.';
      const again = document.createElement('a');
      again.href = location.pathname + location.search + '#sending';
      again.className = 'btn btn-light btn-sm';
      again.textContent = 'Refresh';
      again.addEventListener('click', (e) => { e.preventDefault(); location.reload(); });
      stopBtn.replaceWith(again);
    }
  }));

  /* ---------- Check email domains ---------- */
  const domBtn = $('#mkDomains');
  if (domBtn) domBtn.addEventListener('click', async () => {
    const msg = $('#mkDomMsg');
    const left = $('#mkDomLeft');
    domBtn.disabled = true;
    let bad = 0, checked = 0;
    for (;;) {
      msg.textContent = 'Checking domains… ' + fmt(checked) + ' checked' + (bad ? ', ' + fmt(bad) + ' cannot receive mail' : '');
      const res = await api({ action: 'check_domains' });
      if (!res.ok) { msg.textContent = res.message || 'The check stopped. Try again.'; domBtn.disabled = false; return; }
      checked += res.checked;
      bad += res.bad;
      left.textContent = fmt(res.left);
      if (!res.left || !res.checked) break;
    }
    msg.innerHTML = '<span class="ok-text"><i class="fa-solid fa-circle-check"></i> Done.</span> ' + fmt(checked) + ' domains checked — '
      + (bad ? fmt(bad) + ' could not receive mail, so their addresses were filtered out. <a href="?status=invalid">See them</a>' : 'all of them can receive mail.');
  });

  /* ---------- Import ---------- */
  const listSel = $('#mkListSel');
  const listName = $('#mkListName');
  if (listSel && listName) {
    const sync = () => { listName.hidden = listSel.value !== '0'; };
    listSel.addEventListener('change', sync);
    sync();
  }
  const imp = $('#mkImport');
  if (imp) {
    const file = $('input[type="file"]', imp);
    file.addEventListener('change', () => {
      const span = $('.mk-drop span', imp);
      if (file.files[0]) span.textContent = file.files[0].name + ' · ' + (file.files[0].size / 1048576).toFixed(1) + ' MB';
    });
    imp.addEventListener('submit', () => {
      $('#mkImportNote').hidden = false;
      $('button[type="submit"]', imp).disabled = true;
    });
  }
})();
