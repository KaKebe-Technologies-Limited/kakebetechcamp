<?php
/**
 * Dependency-free PDF writer (adapted from the ObiFunds SimplePdf) with JPEG image support,
 * plus the Kakebe payment / sponsorship receipt layouts.
 * Coordinates are from the TOP-LEFT of the page (y grows downward).
 */
class SimplePdf
{
    private string $content = '';
    private array $images = [];
    private const AVG_WIDTH_REGULAR = 0.52;
    private const AVG_WIDTH_BOLD = 0.56;

    public function __construct(private float $width = 595, private float $height = 842)
    {
    }

    private function enc(string $text): string
    {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) : false;
        if ($converted === false && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }
        return $converted === false ? $text : $converted;
    }

    private function esc(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->enc($text));
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        return strlen($this->enc($text)) * $size * ($bold ? self::AVG_WIDTH_BOLD : self::AVG_WIDTH_REGULAR);
    }

    public function fill(array $rgb): void
    {
        $this->content .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    public function stroke(array $rgb): void
    {
        $this->content .= sprintf("%.3F %.3F %.3F RG\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    public function rect(float $x, float $y, float $w, float $h, string $mode = 'f'): void
    {
        $op = $mode === 's' ? 'S' : ($mode === 'sf' ? 'B' : 'f');
        $this->content .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $this->height - $y - $h, $w, $h, $op);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 1, bool $dashed = false): void
    {
        $this->content .= ($dashed ? "[3 3] 0 d\n" : '') . sprintf("%.2F w\n%.2F %.2F m %.2F %.2F l S\n", $lw, $x1, $this->height - $y1, $x2, $this->height - $y2) . ($dashed ? "[] 0 d\n" : '');
    }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $this->content .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $this->height - $y, $this->esc($text));
    }

    public function textRight(float $right, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $this->text($right - $this->textWidth($text, $size, $bold), $y, $text, $size, $bold);
    }

    public function textCenter(float $cx, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $this->text($cx - $this->textWidth($text, $size, $bold) / 2, $y, $text, $size, $bold);
    }

    /** Wrap text to a width; returns the y after the last line. */
    public function paragraph(float $x, float $y, float $maxW, string $text, float $size = 10, float $lh = 14, bool $bold = false): float
    {
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && $this->textWidth($try, $size, $bold) > $maxW) {
                $this->text($x, $y, $line, $size, $bold);
                $y += $lh;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $this->text($x, $y, $line, $size, $bold);
            $y += $lh;
        }
        return $y;
    }

    public function jpeg(string $path, float $x, float $y, float $w, float $h): void
    {
        $info = @getimagesize($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return;
        }
        $i = count($this->images) + 1;
        $this->images[$i] = ['data' => file_get_contents($path), 'w' => $info[0], 'h' => $info[1], 'cs' => ($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : 'DeviceRGB'];
        $this->content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, $this->height - $y - $h, $i);
    }

    /** Draw a JPEG scaled to cover the box (centre-cropped via a clipping path). Returns false if not a JPEG. */
    public function jpegCover(string $path, float $x, float $y, float $w, float $h): bool
    {
        $info = @getimagesize($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return false;
        }
        $scale = max($w / $info[0], $h / $info[1]);
        $dw = $info[0] * $scale;
        $dh = $info[1] * $scale;
        $i = count($this->images) + 1;
        $this->images[$i] = ['data' => file_get_contents($path), 'w' => $info[0], 'h' => $info[1], 'cs' => ($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : 'DeviceRGB'];
        $py = $this->height - $y - $h;
        $this->content .= sprintf("q %.2F %.2F %.2F %.2F re W n %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n",
            $x, $py, $w, $h, $dw, $dh, $x - ($dw - $w) / 2, $py - ($dh - $h) / 2, $i);
        return true;
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $xobj = '';
        $n = 7;
        $imgObjs = [];
        foreach ($this->images as $i => $img) {
            $xobj .= "/Im$i $n 0 R ";
            $imgObjs[$n] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /{$img['cs']} /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img['data']) . " >>\nstream\n{$img['data']}\nendstream";
            $n++;
        }
        $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->width} {$this->height}] /Resources << /Font << /F1 5 0 R /F2 6 0 R >>" . ($xobj ? " /XObject << $xobj>>" : '') . " >> /Contents 4 0 R >>";
        $objects[4] = '<< /Length ' . strlen($this->content) . " >>\nstream\n{$this->content}endstream";
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects += $imgObjs;
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        return $pdf . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}

function amount_in_words(int $num): string
{
    if ($num === 0) {
        return 'Zero';
    }
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $three = function (int $n) use ($ones, $tens): string {
        $out = [];
        if ($n >= 100) {
            $out[] = $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        } elseif ($n > 0) {
            $out[] = $ones[$n];
        }
        return implode(' ', $out);
    };
    $parts = [];
    foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 1 => ''] as $div => $label) {
        if ($num >= $div) {
            $parts[] = trim($three(intdiv($num, $div)) . ' ' . $label);
            $num %= $div;
        }
    }
    return implode(' ', $parts);
}

/** Build the receipt PDF for a successful payment (camp or sponsorship). Returns raw PDF bytes. */
function receipt_pdf(array $p): string
{
    $red = [225, 29, 42];
    $navy = [15, 37, 87];
    $ink = [16, 25, 53];
    $gray = [110, 116, 140];
    $soft = [246, 248, 252];
    $green = [20, 128, 74];
    $isCamp = $p['purpose'] === 'camp';
    $r = $isCamp && $p['registration_id'] ? find_registration((int) $p['registration_id']) : null;
    $d = !$isCamp && $p['donation_id'] ? db()->query('SELECT * FROM donations WHERE id = ' . (int) $p['donation_id'])->fetch() : null;
    $cur = $p['currency'] ?: 'UGX';
    $pdf = new SimplePdf(595, 842);

    // Header band
    $pdf->fill($red);
    $pdf->rect(0, 0, 595, 104);
    $pdf->fill($navy);
    $pdf->rect(0, 104, 595, 6);
    $pdf->jpeg(ROOT . '/assets/img/pdf-logo.jpg', 36, 32, 150, 31);
    $pdf->fill([255, 255, 255]);
    $pdf->textRight(559, 48, $isCamp ? 'PAYMENT RECEIPT' : 'SPONSORSHIP RECEIPT', 19, true);
    $pdf->fill([255, 222, 218]);
    $pdf->textRight(559, 66, 'Kakebe Tech Camp 2026 · ' . camp()['dates'], 9);
    $pdf->textRight(559, 80, 'Support: ' . setting('contact_phone', '0779 712 990'), 9);

    // Receipt meta
    $pdf->fill($ink);
    $pdf->text(36, 142, 'Receipt No: ' . receipt_no($p), 11, true);
    $pdf->textRight(559, 142, 'Date: ' . date('j M Y, g:i A', strtotime($p['completed_at'] ?: $p['created_at'])), 11, true);
    $pdf->jpeg(ROOT . '/assets/img/techcamp-logo-pdf.jpg', 413, 150, 146, 77);
    $pdf->stroke([225, 228, 238]);
    $pdf->line(36, 152, 559, 152, 0.6);

    // Received from
    $pdf->fill($gray);
    $pdf->text(36, 178, 'RECEIVED WITH THANKS FROM', 8.5, true);
    $pdf->fill($ink);
    $name = $r['full_name'] ?? ($d ? ($d['donor_name'] . ($d['organization'] ? ' — ' . $d['organization'] : '')) : ($p['payer_name'] ?? ''));
    $pdf->text(36, 196, $name, 14, true);
    $pdf->fill($gray);
    $contact = trim(($r['email'] ?? $d['email'] ?? $p['payer_email'] ?? '') . '  ·  ' . ($r['phone'] ?? $d['phone'] ?? $p['payer_phone'] ?? ''), ' ·');
    $pdf->text(36, 213, $contact, 9.5);
    if ($r) {
        $pdf->text(36, 228, 'Participant reference: ' . $r['reference'], 9.5);
    } elseif ($d) {
        $pdf->text(36, 228, 'Sponsorship reference: ' . $d['reference'], 9.5);
    }

    // Amount box
    $pdf->fill([255, 242, 242]);
    $pdf->rect(36, 248, 523, 74);
    $pdf->fill($red);
    $pdf->rect(36, 248, 5, 74);
    $pdf->fill($gray);
    $pdf->text(56, 270, 'AMOUNT PAID', 8.5, true);
    $pdf->fill($ink);
    $pdf->text(56, 296, format_ugx($p['amount'], $cur), 24, true);
    $pdf->fill($gray);
    $pdf->paragraph(56, 313, 300, amount_in_words((int) $p['amount']) . ' ' . ($cur === 'UGX' ? 'Uganda Shillings' : $cur) . ' only', 8.5, 11);
    $pdf->fill($gray);
    $pdf->textRight(540, 270, 'PAYMENT METHOD', 8.5, true);
    $pdf->fill($ink);
    $pdf->textRight(540, 288, payment_methods()[$p['method']] ?? ucfirst($p['method']), 11, true);
    $pdf->fill($gray);
    $txn = $p['provider_txn_id'] ?: ($p['notes'] ?: '—');
    $pdf->textRight(540, 305, 'Txn: ' . mb_strimwidth((string) $txn, 0, 40, '…'), 8.5);

    $y = 356;
    if ($r) {
        $pdf->fill($ink);
        $pdf->text(36, $y, 'Camp package', 12, true);
        $y += 14;
        $pdf->fill($navy);
        $pdf->rect(36, $y, 523, 24);
        $pdf->fill([255, 255, 255]);
        $pdf->text(48, $y + 16, 'Item', 9.5, true);
        $pdf->textRight(547, $y + 16, 'Amount (' . $cur . ')', 9.5, true);
        $y += 24;
        foreach (order_items($r) as $i => [$label, $amt, $tag]) {
            $pdf->fill($i % 2 ? [255, 255, 255] : $soft);
            $pdf->rect(36, $y, 523, 24);
            $pdf->fill($ink);
            $pdf->text(48, $y + 16, $label, 10);
            $pdf->fill($gray);
            $pdf->text(330, $y + 16, $tag, 8.5);
            $pdf->fill($ink);
            $pdf->textRight(547, $y + 16, $amt ? number_format($amt) : 'FREE', 10, true);
            $y += 24;
        }
        $rows = [
            ['Total package', number_format((int) $r['total_amount']), $ink],
            ['Total paid to date', number_format((int) $r['amount_paid']), $green],
            ['Balance to clear', number_format(balance($r)), balance($r) > 0 ? $red : $green],
        ];
        $y += 6;
        foreach ($rows as [$label, $val, $col]) {
            $pdf->fill($gray);
            $pdf->text(330, $y + 14, $label, 10);
            $pdf->fill($col);
            $pdf->textRight(547, $y + 14, $cur . ' ' . $val, 11.5, true);
            $y += 22;
        }
        // Status stamp
        $full = balance($r) === 0;
        $pdf->fill($full ? [230, 247, 238] : [255, 244, 229]);
        $pdf->rect(36, $y - 60, 200, 46);
        $pdf->fill($full ? $green : [181, 71, 8]);
        $pdf->text(52, $y - 40, $full ? 'PAID IN FULL' : (paid_percent($r) >= fees()['deposit_pct'] ? 'SLOT BOOKED' : 'PART PAYMENT'), 14, true);
        $pdf->fill($gray);
        $pdf->text(52, $y - 24, $full ? 'Your place at camp is confirmed.' : paid_percent($r) . '% paid · clear balance before camp', 8.5);
        $y += 18;
        $pdf->fill($ink);
        $pdf->text(36, $y, 'Camp details', 11, true);
        $pdf->fill($gray);
        $y = $pdf->paragraph(36, $y + 16, 523, camp()['dates'] . ' · ' . camp()['venue'] . ' (residential camp). Includes free enrolment in the Kakebe Mentorship & Digital Bridge Internship programmes (' . camp()['mentorship'] . ').', 9.5, 13);
        if (!$full) {
            $y = $pdf->paragraph(36, $y + 4, 523, 'Clear your balance any time at ' . pay_url($r) . ' or through your participant portal.', 9.5, 13);
        }
    } elseif ($d) {
        $pdf->fill($ink);
        $pdf->text(36, $y, 'Sponsorship details', 12, true);
        $y += 16;
        $rows = [
            ['Sponsor', $d['is_anonymous'] ? $d['donor_name'] . ' (listed as anonymous)' : $d['donor_name']],
            ['Organisation', $d['organization'] ?: '—'],
            ['Innovators sponsored', $d['children'] ? (string) $d['children'] : 'General support'],
            ['Pledged amount', format_ugx($d['amount'], $cur)],
        ];
        foreach ($rows as $i => [$label, $val]) {
            $pdf->fill($i % 2 ? [255, 255, 255] : $soft);
            $pdf->rect(36, $y, 523, 26);
            $pdf->fill($gray);
            $pdf->text(48, $y + 17, $label, 10);
            $pdf->fill($ink);
            $pdf->text(220, $y + 17, $val, 10.5, true);
            $y += 26;
        }
        $y += 26;
        $pdf->fill($navy);
        $y = $pdf->paragraph(36, $y, 523, 'Thank you for sponsoring young innovators from Northern Uganda. Your support gives a young person 10 days of hands-on training in AI, software, content creation, entrepreneurship, gaming and robotics at Kakebe Tech Camp 2026.', 11, 16, true);
    }

    // Footer
    $pdf->fill($navy);
    $pdf->rect(0, 790, 595, 52);
    $pdf->fill([255, 255, 255]);
    $pdf->textCenter(297.5, 811, 'Kakebe Technologies Limited  ·  Support / WhatsApp: ' . setting('contact_phone', '0779 712 990') . '  ·  ' . preg_replace('~^https?://~', '', base_url()), 9, true);
    $pdf->fill([196, 205, 234]);
    $pdf->textCenter(297.5, 826, 'This is a system-generated receipt — no signature required.', 8);

    return $pdf->output();
}

/** Camp ticket as a PDF (landscape card) — available once the participant is confirmed. */
function ticket_pdf(array $r): string
{
    $red = [225, 29, 42];
    $navy = [15, 37, 87];
    $ink = [16, 25, 53];
    $gray = [110, 116, 140];
    $soft = [246, 248, 252];
    $W = 842;
    $H = 440;
    $pdf = new SimplePdf($W, $H);
    $confirmed = $r['status'] === 'confirmed';

    $pdf->fill([255, 255, 255]);
    $pdf->rect(0, 0, $W, $H);

    // Stub (right)
    $stubX = 612;
    $pdf->fill($navy);
    $pdf->rect($stubX, 0, $W - $stubX, $H);
    $pdf->fill($red);
    $pdf->rect($stubX, 0, $W - $stubX, 8);
    $pdf->stroke([214, 219, 232]);
    $pdf->line($stubX - 1, 24, $stubX - 1, $H - 24, 1.2, true);
    $cx = $stubX + ($W - $stubX) / 2;
    $pdf->fill([196, 205, 234]);
    $pdf->textCenter($cx, 70, 'REFERENCE', 9, true);
    $pdf->fill([255, 255, 255]);
    $pdf->textCenter($cx, 100, $r['reference'], 24, true);
    $pdf->fill($confirmed ? [34, 160, 90] : [214, 139, 20]);
    $pdf->rect($cx - 70, 130, 140, 30);
    $pdf->fill([255, 255, 255]);
    $pdf->textCenter($cx, 150, $confirmed ? 'CONFIRMED' : 'PENDING', 12, true);
    $pdf->fill([196, 205, 234]);
    $pdf->textCenter($cx, 205, 'ADMIT ONE', 9, true);
    $pdf->fill([255, 255, 255]);
    $pdf->textCenter($cx, 228, camp()['dates_short'], 14, true);
    $pdf->fill([196, 205, 234]);
    $pdf->textCenter($cx, 248, 'Kitgum · Residential camp', 9.5);
    if (is_sponsored($r) && $r['sponsor_name']) {
        $pdf->fill([196, 205, 234]);
        $pdf->textCenter($cx, 300, 'SPONSORED BY', 8.5, true);
        $pdf->fill([255, 255, 255]);
        $pdf->textCenter($cx, 318, mb_strimwidth($r['sponsor_name'], 0, 30, '…'), 11, true);
    }
    $pdf->fill([196, 205, 234]);
    $pdf->textCenter($cx, 392, 'Present this ticket at check-in', 8.5);
    $pdf->textCenter($cx, 406, 'Support: ' . setting('contact_phone', '0779 712 990'), 8.5);

    // Main (left)
    $pdf->jpeg(ROOT . '/assets/img/techcamp-logo-pdf.jpg', 18, 14, 190, 100);
    $pdf->fill($gray);
    $pdf->textRight(588, 52, 'PARTICIPANT TICKET', 10, true);
    $pdf->fill($red);
    $pdf->textRight(588, 72, 'KAKEBE TECH CAMP 2026', 9, true);

    // Photo
    $px = 40;
    $py = 130;
    $pw = 118;
    $ph = 140;
    $pdf->fill($soft);
    $pdf->rect($px - 4, $py - 4, $pw + 8, $ph + 8);
    $photo = photo_path($r['photo'] ?? null);
    if (!$photo || !$pdf->jpegCover($photo, $px, $py, $pw, $ph)) {
        $pdf->fill($red);
        $pdf->rect($px, $py, $pw, $ph);
        $pdf->fill([255, 255, 255]);
        $pdf->textCenter($px + $pw / 2, $py + $ph / 2 + 14, initials($r['full_name']), 38, true);
    }

    // Name & details
    $tx = 186;
    $pdf->fill($gray);
    $pdf->text($tx, 146, 'PARTICIPANT', 8.5, true);
    $pdf->fill($navy);
    $y = $pdf->paragraph($tx, 172, 400, $r['full_name'], 22, 26, true);
    $pdf->fill($ink);
    $pdf->text($tx, $y + 2, $r['district'] . ', ' . $r['country'] . '  ·  Age ' . (int) $r['age'], 10.5);
    $pdf->fill($gray);
    $pdf->text($tx, $y + 26, 'LEARNING TRACKS', 8.5, true);
    $pdf->fill($red);
    $pdf->paragraph($tx, $y + 42, 400, $r['interests'] ?: '—', 11, 15, true);

    // Info cells
    $cells = [
        ['DATES', camp()['dates']],
        ['VENUE', 'Kitgum, Northern Uganda'],
        ['JERSEY SIZE', ($r['jersey_size'] ?: '—') . ' + camp shirt'],
    ];
    $cw = 176;
    foreach ($cells as $i => [$label, $value]) {
        $x = 36 + $i * ($cw + 8);
        $pdf->fill($soft);
        $pdf->rect($x, 300, $cw, 54);
        $pdf->fill($gray);
        $pdf->text($x + 12, 320, $label, 8, true);
        $pdf->fill($ink);
        $pdf->text($x + 12, 340, mb_strimwidth($value, 0, 30, '…'), 10, true);
    }
    $extras = [];
    if (!empty($r['park_visit'])) {
        $extras[] = fees()['park_name'] . ' excursion';
    }
    if (!empty($r['mentorship'])) {
        $extras[] = 'Mentorship & Digital Bridge (Oct – Nov)';
    }
    $pdf->fill($gray);
    $pdf->text(36, 380, $extras ? 'Also includes: ' . implode('  ·  ', $extras) : 'Kakebe Technologies Limited · Learn. Build. Innovate.', 9);
    $pdf->fill($gray);
    $pdf->text(36, 404, 'Verify online: ' . preg_replace('~^https?://~', '', base_url('ticket.php')) . '  ·  Issued ' . date('j M Y'), 8);

    return $pdf->output();
}
