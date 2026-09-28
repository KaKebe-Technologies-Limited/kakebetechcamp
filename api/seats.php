<?php
/**
 * GET /api/seats.php — seats left at Kakebe Tech Camp (the website refreshes its counter from this).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

json_response(['ok' => true, 'capacity' => seat_capacity(), 'left' => seats_left()]);
