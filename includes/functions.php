<?php
/* ------------------------------------------------------------------
 * General helpers
 * ------------------------------------------------------------------ */

function cfg(string $path, $default = null)
{
    $node = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Absolute base URL of the site (no trailing slash). */
function base_url(string $path = ''): string
{
    $url = rtrim((string) cfg('app.url', ''), '/');
    if ($url === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $docRootRaw = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $docRoot = $docRootRaw !== '' ? str_replace('\\', '/', rtrim((string) realpath($docRootRaw), '/\\')) : '';
        $root = str_replace('\\', '/', (string) realpath(ROOT));
        // CLI (cron) has no document root — assume the folder sits in the web root (set app.url in config.php on live servers).
        $sub = ($docRoot !== '' && stripos($root, $docRoot) === 0) ? substr($root, strlen($docRoot)) : '/' . basename(ROOT);
        $url = ($https ? 'https://' : 'http://') . $host . rtrim($sub, '/');
    }
    return $url . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send the JSON response to the browser immediately, then keep running
 * (so the visitor doesn't wait while notification emails are sent).
 */
function respond_and_continue(array $data, int $code = 200): void
{
    ignore_user_abort(true);
    set_time_limit(120);
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/* ------------------------------------------------------------------
 * Settings (stored in the database, editable in Admin → Settings)
 * ------------------------------------------------------------------ */

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = default_settings();
        foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
            $cache[$row['skey']] = (string) $row['svalue'];
        }
    }
    return $cache;
}

function setting(string $key, $default = '')
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute([$key, $value]);
    settings_all(true);
}

/* ------------------------------------------------------------------
 * Security
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function app_key(): string
{
    return hash('sha256', (string) cfg('app.key', 'kakebe'), true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) {
        return $stored;
    }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** Short HMAC used to sign links (tickets, payment pages, receipts). */
function sign(string $purpose, string $value): string
{
    return substr(hash_hmac('sha256', $purpose . '|' . $value, (string) cfg('app.key')), 0, 32);
}

function sign_valid(string $purpose, string $value, $token): bool
{
    return is_string($token) && hash_equals(sign($purpose, $value), $token);
}

function ticket_token(string $reference): string
{
    return sign('ticket', $reference);
}

/** A ticket is valid once the camp package is fully paid, covered by an approved sponsor, or waived. */
function ticket_valid(array $r): bool
{
    return $r['status'] === 'confirmed' && in_array($r['payment_status'], ['paid', 'waived', 'sponsored'], true);
}

/** What the ticket QR code opens: the staff-only verification page in the control panel. */
function ticket_verify_url(array $r): string
{
    return base_url('admin/verify.php?c=' . rawurlencode($r['reference']));
}

/** "Gulu, Uganda" — the participant's district and country for the ticket. */
function ticket_location(array $r): string
{
    return implode(', ', array_filter([trim((string) $r['district']), trim((string) ($r['country'] ?: 'Uganda'))]));
}

/** QR code image (PNG) for the ticket — for emails. */
function ticket_qr_url(array $r): string
{
    return base_url('ticket-qr.php?ref=' . rawurlencode($r['reference']) . '&t=' . ticket_token($r['reference']));
}

/** Proof of payment printed on the ticket: [short label, details]. */
function ticket_payment(array $r): array
{
    if ($r['payment_status'] === 'sponsored') {
        return ['SPONSORED', 'Camp package covered by ' . ($r['sponsor_name'] ?: 'a sponsor')];
    }
    if ($r['payment_status'] === 'waived') {
        return ['FEES WAIVED', 'Camp fees waived by Kakebe Technologies'];
    }
    $st = db()->prepare("SELECT * FROM payments WHERE registration_id = ? AND status = 'success' AND purpose = 'camp' ORDER BY COALESCE(completed_at, created_at) DESC");
    $st->execute([$r['id']]);
    $pays = $st->fetchAll();
    if (balance($r) > 0 || !$pays) {
        return ['NOT PAID YET', 'Balance ' . format_ugx(balance($r)) . ' — the ticket is valid once paid in full'];
    }
    $last = $pays[0];
    return ['PAID IN FULL', format_ugx((int) $r['amount_paid']) . ' · Receipt ' . receipt_no($last) . (count($pays) > 1 ? ' + ' . (count($pays) - 1) . ' more' : '') . ' · ' . date('j M Y', strtotime($last['completed_at'] ?: $last['created_at']))];
}

function ticket_url(array $r): string
{
    return base_url('ticket.php?ref=' . rawurlencode($r['reference']) . '&t=' . ticket_token($r['reference']));
}

/** Link that opens the payment page for a registration without logging in. */
function pay_url(array $r): string
{
    return base_url('pay.php?ref=' . rawurlencode($r['reference']) . '&t=' . sign('pay', $r['reference']));
}

/** Flyer maker with the participant's name and code number already filled in (works without logging in). */
function flyer_url(array $r): string
{
    return base_url('flyer.php?ref=' . rawurlencode($r['reference']) . '&t=' . sign('flyer', $r['reference']));
}

function receipt_url(array $p): string
{
    return base_url('receipt.php?id=' . (int) $p['id'] . '&t=' . sign('receipt', (string) $p['id']));
}

function too_many(string $table, string $ip, int $minutes, int $max): bool
{
    $allowed = ['registrations', 'messages', 'login_attempts', 'payments', 'donations', 'login_codes', 'email_verifications', 'mentorship_registrations', 'flyers', 'volunteers'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE ip = ? AND created_at > ?");
    $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]);
    return (int) $stmt->fetchColumn() >= $max;
}

/* ------------------------------------------------------------------
 * Email authenticity
 * ------------------------------------------------------------------ */

/**
 * Returns a human-readable problem with an email address, or null if it looks genuine:
 * valid format, not a throwaway/disposable provider, no obvious typo, and a domain that can receive mail.
 */
function email_problem(string $email): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    [$user, $domain] = explode('@', $email, 2);

    $typos = [
        'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmail.co' => 'gmail.com',
        'gmail.con' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmali.com' => 'gmail.com',
        'yahooo.com' => 'yahoo.com', 'yaho.com' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'yahoo.con' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'hotmail.con' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'outlook.con' => 'outlook.com', 'iclod.com' => 'icloud.com',
    ];
    if (isset($typos[$domain])) {
        return "Did you mean {$user}@{$typos[$domain]}? Please check your email address.";
    }

    $disposable = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', 'grr.la', '10minutemail.com', '10minutemail.net', 'tempmail.com',
        'temp-mail.org', 'temp-mail.io', 'tempmail.net', 'tempail.com', 'yopmail.com', 'yopmail.net', 'getnada.com', 'nada.email', 'trashmail.com',
        'trashmail.de', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'fakeinbox.com', 'throwawaymail.com', 'mintemail.com', 'emailondeck.com',
        'mohmal.com', 'burnermail.io', 'mailcatch.com', 'spamgourmet.com', 'moakt.com', 'mytemp.email', 'tmpmail.org', 'tmail.ws', 'emailfake.com',
        'fakemail.net', 'mail.tm', 'inboxkitten.com', 'discard.email', 'spambox.us', 'mailpoof.com', 'owlymail.com', 'luxusmail.org', '1secmail.com',
    ];
    if (in_array($domain, $disposable, true) || preg_match('/(tempmail|temp-mail|throwaway|trashmail|disposable|fakemail|10minute)/', $domain)) {
        return 'Temporary or disposable email addresses are not accepted. Please use your personal email.';
    }
    if (preg_match('/\.(test|example|invalid|localhost|local)$/', $domain) || in_array($domain, ['example.com', 'example.org', 'test.com', 'email.com'], true)) {
        return 'Please use a real email address that you can access.';
    }
    if (function_exists('checkdnsrr') && !checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        return "The email domain \"{$domain}\" can't receive mail. Please check the spelling.";
    }
    return null;
}

/** True when this browser session has confirmed ownership of $email via the emailed link (valid for 24 hours). */
function email_verified_in_session(string $email): bool
{
    $at = $_SESSION['verified_emails'][strtolower(trim($email))] ?? 0;
    return $at && time() - (int) $at < 24 * 3600;
}

/* ------------------------------------------------------------------
 * Camp data, fees and lists
 * ------------------------------------------------------------------ */

function camp(): array
{
    return [
        'name'        => 'Kakebe Tech Camp 2026',
        'dates'       => '14th – 23rd December 2026',
        'dates_short' => '14 – 23 Dec 2026',
        'start_iso'   => '2026-12-14T08:00:00+03:00',
        'start_date'  => '2026-12-14',
        'end_date'    => '2026-12-23',
        'days'        => 10,
        'venue'       => 'Kitgum, Northern Uganda',
        'mentorship'  => 'October – November 2026',
    ];
}

/** Strictly camp_capacity participants: every registration that is not cancelled (or waitlisted) takes a seat. */
function seat_capacity(): int
{
    return max(1, (int) setting('camp_capacity', 300));
}

function seats_taken(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM registrations WHERE status NOT IN ('cancelled','waitlisted')")->fetchColumn();
}

function seats_left(): int
{
    return max(0, seat_capacity() - seats_taken());
}

function fees(): array
{
    return [
        'camp'          => (int) setting('camp_fee', 100000),
        'jersey'        => (int) setting('jersey_fee', 20000),
        'park'          => (int) setting('park_fee', 20000),
        'park_name'     => (string) setting('park_name', 'Aruu Falls'),
        'sponsor_child' => (int) setting('sponsor_child_amount', 150000),
    ];
}

/** Line items for a registration (from its saved amounts, or current fees for a new one). */
function order_items(array $r): array
{
    $f = fees();
    return array_values(array_filter([
        ['Camp fee (training, accommodation & meals)', (int) $r['camp_amount'], 'Required'],
        ['Sports jersey vest', (int) $r['jersey_amount'], 'Required'],
        ['Camp shirt', 0, 'Free'],
        !empty($r['park_visit']) ? [$f['park_name'] . ' park visit', (int) $r['park_amount'], 'Optional'] : null,
    ]));
}

function new_order_amounts(bool $park): array
{
    $f = fees();
    $a = ['camp_amount' => $f['camp'], 'jersey_amount' => $f['jersey'], 'park_amount' => $park ? $f['park'] : 0];
    $a['total_amount'] = array_sum($a);
    return $a;
}

function balance(array $r): int
{
    if (is_covered($r)) {
        return 0;
    }
    return max(0, (int) $r['total_amount'] - (int) $r['amount_paid']);
}

/** Camp fees are paid in full in one payment (no part payments) — the amount due is always the whole balance. */
function min_payment(array $r): int
{
    return balance($r);
}

function paid_percent(array $r): int
{
    $total = max(1, (int) $r['total_amount']);
    return is_covered($r) ? 100 : (int) min(100, floor((int) $r['amount_paid'] / $total * 100));
}

function interests(): array
{
    return [
        'AI & Software Development',
        'Content Creation & Production',
        'Entrepreneurship & Innovation',
        'Video Gaming & Development',
        'Robotics & Automation',
        'Digital Marketing & Branding',
    ];
}

function sources(): array
{
    return ['TikTok', 'X (Twitter)', 'LinkedIn', 'Email', 'Facebook', 'Instagram', 'WhatsApp', 'Friend / Referral', 'Other'];
}

function genders(): array
{
    return ['Female', 'Male', 'Prefer not to say'];
}

function jersey_sizes(): array
{
    return ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
}

function statuses(): array
{
    return [
        'review'     => 'Awaiting approval',
        'pending'    => 'Registered',
        'booked'     => 'Part paid',
        'confirmed'  => 'Confirmed',
        'waitlisted' => 'Waitlisted',
        'cancelled'  => 'Cancelled',
    ];
}

function payment_statuses(): array
{
    return ['unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid in full', 'sponsored' => 'Sponsored', 'waived' => 'Waived'];
}

function payment_methods(): array
{
    return ['mobile_money' => 'Mobile Money', 'card' => 'Card', 'cash' => 'Cash', 'bank' => 'Bank transfer', 'other' => 'Other'];
}

function programs(): array
{
    return ['techcamp' => ['name' => 'Kakebe Tech Camp 2026', 'short' => 'Tech Camp', 'prefix' => 'KTC26']];
}

function program_name(string $key): string
{
    return programs()[$key]['name'] ?? 'Kakebe Tech Camp 2026';
}

/** Approximate US-dollar value, for donors abroad (Admin → Settings → Pricing: UGX per $1). */
function approx_usd(int $ugx): int
{
    return (int) max(1, round($ugx / max(1, (int) setting('usd_rate', 3750))));
}

/**
 * Public donors list: everyone whose gift was received, biggest first. Gifts from the same email are added
 * together; anonymous gifts are listed as "Anonymous".
 */
function donor_wall(int $limit = 10): array
{
    $donors = [];
    foreach (db()->query('SELECT id, donor_name, organization, email, is_anonymous, amount_paid FROM donations WHERE amount_paid > 0 ORDER BY paid_at DESC, id DESC') as $d) {
        $key = $d['is_anonymous'] ? 'anon-' . $d['id'] : 'email-' . strtolower((string) $d['email']);
        $donors[$key] ??= [
            'name' => $d['is_anonymous'] ? 'Anonymous' : (string) $d['donor_name'],
            'organization' => $d['is_anonymous'] ? '' : (string) $d['organization'],
            'amount' => 0,
        ];
        $donors[$key]['amount'] += (int) $d['amount_paid'];
    }
    $donors = array_values($donors);
    usort($donors, fn($x, $y) => $y['amount'] <=> $x['amount']); // equal gifts stay newest-first
    return ['donors' => array_slice($donors, 0, $limit), 'count' => count($donors), 'total' => array_sum(array_column($donors, 'amount'))];
}

function format_ugx($amount, string $currency = 'UGX'): string
{
    return $currency . ' ' . number_format((int) $amount);
}

function social_links(): array
{
    $map = [
        'social_tiktok'    => ['fa-tiktok', 'TikTok'],
        'social_x'         => ['fa-x-twitter', 'X'],
        'social_linkedin'  => ['fa-linkedin-in', 'LinkedIn'],
        'social_facebook'  => ['fa-facebook-f', 'Facebook'],
        'social_instagram' => ['fa-instagram', 'Instagram'],
        'social_youtube'   => ['fa-youtube', 'YouTube'],
    ];
    $out = [];
    foreach ($map as $key => [$icon, $label]) {
        $url = trim((string) setting($key));
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $out[] = ['icon' => $icon, 'label' => $label, 'url' => $url];
        }
    }
    return $out;
}

function whatsapp_link(string $text = ''): string
{
    $num = preg_replace('/\D+/', '', (string) setting('contact_whatsapp', '256779712990'));
    return 'https://wa.me/' . $num . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/** Participants' WhatsApp group invite (Admin → Settings → Contacts & social), or '' when not set. */
function whatsapp_group_link(): string
{
    $url = trim((string) setting('whatsapp_group_link'));
    return preg_match('~^https://~i', $url) ? $url : '';
}

/** Phone number as international digits for wa.me links (Ugandan 07… becomes 2567…). */
function intl_digits(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($d, '00')) {
        return substr($d, 2);
    }
    if (str_starts_with($d, '0')) {
        return '256' . substr($d, 1);
    }
    return strlen($d) === 9 ? '256' . $d : $d;
}

/** The team's WhatsApp welcome for a new participant (Admin → Settings → Email). */
function whatsapp_welcome_message(array $r): string
{
    $tpl = trim((string) setting('wa_welcome_message')) ?: (string) default_settings()['wa_welcome_message'];
    return strtr($tpl, [
        '{first_name}'    => explode(' ', trim($r['full_name']))[0],
        '{full_name}'     => trim($r['full_name']),
        '{reference}'     => $r['reference'],
        '{camp_dates}'    => camp()['dates'],
        '{register_link}' => base_url('#register'),
    ]);
}

/** A payment reminder makes sense only while something is owed on an active registration. */
function can_remind(array $r): bool
{
    return balance($r) > 0 && !in_array($r['status'], ['cancelled', 'waitlisted', 'review'], true);
}

/** The team's WhatsApp payment reminder (Admin → Settings → Email). */
function whatsapp_reminder_message(array $r): string
{
    $tpl = trim((string) setting('wa_reminder_message')) ?: (string) default_settings()['wa_reminder_message'];
    return strtr($tpl, [
        '{first_name}' => explode(' ', trim($r['full_name']))[0],
        '{full_name}'  => trim($r['full_name']),
        '{reference}'  => $r['reference'],
        '{total}'      => format_ugx($r['total_amount']),
        '{balance}'    => format_ugx(balance($r)),
        '{pay_link}'   => pay_url($r),
        '{camp_dates}' => camp()['dates'],
    ]);
}

function participant_whatsapp_reminder_link(array $r): string
{
    return 'https://wa.me/' . intl_digits((string) $r['phone']) . '?text=' . rawurlencode(whatsapp_reminder_message($r));
}

/** Opens WhatsApp with the participant, the welcome message already typed (the sender just presses send). */
function participant_whatsapp_link(array $r): string
{
    return 'https://wa.me/' . intl_digits((string) $r['phone']) . '?text=' . rawurlencode(whatsapp_welcome_message($r));
}

function tel_link(string $phone): string
{
    $digits = preg_replace('/[^\d+]/', '', $phone);
    if (str_starts_with($digits, '0')) {
        $digits = '+256' . substr($digits, 1);
    }
    return 'tel:' . $digits;
}

/** Last 9 digits of a phone number — used to match "07xx…" with "+2567xx…". */
function phone_key(string $phone): string
{
    return substr(preg_replace('/\D/', '', $phone), -9);
}

function reference_for(int $id): string
{
    return 'KTC26-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function receipt_no(array $p): string
{
    return 'RCT-' . str_pad((string) $p['id'], 5, '0', STR_PAD_LEFT);
}

function photo_path(?string $file): ?string
{
    if (!$file || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file)) {
        return null;
    }
    $path = STORAGE . '/uploads/photos/' . $file;
    return is_file($path) ? $path : null;
}

/**
 * Validate an uploaded image from $_FILES[$field]. Returns [extension|null, error|null].
 * Extension is null (and error null) when no file was chosen.
 */
function check_image_upload(string $field, int $maxMb = 3): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'Photo upload failed. Please try again.'];
    }
    if ($f['size'] > $maxMb * 1024 * 1024) {
        return [null, "Photo is too large — maximum size is {$maxMb} MB."];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime]) || !@getimagesize($f['tmp_name'])) {
        return [null, 'Please upload a JPG, PNG or WEBP image.'];
    }
    return [$allowed[$mime], null];
}

/** Move a validated upload into $dir with a random name; returns the filename or null. */
function store_upload(string $field, string $ext, string $dir): ?string
{
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    return move_uploaded_file($_FILES[$field]['tmp_name'], rtrim($dir, '/') . '/' . $name) ? $name : null;
}

function team_members(bool $activeOnly = true): array
{
    return db()->query('SELECT * FROM team_members' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll();
}

function team_photo_url(array $m, string $base = ''): ?string
{
    $f = (string) ($m['photo'] ?? '');
    return ($f !== '' && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $f) && is_file(ROOT . '/uploads/team/' . $f)) ? $base . 'uploads/team/' . $f : null;
}

function initials(string $name): string
{
    $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/\(.*?\)/', '', $name))));
    return mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
}

function html_to_text(string $html): string
{
    $html = preg_replace('~<(style|head)[^>]*>.*?</\1>~is', '', $html);
    $text = preg_replace('~<(br|/p|/tr|/h[1-6]|/li|/div)[^>]*>~i', "\n", $html);
    $text = preg_replace('~<a [^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~i', '$2 ($1)', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
    return trim($text);
}

function find_registration(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM registrations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_registration_by_ref(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM registrations WHERE reference = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

/** Signed-in participant (portal) or null. */
function current_participant(): ?array
{
    static $p = false;
    if ($p !== false) {
        return $p;
    }
    $p = null;
    if (!empty($_SESSION['participant_id'])) {
        $p = find_registration((int) $_SESSION['participant_id']);
    }
    return $p;
}

/* ------------------------------------------------------------------
 * Sponsorship & participant accounts
 * ------------------------------------------------------------------ */

/** Fees fully covered without payment (approved sponsorship or admin waiver). */
function is_covered(array $r): bool
{
    return in_array($r['payment_status'] ?? '', ['waived', 'sponsored'], true);
}

function is_sponsored(array $r): bool
{
    return ($r['funding'] ?? 'self') === 'sponsored';
}

/** Sponsors participants can choose from when registering. */
function active_sponsors(): array
{
    return db()->query('SELECT id, name, organization FROM sponsors WHERE is_active = 1 ORDER BY name')->fetchAll();
}

function sponsor_label(array $s): string
{
    return $s['name'] . (!empty($s['organization']) && $s['organization'] !== $s['name'] ? ' — ' . $s['organization'] : '');
}

function find_sponsor(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM sponsors WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Create a single-use password reset / setup link for a participant (valid 60 minutes). */
function password_reset_link(array $r, int $minutes = 60): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO password_resets (registration_id, token_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$r['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + $minutes * 60), client_ip(), now()]);
    return base_url('portal/reset.php?token=' . $token);
}

/** Returns the registration for a valid, unused reset token, or null. */
function find_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT pr.id AS reset_id, r.* FROM password_resets pr JOIN registrations r ON r.id = pr.registration_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? LIMIT 1');
    $stmt->execute([hash('sha256', $token), now()]);
    return $stmt->fetch() ?: null;
}
