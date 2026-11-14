<?php
/**
 * NASA Space Apps Challenge 2026 — registration portal (short link: /NASA26).
 * Kakebe Tech Camp offers 48 spots across up to 12 challenge groups (max 4 per group).
 * Virtual competition; accepted participants are invited to the physical session on 27 Feb 2027.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$errors = [];
$done   = null;

/* ---------- Thank-you page after registering ---------- */
if (isset($_GET['done'])) {
    $ref = (string) $_GET['done'];
    if (sign_valid('nasa-done', $ref, $_GET['t'] ?? null)) {
        $done = q('SELECT r.*, g.name group_name FROM nasa_registrations r JOIN nasa_groups g ON g.id = r.group_id WHERE r.reference = ?', [$ref])->fetch() ?: null;
    }
}

/* ---------- Helpers ---------- */
$groups = q("SELECT g.*, COUNT(r.id) registered
             FROM nasa_groups g
             LEFT JOIN nasa_registrations r ON r.group_id = g.id
             GROUP BY g.id
             ORDER BY g.sort_order, g.id")->fetchAll();

$totalCap        = max(1, (int) setting('nasa_total_cap', '48'));
$totalRegistered = (int) q('SELECT COUNT(*) FROM nasa_registrations')->fetchColumn();
$spotsLeft       = max(0, $totalCap - $totalRegistered);
$waUrl           = setting('nasa_whatsapp_url', '');

/* ---------- Register ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['done'])) {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired — please try again.';
    } else {
        $clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
        $full_name = $clean('full_name', 150);
        $email     = strtolower($clean('email', 190));
        $phone     = $clean('phone', 40);
        $group_id  = (int) ($_POST['group_id'] ?? 0);

        if (mb_strlen($full_name) < 3 || !preg_match('/\p{L}/u', $full_name)) {
            $errors['full_name'] = 'Please enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if (mb_strlen($phone) < 7) {
            $errors['phone'] = 'Please enter your phone number.';
        }

        $group = null;
        foreach ($groups as $g) {
            if ((int) $g['id'] === $group_id) { $group = $g; break; }
        }
        if (!$group) {
            $errors['group_id'] = 'Please choose a challenge group.';
        } elseif ((int) $group['registered'] >= (int) $group['capacity']) {
            $errors['group_id'] = 'That group is full. Please choose another one.';
        }

        // Check if the total cap is reached
        if (!$errors && $spotsLeft <= 0) {
            $errors['form'] = 'All 48 spots have been taken. Thank you for your interest in Kakebe Space Apps!';
        }

        // Duplicate email
        if (!$errors) {
            $existing = q('SELECT id FROM nasa_registrations WHERE email = ?', [$email])->fetch();
            if ($existing) {
                $errors['email'] = 'This email address is already registered for Kakebe Space Apps.';
            }
        }

        // Rate limit
        if (!$errors && too_many('nasa_registrations', client_ip(), 60, 5)) {
            $errors['form'] = 'Too many registrations from your network. Please try again in an hour.';
        }

        if (!$errors) {
            $ref = nasa_next_reference();
            q('INSERT INTO nasa_registrations (reference, full_name, email, phone, group_id, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
              [$ref, $full_name, $email, $phone, $group_id, client_ip(), now()]);

            // Confirmation email to the participant
            [$subj, $html] = tpl_nasa_registered(['reference' => $ref, 'full_name' => $full_name, 'email' => $email, 'group_name' => $group['name']], $waUrl);
            send_mail($email, $subj, $html);

            // Notify the team
            notify_team('nasa_registration', "New NASA Space Apps registration: $full_name ($ref) — {$group['name']}",
                "<p><b>$full_name</b> ($email · $phone) registered for <b>{$group['name']}</b> ($ref).</p>");

            redirect('NASA26?done=' . rawurlencode($ref) . '&t=' . sign('nasa-done', $ref));
        }
    }
}

/* ---------- Helpers ---------- */
function nasa_next_reference(): string
{
    $n = (int) db()->query('SELECT COUNT(*) FROM nasa_registrations')->fetchColumn() + 1;
    return 'NSA26-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
}

function tpl_nasa_registered(array $r, string $waUrl): array
{
    $name     = e(explode(' ', $r['full_name'])[0]);
    $group    = e($r['group_name']);
    $ref      = e($r['reference']);
    $wa       = $waUrl ? '<p style="margin:20px 0"><a href="' . e($waUrl) . '" style="display:inline-block;background:#25D366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Join the WhatsApp group →</a></p>' : '';
    $subject  = "You're in! Kakebe Space Apps 2026 — {$r['group_name']}";
    $html     = <<<HTML
<p>Hi $name,</p>
<p>You are registered for <b>Kakebe Space Apps 2026</b> — the NASA Space Apps Challenge, brought to you by Kakebe Technologies Limited as an added benefit of Kakebe Tech Camp 2026. 🚀</p>
<p><b>Your challenge group:</b> $group<br><b>Your registration code:</b> $ref</p>
<p>The competition is fully virtual. At the end, the top teams will be invited to the <b>Kakebe Space Apps physical session on 27 February 2027</b>.</p>
$wa
<p>Stay tuned for more details from the Kakebe team. We are excited to have you on board!</p>
<p>Warm regards,<br>The Kakebe Tech Camp team</p>
HTML;
    return [$subject, $html];
}

/* ==================== HTML ==================== */
app_header('Kakebe Space Apps 2026 — NASA Space Apps Challenge', '', '', [
    'description' => 'Register for Kakebe Space Apps 2026 — the NASA Space Apps Challenge, brought to you by Kakebe Tech Camp. 48 spots, 8 challenge groups, fully virtual. Physical session 27 Feb 2027.',
    'canonical'   => base_url('NASA26'),
]);
?>

<?php if ($done): ?>
<!-- ======= Thank-you page ======= -->
<section class="vol-thanks nasa-thanks" style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:40px 16px">
  <div class="vol-thanks-inner" style="text-align:center;max-width:560px">
    <div class="nasa-rocket-emoji" style="font-size:64px;margin-bottom:16px">🚀</div>
    <h1 style="font-size:clamp(1.6rem,4vw,2.2rem);margin:0 0 10px">You're in, <?= e(explode(' ', $done['full_name'])[0]) ?>!</h1>
    <p style="font-size:1.1rem;color:#4A5372;margin:0 0 20px">You have registered for <b><?= e($done['group_name']) ?></b> in Kakebe Space Apps 2026.</p>
    <div class="nasa-ref" style="display:inline-block;background:#f2f4f9;border-radius:12px;padding:12px 24px;margin:0 0 24px;font-size:1rem">
      Your code: <b><?= e($done['reference']) ?></b>
    </div>
    <p style="color:#4A5372;margin:0 0 24px;line-height:1.6">The competition is <b>fully virtual</b>. Top teams will be invited to the physical session on <b>27&nbsp;February&nbsp;2027</b>. A confirmation has been sent to <b><?= e($done['email']) ?></b>.</p>
    <?php if ($waUrl): ?>
    <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="btn-wa-big" style="display:inline-flex;align-items:center;gap:10px;background:#25D366;color:#fff;border-radius:14px;padding:14px 28px;font-size:1.05rem;font-weight:700;text-decoration:none;margin-bottom:20px">
      <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.136.564 4.14 1.546 5.874L0 24l6.302-1.516A11.94 11.94 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.6a9.572 9.572 0 0 1-4.879-1.336l-.35-.208-3.741.9.934-3.643-.228-.375A9.555 9.555 0 0 1 2.4 12c0-5.292 4.308-9.6 9.6-9.6 5.293 0 9.6 4.308 9.6 9.6 0 5.293-4.307 9.6-9.6 9.6z"/></svg>
      Join the WhatsApp group
    </a>
    <?php endif; ?>
    <p><a href="<?= e(base_url('NASA26')) ?>" style="color:#0F2557">← Back to Kakebe Space Apps</a></p>
  </div>
</section>

<?php else: ?>
<!-- ======= Registration page ======= -->

<!-- Hero -->
<section class="vol-hero nasa-hero" style="background:linear-gradient(135deg,#0F2557 0%,#162E6E 55%,#1a3a8a 100%);color:#fff;padding:clamp(40px,8vw,80px) 16px 0;text-align:center">
  <div class="container" style="max-width:720px;margin:0 auto">
    <div style="font-size:48px;margin-bottom:12px">🚀</div>
    <div style="display:inline-block;background:rgba(255,255,255,.12);border-radius:999px;padding:5px 16px;font-size:13px;font-weight:700;letter-spacing:.06em;margin-bottom:14px;text-transform:uppercase">Kakebe Space Apps 2026</div>
    <h1 style="font-size:clamp(1.8rem,5vw,2.8rem);line-height:1.2;margin:0 0 16px;font-weight:800">NASA Space Apps<br>Challenge 2026</h1>
    <p style="font-size:clamp(1rem,2.5vw,1.15rem);opacity:.9;line-height:1.6;margin:0 0 24px;max-width:580px;margin-left:auto;margin-right:auto">
      Kakebe Technologies is giving Kakebe Tech Camp 2026 participants an added advantage — access to the global NASA Space Apps Challenge. <b>Fully virtual</b>. Top teams are invited to the <b>physical session on 27 Feb 2027</b>.
    </p>
    <!-- Spot counter -->
    <div class="nasa-counter" style="display:inline-flex;align-items:center;gap:14px;background:rgba(255,255,255,.12);border-radius:16px;padding:14px 24px;margin-bottom:0">
      <div style="text-align:center">
        <div style="font-size:2rem;font-weight:800;line-height:1"><?= $spotsLeft > 0 ? $spotsLeft : 0 ?></div>
        <div style="font-size:12px;opacity:.8;text-transform:uppercase;letter-spacing:.06em">Spots left</div>
      </div>
      <div style="width:1px;height:40px;background:rgba(255,255,255,.25)"></div>
      <div style="text-align:center">
        <div style="font-size:2rem;font-weight:800;line-height:1"><?= $totalRegistered ?></div>
        <div style="font-size:12px;opacity:.8;text-transform:uppercase;letter-spacing:.06em">Registered</div>
      </div>
      <div style="width:1px;height:40px;background:rgba(255,255,255,.25)"></div>
      <div style="text-align:center">
        <div style="font-size:2rem;font-weight:800;line-height:1"><?= $totalCap ?></div>
        <div style="font-size:12px;opacity:.8;text-transform:uppercase;letter-spacing:.06em">Total spots</div>
      </div>
    </div>
  </div>
  <!-- Wave divider -->
  <div style="margin-top:40px;line-height:0"><svg viewBox="0 0 1200 60" xmlns="http://www.w3.org/2000/svg" style="display:block;width:100%;fill:#f4f7fb"><path d="M0 60 V30 Q300 0 600 30 Q900 60 1200 30 V60Z"/></svg></div>
</section>

<div class="container" style="max-width:1100px;margin:0 auto;padding:0 16px">

  <!-- How it works -->
  <section style="padding:40px 0 20px;text-align:center">
    <h2 style="font-size:clamp(1.3rem,3vw,1.7rem);margin:0 0 10px;color:#0F2557">How it works</h2>
    <p style="color:#4A5372;max-width:620px;margin:0 auto 32px;line-height:1.6">Join a challenge group below, compete virtually with your group, and the best teams will represent Kakebe at the physical session in Kitgum in February 2027.</p>
    <div class="nasa-steps" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;text-align:left">
      <?php foreach ([
        ['🎯', 'Choose a group', 'Pick the challenge area that matches your skills and interests.'],
        ['📝', 'Register', 'Fill in your name, email and phone number. Takes 30 seconds.'],
        ['💬', 'Join WhatsApp', 'Join your group\'s WhatsApp to meet your teammates and get started.'],
        ['🏆', 'Compete & win', 'Top teams are invited to the Kakebe Space Apps physical session on 27 Feb 2027.'],
      ] as [$icon, $title, $text]): ?>
      <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(15,37,87,.07)">
        <div style="font-size:28px;margin-bottom:8px"><?= $icon ?></div>
        <b style="color:#0F2557;display:block;margin-bottom:4px"><?= e($title) ?></b>
        <p style="color:#4A5372;font-size:14px;margin:0;line-height:1.5"><?= e($text) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if (!empty($errors['form'])): ?>
  <div class="flash flash-error" style="margin:16px 0"><p><?= e($errors['form']) ?></p></div>
  <?php endif; ?>

  <!-- Groups grid -->
  <section style="padding:20px 0 40px" id="groups">
    <h2 style="font-size:clamp(1.3rem,3vw,1.7rem);margin:0 0 6px;color:#0F2557">Challenge groups</h2>
    <p style="color:#4A5372;margin:0 0 24px">Choose the group you want to register under. Each group takes a maximum of <?= (int) ($groups[0]['capacity'] ?? 4) ?> participants.</p>

    <?php if ($spotsLeft <= 0): ?>
    <div class="flash" style="margin:0 0 20px;padding:16px 20px;background:#fff3cd;border-radius:12px;border:1px solid #ffd875;color:#7a5a00">
      <b>All 48 spots are taken!</b> Registrations for Kakebe Space Apps 2026 are now closed. Thank you to everyone who joined.
    </div>
    <?php endif; ?>

    <div class="nasa-groups" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px">
      <?php foreach ($groups as $g):
        $reg  = (int) $g['registered'];
        $cap  = (int) $g['capacity'];
        $full = $reg >= $cap;
        $pct  = $cap > 0 ? round($reg / $cap * 100) : 0;
      ?>
      <div class="nasa-group-card<?= $full ? ' nasa-full' : '' ?>" style="background:#fff;border-radius:18px;padding:22px;box-shadow:0 2px 14px rgba(15,37,87,.08);border:2px solid <?= $full ? '#e1e4ed' : '#e1e4ed' ?>;display:flex;flex-direction:column;gap:10px;transition:border-color .2s,box-shadow .2s">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
          <h3 style="font-size:1rem;font-weight:700;color:#0F2557;margin:0;line-height:1.3"><?= e($g['name']) ?></h3>
          <?php if ($full): ?>
            <span style="flex-shrink:0;background:#ffe4e4;color:#c4121e;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700">Full</span>
          <?php else: ?>
            <span style="flex-shrink:0;background:#e8f5ff;color:#0F2557;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700"><?= $cap - $reg ?> left</span>
          <?php endif; ?>
        </div>
        <?php if ($g['description']): ?>
        <p style="font-size:14px;color:#4A5372;margin:0;line-height:1.5;flex:1"><?= e((string) $g['description']) ?></p>
        <?php endif; ?>
        <!-- Capacity bar -->
        <div>
          <div style="display:flex;justify-content:space-between;font-size:12px;color:#7A829E;margin-bottom:4px">
            <span><?= $reg ?> / <?= $cap ?> registered</span>
            <?php if ($full): ?><span style="color:#c4121e">Group closed</span><?php endif; ?>
          </div>
          <div style="background:#e8ecf5;border-radius:999px;height:6px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $full ? '#E11D2A' : '#1F4FD1' ?>;border-radius:999px;transition:width .4s"></div>
          </div>
        </div>
        <?php if (!$full && $spotsLeft > 0): ?>
        <button type="button" class="nasa-join-btn" data-group-id="<?= (int) $g['id'] ?>" data-group-name="<?= e($g['name']) ?>"
                style="display:flex;align-items:center;justify-content:center;gap:7px;background:#0F2557;color:#fff;border:0;border-radius:12px;padding:12px 18px;font-size:15px;font-weight:700;cursor:pointer;transition:background .2s">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 1 1 0 16A8 8 0 0 1 10 2zm1 4H9v3H6v2h3v3h2v-3h3V9h-3V6z"/></svg>
          Join this group
        </button>
        <?php else: ?>
        <button type="button" disabled style="display:flex;align-items:center;justify-content:center;gap:7px;background:#e1e4ed;color:#9aa1b8;border:0;border-radius:12px;padding:12px 18px;font-size:15px;font-weight:700;cursor:not-allowed">
          <?= $full ? 'Group is full' : 'Registration closed' ?>
        </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Added value section -->
  <section style="background:linear-gradient(135deg,#0F2557,#1F4FD1);border-radius:20px;padding:clamp(28px,5vw,48px);margin-bottom:48px;color:#fff;text-align:center">
    <div style="font-size:36px;margin-bottom:12px">🌍</div>
    <h2 style="font-size:clamp(1.2rem,3vw,1.6rem);margin:0 0 10px;font-weight:800">An added advantage for Kakebe Tech Camp participants</h2>
    <p style="opacity:.9;max-width:600px;margin:0 auto 20px;line-height:1.6;font-size:15px">
      As you prepare for Kakebe Tech Camp 2026 (14–23 December, Kitgum), Kakebe Technologies is giving you free access to the NASA Space Apps Challenge — a global hackathon in which tens of thousands of people around the world use NASA data to solve real challenges. Compete virtually from home, grow your skills, and the best teams will be invited to the Kakebe Space Apps physical event on <b>27 February 2027</b>.
    </p>
    <a href="#groups" style="display:inline-block;background:#fff;color:#0F2557;border-radius:10px;padding:12px 28px;font-weight:800;text-decoration:none;font-size:15px">Register for a group →</a>
  </section>

</div><!-- /container -->

<!-- ======= Registration modal ======= -->
<div id="nasaModal" role="dialog" aria-modal="true" aria-labelledby="nasaModalTitle" hidden
     style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,26,63,.55);backdrop-filter:blur(3px)">
  <div style="background:#fff;border-radius:22px;padding:clamp(24px,5vw,36px);max-width:480px;width:100%;max-height:calc(100vh - 32px);overflow:auto;position:relative;animation:nsUp .3s cubic-bezier(.2,.9,.3,1.2)">
    <button type="button" id="nasaModalClose" aria-label="Close" style="position:absolute;top:12px;right:12px;width:36px;height:36px;border:0;border-radius:50%;background:#f2f4f9;color:#4A5372;font-size:22px;line-height:1;cursor:pointer">×</button>
    <h2 id="nasaModalTitle" style="font-size:1.3rem;margin:0 0 4px;color:#0F2557">Register for Kakebe Space Apps</h2>
    <p id="nasaModalGroup" style="margin:0 0 20px;color:#1F4FD1;font-weight:700;font-size:15px"></p>
    <form method="post" id="nasaForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="group_id" id="nasaGroupId">
      <div style="display:flex;flex-direction:column;gap:14px">
        <label style="display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600;color:#1B2340">
          Full name *
          <input type="text" name="full_name" value="<?= e((string) ($_POST['full_name'] ?? '')) ?>" autocomplete="name" required
                 style="border:1.5px solid #d1d5e0;border-radius:10px;padding:11px 14px;font-size:15px;font-family:inherit;outline:none;transition:border-color .2s"
                 placeholder="Your full name">
          <?php if (!empty($errors['full_name'])): ?><span style="color:#c4121e;font-size:13px"><?= e($errors['full_name']) ?></span><?php endif; ?>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600;color:#1B2340">
          Email address *
          <input type="email" name="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>" autocomplete="email" required
                 style="border:1.5px solid #d1d5e0;border-radius:10px;padding:11px 14px;font-size:15px;font-family:inherit;outline:none;transition:border-color .2s"
                 placeholder="your@email.com">
          <?php if (!empty($errors['email'])): ?><span style="color:#c4121e;font-size:13px"><?= e($errors['email']) ?></span><?php endif; ?>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600;color:#1B2340">
          Phone / WhatsApp *
          <input type="tel" name="phone" value="<?= e((string) ($_POST['phone'] ?? '')) ?>" autocomplete="tel" required
                 style="border:1.5px solid #d1d5e0;border-radius:10px;padding:11px 14px;font-size:15px;font-family:inherit;outline:none;transition:border-color .2s"
                 placeholder="+256 700 000 000">
          <?php if (!empty($errors['phone'])): ?><span style="color:#c4121e;font-size:13px"><?= e($errors['phone']) ?></span><?php endif; ?>
        </label>
        <?php if (!empty($errors['group_id'])): ?><p style="color:#c4121e;font-size:13px;margin:0"><?= e($errors['group_id']) ?></p><?php endif; ?>
        <button type="submit" style="background:#0F2557;color:#fff;border:0;border-radius:12px;padding:14px;font-size:16px;font-weight:800;cursor:pointer;margin-top:4px">
          Register for this group →
        </button>
        <p style="font-size:12px;color:#7A829E;margin:0;text-align:center">By registering you agree to be contacted by the Kakebe Tech Camp team about Kakebe Space Apps 2026.</p>
      </div>
    </form>
  </div>
</div>

<style>
@keyframes nsUp{from{opacity:0;transform:translateY(24px) scale(.97)}}
.nasa-group-card:not(.nasa-full):hover{border-color:#1F4FD1!important;box-shadow:0 6px 24px rgba(15,37,87,.14)!important}
.nasa-join-btn:hover{background:#1F4FD1!important}
@media(prefers-reduced-motion:reduce){.nasa-group-card,#nasaModal>div{animation:none;transition:none}}
</style>
<script>
(function () {
  const modal   = document.getElementById('nasaModal');
  const closeBtn = document.getElementById('nasaModalClose');
  const groupId = document.getElementById('nasaGroupId');
  const groupLbl = document.getElementById('nasaModalGroup');
  let lastFocus;

  const open = (id, name) => {
    groupId.value = id;
    groupLbl.textContent = '→ ' + name;
    modal.hidden = false;
    lastFocus = document.activeElement;
    document.body.style.overflow = 'hidden';
    modal.querySelector('input[name="full_name"]').focus();
  };
  const close = () => {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };

  document.querySelectorAll('.nasa-join-btn').forEach(btn => {
    btn.addEventListener('click', () => open(btn.dataset.groupId, btn.dataset.groupName));
  });
  closeBtn.addEventListener('click', close);
  modal.addEventListener('click', e => { if (e.target === modal) close(); });
  document.addEventListener('keydown', e => { if (!modal.hidden && e.key === 'Escape') close(); });

  // Open the modal automatically if there were validation errors (form was submitted)
  <?php if ($errors && empty($errors['form']) && !empty($_POST['group_id'])): ?>
  open(<?= (int) ($_POST['group_id'] ?? 0) ?>, <?= json_encode(array_column($groups, 'name', 'id')[(int) ($_POST['group_id'] ?? 0)] ?? '', JSON_HEX_TAG) ?>);
  <?php endif; ?>
})();
</script>

<?php endif; ?>

<?php app_footer(); ?>
