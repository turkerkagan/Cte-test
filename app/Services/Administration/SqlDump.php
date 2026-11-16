<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;

final class SqlDump
{
    public function statements(string $sql): array
    {
        if (strlen($sql) > 20 * 1024 * 1024) {
            throw new \RuntimeException('SQL dosyası en fazla 20 MB olabilir.');
        }
        require_once dirname(__DIR__, 3).'/resources/recovery/sql-reader.php';
        $file = tempnam(sys_get_temp_dir(), 'cte-sql-');
        file_put_contents($file, $sql."\n;");
        $result = [];
        try {
            cte_sql_dokum_komutlari($file, function ($statement) use (&$result) {
                if (count($result) > 20000) {
                    throw new \RuntimeException('En fazla 20000 SQL komutu desteklenir.');
                }$result[] = $statement;
            });
        } finally {
            unlink($file);
        }

        return $result;
    }

    public function split(string $text): array
    {
        $result = [];
        $token = '';
        $quote = '';
        $depth = 0;
        for ($i = 0; $i < strlen($text); $i++) {
            $c = $text[$i];
            if ($quote !== '') {
                $token .= $c;
                if ($c === '\\' && isset($text[$i + 1])) {
                    $token .= $text[++$i];
                } elseif ($c === $quote) {
                    if (($text[$i + 1] ?? '') === $quote) {
                        $token .= $text[++$i];
                    } else {
                        $quote = '';
                    }
                }
            } else {
                if (in_array($c, ["'", '"', '`'])) {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                }if ($c === ',' && $depth === 0) {
                    $result[] = trim($token);
                    $token = '';
                } else {
                    $token .= $c;
                }
            }
        }
        if ($quote !== '' || $depth !== 0) {
            throw new \RuntimeException('SQL değer grubu tamamlanmamış.');
        }if (trim($token) !== '') {
            $result[] = trim($token);
        }

        return $result;
    }

    private function value(string $value): mixed
    {
        if (strtoupper($value) === 'NULL') {
            return null;
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (preg_match('/^0x([a-f0-9]+)$/iD', $value, $m)) {
            return hex2bin($m[1]);
        }
        if (strlen($value) >= 2 && in_array($value[0], ["'", '"']) && $value[-1] === $value[0]) {
            $quote = $value[0];
            $body = substr($value, 1, -1);
            $body = str_replace($quote.$quote, $quote, $body);

            return preg_replace_callback('/\\\\(.)/s', fn ($m) => match ($m[1]) {
                'n' => "\n",'r' => "\r",'t' => "\t",'0' => "\0",'Z' => chr(26),default => $m[1]
            }, $body);
        }
        throw new \RuntimeException('SQL verisinde yalnız sabit değerler desteklenir; ifadeler ve sorgular kabul edilmez.');
    }

    public function parse(string $sql): array
    {
        $tables = [];
        $columns = [];
        $ignored = [];
        $total = 0;
        foreach ($this->statements($sql) as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\((.*)\)\s*(?:ENGINE.*)?$/isD', $statement, $m)) {
                $columns[$m[1]] = [];
                foreach ($this->split($m[2]) as $definition) {
                    if (preg_match('/^`([a-zA-Z0-9_]+)`\s|^([a-zA-Z0-9_]+)\s+(?:INT|BIGINT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|LONGTEXT|MEDIUMTEXT|DATE|DATETIME|TIMESTAMP|TIME|DECIMAL|FLOAT|DOUBLE|JSON|ENUM|BOOLEAN|BOOL)\b/i', $definition, $c)) {
                        $columns[$m[1]][] = $c[1] ?: $c[2];
                    }
                }
                $tables[$m[1]] ??= [];

                continue;
            }
            if (preg_match('/^(?:INSERT(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED|HIGH_PRIORITY))*|REPLACE)\s+INTO\s+`?([a-zA-Z0-9_]+)`?\s*(\([^)]*\))?\s+VALUES?\s*(.*)$/isD', $statement, $m)) {
                $names = ! empty($m[2]) ? array_map(fn ($n) => trim($n, " `\t\r\n"), $this->split(substr($m[2], 1, -1))) : ($columns[$m[1]] ?? []);
                if (! $names) {
                    throw new \RuntimeException('INSERT için kolon listesi veya CREATE TABLE tanımı gerekli.');
                }
                foreach ($names as $name) {
                    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                        throw new \RuntimeException('Geçersiz SQL kolon adı.');
                    }
                }
                if (count(array_unique($names)) !== count($names)) {
                    throw new \RuntimeException('Tekrarlanan SQL kolonu.');
                }
                foreach ($this->split($m[3]) as $tuple) {
                    if ($tuple[0] !== '(' || $tuple[-1] !== ')') {
                        throw new \RuntimeException('VALUES satırı geçersiz.');
                    }$values = array_map($this->value(...), $this->split(substr($tuple, 1, -1)));
                    if (count($values) !== count($names)) {
                        throw new \RuntimeException('Kolon ve değer sayısı eşleşmedi.');
                    }$tables[$m[1]][] = array_combine($names, $values);
                    if (++$total > 50000) {
                        throw new \RuntimeException('Aktarım en fazla 50000 satır destekler.');
                    }
                }

                continue;
            }
            if (preg_match('/^(?:SET\b|USE\b|CREATE\s+DATABASE\b|DROP\s+TABLE\b|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b|START\s+TRANSACTION\b|COMMIT\b|ROLLBACK\b|ALTER\s+TABLE\b)/i', $statement)) {
                $ignored[] = strtok($statement, ' ');

                continue;
            }
            throw new \RuntimeException('Dökümde desteklenmeyen SQL komutu var. Yalnız tablo tanımı ve sabit INSERT değerleri aktarılır.');
        }

        return ['tables' => $tables, 'ignored' => array_count_values($ignored)];
    }

    public function insertStatement(string $name, array $row): string
    {
        if (! preg_match('/^[a-z0-9_]+$/D', $name)) {
            throw new \RuntimeException('Tablo adı geçersiz.');
        }
        foreach (array_keys($row) as $key) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $key)) {
                throw new \RuntimeException('Kolon adı geçersiz.');
            }
        }
        $columns = implode(',', array_map(fn ($key) => '`'.$key.'`', array_keys($row)));
        $values = implode(',', array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) || is_float($value) ? (string) $value : "'".str_replace(['\\', "'", "\0", "\r", "\n"], ['\\\\', "''", '\\0', '\\r', '\\n'], (string) $value)."'"), array_values($row)));

        return 'INSERT INTO `'.DB::connection()->getTablePrefix().$name.'` ('.$columns.') VALUES ('.$values.");\n";
    }

    public function encode(array $tables): string
    {
        $sql = "-- CTE yeni sistem veri aktarımı\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n";
        foreach ($tables as $name => $rows) {
            foreach ($rows as $row) {
                $sql .= $this->insertStatement($name, $row);
            }
        }

        return $sql."COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
    }
}
