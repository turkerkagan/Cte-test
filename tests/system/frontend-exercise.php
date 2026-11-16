<?php

use App\Models\User;
use App\Services\Workflows\CaseRecords;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$app] = require __DIR__.'/bootstrap.php';
require __DIR__.'/server.php';

$mode = $argv[1] ?? '';
if (! in_array($mode, ['live-marker', 'restore-marker'], true)) {
    throw new RuntimeException('Only the synthetic live-refresh exercise is allowed.');
}
$actor = User::where('username', 'synthetic1')->firstOrFail();
$person = DB::table('people')->where('id', 1)->first();
if ($person?->full_name !== 'SENTETİK KİŞİ 00001') {
    throw new RuntimeException('Synthetic person guard failed.');
}
$records = app(CaseRecords::class);
$detail = $records->detail($actor, 'ilkgorus', 1);
$records->save($actor, 'ilkgorus', 1, ['revision' => $detail['revision'], 'values' => ['huk_saglik' => $mode === 'live-marker' ? 'SENTETİK CANLI YENİLEME' : 'SAĞLIKLI']]);
echo "Synthetic row updated; live DB untouched.\n";
