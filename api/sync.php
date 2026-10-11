<?php
/**
 * POST /api/sync.php — background check of pending payments (see auto_sync_pending()).
 * Triggered quietly by website visits and the control panel; does nothing if a check ran in the last 3 minutes.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
ignore_user_abort(true);
@set_time_limit(60);
auto_sync_pending();
http_response_code(204);
