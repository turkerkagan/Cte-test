<?php

[$app,$directory] = require __DIR__.'/bootstrap.php';
use App\Models\User;
use App\Services\Workflows\CaseList;
use Illuminate\Support\Facades\DB;

DB::listen(function ($event) use ($directory) {
    if (! str_starts_with(strtolower($event->sql), 'select')) {
        return;
    }
    $statement = DB::connection()->getPdo()->prepare('EXPLAIN '.$event->sql);
    $statement->execute($event->bindings);
    file_put_contents($directory.'/explain.jsonl', json_encode(['sql' => $event->sql, 'ms' => $event->time, 'plan' => $statement->fetchAll(PDO::FETCH_ASSOC)])."\n", FILE_APPEND);
});
$actor = User::find(2);
app(CaseList::class)->page($actor, 'infaz', ['per_page' => 50]);
echo "Synthetic query plans recorded.\n";
