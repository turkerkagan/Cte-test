<?php

namespace App\Services\Operations;

use RuntimeException;

final class OperationalState
{
    public function read(): array
    {
        $path = storage_path('app/private/operations/state.json');
        if (! is_file($path)) {
            return ['backup' => null, 'job' => null, 'routes' => []];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['backup' => null, 'job' => null, 'routes' => []];
        }
        flock($handle, LOCK_SH);
        $state = json_decode(stream_get_contents($handle), true) ?: [];
        flock($handle, LOCK_UN);
        fclose($handle);

        return $state + ['backup' => null, 'job' => null, 'routes' => []];
    }

    public function update(callable $change): void
    {
        $directory = storage_path('app/private/operations');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Operational state directory unavailable.');
        }
        $handle = fopen($directory.'/state.json', 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Operational state unavailable.');
        }
        try {
            $state = json_decode(stream_get_contents($handle), true) ?: ['routes' => []];
            $state = $change($state);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($directory.'/state.json', 0600);
    }

    public function result(string $kind, bool $success): void
    {
        $this->update(function ($state) use ($kind, $success) {
            $at = now()->toIso8601String();
            $previous = $state[$kind] ?? [];
            $lastSuccess = $previous['last_success_at'] ?? (($previous['success'] ?? false) ? $previous['at'] : null);

            return array_replace($state, [$kind => [
                'at' => $at, 'success' => $success, 'last_success_at' => $success ? $at : $lastSuccess,
            ]]);
        });
    }

    public function request(string $route, int $status, float $ms, int $queries, float $queryMs): void
    {
        $this->update(function ($state) use ($route, $status, $ms, $queries, $queryMs) {
            $day = now()->format('Y-m-d');
            if (($state['day'] ?? null) !== $day) {
                $state['routes'] = [];
                $state['day'] = $day;
            }
            $row = $state['routes'][$route] ?? ['requests' => 0, 'errors' => 0, 'slow' => 0, 'max_ms' => 0, 'max_queries' => 0, 'max_query_ms' => 0];
            $row['requests']++;
            $row['errors'] += $status >= 500 ? 1 : 0;
            $row['slow'] += $ms > 500 ? 1 : 0;
            $row['max_ms'] = round(max($ms, $row['max_ms']), 2);
            $row['max_queries'] = max($queries, $row['max_queries']);
            $row['max_query_ms'] = round(max($queryMs, $row['max_query_ms']), 2);
            if (count($state['routes']) < 250 || isset($state['routes'][$route])) {
                $state['routes'][$route] = $row;
            }

            return $state;
        });
    }
}
