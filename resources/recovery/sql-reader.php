<?php
declare(strict_types=1);
namespace App\Services\Administration;

/** SQL dökümünü tırnak ve yorumları gözeterek komutlara ayırır. */
function cte_sql_dokum_komutlari(string $path, callable $onStatement): void
{
    $stream = @fopen($path, 'rb');
    if (!$stream) { throw new \RuntimeException('SQL dosyası okunamadı.'); }
    $statement = '';
    $quote = '';
    $escaped = false;
    $lineComment = false;
    $blockComment = false;
    $pending = '';
    try {
        while (($chunk = fread($stream, 65536)) !== false && $chunk !== '') {
            $pending .= $chunk;
            $length = strlen($pending);
            $limit = feof($stream) ? $length : max(0, $length - 2);
            $i = 0;
            while ($i < $limit) {
                $ch = $pending[$i];
                $next = $pending[$i + 1] ?? '';
                if ($lineComment) {
                    if ($ch === "\n") { $lineComment = false; $statement .= "\n"; }
                    $i++; continue;
                }
                if ($blockComment) {
                    if ($ch === '*' && $next === '/') { $blockComment = false; $i += 2; }
                    else { $i++; }
                    continue;
                }
                if ($quote !== '') {
                    $statement .= $ch;
                    if ($escaped) { $escaped = false; }
                    elseif ($ch === '\\') { $escaped = true; }
                    elseif ($ch === $quote) {
                        if ($next === $quote) { $statement .= $next; $i++; }
                        else { $quote = ''; }
                    }
                    $i++; continue;
                }
                if (($ch === '-' && $next === '-' && preg_match('/\s/', $pending[$i + 2] ?? ' ')) || $ch === '#') {
                    $lineComment = true; $i += $ch === '#' ? 1 : 2; continue;
                }
                if ($ch === '/' && $next === '*') { $blockComment = true; $i += 2; continue; }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $statement .= $ch; $i++; continue; }
                if ($ch === ';') {
                    $clean = ltrim(trim($statement), "\xEF\xBB\xBF");
                    if ($clean !== '') { $onStatement($clean); }
                    $statement = '';
                    $i++; continue;
                }
                $statement .= $ch;
                if (strlen($statement) > 33554432) {
                    throw new \RuntimeException('Tek SQL komutu 32 MB sınırını aşıyor. Daha küçük bir döküm yükleyin.');
                }
                $i++;
            }
            $pending = substr($pending, $i);
        }
        if ($pending !== '') {
            // Son iki karakteri de aynı ayırıcı mantığıyla işle.
            $pending .= '  ';
            for ($i = 0; $i < strlen($pending) - 2; $i++) {
                $ch = $pending[$i]; $next = $pending[$i + 1];
                if ($lineComment) { if ($ch === "\n") { $lineComment = false; $statement .= "\n"; } continue; }
                if ($blockComment) { if ($ch === '*' && $next === '/') { $blockComment = false; $i++; } continue; }
                if ($quote !== '') {
                    $statement .= $ch;
                    if ($escaped) $escaped = false;
                    elseif ($ch === '\\') $escaped = true;
                    elseif ($ch === $quote) { if ($next === $quote) { $statement .= $next; $i++; } else $quote = ''; }
                    continue;
                }
                if (($ch === '-' && $next === '-' && preg_match('/\s/', $pending[$i + 2] ?? ' ')) || $ch === '#') { $lineComment = true; if ($ch === '-') $i++; continue; }
                if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $statement .= $ch; continue; }
                if ($ch === ';') { $clean = ltrim(trim($statement), "\xEF\xBB\xBF"); if ($clean !== '') $onStatement($clean); $statement = ''; continue; }
                $statement .= $ch;
            }
        }
        if ($quote !== '' || $blockComment || trim($statement) !== '') {
            throw new \RuntimeException('SQL dökümünde tamamlanmamış bir komut var.');
        }
    } finally { fclose($stream); }
}

/** SQL dizesi ve tanımlayıcısı içindeki sözcükleri güvenlik taramasından çıkarır. */
function cte_sql_dis_metin(string $sql): string
{
    $out = '';
    $quote = '';
    $escaped = false;
    for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
        $ch = $sql[$i];
        if ($quote !== '') {
            $out .= ' ';
            if ($escaped) $escaped = false;
            elseif ($ch === '\\') $escaped = true;
            elseif ($ch === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) { $out .= ' '; $i++; }
                else $quote = '';
            }
        } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch; $out .= ' ';
        } else {
            $out .= $ch;
        }
    }
    return $out;
}
