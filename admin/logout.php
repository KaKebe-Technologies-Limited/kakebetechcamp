<?php
require __DIR__ . '/_init.php';

unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
session_regenerate_id(true);
redirect('login.php');
