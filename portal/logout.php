<?php
require __DIR__ . '/_init.php';

unset($_SESSION['participant_id']);
session_regenerate_id(true);
redirect('login.php');
