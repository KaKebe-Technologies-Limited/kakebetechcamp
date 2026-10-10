/* Volunteer trainer application form */
(function () {
  'use strict';
  const form = document.getElementById('volForm');
  if (!form) return;

  // Up to three fields
  const boxes = Array.from(form.querySelectorAll('.js-max3 input[type="checkbox"]'));
  const sync = () => {
    const n = boxes.filter((b) => b.checked).length;
    boxes.forEach((b) => { b.disabled = !b.checked && n >= 3; });
  };
  boxes.forEach((b) => b.addEventListener('change', sync));
  sync();

  // Show the chosen CV
  const cv = document.getElementById('v_cv');
  const cvName = form.querySelector('.js-cv-name');
  if (cv && cvName) {
    cv.addEventListener('change', () => {
      const f = cv.files[0];
      if (!f) return;
      const mb = f.size / 1048576;
      cvName.textContent = f.name + ' · ' + (mb < 0.1 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : mb.toFixed(1) + ' MB') + (mb > 5 ? ' — too large (max 5 MB)' : '');
      cvName.parentElement.classList.toggle('picked', mb <= 5);
      cvName.parentElement.classList.toggle('too-big', mb > 5);
    });
  }

  form.addEventListener('submit', () => {
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Sending your application…';
  });
})();
