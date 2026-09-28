<?php
// TEMPORARY local test helper — deleted after testing.
require __DIR__ . '/includes/bootstrap.php';
if (!is_local_host()) { http_response_code(404); exit; }
$_SESSION['admin_id'] = 1;
$_SESSION['admin_seen'] = time();
redirect('admin/' . preg_replace('/[^a-z0-9_.?=&-]/i', '', (string) ($_GET['to'] ?? 'index.php')));
