<?php
/**
 * Email marketing: contact lists (CSV / Excel import, address checks), campaigns (rich emails with images,
 * bullets and a button), sending in groups — one email per person — with open tracking and unsubscribe.
 */

function mk_settings(): array
{
    return [
        'daily_limit' => max(1, (int) setting('mk_daily_limit', 500)),
        'batch_size'  => max(50, min(2000, (int) setting('mk_batch_size', 500))),
        'from_name'   => (string) (setting('mk_from_name') ?: 'Kakebe Technologies'),
        'reply_to'    => (string) (setting('mk_reply_to') ?: setting('contact_email')),
    ];
}

function mk_token(): string
{
    return bin2hex(random_bytes(12));
}

/* ------------------------------------------------------------------
 * Contacts
 * ------------------------------------------------------------------ */

/** Clean one address and judge it: [email, 'valid'|'invalid'|'skip', reason]. */
function mk_check_email(string $raw): array
{
    $email = strtolower(trim(preg_replace('/^mailto:/i', '', trim($raw)), " \t\"'<>;,.()[]"));
    if ($email === '') {
        return ['', 'skip', null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [$email, 'invalid', 'Not a valid email address'];
    }
    $domain = substr((string) strrchr($email, '@'), 1);
    $typos = [
        'gmial.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com',
        'gmaill.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmail.om' => 'gmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yhoo.com' => 'yahoo.com',
        'hotmial.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'icloud.co' => 'icloud.com',
    ];
    if (isset($typos[$domain])) {
        return [$email, 'invalid', 'Probably a typo of ' . $typos[$domain]];
    }
    if (preg_match('/(^|\.)(example\.(com|org|net)|test\.com|mailinator\.com|yopmail\.com|tempmail\.|temp-mail\.|10minutemail\.|guerrillamail\.|trashmail\.|sharklasers\.com)/', $domain)) {
        return [$email, 'invalid', 'Test or throw-away address'];
    }
    return [$email, 'valid', null];
}

/**
 * Import a CSV / Excel file into a list. Finds the email column (and a name column) on its own.
 * Existing contacts are added to the list but keep their status (unsubscribed people stay unsubscribed).
 */
function mk_import(string $path, string $originalName, int $listId): array
{
    $rows = spreadsheet_rows($path, $originalName);
    $stats = ['rows' => 0, 'added' => 0, 'added_valid' => 0, 'existing' => 0, 'invalid' => 0, 'duplicates' => 0, 'skipped' => 0];
    if (!$rows) {
        return $stats;
    }

    // Email column: the one where most of the first rows contain an "@"
    $sample = array_slice($rows, 0, 30);
    $width = max(array_map('count', $sample));
    $emailCol = 0;
    $bestHits = -1;
    for ($i = 0; $i < $width; $i++) {
        $hits = count(array_filter($sample, fn($r) => str_contains((string) ($r[$i] ?? ''), '@')));
        if ($hits > $bestHits) {
            $bestHits = $hits;
            $emailCol = $i;
        }
    }
    // Header row and name column
    $header = !str_contains((string) ($rows[0][$emailCol] ?? ''), '@') ? array_map('strtolower', $rows[0]) : null;
    $nameCol = null;
    if ($header) {
        foreach ($header as $i => $h) {
            if ($i !== $emailCol && preg_match('/\b(full ?name|names?|first ?name|contact)\b/', $h)) {
                $nameCol = $i;
                break;
            }
        }
        array_shift($rows);
    }

    $seen = [];
    $batch = [];
    $flush = function () use (&$batch, &$stats, $listId) {
        if (!$batch) {
            return;
        }
        $pdo = db();
        // Good and filtered-out addresses go in separately so the summary can say how many good ones are new
        foreach (['valid', 'invalid'] as $status) {
            $group = array_values(array_filter($batch, fn($c) => $c['status'] === $status));
            if (!$group) {
                continue;
            }
            $params = [];
            foreach ($group as $c) {
                array_push($params, $c['email'], $c['name'], $c['status'], $c['reason'], $c['domain'], mk_token(), now());
            }
            $st = $pdo->prepare('INSERT IGNORE INTO mk_contacts (email, name, status, reason, domain, token, created_at) VALUES ' . implode(',', array_fill(0, count($group), '(?, ?, ?, ?, ?, ?, ?)')));
            $st->execute($params);
            $stats['added'] += $st->rowCount();
            $stats['existing'] += count($group) - $st->rowCount();
            if ($status === 'valid') {
                $stats['added_valid'] += $st->rowCount();
            }
            // Some were already saved: fill in their names if they had none
            if ($st->rowCount() < count($group)) {
                $fill = $pdo->prepare('UPDATE mk_contacts SET name = ? WHERE email = ? AND (name IS NULL OR name = \'\')');
                foreach ($group as $c) {
                    if ($c['name'] !== null) {
                        $fill->execute([$c['name'], $c['email']]);
                    }
                }
            }
        }
        if ($listId) {
            $in = implode(',', array_fill(0, count($batch), '?'));
            $ids = $pdo->prepare("SELECT id FROM mk_contacts WHERE email IN ($in)");
            $ids->execute(array_column($batch, 'email'));
            $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $pdo->prepare('INSERT IGNORE INTO mk_list_contacts (list_id, contact_id) VALUES ' . implode(',', array_fill(0, count($ids), '(?, ?)')))
                    ->execute(array_merge(...array_map(fn($id) => [$listId, (int) $id], $ids)));
            }
        }
        $batch = [];
    };

    foreach ($rows as $row) {
        $cell = (string) ($row[$emailCol] ?? '');
        // A cell can hold several addresses ("a@x.com; b@y.com")
        $parts = preg_split('/[\s,;]+/', $cell) ?: [];
        $any = false;
        foreach ($parts as $part) {
            [$email, $status, $reason] = mk_check_email($part);
            if ($status === 'skip') {
                continue;
            }
            $any = true;
            $stats['rows']++;
            if (isset($seen[$email])) {
                $stats['duplicates']++;
                continue;
            }
            $seen[$email] = true;
            if ($status === 'invalid') {
                $stats['invalid']++;
            }
            $name = $nameCol !== null ? mb_substr(trim((string) ($row[$nameCol] ?? '')), 0, 150) : '';
            $batch[] = ['email' => mb_substr($email, 0, 190), 'name' => $name !== '' ? $name : null, 'status' => $status, 'reason' => $reason, 'domain' => mb_substr(substr((string) strrchr($email, '@'), 1), 0, 120)];
            if (count($batch) >= 400) {
                $flush();
            }
        }
        if (!$any) {
            $stats['skipped']++;
        }
    }
    $flush();
    mk_mark_known_domains();
    return $stats;
}

/** New contacts at domains that were already checked (gmail.com…) don't need checking again. */
function mk_mark_known_domains(): void
{
    db()->exec("UPDATE mk_contacts c JOIN (SELECT DISTINCT domain FROM mk_contacts WHERE domain_checked = 1) d ON d.domain = c.domain SET c.domain_checked = 1 WHERE c.domain_checked = 0");
}

/** Check whether email domains can receive mail (MX or A record), a few at a time. */
function mk_check_domains(int $max = 25): array
{
    $pdo = db();
    $domains = $pdo->query("SELECT DISTINCT domain FROM mk_contacts WHERE status = 'valid' AND domain_checked = 0 AND domain <> '' LIMIT " . (int) $max)->fetchAll(PDO::FETCH_COLUMN);
    $bad = 0;
    foreach ($domains as $d) {
        $ok = checkdnsrr($d . '.', 'MX') || checkdnsrr($d . '.', 'A');
        if (!$ok) {
            $bad++;
            $pdo->prepare("UPDATE mk_contacts SET status = 'invalid', reason = 'Email domain cannot receive mail', domain_checked = 1 WHERE domain = ? AND status = 'valid'")->execute([$d]);
        }
        $pdo->prepare('UPDATE mk_contacts SET domain_checked = 1 WHERE domain = ?')->execute([$d]);
    }
    $left = (int) $pdo->query("SELECT COUNT(DISTINCT domain) FROM mk_contacts WHERE status = 'valid' AND domain_checked = 0 AND domain <> ''")->fetchColumn();
    return ['checked' => count($domains), 'bad' => $bad, 'left' => $left];
}

function mk_contact_stats(?int $listId = null): array
{
    $join = $listId ? ' JOIN mk_list_contacts lc ON lc.contact_id = c.id AND lc.list_id = ' . (int) $listId : '';
    $out = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'unsubscribed' => 0];
    foreach (db()->query("SELECT c.status, COUNT(*) n FROM mk_contacts c$join GROUP BY c.status") as $r) {
        $out[$r['status']] = (int) $r['n'];
        $out['total'] += (int) $r['n'];
    }
    return $out;
}

function mk_lists(): array
{
    return db()->query('SELECT l.*, (SELECT COUNT(*) FROM mk_list_contacts lc JOIN mk_contacts c ON c.id = lc.contact_id WHERE lc.list_id = l.id) total,
        (SELECT COUNT(*) FROM mk_list_contacts lc JOIN mk_contacts c ON c.id = lc.contact_id WHERE lc.list_id = l.id AND c.status = \'valid\') valid
        FROM mk_lists l ORDER BY l.name')->fetchAll();
}

/* ------------------------------------------------------------------
 * Campaign content
 * ------------------------------------------------------------------ */

/** Keep only safe, email-friendly HTML from the editor and give it inline styles that email apps respect. */
function mk_clean_body(string $html): string
{
    if (trim(strip_tags($html, '<img>')) === '') {
        return '';
    }
    $allowed = ['p', 'br', 'h1', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'img', 'blockquote', 'span'];
    $styles = [
        'p'          => 'margin:0 0 14px;font-size:15px;line-height:1.65;color:#1B2340;',
        'h1'         => 'margin:22px 0 10px;font-size:24px;line-height:1.3;color:#0F2557;',
        'h2'         => 'margin:22px 0 10px;font-size:20px;line-height:1.3;color:#0F2557;',
        'h3'         => 'margin:18px 0 8px;font-size:17px;line-height:1.35;color:#0F2557;',
        'ul'         => 'margin:0 0 16px;padding-left:22px;',
        'ol'         => 'margin:0 0 16px;padding-left:22px;',
        'li'         => 'margin:0 0 8px;font-size:15px;line-height:1.6;color:#1B2340;',
        'a'          => 'color:#E11D2A;font-weight:bold;',
        'img'        => 'display:block;max-width:100%;height:auto;border:0;border-radius:10px;margin:8px auto 16px;',
        'blockquote' => 'margin:16px 0;padding:12px 16px;border-left:4px solid #E11D2A;background:#FFF5F5;color:#1B2340;',
    ];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="mk-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('mk-root');
    if (!$root) {
        return '';
    }
    $walk = function (DOMNode $node) use (&$walk, $allowed, $styles, $doc) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $walk($child);
                if (!in_array($tag, $allowed, true)) {
                    // Unwrap unknown elements but keep their text
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $keep = [];
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (preg_match('~^(https?://|mailto:|tel:)~i', $href)) {
                        $keep['href'] = $href;
                    }
                }
                if ($tag === 'img') {
                    $src = trim($child->getAttribute('src'));
                    if (!preg_match('~^https?://~i', $src)) {
                        $node->removeChild($child);
                        continue;
                    }
                    $keep['src'] = $src;
                    $keep['alt'] = $child->getAttribute('alt');
                }
                // Alignment from the editor (ql-align-center etc.)
                $align = preg_match('/ql-align-(center|right|justify)/', $child->getAttribute('class'), $m) ? $m[1] : '';
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->name);
                }
                foreach ($keep as $k => $v) {
                    $child->setAttribute($k, $v);
                }
                $style = ($styles[$tag] ?? '') . ($align ? 'text-align:' . $align . ';' : '');
                if ($style !== '') {
                    $child->setAttribute('style', $style);
                }
                if ($tag === 'a') {
                    $child->setAttribute('target', '_blank');
                }
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/** Replace {first_name} / {name} for one person. */
function mk_personalise(string $text, ?array $contact, bool $html = true): string
{
    $name = trim((string) ($contact['name'] ?? ''));
    $first = $name !== '' ? explode(' ', $name)[0] : 'there';
    $map = ['{first_name}' => $first, '{name}' => $name !== '' ? $name : 'there'];
    return strtr($text, $html ? array_map('e', $map) : $map);
}

/**
 * The finished email for one person: [subject, html, text, extra headers].
 * $send: the mk_sends row (open tracking + unsubscribe), or null for a preview / test.
 */
function mk_render(array $c, ?array $contact = null, ?array $send = null): array
{
    $subject = mk_personalise((string) $c['subject'], $contact, false);
    $body = mk_personalise((string) $c['body'], $contact);
    $pre = e(mk_personalise((string) ($c['preheader'] ?? ''), $contact, false));
    $site = base_url();
    $siteLabel = e(preg_replace('~^https?://~', '', $site));
    $logo = e(base_url('assets/img/techcamp-logo-email.png'));
    $from = e(mk_settings()['from_name']);
    $unsub = $send ? base_url('api/mk-unsubscribe.php?t=' . $send['token']) : '#';
    $pixel = $send ? '<img src="' . e(base_url('api/mk-open.php?t=' . $send['token'])) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">' : '';
    $button = '';
    if (!empty($c['cta_label']) && !empty($c['cta_url'])) {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto 8px;"><tr><td style="border-radius:10px;background:#E11D2A;">'
            . '<a href="' . e($c['cta_url']) . '" target="_blank" style="display:inline-block;padding:14px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px;">' . e($c['cta_label']) . '</a></td></tr></table>';
    }
    $phone = e(setting('contact_phone', '0779 712 990'));
    $year = date('Y');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$from}</title></head>
<body style="margin:0;padding:0;background:#F1F2F5;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$pre}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F1F2F5;">
<tr><td align="center" style="padding:22px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
    <tr><td align="center" style="background:#ffffff;border-radius:16px 16px 0 0;padding:22px 24px 14px;border:1px solid #E5E7EB;border-bottom:0;">
      <a href="{$site}" target="_blank"><img src="{$logo}" width="190" alt="Kakebe Tech Camp" style="display:block;width:190px;max-width:60%;height:auto;border:0;"></a>
    </td></tr>
    <tr><td style="height:5px;background:#E11D2A;line-height:5px;font-size:0;">&nbsp;</td></tr>
    <tr><td style="background:#ffffff;padding:28px 28px 22px;border:1px solid #E5E7EB;border-top:0;border-radius:0 0 16px 16px;font-family:Arial,Helvetica,sans-serif;">
      {$body}
      {$button}
    </td></tr>
    <tr><td align="center" style="padding:18px 16px 6px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#8A90A2;">
      Kakebe Technologies Limited · Support / WhatsApp: {$phone} · <a href="{$site}" style="color:#8A90A2;">{$siteLabel}</a><br>
      You are receiving this because you are in touch with Kakebe Technologies. <a href="{$unsub}" style="color:#8A90A2;">Unsubscribe</a>.<br>
      © {$year} Kakebe Technologies Limited
    </td></tr>
  </table>
</td></tr>
</table>
{$pixel}
</body></html>
HTML;

    $headers = [];
    if ($send) {
        $headers['List-Unsubscribe'] = '<' . $unsub . '>' . (setting('contact_email') ? ', <mailto:' . setting('contact_email') . '?subject=unsubscribe>' : '');
        $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        $headers['Precedence'] = 'bulk';
    }
    $text = html_to_text($html) . "\n\nUnsubscribe: " . $unsub;
    return [$subject, $html, $text, $headers];
}

/* ------------------------------------------------------------------
 * Sending
 * ------------------------------------------------------------------ */

/** SMTP for campaigns: MK_SMTP_* in .env (e.g. a bulk-email service) or the site's normal email settings. */
function mk_smtp_config(): array
{
    $cfg = smtp_config();
    if (env('MK_SMTP_HOST') !== '') {
        $cfg = [
            'host' => env('MK_SMTP_HOST'), 'port' => (int) (env('MK_SMTP_PORT') ?: 587), 'secure' => env('MK_SMTP_SECURE') ?: 'tls',
            'user' => env('MK_SMTP_USER'), 'pass' => env('MK_SMTP_PASS'), 'from_email' => env('MK_FROM_EMAIL') ?: env('MK_SMTP_USER'),
        ];
    }
    $cfg['from_name'] = mk_settings()['from_name'];
    return $cfg;
}

function mk_sent_last_24h(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM mk_sends WHERE status = 'sent' AND sent_at > '" . date('Y-m-d H:i:s', time() - 86400) . "'")->fetchColumn();
}

/** The people a campaign goes to: valid contacts (in its list, if one is chosen). */
function mk_audience_count(array $c): int
{
    $sql = "SELECT COUNT(*) FROM mk_contacts c" . ($c['list_id'] ? ' JOIN mk_list_contacts lc ON lc.contact_id = c.id AND lc.list_id = ' . (int) $c['list_id'] : '') . " WHERE c.status = 'valid'";
    return (int) db()->query($sql)->fetchColumn();
}

/** Put everyone in the audience into the send queue, numbered into groups of batch_size. Returns how many were added. */
function mk_queue(array $c): int
{
    $pdo = db();
    $size = mk_settings()['batch_size'];
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM mk_sends WHERE campaign_id = ' . (int) $c['id'])->fetchColumn();
    $sql = 'SELECT c.id, c.email FROM mk_contacts c' . ($c['list_id'] ? ' JOIN mk_list_contacts lc ON lc.contact_id = c.id AND lc.list_id = ' . (int) $c['list_id'] : '')
        . " WHERE c.status = 'valid' AND NOT EXISTS (SELECT 1 FROM mk_sends s WHERE s.campaign_id = " . (int) $c['id'] . ' AND s.contact_id = c.id) ORDER BY c.id';
    $rows = $pdo->query($sql)->fetchAll();
    $n = 0;
    foreach (array_chunk($rows, 400) as $chunk) {
        $params = [];
        foreach ($chunk as $r) {
            $batch = intdiv($existing + $n, $size) + 1;
            array_push($params, (int) $c['id'], (int) $r['id'], $r['email'], $batch, mk_token());
            $n++;
        }
        $pdo->prepare('INSERT IGNORE INTO mk_sends (campaign_id, contact_id, email, batch, token) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')))->execute($params);
    }
    if ($n) {
        $pdo->prepare("UPDATE mk_campaigns SET status = IF(status IN ('draft', 'sent'), 'ready', status), queued_at = COALESCE(queued_at, ?), finished_at = NULL WHERE id = ?")->execute([now(), $c['id']]);
    }
    return $n;
}

/**
 * Send up to $max queued emails of one group. Each person gets their own email (no CC/BCC).
 * Stops at the daily limit. Returns progress for the screen.
 */
function mk_send_chunk(array $c, int $batch, int $max = 10): array
{
    $pdo = db();
    $limit = mk_settings()['daily_limit'];
    $left = max(0, $limit - mk_sent_last_24h());
    $out = ['sent' => 0, 'failed' => 0, 'error' => null, 'daily_left' => $left];
    if ($left === 0) {
        $out['error'] = "The daily sending limit ($limit emails in 24 hours) has been reached. Continue tomorrow, or raise the limit if your email service allows more.";
        return $out + mk_batch_progress((int) $c['id'], $batch);
    }
    $rows = $pdo->prepare("SELECT s.*, ct.name, ct.status contact_status FROM mk_sends s JOIN mk_contacts ct ON ct.id = s.contact_id WHERE s.campaign_id = ? AND s.batch = ? AND s.status = 'queued' ORDER BY s.id LIMIT " . min($max, $left));
    $rows->execute([$c['id'], $batch]);
    $rows = $rows->fetchAll();

    $transport = setting('mail_transport', 'log');
    $mailer = null;
    if ($rows && $transport === 'smtp') {
        $mailer = new SmtpMailer(mk_smtp_config());
        if (!$mailer->open()) {
            $out['error'] = 'Could not connect to the email server: ' . $mailer->error;
            return $out + mk_batch_progress((int) $c['id'], $batch);
        }
    }
    $replyTo = mk_settings()['reply_to'] ?: null;
    $pdo->prepare("UPDATE mk_campaigns SET status = 'sending' WHERE id = ? AND status IN ('ready', 'draft', 'paused')")->execute([$c['id']]);

    foreach ($rows as $s) {
        if ($s['contact_status'] !== 'valid') {   // unsubscribed or marked invalid since queueing
            $pdo->prepare("UPDATE mk_sends SET status = 'skipped', error = 'No longer subscribed' WHERE id = ?")->execute([$s['id']]);
            continue;
        }
        [$subject, $html, $text, $headers] = mk_render($c, ['name' => $s['name']], $s);
        $error = null;
        if ($transport === 'smtp') {
            if (!$mailer->connected() && !$mailer->open()) {
                $out['error'] = 'Lost the connection to the email server: ' . $mailer->error;
                break;
            }
            $ok = $mailer->deliver($s['email'], $subject, $html, $text, $replyTo, $headers);
            $error = $ok ? null : $mailer->error;
        } elseif ($transport === 'mail') {
            $cfg = mk_smtp_config();
            $raw = build_mime_message($cfg['from_email'] ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), $cfg['from_name'], [$s['email']], $subject, $html, $text, $replyTo, false, [], [], $headers);
            [$h, $b] = explode("\r\n\r\n", $raw, 2);
            $ok = @mail($s['email'], encode_header($subject), $b, $h);
            $error = $ok ? null : 'PHP mail() failed';
        } else {   // "log only" (testing): nothing leaves the server
            $ok = file_put_contents(STORAGE . '/logs/mail.log', str_repeat('=', 70) . "\n" . now() . "\n[campaign {$c['id']}] To: {$s['email']}\nSubject: $subject\n\n", FILE_APPEND | LOCK_EX) !== false;
        }
        if ($ok) {
            $out['sent']++;
            $pdo->prepare("UPDATE mk_sends SET status = 'sent', sent_at = ?, error = NULL WHERE id = ?")->execute([now(), $s['id']]);
        } else {
            $out['failed']++;
            $pdo->prepare("UPDATE mk_sends SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr((string) $error, 0, 255), $s['id']]);
            // Hard bounces at the door ("user unknown") mean the address is dead
            if (preg_match('/^55[0-3]|user unknown|does not exist|no such user|mailbox unavailable/i', (string) $error)) {
                $pdo->prepare("UPDATE mk_contacts SET status = 'invalid', reason = 'Rejected by the receiving server' WHERE id = ?")->execute([$s['contact_id']]);
            }
        }
    }
    if ($mailer) {
        $mailer->close();
    }
    $left = (int) $pdo->query("SELECT COUNT(*) FROM mk_sends WHERE campaign_id = " . (int) $c['id'] . " AND status = 'queued'")->fetchColumn();
    if ($left === 0) {
        $pdo->prepare("UPDATE mk_campaigns SET status = 'sent', finished_at = COALESCE(finished_at, ?) WHERE id = ?")->execute([now(), $c['id']]);
    }
    $out['daily_left'] = max(0, $limit - mk_sent_last_24h());
    return $out + mk_batch_progress((int) $c['id'], $batch);
}

function mk_batch_progress(int $campaignId, int $batch): array
{
    $st = db()->prepare('SELECT status, COUNT(*) n FROM mk_sends WHERE campaign_id = ? AND batch = ? GROUP BY status');
    $st->execute([$campaignId, $batch]);
    $p = ['batch_total' => 0, 'batch_queued' => 0, 'batch_done' => 0];
    foreach ($st as $r) {
        $p['batch_total'] += (int) $r['n'];
        if ($r['status'] === 'queued') {
            $p['batch_queued'] = (int) $r['n'];
        } else {
            $p['batch_done'] += (int) $r['n'];
        }
    }
    return $p;
}

/** Campaign numbers: queued, sent, failed, skipped, opened, unsubscribed. */
function mk_campaign_stats(int $campaignId): array
{
    $s = ['total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'opens' => 0, 'unsubscribed' => 0];
    $st = db()->prepare('SELECT status, COUNT(*) n, SUM(opened_at IS NOT NULL) o, SUM(open_count) oc FROM mk_sends WHERE campaign_id = ? GROUP BY status');
    $st->execute([$campaignId]);
    foreach ($st as $r) {
        $s[$r['status']] = (int) $r['n'];
        $s['total'] += (int) $r['n'];
        $s['opened'] += (int) $r['o'];
        $s['opens'] += (int) $r['oc'];
    }
    $u = db()->prepare("SELECT COUNT(*) FROM mk_sends s JOIN mk_contacts c ON c.id = s.contact_id WHERE s.campaign_id = ? AND c.status = 'unsubscribed' AND c.unsubscribed_at >= s.sent_at");
    $u->execute([$campaignId]);
    $s['unsubscribed'] = (int) $u->fetchColumn();
    $s['open_rate'] = $s['sent'] ? round($s['opened'] / $s['sent'] * 100, 1) : 0;
    return $s;
}

function find_campaign(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM mk_campaigns WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
