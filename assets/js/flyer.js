/* Kakebe Tech Camp 2026 — "I will be there" flyer maker.
   The flyer is drawn in the browser. On download / share, a copy of the finished flyer is sent to the site
   (api/flyer-save.php) so the team can reuse it; the original photo is never uploaded. */
(function () {
  'use strict';

  const root = document.getElementById('flyerMaker');
  if (!root) return;
  const $ = (s) => root.querySelector(s);
  const canvas = $('#flyerCanvas');
  const ctx = canvas.getContext('2d');
  const nameInput = $('#flyerName');
  const codeInput = $('#flyerCode');
  const zoomInput = $('#flyerZoom');
  const fileInput = $('#flyerPhoto');
  const errorEl = $('#flyerError');
  const downloadBtn = $('#flyerDownload');
  const shareBtn = $('#flyerShare');

  // Geometry of the template (assets/iwillbethere.png, 1254 × 1254). The new card is drawn over the sample card.
  const S = 1254;
  // Radii are [top-left, top-right, bottom-right, bottom-left]; the name panel shares the photo window's bottom corners.
  const FRAME = { x: 683, y: 211, w: 532, h: 818, r: [58, 58, 38, 68] }; // white border (a little larger than the sample card)
  const PHOTO = { x: 687, y: 215, w: 524, h: 810, r: [54, 54, 34, 64] }; // photo window inside the border
  const CARD = { x: 687, y: 868, w: 524, h: 157, r: [28, 28, 34, 64] };  // name panel
  const NAVY = '#001A4B';
  const RED = '#E11D2A';
  const FONT = '"Plus Jakarta Sans", "Segoe UI", Arial, sans-serif';

  const base = new Image();
  let photo = null;
  let zoom = 1;
  let offX = 0;
  let offY = 0;
  let queued = false;

  const roundRect = (x, y, w, h, r) => {
    const [tl, tr, br, bl] = Array.isArray(r) ? r : [r, r, r, r];
    ctx.beginPath();
    ctx.moveTo(x + tl, y);
    ctx.arcTo(x + w, y, x + w, y + h, tr);
    ctx.arcTo(x + w, y + h, x, y + h, br);
    ctx.arcTo(x, y + h, x, y, bl);
    ctx.arcTo(x, y, x + w, y, tl);
    ctx.closePath();
  };

  // Where the photo sits: scaled to cover the window, moved by the user, never leaving a gap.
  const placement = () => {
    const s = Math.max(PHOTO.w / photo.naturalWidth, PHOTO.h / photo.naturalHeight) * zoom;
    const w = photo.naturalWidth * s;
    const h = photo.naturalHeight * s;
    const maxX = (w - PHOTO.w) / 2;
    const maxY = (h - PHOTO.h) / 2;
    offX = Math.max(-maxX, Math.min(maxX, offX));
    offY = Math.max(-maxY, Math.min(maxY, offY));
    return { x: PHOTO.x + (PHOTO.w - w) / 2 + offX, y: PHOTO.y + (PHOTO.h - h) / 2 + offY, w, h, maxY };
  };

  // Letter-spaced text, centred on cx (drawn letter by letter so it works in every browser).
  const spacedText = (text, cx, y, spacing) => {
    const chars = [...text];
    const widths = chars.map((c) => ctx.measureText(c).width);
    let x = cx - (widths.reduce((a, b) => a + b, 0) + spacing * (chars.length - 1)) / 2;
    ctx.textAlign = 'left';
    chars.forEach((c, i) => { ctx.fillText(c, x, y); x += widths[i] + spacing; });
  };

  const draw = () => {
    queued = false;
    ctx.clearRect(0, 0, S, S);
    if (base.complete && base.naturalWidth) ctx.drawImage(base, 0, 0, S, S);

    // White frame with a soft shadow
    ctx.save();
    ctx.shadowColor = 'rgba(15, 37, 87, .18)';
    ctx.shadowBlur = 26;
    ctx.shadowOffsetY = 10;
    roundRect(FRAME.x, FRAME.y, FRAME.w, FRAME.h, FRAME.r);
    ctx.fillStyle = '#fff';
    ctx.fill();
    ctx.restore();

    // Photo, or a placeholder until one is chosen
    ctx.save();
    roundRect(PHOTO.x, PHOTO.y, PHOTO.w, PHOTO.h, PHOTO.r);
    ctx.clip();
    if (photo) {
      const p = placement();
      ctx.drawImage(photo, p.x, p.y, p.w, p.h);
    } else {
      const g = ctx.createLinearGradient(0, PHOTO.y, 0, PHOTO.y + PHOTO.h);
      g.addColorStop(0, '#EEF1F8');
      g.addColorStop(1, '#DCE2F0');
      ctx.fillStyle = g;
      ctx.fillRect(PHOTO.x, PHOTO.y, PHOTO.w, PHOTO.h);
      const cx = PHOTO.x + PHOTO.w / 2;
      ctx.fillStyle = '#B8C2DA';
      ctx.beginPath(); ctx.arc(cx, 440, 92, 0, Math.PI * 2); ctx.fill();
      ctx.beginPath(); ctx.ellipse(cx, 700, 190, 150, 0, Math.PI, 0); ctx.fill();
      ctx.fillStyle = '#7A829E';
      ctx.font = `700 30px ${FONT}`;
      ctx.textAlign = 'center';
      ctx.fillText('Your photo here', cx, 330);
    }
    ctx.restore();

    // Name panel
    roundRect(CARD.x, CARD.y, CARD.w, CARD.h, CARD.r);
    ctx.fillStyle = NAVY;
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#fff';
    ctx.stroke();

    const cx = CARD.x + CARD.w / 2;
    const name = (nameInput.value.trim() || 'Your name').toUpperCase();
    let size = 46;
    ctx.font = `800 ${size}px ${FONT}`;
    while (ctx.measureText(name).width > CARD.w - 56 && size > 20) {
      size -= 1;
      ctx.font = `800 ${size}px ${FONT}`;
    }
    ctx.fillStyle = '#fff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(name, cx, 936);

    ctx.fillStyle = RED;
    ctx.fillRect(cx - 45, 957, 90, 3);

    // Under the name: the camper's code number (or "I will be there" until one is entered)
    const code = codeInput ? codeInput.value.trim().toUpperCase().replace(/[^A-Z0-9-]/g, '') : '';
    ctx.fillStyle = '#fff';
    if (code) {
      ctx.font = `700 24px ${FONT}`;
      spacedText(code, cx, 1002, 5);
    } else {
      ctx.font = `600 19px ${FONT}`;
      spacedText('I WILL BE THERE', cx, 1000, 9);
    }
  };
  const redraw = () => { if (!queued) { queued = true; requestAnimationFrame(draw); } };

  const showError = (msg) => { errorEl.textContent = msg || ''; errorEl.hidden = !msg; };

  // ---------- Photo ----------
  fileInput.addEventListener('change', () => {
    const file = fileInput.files[0];
    showError('');
    if (!file) return;
    if (!/^image\//.test(file.type)) { showError('Please choose a photo (JPG, PNG or WEBP).'); return; }
    if (file.size > 20 * 1024 * 1024) { showError('That photo is very large — please choose one under 20 MB.'); return; }
    const img = new Image();
    img.onload = () => {
      photo = img;
      zoom = 1;
      zoomInput.value = '1';
      offX = 0;
      offY = placement().maxY * 0.6; // show more of the top of a portrait photo (the face), above the name panel
      root.classList.add('has-photo');
      zoomInput.disabled = false;
      downloadBtn.disabled = false;
      if (shareBtn) shareBtn.disabled = false;
      redraw();
      window.ktTrack && window.ktTrack('flyer_photo_added');
    };
    img.onerror = () => showError('We could not open that photo. Please try a different one (JPG or PNG).');
    img.src = URL.createObjectURL(file);
  });

  zoomInput.addEventListener('input', () => { zoom = parseFloat(zoomInput.value) || 1; redraw(); });
  nameInput.addEventListener('input', redraw);
  if (codeInput) codeInput.addEventListener('input', redraw);

  // Drag to move, pinch or scroll to zoom
  const pointers = new Map();
  let pinchStart = 0;
  let zoomStart = 1;
  const toCanvas = (dx) => dx * (S / canvas.getBoundingClientRect().width);
  canvas.addEventListener('pointerdown', (e) => {
    if (!photo) { fileInput.click(); return; }
    canvas.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      pinchStart = Math.hypot(a.x - b.x, a.y - b.y);
      zoomStart = zoom;
    }
  });
  canvas.addEventListener('pointermove', (e) => {
    if (!photo || !pointers.has(e.pointerId)) return;
    const prev = pointers.get(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      zoom = Math.max(1, Math.min(3, zoomStart * Math.hypot(a.x - b.x, a.y - b.y) / (pinchStart || 1)));
      zoomInput.value = String(zoom);
    } else {
      offX += toCanvas(e.clientX - prev.x);
      offY += toCanvas(e.clientY - prev.y);
    }
    redraw();
  });
  const release = (e) => { pointers.delete(e.pointerId); };
  canvas.addEventListener('pointerup', release);
  canvas.addEventListener('pointercancel', release);
  canvas.addEventListener('wheel', (e) => {
    if (!photo) return;
    e.preventDefault();
    zoom = Math.max(1, Math.min(3, zoom * (1 - e.deltaY * 0.0015)));
    zoomInput.value = String(zoom);
    redraw();
  }, { passive: false });

  // ---------- Download & share ----------
  const fileName = () => 'i-will-be-there-' + ((nameInput.value.trim() || 'kakebe-tech-camp').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'kakebe') + '.png';
  const toBlob = () => new Promise((resolve) => { draw(); canvas.toBlob(resolve, 'image/png'); });

  // Keep a copy of the finished flyer (plus a small preview) for the team — quietly, it never blocks the download
  const saveCopy = (blob) => {
    if (!root.dataset.save || !blob) return;
    const small = document.createElement('canvas');
    small.width = small.height = 420;
    small.getContext('2d').drawImage(canvas, 0, 0, 420, 420);
    small.toBlob((thumb) => {
      const fd = new FormData();
      fd.append('csrf', root.dataset.csrf);
      fd.append('name', nameInput.value.trim());
      fd.append('code', codeInput ? codeInput.value.trim() : '');
      fd.append('flyer', blob, 'flyer.png');
      if (thumb) fd.append('thumb', thumb, 'flyer.jpg');
      fetch(root.dataset.save, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
    }, 'image/jpeg', 0.82);
  };

  downloadBtn.addEventListener('click', async () => {
    const blob = await toBlob();
    if (!blob) { showError('Sorry, your browser could not create the image. Please try another browser.'); return; }
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName();
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
    window.ktTrack && window.ktTrack('flyer_download');
    saveCopy(blob);
  });

  if (shareBtn) {
    const canShareFiles = () => {
      try { return !!(navigator.canShare && navigator.canShare({ files: [new File([''], 'x.png', { type: 'image/png' })] })); } catch (_) { return false; }
    };
    if (!canShareFiles()) {
      shareBtn.hidden = true;
    } else {
      shareBtn.addEventListener('click', async () => {
        const blob = await toBlob();
        if (!blob) return;
        saveCopy(blob);
        try {
          await navigator.share({
            files: [new File([blob], fileName(), { type: 'image/png' })],
            title: 'I will be there!',
            text: 'I will be there! Join me at Kakebe Tech Camp 2026 — 14th–23rd December in Kitgum. Register at ' + root.dataset.site,
          });
          window.ktTrack && window.ktTrack('share', { method: 'flyer' });
        } catch (_) { /* cancelled */ }
      });
    }
  }

  // ---------- Start ----------
  base.onload = redraw;
  base.src = root.dataset.base;
  (document.fonts && document.fonts.load ? Promise.all([document.fonts.load(`800 46px ${FONT}`), document.fonts.load(`700 24px ${FONT}`), document.fonts.load(`600 19px ${FONT}`)]) : Promise.resolve())
    .catch(() => {})
    .then(redraw);
})();
