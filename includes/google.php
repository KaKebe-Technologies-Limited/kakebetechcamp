<?php
/**
 * "Continue with Google" (Google Identity Services).
 * The browser receives a signed ID token from Google; we verify it here with Google's public
 * certificates (RS256), so no client library and no client secret are needed for sign-in.
 */

function google_client_id(): string
{
    return env('GOOGLE_CLIENT_ID');
}

function google_enabled(): bool
{
    return google_client_id() !== '';
}

function b64url_decode(string $s): string|false
{
    $s = strtr($s, '-_', '+/');
    if ($pad = strlen($s) % 4) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s, true);
}

/** Google's current signing certificates (kid => PEM), cached for as long as Google allows. */
function google_certs(bool $refresh = false): array
{
    $file = STORAGE . '/cache/google_certs.json';
    if (!$refresh && is_file($file)) {
        $cached = json_decode((string) file_get_contents($file), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() && !empty($cached['keys'])) {
            return $cached['keys'];
        }
    }
    $ch = curl_init('https://www.googleapis.com/oauth2/v1/certs');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false || $code !== 200) {
        error_log('Google certs fetch failed (HTTP ' . $code . ')');
        return [];
    }
    $keys = json_decode(substr((string) $raw, $headerSize), true) ?: [];
    $maxAge = preg_match('/max-age=(\d+)/i', substr((string) $raw, 0, $headerSize), $m) ? (int) $m[1] : 3600;
    @file_put_contents($file, json_encode(['expires' => time() + max(300, min($maxAge, 86400)), 'keys' => $keys]));
    return $keys;
}

/**
 * Verify a Google ID token. Returns the token claims (email, name, picture, sub…) or null if invalid.
 */
function google_verify_id_token(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3 || !google_enabled()) {
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode((string) b64url_decode($h64), true);
    $payload = json_decode((string) b64url_decode($p64), true);
    $signature = b64url_decode($s64);
    if (!is_array($header) || !is_array($payload) || $signature === false || ($header['alg'] ?? '') !== 'RS256') {
        return null;
    }
    $kid = (string) ($header['kid'] ?? '');
    $certs = google_certs();
    if (!isset($certs[$kid])) {
        $certs = google_certs(true); // Google rotated its keys
    }
    if (!isset($certs[$kid]) || openssl_verify("$h64.$p64", $signature, $certs[$kid], OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $now = time();
    if (!in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
        || ($payload['aud'] ?? '') !== google_client_id()
        || (int) ($payload['exp'] ?? 0) < $now - 60
        || (int) ($payload['iat'] ?? 0) > $now + 300
        || empty($payload['email'])
        || !in_array($payload['email_verified'] ?? false, [true, 'true'], true)) {
        return null;
    }
    return $payload;
}

/** Download the Google profile picture into the private photos folder; returns the file name or null. */
function google_fetch_photo(?string $url): ?string
{
    if (!$url || !preg_match('~^https://[a-z0-9.-]+\.googleusercontent\.com/~i', $url)) {
        return null;
    }
    $url = preg_replace('/=s\d+(-c)?$/', '=s480-c', $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($data) || $code !== 200 || strlen($data) < 500 || strlen($data) > 3 * 1024 * 1024) {
        return null;
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (!isset($types[$mime])) {
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    return file_put_contents(STORAGE . '/uploads/photos/' . $name, $data) ? $name : null;
}

/** Google profile saved in the session while a Google user completes registration. */
function google_profile_for(string $email): ?array
{
    $g = $_SESSION['google_profile'] ?? null;
    return is_array($g) && ($g['email'] ?? '') === strtolower(trim($email)) ? $g : null;
}

/** The "Continue with Google" button (Google renders it inside the placeholder). */
/** $big: the main call to action — the button fills the available width (Google's max is 400px) and is scaled up slightly. */
function google_button(string $intent, string $endpoint, string $text = 'continue_with', bool $big = false): string
{
    if (!google_enabled()) {
        return '';
    }
    $cfg = json_encode(['endpoint' => $endpoint, 'intent' => $intent, 'csrf' => csrf_token()], JSON_UNESCAPED_SLASHES);
    return '<div class="google-block' . ($big ? ' big' : '') . '">'
        . '<div id="g_id_onload" data-client_id="' . e(google_client_id()) . '" data-callback="ktGoogleCredential" data-auto_prompt="false" data-context="' . ($intent === 'login' ? 'signin' : 'signup') . '" data-ux_mode="popup" data-itp_support="true"></div>'
        . '<div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="' . ($big ? 'filled_blue' : 'outline') . '" data-text="' . e($text) . '" data-size="large" data-logo_alignment="center" data-width="320"></div>'
        . '<p class="google-msg" id="googleMsg" hidden></p>'
        . '</div>'
        . '<script>window.KT_GOOGLE = ' . $cfg . ';</script>';
}
