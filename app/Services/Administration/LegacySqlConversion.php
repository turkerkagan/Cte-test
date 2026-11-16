<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Exports\LegacyOrganizationAdapter;
use App\Services\Imports\OrganizationSnapshot;
use App\Services\Institution\InstitutionRecords;
use App\Services\Workflows\CaseFields;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Source SQL is parsed as data; it is never executed against the application. */
final class LegacySqlConversion
{
    private array $output = [];

    private array $replacements = [];

    private function rows(string $table): array
    {
        return array_merge(DB::table($table)->get()->map(fn ($r) => (array) $r)->all(), $this->output[$table] ?? []);
    }

    private function add(string $table, array $row, ?array $natural = null): int
    {
        if ($natural !== null) {
            foreach ($this->rows($table) as $existing) {
                if (collect($natural)->every(fn ($v, $k) => ($existing[$k] ?? null) == $v)) {
                    if (($existing['kind'] ?? null) === 'kurul' && isset($row['values']) && collect(json_decode($existing['values'], true))->every(fn ($v, $k) => $k === 'position' || $v === null || $v === '')) {
                        $this->output[$table][] = ['id' => $existing['id']] + $row;
                        $this->replacements[$table][] = $existing['id'];

                        return (int) $existing['id'];
                    }
                    if (! collect($row)->every(fn ($v, $k) => in_array($k, ['created_at', 'updated_at', 'created_by', 'updated_by', 'revision']) || ($existing[$k] ?? null) == $v)) {
                        throw new \RuntimeException($table.' içinde mevcut kayıtla farklı veri var. Aktarım uygulanmadı.');
                    }

                    return (int) $existing['id'];
                }
            }
        }
        $id = max(array_column($this->rows($table), 'id') ?: [0]) + 1;
        $this->output[$table][] = ['id' => $id] + $row;

        return $id;
    }

    private function date(mixed $v, bool $time = false): ?string
    {
        if (! $v || str_starts_with((string) $v, '0000-')) {
            return null;
        }$format = $time ? 'Y-m-d H:i:s' : 'Y-m-d';
        $text = substr(str_replace('T', ' ', (string) $v), 0, $time ? 19 : 10);
        if ($time && strlen($text) === 16) {
            $text .= ':00';
        }Validator::make(['date' => $text], ['date' => ['date_format:'.$format]])->validate();

        return $text;
    }

    public function convert(string $sql): array
    {
        $this->output = [];
        $this->replacements = [];
        $parsed = app(SqlDump::class)->parse($sql);
        $tables = $parsed['tables'];
        $at = now()->toDateTimeString();
        $fields = app(CaseFields::class)->all();
        $blockMap = [];
        $roomMap = [];
        $userMap = [];
        foreach ($tables['bloklar'] ?? [] as $r) {
            $blockMap[$r['id']] = $this->add('housing_blocks', ['code' => (string) $r['kod'], 'name' => $r['ad'], 'is_active' => (int) ($r['aktif'] ?? 1), 'revision' => 1, 'position' => (int) ($r['sira'] ?? 0), 'created_at' => $at, 'updated_at' => $at], ['code' => $r['kod']]);
        }
        foreach ($tables['koguslar'] ?? [] as $r) {
            $block = $blockMap[$r['blok_id']] ?? null;
            if (! $block) {
                throw new \RuntimeException('Koğuşun blok eşlemesi eksik.');
            }$room = $this->add('housing_rooms', ['block_id' => $block, 'code' => (string) $r['kod'], 'name' => $r['ad'], 'bed_count' => (int) $r['ranza_sayisi'], 'capacity' => (int) $r['kapasite'], 'kind' => in_array($r['tur'] ?? '', ['normal', 'special']) ? $r['tur'] : 'normal', 'is_active' => (int) ($r['aktif'] ?? 1), 'revision' => 1, 'position' => (int) ($r['sira'] ?? 0), 'created_at' => $at, 'updated_at' => $at], ['block_id' => $block, 'code' => $r['kod']]);
            $roomMap[$r['id']] = $room;
            for ($n = 1; $n <= min(1000, (int) $r['ranza_sayisi']); $n++) {
                $this->add('housing_beds', ['room_id' => $room, 'number' => $n, 'is_active' => 1], ['room_id' => $room, 'number' => $n]);
            }
        }
        $organization = null;
        if (isset($tables['kullanicilar']) || isset($tables['birimler']) || isset($tables['unvanlar'])) {
            $adapt = app(LegacyOrganizationAdapter::class)->adapt('sql-admin', array_replace(['birimler' => [], 'unvanlar' => [], 'kullanicilar' => []], array_intersect_key($tables, array_flip(['birimler', 'unvanlar', 'kullanicilar']))));
            $snapshot = OrganizationSnapshot::fromArray($adapt['snapshot']);
            $ids = ['departments' => [], 'job_titles' => []];
            foreach ($snapshot->tables as $table => $rows) {
                foreach ($rows as $sourceId => $r) {
                    unset($r['id']);
                    if ($table === 'users') {
                        $r['department_id'] = $r['department_id'] === null ? null : $ids['departments'][$r['department_id']];
                        $r['job_title_id'] = $r['job_title_id'] === null ? null : $ids['job_titles'][$r['job_title_id']];
                        $userMap[$sourceId] = $this->add($table, $r + ['created_at' => $at, 'updated_at' => $at], ['username' => $r['username']]);
                    } else {
                        $ids[$table][$sourceId] = $this->add($table, $r + ['created_at' => $at, 'updated_at' => $at], ['code' => $r['code']]);
                    }
                }
            }
        }
        foreach ($tables['calisma_yerleri'] ?? [] as $r) {
            $this->option('huk-calis', $r['ad'], (int) ($r['sira'] ?? 0), (int) ($r['aktif'] ?? 1), $at);
        }
        foreach ($tables['secenek_listeleri'] ?? [] as $r) {
            $code = str_replace('_', '-', $r['liste_kodu']);
            $this->option($code, $r['deger'], (int) ($r['sira'] ?? 0), (int) ($r['aktif'] ?? 1), $at);
        }
        $boardCodes = ['disiplin_kurulu' => 'discipline', 'idare_gozlem_kurulu' => 'administration', 'egitim_kurulu' => 'education', 'isyurdu_yonetim_kurulu' => 'workshop', 'arsiv_ayiklama_komisyonu' => 'archive', 'mektup_okuma_komisyonu' => 'letters', 'koli_acma_komisyonu' => 'packages', 'kitap_sayim_komisyonu' => 'books', 'muayene_kabul_genel' => 'general_acceptance', 'muayene_kabul_isyurdu' => 'workshop_acceptance'];
        $boardColumns = [];
        $headers = app(BoardDefinitions::class)->read();
        $items = $headers['values']['items'];
        foreach ($tables['kurul_komisyon_basliklari'] ?? [] as $r) {
            $code = $boardCodes[$r['kod']] ?? $r['kod'];
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $code)) {
                throw new \RuntimeException('Kurul başlık kodu geçersiz.');
            }$boardColumns[$r['ust_kolon']] = $code.'_top';
            $boardColumns[$r['alt_kolon']] = $code.'_bottom';
            $items = array_values(array_filter($items, fn ($i) => $i['code'] !== $code));
            $items[] = ['code' => $code, 'label' => $r['baslik'], 'is_active' => (bool) $r['aktif']];
        }
        foreach ($boardCodes as $old => $new) {
            $boardColumns[$old.'_ust'] ??= $new.'_top';
            $boardColumns[$old.'_alt'] ??= $new.'_bottom';
        }
        if (isset($tables['kurul_komisyon_basliklari'])) {
            $this->setting('board_headers', ['items' => $items], $at);
        }
        foreach ($tables['infaz_nobet_musait_olmayan'] ?? [] as $r) {
            $user = $userMap[$r['kullanici_id'] ?? 0] ?? collect($this->rows('users'))->firstWhere('full_name', $r['personel_adi'] ?? '')['id'] ?? null;
            if (! $user) {
                throw new \RuntimeException('Müsait olmayan nöbet personeli eşlenemedi: '.($r['personel_adi'] ?? ''));
            }$this->add('duty_unavailable', ['user_id' => $user, 'date' => $this->date($r['tarih']), 'reason' => $r['aciklama'] ?: 'Eski kayıt'], ['user_id' => $user, 'date' => $this->date($r['tarih'])]);
        }
        foreach ($tables['tablo'] ?? [] as $r) {
            $tc = ($r['huk_tc'] ?? '') !== '' ? (string) $r['huk_tc'] : null;
            $name = trim($r['huk_adi'] ?? '');
            Validator::make(['tc' => $tc, 'name' => $name], ['tc' => ['nullable', 'regex:/^[0-9]{11}$/D'], 'name' => ['required', 'string', 'max:255']])->validate();
            $id = $this->add('people', ['tc_identity' => $tc, 'full_name' => $name, 'admitted_on' => $this->date($r['huk_cezaevine_giris'] ?? null), 'registered_on' => $this->date($r['huk_kayit_tarih'] ?? null), 'revision' => 1, 'created_by' => null, 'updated_by' => null, 'created_at' => $at, 'updated_at' => $at], $tc !== null ? ['tc_identity' => $tc] : ['full_name' => $name, 'tc_identity' => null]);
            $data = ['person_id' => $id];
            foreach ($fields as $key => $field) {
                if (array_key_exists($key, $r)) {
                    $v = $r[$key];
                    if ($v === '' || $v === null) {
                        $v = null;
                    }
                    if ($v !== null && $field['type'] === 'option') {
                        $list = ($field['option_list'] ?? str_replace('_', '-', $key));
                        $listId = collect($this->rows('case_option_lists'))->firstWhere('code', $list)['id'] ?? null;
                        if (! $listId) {
                            throw new \RuntimeException('Seçenek listesi tanımlanmamış: '.$list);
                        }
                        $option = collect($this->rows('case_option_items'))->first(fn ($o) => $o['option_list_id'] == $listId && (string) ($o['legacy_value'] ?? $o['label']) === (string) $v);
                        $v = $option['id'] ?? $this->add('case_option_items', ['option_list_id' => $listId, 'label' => (string) $v, 'legacy_value' => (string) $v, 'position' => count($this->rows('case_option_items')), 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at]);
                    } elseif ($v !== null && $field['type'] === 'date') {
                        $v = $this->date($v);
                    } elseif ($v !== null && $field['type'] === 'integer') {
                        Validator::make(['v' => $v], ['v' => ['integer']])->validate();
                        $v = (int) $v;
                    }
                    if (($field['secret'] ?? false) && $v !== null) {
                        $v = Crypt::encryptString((string) $v);
                    }$data[$key] = $v;
                }
            }
            $existing = collect($this->rows('person_case_data'))->firstWhere('person_id', $id);
            if ($existing) {
                if (! collect($data)->every(fn ($v, $k) => ($existing[$k] ?? null) == $v)) {
                    throw new \RuntimeException('Kişinin mevcut birim verisi farklı. Aktarım uygulanmadı.');
                }
            } else {
                $this->add('person_case_data', $data + ['created_at' => $at, 'updated_at' => $at]);
            }
            $status = collect($this->rows('person_statuses'))->firstWhere('label', $r['huk_durum'] ?? '') ?? collect($this->rows('person_statuses'))->firstWhere('code', 'new_record');
            $this->add('person_status_assignments', ['person_id' => $id, 'status_id' => $status['id'], 'revision' => 1, 'created_at' => $at, 'updated_at' => $at], ['person_id' => $id]);
            $block = collect($this->rows('housing_blocks'))->firstWhere('code', (string) ($r['huk_blok'] ?? ''));
            $room = $block ? collect($this->rows('housing_rooms'))->first(fn ($o) => $o['block_id'] === $block['id'] && (string) $o['code'] === (string) ($r['huk_kogus'] ?? '')) : null;
            $bed = $room ? collect($this->rows('housing_beds'))->first(fn ($o) => $o['room_id'] === $room['id'] && $o['number'] == (int) ($r['huk_ranza'] ?? 0)) : null;
            if (! empty($r['huk_blok']) && (! $block || ! $room)) {
                throw new \RuntimeException('Kişinin koğuş eşlemesi bulunamadı.');
            }
            $this->add('person_locations', ['person_id' => $id, 'block_id' => $block['id'] ?? null, 'room_id' => $room['id'] ?? null, 'bed_id' => $bed['id'] ?? null, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at], ['person_id' => $id]);
            if ($bed && ($status['reserves_bed'] ?? false)) {
                $this->add('housing_occupancies', ['person_id' => $id, 'bed_id' => $bed['id'], 'created_at' => $at, 'updated_at' => $at], ['bed_id' => $bed['id']]);
            }
        }
        $mapping = ['telefon_rehberi' => 'telefon', 'kurul_komisyon' => 'kurul', 'mudur_nobet' => 'mudurnobet', 'vardiya_nobet' => 'vardiyanobet', 'infaz_nobet' => 'infaznobet', 'duyuru' => 'duyurular', 'kismi_arama' => 'kismiarama', 'kismi_arama_kayitlari' => 'kismiarama'];
        foreach ($mapping as $table => $kind) {
            foreach ($tables[$table] ?? [] as $r) {
                $values = match ($kind) {
                    'telefon' => ['name' => $r['isim'], 'phone' => $r['telefon'], 'position' => (int) ($r['sira'] ?? 0)],
                    'duyurular' => ['title' => $r['baslik'] ?? 'Duyuru', 'body' => $r['icerik'] ?? $r['mesaj'] ?? ''],
                    'vardiyanobet' => ['date' => $this->date($r['gunduz_tarih']), 'night' => $this->date($r['gece_tarih']), 'leave1' => $this->date($r['izin1_tarih']), 'leave2' => $this->date($r['izin2_tarih']), 'user_name' => $r['basmemur_adi'], 'user_id' => null],
                    'mudurnobet','infaznobet' => ['date' => $this->date($r['nobet_tarihi']), 'user_name' => $r['personel_adi_snapshot'] ?? $r['mudur_adi'] ?? $r['personel_adi'], 'user_id' => null, 'deputy' => $r['vekil_nobetci'] ?? ''],
                    'kismiarama' => ['date' => str_replace(' ', 'T', substr($this->date($r['arama_zamani'] ?? (($r['arama_tarihi'] ?? '').' 12:00:00'), true), 0, 16)), 'room_id' => null, 'room_name' => ($r['blok'] ?? '').' · '.($r['kogus'] ?? ''), 'room_block' => $r['blok'] ?? '', 'staff' => $r['gorevliler'] ?? '', 'result' => $r['sonuc'] ?? 'Eski kayıt', 'note' => $r['aciklama'] ?? '', 'created_by_name' => $r['olusturan'] ?? $r['kullanici'] ?? 'Eski kayıt'],
                    default => array_diff_key($r, array_flip(['id', 'created_at', 'updated_at']))
                };
                if ($kind === 'kurul') {
                    $values = ['position' => (int) ($r['sira_no'] ?? $r['sira'] ?? $r['id'])];
                    foreach ($boardColumns as $old => $new) {
                        if (array_key_exists($old, $r)) {
                            $values[$new] = $r[$old];
                        }
                    }
                }
                if (in_array($kind, ['mudurnobet', 'infaznobet', 'vardiyanobet'])) {
                    $values['user_id'] = $userMap[$r['kullanici_id'] ?? 0] ?? null;
                }
                $key = in_array($kind, ['mudurnobet', 'vardiyanobet', 'infaznobet']) ? $values['date'] : ($kind === 'kurul' ? (string) $values['position'] : null);
                if ($key === '1900-01-01') {
                    continue;
                }
                $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->add(app(InstitutionRecords::class)->table($kind), ['kind' => $kind, 'natural_key' => $key, 'values' => $json, 'position' => (int) ($values['position'] ?? 0), 'event_on' => isset($values['date']) ? str_replace('T', ' ', $values['date']).(strlen($values['date']) === 10 ? ' 00:00:00' : ':00') : null, 'revision' => 1, 'is_active' => 1, 'created_by' => null, 'created_at' => $at, 'updated_at' => $at], $key !== null ? ['kind' => $kind, 'natural_key' => $key] : ['kind' => $kind, 'values' => $json]);
            }
        }
        $known = ['calisma_yerleri', 'secenek_listeleri', 'kurul_komisyon_basliklari', 'infaz_nobet_musait_olmayan', 'tablo', 'bloklar', 'koguslar', 'birimler', 'unvanlar', 'kullanicilar', ...array_keys($mapping)];
        $unsupported = array_diff(array_keys(array_filter($tables, fn ($rows) => count($rows) > 0)), $known);

        return ['replacements' => $this->replacements, 'tables' => $this->output, 'organization' => $organization, 'source_counts' => array_map('count', $tables), 'counts' => array_map('count', $this->output), 'unsupported' => array_values($unsupported), 'ignored' => $parsed['ignored']];
    }

    public function preview(string $sql): array
    {
        $data = $this->convert($sql);
        $download = app(SqlDump::class)->encode($data['tables']);
        $deletes = '';
        foreach ($data['replacements'] as $table => $ids) {
            $deletes .= 'DELETE FROM `'.DB::connection()->getTablePrefix().$table.'` WHERE id IN ('.implode(',', $ids).");\n";
        }$download = str_replace("START TRANSACTION;\n", "START TRANSACTION;\n".$deletes, $download);

        return array_diff_key($data, ['tables' => true, 'organization' => true]) + ['checksum' => hash('sha256', $sql), 'target_checksum' => $this->fingerprint(), 'download_sql' => $download];
    }

    private function option(string $code, string $value, int $position, int $active, string $at): int
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/D', $code)) {
            throw new \RuntimeException('Seçenek liste kodu geçersiz.');
        }
        $list = collect($this->rows('case_option_lists'))->firstWhere('code', $code)['id'] ?? $this->add('case_option_lists', ['code' => $code, 'name' => $code, 'revision' => 1, 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at]);
        $existing = collect($this->rows('case_option_items'))->first(fn ($r) => $r['option_list_id'] === $list && ($r['legacy_value'] ?? $r['label']) === $value);

        return $existing['id'] ?? $this->add('case_option_items', ['option_list_id' => $list, 'label' => $value, 'legacy_value' => $value, 'position' => $position, 'is_active' => $active, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function setting(string $key, array $value, string $at): void
    {
        $existing = DB::table('administration_settings')->where('key', $key)->first();
        $this->output['administration_settings'][] = ['id' => $existing->id ?? max(array_column($this->rows('administration_settings'), 'id') ?: [0]) + 1, 'key' => $key, 'values' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'revision' => ($existing->revision ?? 0) + 1, 'updated_by' => null, 'created_at' => $existing->created_at ?? $at, 'updated_at' => $at];
    }

    private function fingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (app(ManagedDatabase::class)->tables() as $table) {
            if (! in_array($table, ['audit_events', 'portal_login_events', 'administration_backups', 'private_file_deletions', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) {
                hash_update($hash, $table.json_encode(Schema::getColumns($table)));
                $names = Schema::getColumnListing($table);
                foreach (DB::table($table)->orderBy(in_array('id', $names) ? 'id' : $names[0])->cursor() as $row) {
                    $r = (array) $row;
                    if ($table === 'users') {
                        unset($r['last_activity_at'],$r['last_login_at']);
                    }hash_update($hash, json_encode($r, JSON_THROW_ON_ERROR));
                }
            }
        }

        return hash_final($hash);
    }

    public function apply(User $actor, array $input): array
    {
        $data = Validator::make($input, ['sql' => ['required', 'string', 'max:20971520'], 'checksum' => ['required', 'size:64'], 'target_checksum' => ['required', 'size:64'], 'confirmed' => ['accepted']])->validate();
        abort_unless(hash_equals(hash('sha256', $data['sql']), $data['checksum']) && hash_equals($this->fingerprint(), $data['target_checksum']), 409, 'Kaynak veya hedef değişti; yeniden önizleyin.');
        $converted = $this->convert($data['sql']);
        if ($converted['unsupported']) {
            throw new \RuntimeException('Eşlemesi olmayan dolu tablolar var: '.implode(', ', $converted['unsupported']).'. Eksik aktarım yapılmadı.');
        }
        $backup = app(ManagedDatabase::class)->create($actor, 'before-sql-conversion');

        return DB::transaction(function () use ($actor, $converted, $backup) {
            $actor = app(ManagementAccess::class)->actor($actor, true);
            foreach ($converted['replacements'] as $table => $ids) {
                DB::table($table)->whereIn('id', $ids)->delete();
            }foreach ($converted['tables'] as $table => $rows) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    if ($table === 'administration_settings') {
                        foreach ($chunk as $row) {
                            DB::table($table)->updateOrInsert(['key' => $row['key']], $row);
                        }
                    } else {
                        DB::table($table)->insert($chunk);
                    }
                }
            }app(AuditLog::class)->record($actor, 'administration_conversion', 0, 'imported', ['counts' => $converted['counts'], 'backup_id' => $backup['id']]);

            return ['counts' => $converted['counts'], 'backup_id' => $backup['id']];
        }, 3);
    }
}
