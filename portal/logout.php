<?php
require __DIR__ . '/_init.php';

unset($_SESSION['participant_id']);
forget_registration();   // the payment pop-up no longer greets them on this device
session_regenerate_id(true);
redirect('login.php');
