<?php
/**
 * Quick attendance for Kakebe Tech Camp (short link: /attendance).
 * People type their names — one or several at once; the date and time are recorded automatically.
 * The team sees the list in Admin → Attendance.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$error = null;
$names = [''];
$done = $_SESSION['attendance_done'] ?? null;   // shown once after a submit, then the form is ready for the next people
unset($_SESSION['attendance_done']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $names = array_slice(array_values(array_filter(array_map(
        fn($n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $n)), 0, 150),
        (array) ($_POST['names'] ?? [])
    ), fn($n) => $n !== '')), 0, 60) ?: [''];
    $bad = array_filter($names, fn($n) => mb_strlen($n) < 3 || !preg_match('/\p{L}/u', $n));
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'The page expired. Please enter the names again.';
    } elseif (!empty($_POST['website'])) {
        redirect('attendance');
    } elseif ($names === [''] ) {
        $error = 'Please enter at least one full name.';
    } elseif ($bad) {
        $error = 'Please enter full names (check: ' . implode(', ', $bad) . ').';
    } elseif (too_many('attendance', client_ip(), 10, 400)) {
        $error = 'Too many entries from this network. Please wait a few minutes.';
    } else {
        // One entry per name per day — a name already marked just shows the time recorded earlier
        $find = db()->prepare('SELECT created_at FROM attendance WHERE LOWER(full_name) = LOWER(?) AND created_at >= ? ORDER BY id LIMIT 1');
        $add = db()->prepare('INSERT INTO attendance (full_name, ip, created_at) VALUES (?, ?, ?)');
        $result = [];
        foreach (array_unique($names) as $n) {
            $find->execute([$n, date('Y-m-d 00:00:00')]);
            $already = $find->fetchColumn();
            if (!$already) {
                $add->execute([$n, client_ip(), now()]);
            }
            $result[] = ['name' => $n, 'at' => $already ?: now(), 'already' => (bool) $already];
        }
        $_SESSION['attendance_done'] = $result;
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
      <div>
        <b><?= count($done) === 1 ? 'Marked present' : count($done) . ' people marked present' ?></b>
        <ul>
          <?php foreach ($done as $d): ?><li><b><?= e($d['name']) ?></b> — <?= $d['already'] ? 'already marked today at' : 'present at' ?> <?= e(date('g:i A', strtotime($d['at']))) ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="attendance" class="att-form" id="attForm" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="field<?= $error ? ' invalid' : '' ?>">
      <label>Full name<?= count($names) > 1 ? 's' : '' ?></label>
      <div class="att-rows" id="attRows">
        <?php foreach ($names as $i => $n): ?>
        <div class="att-row"><span><?= $i + 1 ?></span><input name="names[]" value="<?= e($n) ?>" maxlength="150" autocomplete="off" autocapitalize="words" placeholder="e.g. Akello Grace" aria-label="Full name <?= $i + 1 ?>"<?= $i === 0 ? ' autofocus' : '' ?>><button type="button" class="att-x" aria-label="Remove this name"<?= count($names) === 1 ? ' hidden' : '' ?>><i class="fa-solid fa-xmark"></i></button></div>
        <?php endforeach; ?>
      </div>
      <?php if ($error): ?><span class="err"><?= e($error) ?></span><?php endif; ?>
      <button type="button" class="btn btn-light btn-sm att-add" id="attAdd"><i class="fa-solid fa-plus"></i> Add another person</button>
    </div>
    <button class="btn btn-primary btn-block" type="submit" id="attSubmit"><i class="fa-solid fa-check"></i> <span>Mark present</span></button>
    <p class="hint">Enter one name or several. The date and time are recorded automatically.</p>
  </form>
</section>
<script>
  (function () {
    // Live clock, so people can see the time being recorded
    var el = document.querySelector('.js-clock');
    var tick = function () {
      var d = new Date();
      el.textContent = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    };
    if (el) { tick(); setInterval(tick, 15000); }

    // Several names: add / remove rows; Enter in the last box adds a new row
    var rows = document.getElementById('attRows');
    var label = document.querySelector('#attSubmit span');
    var renumber = function () {
      var all = rows.querySelectorAll('.att-row');
      all.forEach(function (r, i) {
        r.querySelector('span').textContent = i + 1;
        r.querySelector('input').setAttribute('aria-label', 'Full name ' + (i + 1));
        r.querySelector('.att-x').hidden = all.length === 1;
      });
      var filled = Array.prototype.filter.call(rows.querySelectorAll('input'), function (i) { return i.value.trim() !== ''; }).length;
      label.textContent = filled > 1 ? 'Mark ' + filled + ' people present' : 'Mark present';
    };
    var addRow = function () {
      var row = rows.querySelector('.att-row').cloneNode(true);
      var input = row.querySelector('input');
      input.value = '';
      input.removeAttribute('autofocus');
      rows.appendChild(row);
      renumber();
      input.focus();
    };
    document.getElementById('attAdd').addEventListener('click', addRow);
    rows.addEventListener('click', function (e) {
      var x = e.target.closest('.att-x');
      if (!x) return;
      x.closest('.att-row').remove();
      renumber();
    });
    rows.addEventListener('input', renumber);
    rows.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var inputs = rows.querySelectorAll('input');
      if (e.target === inputs[inputs.length - 1] && e.target.value.trim() !== '') {
        e.preventDefault();
        addRow();
      }
    });
    // Pasting a list (one name per line) fills several rows at once
    rows.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text');
      var lines = text.split(/\r?\n|,|;/).map(function (s) { return s.trim(); }).filter(Boolean);
      if (lines.length < 2) return;
      e.preventDefault();
      var target = e.target;
      target.value = lines.shift();
      lines.forEach(function (n) { addRow(); var all = rows.querySelectorAll('input'); all[all.length - 1].value = n; });
      renumber();
    });
    renumber();
  })();
</script>
<?php app_footer('');
