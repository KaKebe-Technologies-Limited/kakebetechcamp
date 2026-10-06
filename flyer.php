<?php
/**
 * "I will be there" flyer — upload a photo, get a personalised PNG to share.
 * The flyer is drawn in the browser (assets/js/flyer.js). The photo itself is never uploaded; when the flyer is
 * downloaded or shared, a copy of the finished flyer is kept for the team (api/flyer-save.php, Admin → flyers).
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

// The participant: from a personal flyer link (emails, payment page) or the logged-in session
$p = current_participant();
$ref = (string) ($_GET['ref'] ?? '');
if ($ref !== '' && sign_valid('flyer', $ref, $_GET['t'] ?? null)) {
    $p = find_registration_by_ref($ref) ?: $p;
}

app_header('My “I will be there” flyer');
?>
<div class="app-grid flyer-grid" id="flyerMaker"
     data-base="assets/img/flyer-base.webp?v=<?= filemtime(__DIR__ . '/assets/img/flyer-base.webp') ?>"
     data-site="<?= e(preg_replace('~^https?://~', '', base_url())) ?>"
     data-save="api/flyer-save.php" data-csrf="<?= e(csrf_token()) ?>">
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
          <small class="muted"><i class="fa-solid fa-circle-info"></i> When you download or share, Kakebe Tech Camp keeps a copy of your finished flyer so we can feature it.</small>
          <small class="muted"><i class="fa-solid fa-id-badge"></i> This photo is only for your flyer. The photo on your camp ticket is the one in <a href="portal/profile.php">your portal</a> — use a clear photo of your face there.</small>
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
          <b>Your name &amp; code number</b>
          <div class="field"><input id="flyerName" type="text" maxlength="40" autocomplete="name" placeholder="e.g. Akello Grace" value="<?= e($p['full_name'] ?? '') ?>" aria-label="Your name"></div>
          <div class="field"><input id="flyerCode" type="text" maxlength="12" autocomplete="off" placeholder="Code number, e.g. KTC26-0123" value="<?= e($p['reference'] ?? '') ?>"<?= $p ? ' readonly' : '' ?> aria-label="Your code number"></div>
          <small class="muted"><?= $p ? 'Your code number is printed under your name.' : 'Your code number is in your registration email (e.g. KTC26-0123).' ?></small>
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
