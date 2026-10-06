<?php
/**
 * Small QR code generator (byte mode, error correction level M, versions 1–10 — up to 213 characters),
 * so tickets carry a scannable code in the PDF and in emails without any image library.
 * Follows ISO/IEC 18004; structure after Project Nayuki's reference implementation.
 */

/** [error-correction codewords per block, [[number of blocks, data codewords per block], …]] for level M. */
function qr_blocks(int $version): array
{
    return [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ][$version];
}

function qr_align_positions(int $version): array
{
    return [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]][$version];
}

function qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

function qr_rs_divisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 0x02);
    }
    return $result;
}

function qr_rs_remainder(array $data, array $divisor): array
{
    $result = array_fill(0, count($divisor), 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($result);
        $result[] = 0;
        foreach ($divisor as $i => $coef) {
            $result[$i] ^= qr_gf_mul($coef, $factor);
        }
    }
    return $result;
}

/** The QR code for $text as rows of booleans (true = dark), without the quiet zone. */
function qr_matrix(string $text): array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len = count($bytes);
    for ($version = 1; $version <= 10; $version++) {
        [$ecLen, $groups] = qr_blocks($version);
        $dataCw = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
        if (4 + ($version < 10 ? 8 : 16) + $len * 8 <= $dataCw * 8) {
            break;
        }
    }
    if ($version > 10) {
        throw new InvalidArgumentException('Text too long for a QR code.');
    }

    // Data bits: byte mode, length, data, terminator, padding
    $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) {
        $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($pad = 0xEC; count($data) < $dataCw; $pad ^= 0xEC ^ 0x11) {
        $data[] = $pad;
    }

    // Split into blocks, add error correction, interleave
    $divisor = qr_rs_divisor($ecLen);
    $blocks = [];
    $offset = 0;
    foreach ($groups as [$count, $size]) {
        for ($i = 0; $i < $count; $i++) {
            $d = array_slice($data, $offset, $size);
            $offset += $size;
            $blocks[] = [$d, qr_rs_remainder($d, $divisor)];
        }
    }
    $codewords = [];
    $maxData = max(array_map(fn($b) => count($b[0]), $blocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as [$d]) {
            if ($i < count($d)) {
                $codewords[] = $d[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($blocks as [, $ec]) {
            $codewords[] = $ec[$i];
        }
    }

    // Function patterns
    $size = $version * 4 + 17;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = $m;
    $set = function (int $x, int $y, bool $dark) use (&$m, &$fn) {
        $m[$y][$x] = $dark;
        $fn[$y][$x] = true;
    };
    for ($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $dist = max(abs($dx), abs($dy));
                    $set($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }
    $align = qr_align_positions($version);
    $last = count($align) - 1;
    foreach ($align as $i => $ax) {
        foreach ($align as $j => $ay) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    $drawFormat = function (int $mask) use ($set, $size) {
        $data = (0 << 3) | $mask;   // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    };
    $drawFormat(0);   // reserve the format areas
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $vbits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($vbits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, $dark);
            $set($b, $a, $dark);
        }
    }

    // Codewords in the zigzag order
    $total = count($codewords) * 8;
    $i = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) {
                    $m[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // Try the eight masks, keep the one with the lowest penalty
    $masks = [
        fn($x, $y) => ($x + $y) % 2 === 0, fn($x, $y) => $y % 2 === 0, fn($x, $y) => $x % 3 === 0, fn($x, $y) => ($x + $y) % 3 === 0,
        fn($x, $y) => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0, fn($x, $y) => ($x * $y) % 2 + ($x * $y) % 3 === 0,
        fn($x, $y) => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0, fn($x, $y) => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
    ];
    $base = $m;
    $best = null;
    $bestScore = PHP_INT_MAX;
    foreach ($masks as $k => $cond) {
        $m = $base;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$fn[$y][$x] && $cond($x, $y)) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
        $drawFormat($k);
        $score = qr_penalty($m, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $m;
        }
    }
    return $best;
}

/** Penalty score used to pick a mask (lower is easier to scan). */
function qr_penalty(array $m, int $size): int
{
    $score = 0;
    $dark = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $row = '';
        $col = '';
        for ($j = 0; $j < $size; $j++) {
            $row .= $m[$i][$j] ? '1' : '0';
            $col .= $m[$j][$i] ? '1' : '0';
        }
        $lines[] = $row;
        $lines[] = $col;
        $dark += substr_count($row, '1');
    }
    foreach ($lines as $line) {
        preg_match_all('/0{5,}|1{5,}/', $line, $runs);
        foreach ($runs[0] as $run) {
            $score += 3 + strlen($run) - 5;
        }
        $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
    }
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $c = $m[$y][$x];
            if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }
    $total = $size * $size;
    $score += (intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1) * 10;
    return $score;
}

/** PNG image of the QR code (two colours, crisp at any zoom). */
function qr_png(string $text, int $scale = 8, int $quiet = 4, array $rgb = [15, 37, 87]): string
{
    $m = qr_matrix($text);
    $n = count($m);
    $px = ($n + 2 * $quiet) * $scale;
    $raw = '';
    for ($y = 0; $y < $px; $y++) {
        $my = intdiv($y, $scale) - $quiet;
        $row = '';
        for ($x = 0; $x < $px; $x++) {
            $mx = intdiv($x, $scale) - $quiet;
            $row .= ($my >= 0 && $my < $n && $mx >= 0 && $mx < $n && $m[$my][$mx]) ? '1' : '0';
        }
        $row = str_pad($row, (int) ceil($px / 8) * 8, '0');
        $raw .= "\0" . implode('', array_map(fn($b) => chr(bindec($b)), str_split($row, 8)));
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $px, $px, 1, 3, 0, 0, 0))
        . $chunk('PLTE', "\xFF\xFF\xFF" . chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}
