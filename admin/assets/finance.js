/* Project finance: requisition items (rows, amounts, totals, budget warnings) and money inputs */
(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const digits = (v) => parseInt(String(v || '').replace(/\D/g, ''), 10) || 0;
  const fmt = (n) => n.toLocaleString('en-US');

  // Money inputs show thousands separators as you type
  document.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.matches('.js-money, .js-cost')) return;
    const n = digits(el.value);
    el.value = n ? fmt(n) : '';
  });

  // Signature pad: draw with a finger or mouse; the drawing is sent as a PNG with the form
  $$('.js-sig-canvas').forEach((canvas) => {
    const form = canvas.closest('form');
    const data = $('.js-sig-data', form);
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let drawn = false;
    ctx.lineWidth = 3.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0F2557';
    const pos = (e) => {
      const r = canvas.getBoundingClientRect();
      return [(e.clientX - r.left) * (canvas.width / r.width), (e.clientY - r.top) * (canvas.height / r.height)];
    };
    canvas.addEventListener('pointerdown', (e) => {
      drawing = true;
      canvas.setPointerCapture(e.pointerId);
      const [x, y] = pos(e);
      ctx.beginPath();
      ctx.moveTo(x, y);
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drawing) return;
      const [x, y] = pos(e);
      ctx.lineTo(x, y);
      ctx.stroke();
      if (!drawn) { drawn = true; canvas.parentElement.classList.add('signed'); }
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => canvas.addEventListener(t, () => { drawing = false; }));
    const clear = $('.js-sig-clear', form);
    if (clear) clear.addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; canvas.parentElement.classList.remove('signed'); data.value = ''; });
    form.addEventListener('submit', () => { data.value = drawn ? canvas.toDataURL('image/png') : ''; });
  });

  // Team lead adjusting amounts before approval: live amounts and total
  $$('.js-adjust').forEach((form) => {
    const totalEl = $('.js-adj-total', form);
    const btn = $('.js-adj-btn span', form);
    const recalc = () => {
      let total = 0;
      $$('.adj-row', form).forEach((row) => {
        const amount = Math.round((parseFloat($('.js-adj-qty', row).value) || 0) * digits($('.js-adj-cost', row).value));
        $('.js-adj-amount', row).textContent = fmt(amount);
        total += amount;
      });
      totalEl.textContent = 'UGX ' + fmt(total);
      totalEl.classList.toggle('changed', total !== parseInt(totalEl.dataset.original, 10));
      if (btn) btn.textContent = (total !== parseInt(totalEl.dataset.original, 10) ? 'Adjust & approve UGX ' : 'Approve UGX ') + fmt(total);
    };
    form.addEventListener('input', recalc);
  });

  const table = $('#reqItems');
  if (!table) return;
  const body = $('tbody', table);
  const totalEl = $('#reqTotal');
  const clusterSel = $('#reqCluster');

  const recalc = () => {
    let total = 0;
    const perLine = {};
    $$('.req-row', body).forEach((row) => {
      const qty = parseFloat($('.js-qty', row).value) || 0;
      const amount = Math.round(qty * digits($('.js-cost', row).value));
      $('.js-amount', row).textContent = fmt(amount);
      total += amount;
      const sel = $('.js-line', row);
      if (sel.value) perLine[sel.value] = (perLine[sel.value] || 0) + amount;
    });
    // Warn when the items on a line add up to more than the line has left (finance / operations / team lead only)
    $$('.req-row', body).forEach((row) => {
      const opt = $('.js-line', row).selectedOptions[0];
      const left = opt && opt.dataset.available !== undefined ? parseInt(opt.dataset.available, 10) : null;
      const over = left !== null && perLine[opt.value] > left;
      row.classList.toggle('over', over);
      row.title = over ? 'More than this budget line has left (' + fmt(left) + ')' : '';
    });
    totalEl.textContent = 'UGX ' + fmt(total);
  };

  // Show only the chosen activity's budget lines (all lines when no activity is chosen)
  const filterLines = () => {
    const cid = clusterSel ? clusterSel.value : '0';
    $$('.js-line optgroup', body).forEach((g) => { g.hidden = cid !== '0' && g.dataset.cluster !== cid; g.disabled = g.hidden; });
  };

  const renumber = () => {
    $$('.req-row', body).forEach((row, i) => {
      $$('[name^="items["]', row).forEach((el) => { el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']'); });
    });
  };

  $('#reqAdd').addEventListener('click', () => {
    const first = $('.req-row', body);
    const row = first.cloneNode(true);
    $$('input', row).forEach((el) => { el.value = el.classList.contains('js-qty') ? '1' : ''; });
    $('.js-line', row).value = '';
    row.classList.remove('over');
    body.appendChild(row);
    renumber();
    filterLines();
    recalc();
    $('.js-line', row).focus();
  });

  body.addEventListener('click', (e) => {
    const del = e.target.closest('.js-del');
    if (!del) return;
    if ($$('.req-row', body).length === 1) {
      $$('input', body).forEach((el) => { el.value = el.classList.contains('js-qty') ? '1' : ''; });
      $('.js-line', body).value = '';
    } else {
      del.closest('.req-row').remove();
      renumber();
    }
    recalc();
  });
  body.addEventListener('input', recalc);
  body.addEventListener('change', recalc);
  if (clusterSel) clusterSel.addEventListener('change', filterLines);
  filterLines();
  recalc();
})();
