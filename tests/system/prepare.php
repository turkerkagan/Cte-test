<?php

if (PHP_SAPI !== 'cli' || ! in_array('--create-disposable-fixture', $argv, true)) {
    exit(1);
}
[$app, $directory, $settings] = require __DIR__.'/bootstrap.php';
use App\Models\User;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModulePublication;
use App\Services\Security\InitialAdministrator;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$server = require __DIR__.'/server.php';
$server->exec('CREATE DATABASE `'.$settings['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Artisan::call('migrate', ['--force' => true]);
$admin = app(InitialAdministrator::class)->create('synthetic1', $settings['password'], 'SENTETİK YÖNETİCİ');
for ($i = 2; $i <= 15; $i++) {
    $u = new User;
    $u->forceFill(['username' => 'synthetic'.$i, 'full_name' => 'SENTETİK PERSONEL '.$i, 'password' => Hash::make($settings['password']), 'is_active' => true, 'is_system_admin' => false, 'must_change_password' => $i === 15])->save();
}
foreach (['hukumlu_kayit' => ['Ana sayfa', 'portal'], ...WorkflowAccess::PAGES] as $code => [$label,$route]) {
    $page = DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => $label, 'path' => parse_url(route($route), PHP_URL_PATH), 'is_active' => true, 'revision' => 1]);
    for ($i = 2; $i <= 11; $i++) {
        DB::table('page_permissions')->insert(['page_id' => $page, 'target_type' => 'kisi', 'target_id' => $i, 'user_id' => $i, 'permission' => 'yazma']);
    }
    if ($code === 'ilkgorus') {
        DB::table('page_permissions')->insert(['page_id' => $page, 'target_type' => 'kisi', 'target_id' => 13, 'user_id' => 13, 'permission' => 'yazma']);
    }
}
$status = DB::table('person_statuses')->where('code', 'present')->value('id');
DB::transaction(function () use ($status) {
    for ($start = 1; $start <= 10000; $start += 200) {
        $people = $assignments = $cases = [];
        for ($i = $start; $i < $start + 200; $i++) {
            $people[] = ['id' => $i, 'full_name' => 'SENTETİK KİŞİ '.sprintf('%05d', $i), 'tc_identity' => sprintf('%011d', $i), 'registered_on' => '2026-10-05', 'revision' => 1];
            $assignments[] = ['person_id' => $i, 'status_id' => $status, 'revision' => 1];
            $cases[] = ['person_id' => $i, 'infaz_dogum_tarihi' => '1990-01-01', 'infaz_ds_tarihi' => '2027-06-01', 'infaz_uyap_no' => '2026/'.$i, 'huk_saglik' => 'SAĞLIKLI'];
        }
        DB::table('people')->insert($people);
        DB::table('person_status_assignments')->insert($assignments);
        DB::table('person_case_data')->insert($cases);
    }
});
$designer = app(ModuleDefinitions::class);
$module = $designer->create($admin, ['code' => 'synthetic-performance', 'name' => 'SENTETİK PERFORMANS MODÜLÜ', 'record_mode' => 'standalone']);
$version = DB::table('module_versions')->where('module_id', $module)->first();
$designer->save($admin, $module, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK PERFORMANS MODÜLÜ', 'fields' => [
    ['id' => null, 'type' => 'text', 'label' => 'Sentetik başlık', 'helper' => '', 'required' => true, 'show_in_form' => true, 'show_in_table' => true],
    ['id' => null, 'type' => 'integer', 'label' => 'Sentetik sayı', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true],
]]);
$publication = app(ModulePublication::class);
$publication->publish($admin, $module, $publication->inspect($admin, $module) + ['confirmed' => true]);
$fields = DB::table('module_fields')->where('module_id', $module)->orderBy('id')->pluck('id')->all();
$page = DB::table('portal_pages')->where('module_id', $module)->first();
for ($i = 2; $i <= 11; $i++) {
    DB::table('page_permissions')->insert(['page_id' => $page->id, 'target_type' => 'kisi', 'target_id' => $i, 'user_id' => $i, 'permission' => 'yazma']);
}
DB::transaction(function () use ($module, $version, $fields) {
    for ($start = 1; $start <= 100000; $start += 250) {
        $records = $index = [];
        for ($i = $start; $i < $start + 250; $i++) {
            $title = 'SENTETİK KAYIT '.sprintf('%06d', $i);
            $records[] = ['id' => $i, 'module_id' => $module, 'version_id' => $version->id, 'values' => json_encode((object) [$fields[0] => $title, $fields[1] => $i]), 'revision' => 1];
            $index[] = ['record_id' => $i, 'module_id' => $module, 'field_id' => $fields[0], 'text_value' => $title, 'integer_value' => null];
            $index[] = ['record_id' => $i, 'module_id' => $module, 'field_id' => $fields[1], 'text_value' => null, 'integer_value' => $i];
        }
        DB::table('module_records')->insert($records);
        DB::table('module_record_index')->insert($index);
    }
});
for ($i = 1; $i <= 100; $i++) {
    DB::table('staff_nodes')->insert(['id' => $i, 'parent_id' => $i === 1 ? null : max(1, (int) floor(($i - 2) / 3) + 1), 'full_name' => 'SENTETİK KADRO '.$i, 'title' => 'Uzun sentetik görev unvanı', 'position' => $i, 'is_active' => true, 'revision' => 1]);
}
file_put_contents($directory.'/fixture.json', json_encode(['people' => 10000, 'records' => 100000, 'users' => 15, 'module' => $module, 'version' => $version->id, 'fields' => $fields]));
echo "Disposable fixture ready: 10000 people, 100000 records, 15 users.\n";
