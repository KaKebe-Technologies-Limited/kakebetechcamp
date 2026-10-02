<?php
/**
 * Lightweight SMTP client with no external dependencies.
 * Supports SSL (port 465), STARTTLS (port 587) and AUTH LOGIN — works with Gmail App Passwords.
 */
class SmtpMailer
{
    /** @var resource|null */
    private $sock = null;
    public string $error = '';

    public function __construct(private array $cfg)
    {
    }

    /** Open one connection for several messages (bulk campaigns): open() → deliver() … → close(). */
    public function open(): bool
    {
        $this->error = '';
        try {
            $this->connect();
            $this->authenticate();
            return true;
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->close();
            return false;
        }
    }

    /** Send one message on an open connection. $headers: extra headers, e.g. List-Unsubscribe. */
    public function deliver(string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $headers = []): bool
    {
        $this->error = '';
        if (!is_resource($this->sock)) {
            $this->error = 'Not connected.';
            return false;
        }
        try {
            $this->command('MAIL FROM:<' . $this->fromEmail() . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $message = preg_replace('/^\./m', '..', build_mime_message($this->fromEmail(), (string) ($this->cfg['from_name'] ?? ''), [$to], $subject, $html, $text, $replyTo, true, [], [], $headers));
            fwrite($this->sock, $message . "\r\n.\r\n");
            $this->expect([250]);
            return true;
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            try {
                $this->command('RSET', [250]);   // keep the connection usable for the next recipient
            } catch (RuntimeException $ignored) {
                $this->close();
            }
            return false;
        }
    }

    public function connected(): bool
    {
        return is_resource($this->sock);
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException $ignored) {
            }
            fclose($this->sock);
        }
        $this->sock = null;
    }

    /** @param string[] $to */
    public function send(array $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = [], array $cc = []): bool
    {
        $this->error = '';
        try {
            $this->connect();
            $this->authenticate();
            $this->command('MAIL FROM:<' . $this->fromEmail() . '>', [250]);
            foreach (array_merge($to, $cc) as $addr) {
                $this->command('RCPT TO:<' . $addr . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $message = preg_replace('/^\./m', '..', build_mime_message($this->fromEmail(), (string) ($this->cfg['from_name'] ?? ''), $to, $subject, $html, $text, $replyTo, true, $attachments, $cc));
            fwrite($this->sock, $message . "\r\n.\r\n");
            $this->expect([250]);
            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException $ignored) {
            }
            return true;
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            return false;
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    private function fromEmail(): string
    {
        $from = trim((string) ($this->cfg['from_email'] ?? ''));
        return $from !== '' ? $from : trim((string) ($this->cfg['user'] ?? ''));
    }

    private function connect(): void
    {
        $host = trim((string) $this->cfg['host']);
        $port = (int) $this->cfg['port'];
        $secure = (string) ($this->cfg['secure'] ?? 'tls');
        $timeout = 20;

        if ($host === '' || $port <= 0) {
            throw new RuntimeException('SMTP host/port not configured.');
        }

        $ssl = [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'peer_name'         => $host,
            'allow_self_signed' => false,
        ];
        if (!ini_get('openssl.cafile')) {
            foreach (['C:/xampp/apache/bin/curl-ca-bundle.crt', 'C:/xampp/php/extras/ssl/cacert.pem'] as $bundle) {
                if (is_file($bundle)) {
                    $ssl['cafile'] = $bundle;
                    break;
                }
            }
        }
        $ctx = stream_context_create(['ssl' => $ssl]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sock = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("Could not connect to $host:$port — " . ($errstr ?: 'connection failed') . " ($errno)");
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, $timeout);

        $this->expect([220]);
        $this->ehlo();

        if ($secure === 'tls') {
            $this->command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->sock, true, $method)) {
                throw new RuntimeException('TLS negotiation failed. Check that PHP openssl is enabled and a CA bundle is configured.');
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $name = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost'));
        $this->command('EHLO ' . ($name !== '' ? $name : 'localhost'), [250]);
    }

    private function authenticate(): void
    {
        $user = (string) ($this->cfg['user'] ?? '');
        if ($user === '') {
            return;
        }
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($user), [334]);
        try {
            $this->command(base64_encode((string) ($this->cfg['pass'] ?? '')), [235]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Authentication failed. For Gmail, use a 16-character App Password (Google Account → Security → 2-Step Verification → App passwords), not your normal password. Server said: ' . $e->getMessage());
        }
    }

    private function command(string $cmd, array $expect): string
    {
        fwrite($this->sock, $cmd . "\r\n");
        return $this->expect($expect);
    }

    private function expect(array $codes): string
    {
        $data = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ($data === '') {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP server timed out.' : 'SMTP connection closed unexpectedly.');
        }
        $code = (int) substr($data, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException(trim($data));
        }
        return $data;
    }
}

function encode_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

function format_address(string $email, string $name = ''): string
{
    $name = trim(str_replace(["\r", "\n", '"'], '', $name));
    if ($name === '') {
        return $email;
    }
    return (preg_match('/[^\x20-\x7E]/', $name) ? encode_header($name) : '"' . $name . '"') . ' <' . $email . '>';
}

/**
 * Build a MIME message (text + HTML, plus optional attachments).
 * With $full = false the To/Subject headers are omitted (for mail()).
 * $attachments: list of ['name' => 'file.pdf', 'type' => 'application/pdf', 'data' => bytes].
 */
function build_mime_message(string $fromEmail, string $fromName, array $to, string $subject, string $html, string $text, ?string $replyTo, bool $full, array $attachments = [], array $cc = [], array $extra = []): string
{
    $boundary = 'ktc_' . bin2hex(random_bytes(12));
    $mixed = 'ktm_' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
    $headers = [
        'Date: ' . date('r'),
        'From: ' . format_address($fromEmail, $fromName),
    ];
    if ($full) {
        $headers[] = 'To: ' . implode(', ', $to);
        $headers[] = 'Subject: ' . encode_header($subject);
    }
    if ($cc) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = $attachments
        ? 'Content-Type: multipart/mixed; boundary="' . $mixed . '"'
        : 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: KakebeTechCamp';
    foreach ($extra as $name => $value) {
        $headers[] = preg_replace('/[^A-Za-z0-9-]/', '', (string) $name) . ': ' . str_replace(["\r", "\n"], '', (string) $value);
    }

    $alt = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--";

    if (!$attachments) {
        return implode("\r\n", $headers) . "\r\n\r\n" . $alt;
    }

    $body = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n" . $alt . "\r\n";
    foreach ($attachments as $a) {
        $name = preg_replace('/[^\w.\-]/', '_', (string) $a['name']);
        $body .= "--$mixed\r\n"
            . 'Content-Type: ' . ($a['type'] ?? 'application/octet-stream') . "; name=\"$name\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
            . chunk_split(base64_encode((string) $a['data']));
    }
    $body .= "--$mixed--";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function smtp_config(): array
{
    return [
        'host'       => setting('smtp_host'),
        'port'       => (int) setting('smtp_port', 587),
        'secure'     => setting('smtp_secure', 'tls'),
        'user'       => setting('smtp_user'),
        // Password saved in Admin → Settings wins; otherwise the SMTP_PASS from .env is used.
        'pass'       => decrypt_secret((string) setting('smtp_pass')) ?: env('SMTP_PASS'),
        'from_email' => setting('mail_from_email'),
        'from_name'  => setting('mail_from_name', 'Kakebe Tech Camp'),
    ];
}

/**
 * Send an email using the transport chosen in Admin → Settings:
 *   smtp — Gmail or any SMTP server (recommended)
 *   mail — PHP mail() (works on many Linux hosts)
 *   log  — write to storage/logs/mail.log (default until email is configured)
 *
 * @param string|string[] $to
 */
function send_mail($to, string $subject, string $html, ?string $replyTo = null, ?string &$error = null, array $attachments = [], array $cc = []): bool
{
    $list = email_list($to);
    $cc = array_values(array_diff(email_list($cc), $list));
    if (!$list) {
        $error = 'No valid recipient.';
        return false;
    }

    $transport = setting('mail_transport', 'log');
    $text = html_to_text($html);
    $error = null;
    $status = 'sent';

    if ($transport === 'smtp') {
        $mailer = new SmtpMailer(smtp_config());
        $ok = $mailer->send($list, $subject, $html, $text, $replyTo, $attachments, $cc);
        $error = $ok ? null : $mailer->error;
    } elseif ($transport === 'mail') {
        $cfg = smtp_config();
        $from = $cfg['from_email'] ?: ('no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
        $raw = build_mime_message($from, $cfg['from_name'], $list, $subject, $html, $text, $replyTo, false, $attachments, $cc);
        [$headers, $body] = explode("\r\n\r\n", $raw, 2);
        $ok = @mail(implode(', ', $list), encode_header($subject), $body, $headers);
        $error = $ok ? null : 'PHP mail() returned false — your server may not be configured to send mail. Use SMTP instead.';
    } else {
        $files = $attachments ? "\nAttachments: " . implode(', ', array_column($attachments, 'name')) : '';
        $entry = str_repeat('=', 70) . "\n" . now() . "\nTo: " . implode(', ', $list) . ($cc ? "\nCc: " . implode(', ', $cc) : '') . "\nSubject: $subject$files\n\n$text\n\n";
        $ok = file_put_contents(STORAGE . '/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
        $status = 'logged';
    }

    try {
        db()->prepare('INSERT INTO email_log (recipient, subject, status, error, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([substr(implode(', ', $list) . ($cc ? ' (cc: ' . implode(', ', $cc) . ')' : ''), 0, 255), substr($subject, 0, 255), $ok ? $status : 'failed', $error, now()]);
    } catch (Throwable $ignored) {
    }

    return $ok;
}

/** Parse "a@x.com, b@y.com" (or an array) into a clean, unique, lower-case list of valid emails. */
function email_list($value): array
{
    $items = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
    $items = array_map(fn($a) => strtolower(trim((string) $a)), $items);
    return array_values(array_unique(array_filter($items, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
}

/**
 * Email the team: sent to Admin → Settings "notify" addresses, with the copy (CC) list for the event type.
 * $type: 'registration', 'payment' or 'message'.
 */
function notify_team(string $type, string $subject, string $html, ?string $replyTo = null, array $attachments = []): bool
{
    $to = email_list((string) setting('notify_emails'));
    $ccKey = ['registration' => 'notify_registration_cc', 'payment' => 'notify_payment_cc'][$type] ?? null;
    $cc = $ccKey ? array_values(array_diff(email_list((string) setting($ccKey)), $to)) : [];
    if (!$to && !$cc) {
        return false;
    }
    if (!$to) {
        [$to, $cc] = [$cc, []];
    }
    return send_mail($to, $subject, $html, $replyTo, $err, $attachments, $cc);
}