/* Mentorship & DBIP registration form */
(function () {
  'use strict';
  const form = document.getElementById('mentForm');
  if (!form) return;

  // Up to three fields
  const boxes = Array.from(form.querySelectorAll('.js-max3 input[type="checkbox"]'));
  const syncTracks = () => {
    const n = boxes.filter((b) => b.checked).length;
    boxes.forEach((b) => { b.disabled = !b.checked && n >= 3; });
  };
  boxes.forEach((b) => b.addEventListener('change', syncTracks));
  syncTracks();

  // WhatsApp number same as phone
  const phone = document.getElementById('m_phone');
  const wa = document.getElementById('m_wa');
  const same = document.getElementById('m_same');
  const syncWa = () => {
    if (same.checked) wa.value = phone.value;
    wa.readOnly = same.checked;
  };
  same.addEventListener('change', syncWa);
  phone.addEventListener('input', () => { if (same.checked) wa.value = phone.value; });
  syncWa();

  form.addEventListener('submit', () => {
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Registering…';
  });
})();
