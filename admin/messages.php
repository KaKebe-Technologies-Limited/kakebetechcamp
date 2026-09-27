<?php
require __DIR__ . '/_init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'read':
            q('UPDATE messages SET is_read = 1 WHERE id = ?', [$id]);
            break;
        case 'unread':
            q('UPDATE messages SET is_read = 0 WHERE id = ?', [$id]);
            break;
        case 'read_all':
            q('UPDATE messages SET is_read = 1 WHERE is_read = 0');
            flash('All messages marked as read.');
            break;
        case 'delete':
            q('DELETE FROM messages WHERE id = ?', [$id]);
            flash('Message deleted.');
            break;
    }
    redirect('messages.php' . (isset($_POST['filter']) && $_POST['filter'] === 'unread' ? '?filter=unread' : ''));
}

$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$list = q('SELECT * FROM messages ' . ($filter === 'unread' ? 'WHERE is_read = 0 ' : '') . 'ORDER BY id DESC LIMIT 300')->fetchAll();
$unread = (int) q('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();

admin_header('Messages', 'messages');
?>
<div class="card">
  <div class="card-head list-head">
    <div class="tabs">
      <a href="messages.php" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
      <a href="messages.php?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread <?php if ($unread): ?><em><?= $unread ?></em><?php endif; ?></a>
    </div>
    <?php if ($unread): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-check-double"></i> Mark all read</button></form>
    <?php endif; ?>
  </div>

  <?php if ($list): ?>
  <div class="messages">
    <?php foreach ($list as $m): ?>
    <details class="msg <?= $m['is_read'] ? '' : 'unread' ?>" data-id="<?= (int) $m['id'] ?>">
      <summary>
        <span class="msg-avatar"><?= e(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span>
        <span class="msg-main"><b><?= e($m['name']) ?></b><small><?= e(mb_strimwidth($m['message'], 0, 110, '…')) ?></small></span>
        <span class="msg-time muted"><?= e(time_ago($m['created_at'])) ?></span>
      </summary>
      <div class="msg-body">
        <p class="msg-meta"><a href="mailto:<?= e($m['email']) ?>"><i class="fa-regular fa-envelope"></i> <?= e($m['email']) ?></a><?php if ($m['phone']): ?> · <a href="<?= e(tel_link($m['phone'])) ?>"><i class="fa-solid fa-phone"></i> <?= e($m['phone']) ?></a><?php endif; ?> · <?= e(date('D j M Y, g:i a', strtotime($m['created_at']))) ?></p>
        <p><?= nl2br(e($m['message'])) ?></p>
        <div class="msg-actions">
          <a class="btn btn-navy btn-sm" href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: Your message to Kakebe Tech Camp') ?>"><i class="fa-solid fa-reply"></i> Reply by email</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="filter" value="<?= $filter ?>"><input type="hidden" name="action" value="<?= $m['is_read'] ? 'unread' : 'read' ?>"><button class="btn btn-light btn-sm" type="submit"><?= $m['is_read'] ? 'Mark unread' : 'Mark read' ?></button></form>
          <form method="post" data-confirm="Delete this message?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </div>
    </details>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-regular fa-envelope-open"></i><p><?= $filter === 'unread' ? 'No unread messages. 🎉' : 'No messages yet.' ?></p></div>
  <?php endif; ?>
</div>
<form method="post" id="markReadForm" hidden><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value=""></form>
<?php admin_footer();
