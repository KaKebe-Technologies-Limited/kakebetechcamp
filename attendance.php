<?php
/**
 * Quick attendance for Kakebe Tech Camp (short link: /attendance).
 * People type their name; the date and time are recorded automatically. The team sees the list in Admin → Attendance.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$error = null;
$name = '';
$done = $_SESSION['attendance_done'] ?? null;   // shown once after a submit, then the form is ready for the next person
unset($_SESSION['attendance_done']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['full_name'] ?? ''))), 0, 150);
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'The page expired. Please enter your name again.';
    } elseif (!empty($_POST['website'])) {
        redirect('attendance');
    } elseif (mb_strlen($name) < 3 || !preg_match('/\p{L}/u', $name)) {
        $error = 'Please enter your full name.';
    } elseif (too_many('attendance', client_ip(), 10, 120)) {
        $error = 'Too many entries from this network. Please wait a few minutes.';
    } else {
        // One entry per name per day — a second tap just shows the time already recorded
        $st = db()->prepare('SELECT created_at FROM attendance WHERE LOWER(full_name) = LOWER(?) AND created_at >= ? ORDER BY id LIMIT 1');
        $st->execute([$name, date('Y-m-d 00:00:00')]);
        $already = $st->fetchColumn();
        if (!$already) {
            db()->prepare('INSERT INTO attendance (full_name, ip, created_at) VALUES (?, ?, ?)')->execute([$name, client_ip(), now()]);
        }
        $_SESSION['attendance_done'] = ['name' => $name, 'at' => $already ?: now(), 'already' => (bool) $already];
        redirect('attendance');
    }
}

app_header('Attendance — Kakebe Tech Camp 2026', '', '', ['description' => 'Kakebe Tech Camp 2026 attendance.', 'canonical' => base_url('attendance')]);
?>
<section class="app-card narrow center att-card">
  <div class="card-icon ok"><i class="fa-solid fa-clipboard-user"></i></div>
  <h1>Attendance</h1>
  <p class="muted">Kakebe Tech Camp 2026 · <b class="js-clock"><?= e(date('D j M Y · g:i A')) ?></b></p>

  <?php if ($done): ?>
    <div class="att-done">
      <i class="fa-solid fa-circle-check"></i>
      <div><b><?= e($done['name']) ?></b><span><?= $done['already'] ? 'Already marked present today at' : 'Marked present at' ?> <?= e(date('g:i A · D j M', strtotime($done['at']))) ?></span></div>
    </div>
  <?php endif; ?>

  <form method="post" action="attendance" class="att-form" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="field<?= $error ? ' invalid' : '' ?>">
      <label for="a_name">Your full name</label>
      <input id="a_name" name="full_name" value="<?= e($name) ?>" maxlength="150" autocomplete="off" autocapitalize="words" placeholder="e.g. Akello Grace" required autofocus>
      <?php if ($error): ?><span class="err"><?= e($error) ?></span><?php endif; ?>
    </div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-check"></i> Mark me present</button>
    <p class="hint">The date and time are recorded automatically.</p>
  </form>
</section>
<script>
  // Live clock, so people can see the time being recorded
  (function () {
    var el = document.querySelector('.js-clock');
    if (!el) return;
    var tick = function () {
      var d = new Date();
      el.textContent = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    };
    tick();
    setInterval(tick, 15000);
  })();
</script>
<?php app_footer('');
