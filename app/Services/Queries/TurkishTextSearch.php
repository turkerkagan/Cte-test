<?php

namespace App\Services\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TurkishTextSearch
{
    public function contains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        // Explicit Turkish case folding, identical on SQLite and MariaDB.
        $sql = DB::connection()->getQueryGrammar()->wrap($column);
        foreach (['I' => 'ı', 'İ' => 'i', 'Ğ' => 'ğ', 'Ü' => 'ü', 'Ş' => 'ş', 'Ö' => 'ö', 'Ç' => 'ç', 'Â' => 'â', 'Î' => 'î', 'Û' => 'û'] as $upper => $lower) {
            $sql = "REPLACE($sql, '$upper', '$lower')";
        }
        $needle = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $value), 'UTF-8');
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);
        $sql = DB::connection()->getDriverName() === 'mysql' ? "BINARY LOWER($sql)" : "LOWER($sql)";
        $query->whereRaw("$sql LIKE ? ESCAPE '!'", ['%'.$needle.'%'], $boolean);
    }
}
