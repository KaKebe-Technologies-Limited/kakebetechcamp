<?php
require __DIR__ . '/_init.php';
require_admin();

$status = in_array($_GET['status'] ?? '', ['sent', 'failed', 'logged'], true) ? $_GET['status'] : '';
$list = q('SELECT * FROM email_log' . ($status ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 300', $status ? [$status] : [])->fetchAll();
$counts = array_column(q('SELECT status, COUNT(*) c FROM email_log GROUP BY status')->fetchAll(), 'c', 'status');

admin_header('Email log', 'emails', 'Every notification the system has sent (latest 300)');
?>
<div class="tabs big">
  <a href="emails.php" class="<?= $status === '' ? 'active' : '' ?>">All <em><?= array_sum($counts) ?></em></a>
  <a href="emails.php?status=sent" class="<?= $status === 'sent' ? 'active' : '' ?>">Sent <em><?= (int) ($counts['sent'] ?? 0) ?></em></a>
  <a href="emails.php?status=failed" class="<?= $status === 'failed' ? 'active' : '' ?>">Failed <em><?= (int) ($counts['failed'] ?? 0) ?></em></a>
  <a href="emails.php?status=logged" class="<?= $status === 'logged' ? 'active' : '' ?>">Logged only <em><?= (int) ($counts['logged'] ?? 0) ?></em></a>
</div>
<div class="card">
  <?php if ($list): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($list as $l): ?>
        <tr>
          <td class="nowrap muted"><?= e(date('j M, g:i a', strtotime($l['created_at']))) ?></td>
          <td><?= e($l['recipient']) ?></td>
          <td><?= e($l['subject']) ?><?php if ($l['error']): ?><small class="block err-text"><?= e($l['error']) ?></small><?php endif; ?></td>
          <td><span class="badge <?= $l['status'] === 'sent' ? 'st-confirmed' : ($l['status'] === 'logged' ? 'st-waitlisted' : 'st-cancelled') ?>"><?= e(ucfirst($l['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><div class="empty-state"><i class="fa-regular fa-envelope"></i><p>No emails yet.</p></div><?php endif; ?>
</div>
<?php admin_footer();
