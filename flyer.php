<?php
/**
 * "I will be there" flyer — upload a photo, get a personalised PNG to share.
 * The flyer is drawn in the browser (assets/js/flyer.js); the photo is never uploaded.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$p = current_participant();

app_header('My “I will be there” flyer');
?>
<div class="app-grid flyer-grid" id="flyerMaker"
     data-base="assets/img/flyer-base.webp?v=<?= filemtime(__DIR__ . '/assets/img/flyer-base.webp') ?>"
     data-site="<?= e(preg_replace('~^https?://~', '', base_url())) ?>">
  <section class="app-card flyer-preview">
    <canvas id="flyerCanvas" width="1254" height="1254" role="img" aria-label="Preview of your I will be there flyer"></canvas>
    <p class="muted small center flyer-hint">Drag your photo to position it · pinch or use the slider to zoom</p>
  </section>

  <aside class="app-side">
    <div class="app-card">
      <h1>Your “I will be there” flyer</h1>
      <p class="muted">Upload your best photo and we'll design your flyer — ready to share on WhatsApp, Instagram, TikTok and Facebook.</p>

      <div class="flyer-step">
        <span>1</span>
        <div>
          <b>Your best photo</b>
          <label class="btn btn-primary btn-block flyer-upload" for="flyerPhoto"><i class="fa-solid fa-camera"></i> <span>Upload your photo</span></label>
          <input id="flyerPhoto" type="file" accept="image/*" hidden>
          <small class="muted"><i class="fa-solid fa-lock"></i> Your photo stays on your device — it is not uploaded.</small>
        </div>
      </div>

      <div class="flyer-step">
        <span>2</span>
        <div>
          <b>Adjust</b>
          <label class="flyer-zoom"><i class="fa-solid fa-magnifying-glass-minus"></i><input id="flyerZoom" type="range" min="1" max="3" step="0.01" value="1" disabled aria-label="Zoom"><i class="fa-solid fa-magnifying-glass-plus"></i></label>
          <small class="muted">Drag the photo on the flyer to move it.</small>
        </div>
      </div>

      <div class="flyer-step">
        <span>3</span>
        <div>
          <b>Your name</b>
          <div class="field"><input id="flyerName" type="text" maxlength="40" autocomplete="name" placeholder="e.g. Akello Grace" value="<?= e($p['full_name'] ?? '') ?>"></div>
        </div>
      </div>

      <div class="form-alert" id="flyerError" role="alert" hidden></div>
      <div class="flyer-actions">
        <button type="button" class="btn btn-primary btn-block" id="flyerDownload" disabled><i class="fa-solid fa-download"></i> Download my flyer (PNG)</button>
        <button type="button" class="btn btn-ghost btn-block" id="flyerShare" disabled><i class="fa-solid fa-share-nodes"></i> Share</button>
      </div>
      <?php if (!$p): ?><p class="small muted center" style="margin-top:14px;">Not registered yet? <a href="./#register">Register for the camp</a> first — seats are limited.</p><?php endif; ?>
    </div>
  </aside>
</div>
<?php app_footer('', ['assets/js/flyer.js']);
