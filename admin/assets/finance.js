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
