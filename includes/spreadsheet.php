<?php
/**
 * Read the rows of an uploaded CSV or Excel (.xlsx) file — first sheet only.
 * Works without the PHP zip extension (a small built-in ZIP reader is used when ZipArchive is missing).
 */

/** @return array<int, array<int, string>> */
function spreadsheet_rows(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_rows($path);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Old Excel (.xls) files are not supported — in Excel choose File → Save As → .xlsx or CSV.');
    }
    return csv_rows($path);
}

function csv_rows(string $path): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Could not open the file.');
    }
    $first = (string) fgets($fh);
    rewind($fh);
    // Pick the delimiter that splits the first line into the most columns
    $delimiter = ',';
    $best = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        $n = count(str_getcsv($first, $d));
        if ($n > $best) {
            $best = $n;
            $delimiter = $d;
        }
    }
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(fn($v) => trim((string) $v), $row);
    }
    fclose($fh);
    if ($rows) {
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]); // UTF-8 BOM from Excel
    }
    return $rows;
}

function xlsx_rows(string $path): array
{
    $files = zip_entries($path, ['xl/sharedStrings.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'], '~^xl/worksheets/sheet\d+\.xml$~');

    // The first sheet in the workbook (falls back to sheet1.xml)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (!empty($files['xl/workbook.xml']) && !empty($files['xl/_rels/workbook.xml.rels'])) {
        $wb = @simplexml_load_string($files['xl/workbook.xml']);
        $rels = @simplexml_load_string($files['xl/_rels/workbook.xml.rels']);
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $sheetXml = $files[$sheetPath] ?? (array_values(array_filter($files, fn($k) => str_starts_with($k, 'xl/worksheets/'), ARRAY_FILTER_USE_KEY))[0] ?? null);
    if (!$sheetXml) {
        throw new RuntimeException('This Excel file has no readable sheet.');
    }

    $shared = [];
    if (!empty($files['xl/sharedStrings.xml'])) {
        $ss = @simplexml_load_string($files['xl/sharedStrings.xml']);
        foreach ($ss ? $ss->si : [] as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('The Excel sheet could not be read.');
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) {
                $col = $col * 26 + (ord(strtoupper($ch)) - 64);
            }
            $type = (string) $c['t'];
            if ($type === 's') {
                $value = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($c->is->t ?? '');
            } else {
                $value = (string) $c->v;
            }
            $cells[max(0, $col - 1)] = trim($value);
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
        }
    }
    return $rows;
}

/**
 * Pull named files out of a ZIP archive (an .xlsx is a ZIP). Uses ZipArchive when available,
 * otherwise reads the archive's central directory directly (stored and deflated entries).
 */
function zip_entries(string $path, array $names, string $pattern = ''): array
{
    $want = fn(string $n) => in_array($n, $names, true) || ($pattern !== '' && preg_match($pattern, $n));
    $out = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This does not look like a valid .xlsx file.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if ($want($n)) {
                $out[$n] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        return $out;
    }

    $data = (string) file_get_contents($path);
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) {
        throw new RuntimeException('This does not look like a valid .xlsx file.');
    }
    $count = unpack('v', substr($data, $eocd + 10, 2))[1];
    $offset = unpack('V', substr($data, $eocd + 16, 4))[1];
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $offset, 4) !== "PK\x01\x02") {
            break;
        }
        $h = unpack('vmethod/x8/Vcsize/Vusize/vnlen/velen/vclen/x8/Vlocal', substr($data, $offset + 10, 36));
        $name = substr($data, $offset + 46, $h['nlen']);
        if ($want($name)) {
            $local = unpack('vnlen/velen', substr($data, $h['local'] + 26, 4));
            $raw = substr($data, $h['local'] + 30 + $local['nlen'] + $local['elen'], $h['csize']);
            $out[$name] = $h['method'] === 8 ? (string) @gzinflate($raw) : $raw;
        }
        $offset += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }
    return $out;
}
