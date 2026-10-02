<?php
/**
 * Mentorship Program & Digital Bridge Internship Program (DBIP): free registration,
 * confirmed by an emailed link. Weekly online sessions every Monday, 8:00 – 9:30 PM (EAT).
 */

/** The internship fields people choose from (up to three). Admin → Mentorship & DBIP can change them. */
function mentorship_tracks(): array
{
    $custom = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) setting('mentorship_tracks')) ?: [])));
    return $custom ?: [
        'AI & Software Development',
        'Content Creation & Digital Media',
        'Digital Marketing & Branding',
        'Entrepreneurship & Business Innovation',
        'Robotics & Automation',
    ];
}

function mentorship_open(): bool
{
    return setting('mentorship_open', '1') === '1';
}

/** Places in the program (first come, first served — counted when people confirm their email). */
function mentorship_capacity(): int
{
    return max(1, (int) setting('mentorship_capacity', 50));
}

function mentorship_taken(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM mentorship_registrations WHERE status = 'confirmed'")->fetchColumn();
}

function mentorship_places_left(): int
{
    return max(0, mentorship_capacity() - mentorship_taken());
}

/** Session schedule: first and last Monday, and the next session from today. */
function mentorship_schedule(): array
{
    $start = strtotime((string) setting('mentorship_start_date', '2026-10-05')) ?: strtotime('2026-10-05');
    $end = strtotime((string) setting('mentorship_end_date', '2026-11-30')) ?: strtotime('2026-11-30');
    $from = max($start, strtotime('today'));
    $next = date('N', $from) == 1 ? $from : strtotime('next monday', $from);
    return [
        'start'   => $start,
        'end'     => $end,
        'next'    => $next <= $end ? $next : null,
        'started' => strtotime('today') > $start,
        'time'    => 'Every Monday, 8:00 – 9:30 PM (EAT)',
    ];
}

/** "Add to Google Calendar" link for the weekly session (20:00 – 21:30 EAT = 17:00 – 18:30 UTC). */
function mentorship_calendar_url(): string
{
    $s = mentorship_schedule();
    $first = $s['next'] ?? $s['start'];
    $text = 'Kakebe Mentorship & DBIP — weekly online session';
    $details = 'Weekly online session of the Kakebe Mentorship Program & Digital Bridge Internship Program. '
        . (setting('mentorship_session_link') ? 'Join: ' . setting('mentorship_session_link') : 'The joining link is shared by email and WhatsApp before each session.');
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode($text)
        . '&dates=' . date('Ymd', $first) . 'T170000Z/' . date('Ymd', $first) . 'T183000Z'
        . '&recur=' . rawurlencode('RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=' . date('Ymd', $s['end']) . 'T235959Z')
        . '&details=' . rawurlencode($details);
}

function mentorship_reference(int $id): string
{
    return 'MDB26-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function find_mentee(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM mentorship_registrations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** The link in the confirmation email. */
function mentorship_confirm_url(array $m): string
{
    return base_url('mentorship?confirm=' . (int) $m['id'] . '&t=' . sign('mentorship-confirm', $m['id'] . '|' . $m['email']));
}

function mentorship_whatsapp_group(): string
{
    $url = trim((string) setting('mentorship_whatsapp_link'));
    return preg_match('~^https://(chat\.whatsapp\.com|wa\.me|whatsapp\.com)/~i', $url) ? $url : '';
}

/** Email the confirmation link and note when it was sent. */
function send_mentorship_link(array $m, ?string &$error = null): bool
{
    $link = mentorship_confirm_url($m);
    $inner = '<p><strong>Hi ' . first_name($m['full_name']) . ',</strong></p>'
        . '<p>Thank you for registering for the <strong>Kakebe Mentorship Program &amp; Digital Bridge Internship Program (DBIP)</strong>. Please confirm your email address to complete your registration.</p>'
        . kt_btn('Confirm my registration', $link)
        . '<p style="font-size:13px;color:#6B7390;">If the button does not work, copy this address into your browser:<br><a href="' . e($link) . '">' . e($link) . '</a></p>'
        . '<p style="font-size:13px;color:#6B7390;">If you did not register, you can safely ignore this email.</p>';
    $ok = send_mail($m['email'], 'Confirm your Kakebe Mentorship & DBIP registration', kt_email('Mentorship & DBIP', $inner, 'One click to confirm your free mentorship place'), setting('contact_email') ?: null, $error);
    if ($ok) {
        db()->prepare('UPDATE mentorship_registrations SET link_sent_at = ? WHERE id = ?')->execute([now(), $m['id']]);
    }
    return $ok;
}

/** Welcome email once the address is confirmed: schedule, tracks, commitments and the WhatsApp group. */
function send_mentorship_welcome(array $m): bool
{
    $s = mentorship_schedule();
    $first = $s['next'] ? date('l, j F Y', $s['next']) : null;
    $inner = '<p><strong>Hi ' . first_name($m['full_name']) . ',</strong></p>'
        . '<p>Welcome! 🎉 Your place in the <strong>Kakebe Mentorship Program &amp; Digital Bridge Internship Program (DBIP)</strong> is confirmed. The program is completely free.</p>'
        . "<div class='ref'><small>YOUR MENTORSHIP NUMBER</small><b>" . e($m['reference']) . '</b></div>'
        . kt_detail([
            '📅 Program' => date('j F', $s['start']) . ' – ' . date('j F Y', $s['end']),
            '🗓️ Online sessions' => $s['time'],
            '▶️ ' . ($s['started'] ? 'Next session' : 'First session') => $first,
            '🔗 Join link' => setting('mentorship_session_link') ?: 'Shared by email and WhatsApp before each session',
            '🎯 Your fields' => str_replace(',', ', ', (string) $m['tracks']),
        ], 'navy')
        . '<p><strong>What happens next</strong></p>'
        . '<ul style="padding-left:20px;margin:0 0 14px;">'
        . '<li>Join the online session every Monday from 8:00 to 9:30 PM, led by great speakers and professionals who champion each session.</li>'
        . '<li>You are officially attached to the <strong>Digital Bridge Internship Program</strong>. Mentors in your field will be in touch with you — virtually or in person.</li>'
        . '<li>Attend at least <strong>75% of the sessions</strong> to receive your <strong>certificate</strong> and join the in-person <strong>closing session</strong> at selected locations.</li>'
        . '</ul>'
        . kt_btn('Add the sessions to my calendar', mentorship_calendar_url(), 'navy')
        . (($g = mentorship_whatsapp_group()) ? kt_btn('Join the mentorship WhatsApp group', $g, 'whatsapp') : '')
        . '<p>We are excited to learn and build with you.</p><p><strong>The Kakebe Technologies team</strong></p>';
    return send_mail($m['email'], '🎉 You\'re in: Kakebe Mentorship & DBIP — every Monday, 8:00 PM', kt_email('Mentorship & DBIP', $inner, 'Your free mentorship place is confirmed'), setting('contact_email') ?: null);
}

/** Waiting-list email when all places were taken by the time they confirmed. */
function send_mentorship_waitlist(array $m): bool
{
    $inner = '<p><strong>Hi ' . first_name($m['full_name']) . ',</strong></p>'
        . '<p>Thank you for confirming your email. All <strong>' . mentorship_capacity() . ' places</strong> in the Kakebe Mentorship Program &amp; Digital Bridge Internship Program are now taken, so you are on the <strong>waiting list</strong>.</p>'
        . '<p>If a place opens up, we will email you straight away with the session details. We will also let you know about the next intake.</p>'
        . '<p><strong>The Kakebe Technologies team</strong></p>';
    return send_mail($m['email'], "You're on the waiting list — Kakebe Mentorship & DBIP", kt_email('Mentorship & DBIP', $inner, 'All places are taken — you are on the waiting list'), setting('contact_email') ?: null);
}

/**
 * Confirm a registration (from the emailed link, or an admin). When all places are taken the person goes on the
 * waiting list instead; $force (admins) gives them a place anyway. Returns the updated row.
 */
function confirm_mentee(array $m, bool $force = false): array
{
    if ($m['status'] === 'confirmed') {
        return $m;
    }
    $pdo = db();
    $pdo->query("SELECT GET_LOCK('kt_mentorship_confirm', 5)");
    try {
        if (!$force && mentorship_places_left() <= 0) {
            if ($m['status'] !== 'waitlist') {
                $pdo->prepare("UPDATE mentorship_registrations SET status = 'waitlist', updated_at = ? WHERE id = ?")->execute([now(), $m['id']]);
                $m = find_mentee((int) $m['id']);
                send_mentorship_waitlist($m);
            }
            return $m;
        }
        $pdo->prepare("UPDATE mentorship_registrations SET status = 'confirmed', reference = COALESCE(reference, ?), confirmed_at = ?, updated_at = ? WHERE id = ?")
            ->execute([mentorship_reference((int) $m['id']), now(), now(), $m['id']]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('kt_mentorship_confirm')");
    }
    $m = find_mentee((int) $m['id']);
    send_mentorship_welcome($m);
    return $m;
}
