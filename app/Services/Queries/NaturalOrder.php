<?php

namespace App\Services\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class NaturalOrder
{
    public static function compare(mixed $left, mixed $right): int
    {
        if ($left === null || $left === '' || $right === null || $right === '') {
            return (int) ($left === null || $left === '') <=> (int) ($right === null || $right === '');
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strnatcasecmp(mb_strtolower((string) $left, 'UTF-8'), mb_strtolower((string) $right, 'UTF-8'));
    }

    public static function rows(Collection $rows, array $keys): Collection
    {
        return $rows->sort(function ($left, $right) use ($keys) {
            foreach ($keys as $key) {
                $comparison = self::compare(data_get($left, $key), data_get($right, $key));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        })->values();
    }

    public static function numericChoices(Collection $rows): Collection
    {
        return $rows->isNotEmpty() && $rows->every(fn ($row) => is_numeric(data_get($row, 'label')))
            ? self::rows($rows, ['label']) : $rows;
    }

    // Rank the small definition catalogue in SQL before pagination/export, without loading people.
    // The expression comes only from server-owned field definitions; labels stay bound parameters.
    public static function catalogue(Builder $query, string $expression, Collection $values, string $direction = 'asc'): void
    {
        $values = $values->unique()->sort(self::compare(...))->values();
        $query->orderByRaw("$expression IS NULL");
        if ($values->isNotEmpty()) {
            $cases = implode(' ', $values->map(fn ($value, $index) => 'WHEN ? THEN '.$index)->all());
            $query->orderByRaw("CASE $expression $cases ELSE ".$values->count().' END '.($direction === 'desc' ? 'DESC' : 'ASC'), $values->all());
        }
    }
}
