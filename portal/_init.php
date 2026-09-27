<?php
/**
 * Participant portal bootstrap.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT . '/includes/app_layout.php';

header('X-Frame-Options: DENY');

function require_participant(): array
{
    $p = current_participant();
    if (!$p || $p['status'] === 'cancelled') {
        unset($_SESSION['participant_id']);
        redirect('login.php');
    }
    return $p;
}

function portal_flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['portal_flash'] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['portal_flash'] ?? null;
    unset($_SESSION['portal_flash']);
    return $f;
}

function participant_photo_url(array $p): ?string
{
    return photo_path($p['photo']) ? '../photo.php?me=1&v=' . substr(md5((string) $p['photo']), 0, 8) : null;
}
