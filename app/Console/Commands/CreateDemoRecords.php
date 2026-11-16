<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Administration\UserAdministration;
use App\Services\Housing\HousingDefinitions;
use App\Services\Housing\PersonPlacement;
use App\Services\Organization\OrganizationDefinitions;
use App\Services\Organization\StaffTree;
use App\Services\People\PersonRecords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateDemoRecords extends Command
{
    protected $signature = 'portal:demo-records {--local-test : Yalnız yerel deneme kurulumunda örnek kayıt oluştur} {--with-pages : Eksik yerleşik sayfa tanımlarını izin vermeden ekle}';

    protected $description = 'Mevcut kayıtları değiştirmeden 5 örnek hükümlü ve birim/unvan atamalı 5 personel ekler';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! $this->option('local-test') || (! app()->environment('testing') &&
            (! in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1'], true) ||
                ! str_starts_with($database, 'cte_test_local_')))) {
            $this->error('Bu komut yalnız --local-test ile yerel deneme veritabanında çalışır.');

            return self::FAILURE;
        }
        $actor = User::where('is_system_admin', true)->where('is_active', true)->first();
        if (! $actor) {
            $this->error('Önce etkin bir sistem yöneticisi bulunmalıdır.');

            return self::FAILURE;
        }
        $password = 'Deneme-'.Str::random(16).'!';
        $created = DB::transaction(function () use ($actor, $password) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $created = ['people' => 0, 'staff' => 0];
            if ($this->option('with-pages')) {
                $labels = [];
                $walk = function (array $items) use (&$walk, &$labels): void {
                    foreach ($items as $item) {
                        if (isset($item['page'])) {
                            $labels[$item['page']] = $item['label'];
                        }
                        $walk($item['children'] ?? []);
                    }
                };
                $walk(json_decode(file_get_contents(resource_path('defaults/legacy-navigation.json')), true, 512, JSON_THROW_ON_ERROR));
                $pages = ['hukumlu_kayit' => ['name' => 'Ana Sayfa', 'path' => '/portal']];
                foreach (json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true, 512, JSON_THROW_ON_ERROR) as $file => $mapping) {
                    $pages[$mapping['page']] = ['name' => $labels[$mapping['page']] ?? str_replace('_', ' ', $mapping['page']), 'legacy_file' => $file];
                }
                foreach (PageCatalog::SENSITIVE_PAGES as $code => $name) {
                    $pages[$code] = ['name' => $name];
                }
                foreach ($pages as $code => $definition) {
                    if (! DB::table('portal_pages')->where('code', $code)->exists()) {
                        app(PageCatalog::class)->save($actor, ['code' => $code] + $definition);
                    }
                }
            }
            $staff = [
                ['Elif Yılmaz (Deneme)', 'İnfaz Birimi', 'İnfaz ve Koruma Memuru'],
                ['Mehmet Kaya (Deneme)', 'Eğitim Servisi', 'Öğretmen'],
                ['Zeynep Demir (Deneme)', 'Psiko Sosyal Servisi', 'Psikolog'],
                ['Ali Şahin (Deneme)', 'Revir Birimi', 'Sağlık Memuru'],
                ['Ayşe Çelik (Deneme)', 'İdari İşler', 'Büro Personeli'],
            ];
            foreach ($staff as $index => [$name, $department, $title]) {
                $username = 'deneme.personel'.($index + 1);
                if (User::where('username', $username)->exists()) {
                    continue; // Never overwrite an account or its password on a second run.
                }
                $ids = [];
                foreach (['birimler' => ['departments', $department], 'unvanlar' => ['job_titles', $title]] as $kind => [$table, $label]) {
                    $code = 'deneme_'.($index + 1);
                    $definition = DB::table($table)->where('code', $code)->first();
                    if (! $definition) {
                        app(OrganizationDefinitions::class)->save($actor, $kind, ['code' => $code, 'name' => $label.' (Deneme)', 'is_active' => true]);
                        $definition = DB::table($table)->where('code', $code)->first();
                    }
                    $ids[$table] = $definition->id;
                }
                $user = app(UserAdministration::class)->save($actor, [
                    'username' => $username, 'registry_number' => 'DENEME-'.($index + 1), 'full_name' => $name,
                    'email' => null, 'phone' => null, 'department_id' => $ids['departments'], 'job_title_id' => $ids['job_titles'],
                    'level' => 'personnel', 'is_active' => true, 'password' => $password, 'password_confirmation' => $password,
                ]);
                app(StaffTree::class)->save($actor, null, ['parent_id' => null, 'user_id' => $user->id,
                    'full_name' => $name, 'title' => $title, 'description' => $department.' — yalnız deneme amaçlı örnek personel.',
                    'position' => $index + 1, 'is_active' => true]);
                $created['staff']++;
            }

            $names = ['Ahmet Yıldız', 'Mustafa Aydın', 'Emre Arslan', 'Hasan Koç', 'Burak Aksoy'];
            $missing = array_filter($names, fn ($name) => ! DB::table('people')->where('full_name', $name.' (Deneme)')->exists());
            if ($missing) {
                $housing = app(HousingDefinitions::class);
                $block = DB::table('housing_blocks')->where('code', 'DENEME')->value('id');
                $block ??= $housing->save($actor, 'blocks', ['code' => 'DENEME', 'name' => 'Deneme Bloğu', 'is_active' => true]);
                $room = DB::table('housing_rooms')->where('block_id', $block)->where('code', 'DENEME-1')->value('id');
                $room ??= $housing->save($actor, 'rooms', ['block_id' => $block, 'code' => 'DENEME-1', 'name' => 'Deneme Koğuşu',
                    'bed_count' => 5, 'capacity' => 5, 'kind' => 'normal', 'is_active' => true]);
                $status = DB::table('person_statuses')->where('is_active', true)->where('included_in_active_list', true)
                    ->where('counts_room', true)->where('reserves_bed', true)->orderBy('position')->value('id');
                if (! $status) {
                    throw new \RuntimeException('Aktif mevcut durum tanımı bulunamadı.');
                }
                foreach ($names as $index => $name) {
                    if (DB::table('people')->where('full_name', $name.' (Deneme)')->exists()) {
                        continue;
                    }
                    $bed = DB::table('housing_beds')->where('room_id', $room)->where('number', $index + 1)->value('id');
                    if (! $bed || DB::table('housing_occupancies')->where('bed_id', $bed)->exists()) {
                        throw new \RuntimeException('Deneme ranzası kullanılıyor; mevcut yerleşimler değiştirilmedi.');
                    }
                    $person = app(PersonRecords::class)->save($actor, ['tc_identity' => null, 'full_name' => $name.' (Deneme)',
                        'admitted_on' => now()->subDays($index + 1)->toDateString(), 'registered_on' => now()->toDateString()]);
                    app(PersonPlacement::class)->save($actor, $person, ['status_id' => $status, 'block_id' => $block,
                        'room_id' => $room, 'bed_id' => $bed, 'revision' => 0, 'status_revision' => 0]);
                    $created['people']++;
                }
            }

            return $created;
        });
        $this->info("Eklenen: {$created['people']} hükümlü, {$created['staff']} personel.");
        if ($created['staff']) {
            $this->line('Kullanıcı adları: deneme.personel1 — deneme.personel5');
            $this->line('Yeni örnek personelin geçici şifresi: '.$password);
            $this->line('İlk girişte şifre değişikliği istenir. Sayfa izinleri Erişim Politikalarından verilir.');
        }

        return self::SUCCESS;
    }
}
