<?php
/**
 * POST /api/track.php — page views and clicks from the website (see includes/traffic.php).
 * Visits by logged-in admins and by bots are not counted.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$data = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
if (is_array($data) && empty($_SESSION['admin_id']) && empty($_SESSION['impersonated_by'])) {
    traffic_record($data);
}
http_response_code(204);
