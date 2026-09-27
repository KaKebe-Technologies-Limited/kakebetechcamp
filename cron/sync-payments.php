<?php
/**
 * Reconcile pending ioTec payments (run every 5–10 minutes via cron / Task Scheduler):
 *   php /path/to/kakebetechcamp/cron/sync-payments.php
 * Confirms payments whose browser window was closed before ioTec finished, and emails their receipts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/includes/bootstrap.php';

$ids = db()->prepare("SELECT id FROM payments WHERE status = 'pending' AND provider = 'iotec' AND created_at BETWEEN ? AND ?");
$ids->execute([date('Y-m-d H:i:s', strtotime('-3 days')), date('Y-m-d H:i:s', time() - 30)]);
$counts = ['success' => 0, 'failed' => 0, 'pending' => 0];
foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $p = sync_payment((int) $id, true);
    $counts[$p['status'] ?? 'pending']++;
}
// Give up on payments still pending after 3 days.
db()->prepare("UPDATE payments SET status = 'failed', message = 'Expired — no confirmation from ioTec' WHERE status = 'pending' AND provider = 'iotec' AND created_at < ?")
    ->execute([date('Y-m-d H:i:s', strtotime('-3 days'))]);

echo date('c') . " synced: {$counts['success']} success, {$counts['failed']} failed, {$counts['pending']} pending\n";
