<?php

namespace App\Services\Institution\Parsers;

use ZipArchive;

/** UYAP sayım dosyalarını sunucuda Python gerektirmeden okur. */
function sayim_u16(string $data, int $offset): int
{
    return strlen($data) >= $offset + 2 ? unpack('v', substr($data, $offset, 2))[1] : 0;
}

function sayim_u32(string $data, int $offset): int
{
    return strlen($data) >= $offset + 4 ? unpack('V', substr($data, $offset, 4))[1] : 0;
}

function sayim_ole_workbook(string $raw): ?string
{
    if (substr($raw, 0, 8) !== "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") {
        return null;
    }
    $sectorSize = 1 << sayim_u16($raw, 30);
    $miniSize = 1 << sayim_u16($raw, 32);
    if (! in_array($sectorSize, [512, 4096], true) || $miniSize !== 64) {
        return null;
    }
    $sector = static fn (int $id): string => substr($raw, ($id + 1) * $sectorSize, $sectorSize);
    $fatIds = [];
    for ($i = 0; $i < 109; $i++) {
        $id = sayim_u32($raw, 76 + $i * 4);
        if ($id < 0xFFFFFFFA) {
            $fatIds[] = $id;
        }
    }
    $difatId = sayim_u32($raw, 68);
    for ($n = 0; $n < min(100, sayim_u32($raw, 72)) && $difatId < 0xFFFFFFFA; $n++) {
        $chunk = $sector($difatId);
        for ($i = 0; $i < $sectorSize / 4 - 1; $i++) {
            $id = sayim_u32($chunk, $i * 4);
            if ($id < 0xFFFFFFFA) {
                $fatIds[] = $id;
            }
        }
        $difatId = sayim_u32($chunk, $sectorSize - 4);
    }
    $fat = [];
    foreach ($fatIds as $id) {
        $part = unpack('V*', $sector($id));
        if ($part) {
            foreach ($part as $entry) {
                $fat[] = $entry;
            }
        }
    }
    $readChain = static function (int $start, int $length = 20971520) use ($fat, $sector): string {
        $data = '';
        $seen = [];
        for ($id = $start; $id < 0xFFFFFFFA && isset($fat[$id]) && ! isset($seen[$id]) && strlen($data) < $length; $id = $fat[$id]) {
            $seen[$id] = true;
            $data .= $sector($id);
        }

        return substr($data, 0, $length);
    };
    $directory = $readChain(sayim_u32($raw, 48));
    $root = null;
    $workbook = null;
    for ($offset = 0; $offset + 128 <= strlen($directory); $offset += 128) {
        $entry = substr($directory, $offset, 128);
        $nameBytes = max(0, min(64, sayim_u16($entry, 64) - 2));
        $name = $nameBytes ? @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($entry, 0, $nameBytes)) : '';
        $item = ['start' => sayim_u32($entry, 116), 'size' => sayim_u32($entry, 120)];
        if (ord($entry[66]) === 5) {
            $root = $item;
        }
        if (ord($entry[66]) === 2 && in_array($name, ['Workbook', 'Book'], true)) {
            $workbook = $item;
        }
    }
    if (! $workbook || $workbook['size'] > 20971520) {
        return null;
    }
    if ($workbook['size'] >= sayim_u32($raw, 56)) {
        return $readChain($workbook['start'], $workbook['size']);
    }
    if (! $root) {
        return null;
    }
    $miniStream = $readChain($root['start'], $root['size']);
    $miniFatRaw = $readChain(sayim_u32($raw, 60), sayim_u32($raw, 64) * $sectorSize);
    $miniFat = array_values(unpack('V*', $miniFatRaw) ?: []);
    $data = '';
    $seen = [];
    for ($id = $workbook['start']; $id < 0xFFFFFFFA && isset($miniFat[$id]) && ! isset($seen[$id]) && strlen($data) < $workbook['size']; $id = $miniFat[$id]) {
        $seen[$id] = true;
        $data .= substr($miniStream, $id * $miniSize, $miniSize);
    }

    return substr($data, 0, $workbook['size']);
}

function sayim_biff_string(string $data, int &$offset): string
{
    $length = sayim_u16($data, $offset);
    $options = ord($data[$offset + 2] ?? "\0");
    $offset += 3;
    $runs = ($options & 8) ? sayim_u16($data, $offset) : 0;
    if ($options & 8) {
        $offset += 2;
    }
    $extended = ($options & 4) ? sayim_u32($data, $offset) : 0;
    if ($options & 4) {
        $offset += 4;
    }
    $wide = (bool) ($options & 1);
    $bytes = substr($data, $offset, $length * ($wide ? 2 : 1));
    $offset += strlen($bytes) + $runs * 4 + $extended;

    return (string) @iconv($wide ? 'UTF-16LE' : 'Windows-1252', 'UTF-8//IGNORE', $bytes);
}

function sayim_biff_rk(int $raw): float
{
    if ($raw & 2) {
        $number = $raw >> 2;
        if ($number & 0x20000000) {
            $number -= 0x40000000;
        }
    } else {
        $number = unpack('e', "\0\0\0\0".pack('V', $raw & 0xFFFFFFFC))[1];
    }

    return ($raw & 1) ? $number / 100 : $number;
}

function sayim_xls_grid(string $raw): ?array
{
    $book = sayim_ole_workbook($raw);
    if ($book === null) {
        return null;
    }
    $grid = [];
    $strings = [];
    $sheet = false;
    for ($p = 0, $size = strlen($book); $p + 4 <= $size;) {
        $type = sayim_u16($book, $p);
        $length = sayim_u16($book, $p + 2);
        $p += 4;
        if ($p + $length > $size) {
            break;
        }
        $data = substr($book, $p, $length);
        $p += $length;
        if ($type === 0x0809) {
            if (sayim_u16($data, 2) === 0x0010) {
                $sheet = true;
            }
        } elseif ($type === 0x000A && $sheet) {
            break;
        }
        if ($type === 0x00FC && ! $sheet) {
            $count = sayim_u32($data, 4);
            $offset = 8;
            for ($i = 0; $i < min($count, 100000) && $offset + 3 <= strlen($data); $i++) {
                $strings[] = sayim_biff_string($data, $offset);
            }
        }
        if (! $sheet || $length < 6) {
            continue;
        }
        $row = sayim_u16($data, 0);
        $col = sayim_u16($data, 2);
        if ($row > 10000 || $col > 100) {
            continue;
        }
        $value = null;
        if ($type === 0x0204 && $length >= 9) {
            $offset = 6;
            $value = sayim_biff_string($data, $offset);
        } elseif ($type === 0x00FD && $length >= 10) {
            $value = $strings[sayim_u32($data, 6)] ?? '';
        } elseif (($type === 0x0203 || $type === 0x0006) && $length >= 14) {
            $value = unpack('e', substr($data, 6, 8))[1];
        } elseif ($type === 0x027E && $length >= 10) {
            $value = sayim_biff_rk(sayim_u32($data, 6));
        } elseif ($type === 0x00BD && $length >= 12) {
            $last = sayim_u16($data, $length - 2);
            for ($c = $col, $pos = 4; $c <= $last && $pos + 6 <= $length - 2; $c++, $pos += 6) {
                $grid[$row][$c] = sayim_biff_rk(sayim_u32($data, $pos + 2));
            }
        }
        if ($value !== null) {
            $grid[$row][$col] = $value;
        }
    }
    ksort($grid, SORT_NUMERIC);

    return $grid ?: null;
}

function sayim_xlsx_grid(string $path): ?array
{
    if (! class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        return null;
    }
    if ($zip->numFiles > 10000) {
        $zip->close();

        return null;
    }
    $expanded = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $expanded += $stat['size'];
    }
    if ($expanded > 52428800) {
        $zip->close();

        return null;
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = (stripos($sharedXml, '<!DOCTYPE') === false ? @simplexml_load_string($sharedXml, \SimpleXMLElement::class, LIBXML_NONET) : false);
        if ($xml) {
            foreach ($xml->si as $si) {
                $shared[] = trim((string) $si->t ?: implode('', array_map('strval', iterator_to_array($si->r->t ?? []))));
            }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) {
        return null;
    }
    $xml = (stripos($sheet, '<!DOCTYPE') === false ? @simplexml_load_string($sheet, \SimpleXMLElement::class, LIBXML_NONET) : false);
    if (! $xml) {
        return null;
    }
    $grid = [];
    foreach ($xml->sheetData->row as $row) {
        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];
            if (! preg_match('/^([A-Z]+)(\d+)$/', $reference, $m)) {
                continue;
            }
            $column = 0;
            foreach (str_split($m[1]) as $letter) {
                $column = $column * 26 + ord($letter) - 64;
            }
            $value = (string) $cell->v;
            if ((string) $cell['t'] === 's') {
                $value = $shared[(int) $value] ?? '';
            } elseif ((string) $cell['t'] === 'inlineStr') {
                $value = (string) $cell->is->t;
            }
            $grid[(int) $m[2] - 1][$column - 1] = $value;
        }
    }

    return $grid ?: null;
}

function sayim_grid_result(array $grid): array
{
    $meta = ['kurum' => 'Buca Açık Ceza İnfaz Kurumu Müdürlüğü', 'blok' => 'Tüm Bloklar', 'tarih' => '', 'baslik' => 'Oda/Koğuşlardaki Tutuklu/Hükümlü Sayısı', 'tarih_saat' => ''];
    $headers = [];
    $rows = [];
    $totals = ['kapasite' => 0, 'kayitli' => 0, 'gayri_mevcut' => 0, 'olmasi_gereken' => 0, 'hazir' => 0];
    $number = static fn ($value): int => (int) preg_replace('/[^\d.-]/', '', (string) $value);
    foreach ($grid as $index => $cells) {
        $line = array_values($cells);
        $text = implode(' ', array_map('strval', $line));
        if ($index < 25) {
            if (preg_match('/Blok Adı\s*:\s*(.+)/ui', $text, $m)) {
                $meta['blok'] = trim($m[1]);
            }
            if (preg_match('/Tarih\s*:\s*([0-9.\/\-]+)/ui', $text, $m)) {
                $meta['tarih'] = $m[1];
            }
        }
        if (stripos($text, 'Sıra') !== false && (stripos($text, 'Koğuş') !== false || stripos($text, 'Oda') !== false)) {
            foreach ($cells as $column => $label) {
                $label = mb_strtolower((string) $label, 'UTF-8');
                if (str_contains($label, 'sıra')) {
                    $headers['sira'] = $column;
                } elseif (str_contains($label, 'blok') || str_contains($label, 'kısım')) {
                    $headers['blok'] = $column;
                } elseif (str_contains($label, 'koridor')) {
                    $headers['koridor'] = $column;
                } elseif (str_contains($label, 'koğuş') || str_contains($label, 'oda')) {
                    $headers['kogus'] = $column;
                } elseif (str_contains($label, 'kapasite')) {
                    $headers['kapasite'] = $column;
                } elseif (str_contains($label, 'kayıtlı')) {
                    $headers['kayitli'] = $column;
                } elseif (str_contains($label, 'gayri') || str_contains($label, 'gayrı')) {
                    $headers['gayri_mevcut'] = $column;
                } elseif (str_contains($label, 'olması gereken')) {
                    $headers['olmasi_gereken'] = $column;
                } elseif (str_contains($label, 'hazır')) {
                    $headers['hazir'] = $column;
                }
            }

            continue;
        }
        if (! isset($headers['kogus'])) {
            continue;
        }
        $room = trim((string) ($cells[$headers['kogus']] ?? ''));
        if ($room === '' || stripos($room, 'toplam') !== false) {
            continue;
        }
        $record = ['sira' => count($rows) + 1, 'blok' => trim((string) ($cells[$headers['blok'] ?? -1] ?? '')), 'koridor' => trim((string) ($cells[$headers['koridor'] ?? -1] ?? '')), 'kogus' => $room];
        foreach (array_keys($totals) as $key) {
            $record[$key] = $number($cells[$headers[$key] ?? -1] ?? 0);
        }
        if ($record['hazir'] === 0 && $record['kayitli'] > 0 && $record['olmasi_gereken'] > 0) {
            $record['hazir'] = $record['olmasi_gereken'];
        }
        foreach ($totals as $key => $_) {
            $totals[$key] += $record[$key];
        }
        $rows[] = $record;
    }
    usort($rows, static function (array $left, array $right): int {
        $rank = static function (array $row): array {
            $block = mb_strtoupper($row['blok'], 'UTF-8');
            $group = str_contains($block, 'A BLOK') ? 1 : (str_contains($block, 'B BLOK') ? 2 : (str_contains($block, 'C BLOK') ? 3 : (str_contains($block, 'GEÇİCİ') ? 4 : (str_contains($block, 'ENGELLİ') ? 5 : 6))));
            preg_match('/(\d+)/', $row['kogus'], $match);

            return [$group, isset($match[1]) ? (int) $match[1] : 999, $row['kogus']];
        };

        return $rank($left) <=> $rank($right);
    });
    foreach ($rows as $index => &$row) {
        $row['sira'] = $index + 1;
    }
    unset($row);

    return $rows ? ['success' => true, 'meta' => $meta, 'rows' => $rows, 'toplam' => $totals, 'row_count' => count($rows)] : ['success' => false, 'error' => 'Excel dosyasında geçerli UYAP sayım tablosu bulunamadı.'];
}

function sayim_parse_native(string $path): ?array
{
    if (! is_file($path) || filesize($path) > 20971520) {
        return null;
    }
    $signature = file_get_contents($path, false, null, 0, 8);
    if ($signature === false) {
        return null;
    }
    $grid = null;
    if (str_starts_with($signature, "\xd0\xcf\x11\xe0")) {
        $grid = sayim_xls_grid((string) file_get_contents($path));
    } elseif (str_starts_with($signature, 'PK')) {
        $grid = sayim_xlsx_grid($path);
    }

    return $grid ? sayim_grid_result($grid) : null;
}

function parse_uyap_html_fallback($file_path): ?array
{
    $content = @file_get_contents($file_path);
    if (! $content) {
        return null;
    }

    // HTML table kontrolü
    if (stripos($content, '<table') === false || stripos($content, '<tr') === false) {
        return null;
    }

    $dom = new \DOMDocument;
    libxml_use_internal_errors(true);
    // UTF-8 veya Windows-1254 karakter kodlaması desteği
    if (stripos($content, 'charset=') === false) {
        $content = '<?xml encoding="UTF-8">'.$content;
    }
    @$dom->loadHTML($content);
    libxml_clear_errors();

    $rows = [];
    $tableRows = $dom->getElementsByTagName('tr');
    /** @var array<string, int> $header_map */
    $header_map = [];

    foreach ($tableRows as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $node) {
            if ($node->nodeName === 'td' || $node->nodeName === 'th') {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            }
        }
        if (empty($cells)) {
            continue;
        }

        // Başlık satırı tespiti
        if (empty($header_map)) {
            $line_str = implode(' ', $cells);
            if (stripos($line_str, 'blok') !== false && (stripos($line_str, 'oda') !== false || stripos($line_str, 'koğuş') !== false || stripos($line_str, 'kogus') !== false)) {
                foreach ($cells as $idx => $header_text) {
                    $norm = mb_strtolower($header_text, 'UTF-8');
                    if (strpos($norm, 'sıra') !== false || strpos($norm, 'sira') !== false) {
                        $header_map['sira'] = $idx;
                    } elseif (strpos($norm, 'blok') !== false) {
                        $header_map['blok'] = $idx;
                    } elseif (strpos($norm, 'koridor') !== false) {
                        $header_map['koridor'] = $idx;
                    } elseif (strpos($norm, 'oda') !== false || strpos($norm, 'koğuş') !== false || strpos($norm, 'kogus') !== false) {
                        $header_map['kogus'] = $idx;
                    } elseif (strpos($norm, 'kapasite') !== false) {
                        $header_map['kapasite'] = $idx;
                    } elseif (strpos($norm, 'kayıtlı') !== false || strpos($norm, 'kayitli') !== false) {
                        $header_map['kayitli'] = $idx;
                    } elseif (strpos($norm, 'gayri') !== false) {
                        $header_map['gayri_mevcut'] = $idx;
                    } elseif (strpos($norm, 'olması') !== false || strpos($norm, 'olmasi') !== false) {
                        $header_map['olmasi_gereken'] = $idx;
                    } elseif (strpos($norm, 'hazır') !== false || strpos($norm, 'hazir') !== false) {
                        $header_map['hazir'] = $idx;
                    }
                }

                continue;
            }
        }

        if (! empty($header_map) && isset($header_map['kogus'])) {
            $kogus = $cells[$header_map['kogus']] ?? '';
            if (empty($kogus) || stripos($kogus, 'toplam') !== false) {
                continue;
            }

            $blok = isset($header_map['blok']) ? ($cells[$header_map['blok']] ?? '') : '';
            $koridor = isset($header_map['koridor']) ? ($cells[$header_map['koridor']] ?? '') : '';
            $kapasite = isset($header_map['kapasite']) ? (int) preg_replace('/[^\d]/', '', $cells[$header_map['kapasite']] ?? '0') : 0;
            $kayitli = isset($header_map['kayitli']) ? (int) preg_replace('/[^\d]/', '', $cells[$header_map['kayitli']] ?? '0') : 0;
            $gayri = isset($header_map['gayri_mevcut']) ? (int) preg_replace('/[^\d]/', '', $cells[$header_map['gayri_mevcut']] ?? '0') : 0;
            $olmasi = isset($header_map['olmasi_gereken']) ? (int) preg_replace('/[^\d]/', '', $cells[$header_map['olmasi_gereken']] ?? '0') : 0;
            $hazir = isset($header_map['hazir']) ? (int) preg_replace('/[^\d]/', '', $cells[$header_map['hazir']] ?? '0') : 0;

            if ($hazir === 0 && $kayitli > 0) {
                $hazir = $olmasi > 0 ? $olmasi : ($kayitli - $gayri);
            }

            $rows[] = [
                'sira' => count($rows) + 1,
                'blok' => $blok,
                'koridor' => $koridor,
                'kogus' => $kogus,
                'kapasite' => $kapasite,
                'kayitli' => $kayitli,
                'gayri_mevcut' => $gayri,
                'olmasi_gereken' => $olmasi,
                'hazir' => $hazir,
            ];
        }
    }

    if (! empty($rows)) {
        $tot_kap = 0;
        $tot_kay = 0;
        $tot_gay = 0;
        $tot_olm = 0;
        $tot_haz = 0;
        foreach ($rows as $r) {
            $tot_kap += $r['kapasite'];
            $tot_kay += $r['kayitli'];
            $tot_gay += $r['gayri_mevcut'];
            $tot_olm += $r['olmasi_gereken'];
            $tot_haz += $r['hazir'];
        }

        return [
            'success' => true,
            'rows' => $rows,
            'toplam' => [
                'kapasite' => $tot_kap,
                'kayitli' => $tot_kay,
                'gayri_mevcut' => $tot_gay,
                'olmasi_gereken' => $tot_olm,
                'hazir' => $tot_haz,
            ],
            'row_count' => count($rows),
        ];
    }

    return null;
}

function execute_uyap_parser(string $file_path): array
{
    $native = sayim_parse_native($file_path);
    if ($native !== null) {
        return $native;
    }
    $html = parse_uyap_html_fallback($file_path);
    if ($html !== null) {
        return $html;
    }

    return ['success' => false, 'error' => 'Dosya okunamadı. Geçerli bir UYAP .xls veya .xlsx dosyası seçin.'];
}

function sayim_norm_key($blok, $kogus): string
{
    $str = mb_strtoupper(trim($blok.' '.$kogus), 'UTF-8');
    if (strpos($str, 'HASTANE') !== false) {
        return 'HASTANE';
    }
    if (strpos($str, 'İZOLASYON') !== false || strpos($str, 'IZOLASYON') !== false) {
        return 'İZOLASYON';
    }
    if (strpos($str, 'HAFTA') !== false || strpos($str, 'İNFAZ') !== false || strpos($str, 'INFAZ') !== false) {
        return 'HAFTA-SONU';
    }
    if (strpos($str, 'ENGELLİ') !== false || strpos($str, 'ENGELLI') !== false) {
        if (strpos($str, '2') !== false) {
            return 'ENGELLİ-2';
        }

        return 'ENGELLİ-1';
    }
    if (preg_match('/\bA\b.*?\b(\d+)\b/u', $str, $m) || preg_match('/A\s*[-–]?\s*(\d+)/u', $str, $m)) {
        return 'A-'.(int) $m[1];
    }
    if (preg_match('/\bB\b.*?\b(\d+)\b/u', $str, $m) || preg_match('/B\s*[-–]?\s*(\d+)/u', $str, $m)) {
        return 'B-'.(int) $m[1];
    }
    if (preg_match('/\bC\b.*?\b(\d+)\b/u', $str, $m) || preg_match('/C\s*[-–]?\s*(\d+)/u', $str, $m)) {
        return 'C-'.(int) $m[1];
    }
    if (strpos($str, 'GEÇİCİ') !== false || strpos($str, 'GECICI') !== false) {
        return 'C-GEÇİCİ';
    }

    return trim($blok.' '.$kogus);
}

function sayim_sort_priority($key): array
{
    if (preg_match('/^A-(\d+)$/', $key, $m)) {
        return [1, (int) $m[1], $key];
    }
    if (preg_match('/^B-(\d+)$/', $key, $m)) {
        return [2, (int) $m[1], $key];
    }
    if (preg_match('/^C-(\d+)$/', $key, $m)) {
        return [3, (int) $m[1], $key];
    }
    if ($key === 'C-GEÇİCİ') {
        return [4, 1, $key];
    }
    if ($key === 'HAFTA-SONU') {
        return [4, 2, $key];
    }
    if ($key === 'İZOLASYON') {
        return [4, 3, $key];
    }
    if ($key === 'ENGELLİ-1') {
        return [5, 1, $key];
    }
    if ($key === 'ENGELLİ-2') {
        return [5, 2, $key];
    }
    if ($key === 'HASTANE') {
        return [5, 3, $key];
    }

    return [6, 999, $key];
}
