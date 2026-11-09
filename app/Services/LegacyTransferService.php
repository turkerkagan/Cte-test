<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\OperationRecord;
use App\Models\Role;
use App\Models\UnitRecord;
use App\Models\User;
use App\Models\Ward;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyTransferService
{
    public const EXPECTED_TABLES = ['duyuru', 'duyuru_okundu', 'infaz_nobet', 'kismi_arama', 'kullanici_durum', 'kurul_komisyon', 'mesaj_okunma', 'mudur_nobet', 'sohbet', 'tablo', 'users', 'vardiya_nobet', 'yemek_listesi'];

    public function inspect(?string $connection = null): array
    {
        $connection ??= config('database.default');
        $tables = collect(self::EXPECTED_TABLES)->map(function (string $table) use ($connection): array {
            $exists = Schema::connection($connection)->hasTable($table);

            return ['table' => $table, 'exists' => $exists, 'columns' => $exists ? Schema::connection($connection)->getColumnListing($table) : [], 'count' => $exists ? DB::connection($connection)->table($table)->count() : 0];
        })->all();

        return ['connection' => $connection, 'tables' => $tables, 'missing' => collect($tables)->where('exists', false)->pluck('table')->values()->all(), 'total_columns' => collect($tables)->sum(fn ($item) => count($item['columns']))];
    }

    public function dryRun(array $sampleRows): array
    {
        $mapped = collect($sampleRows)->map(fn ($row) => $this->mapInmate($row))->all();
        $errors = collect($mapped)->flatMap(fn ($row, $index) => collect($row['errors'])->map(fn ($error) => ['row' => $index + 1, 'error' => $error]))->values()->all();

        return ['rows' => count($sampleRows), 'valid' => collect($mapped)->where('valid', true)->count(), 'invalid' => count($errors), 'errors' => $errors, 'mapped_rows' => $mapped, 'mapped_preview' => array_slice($mapped, 0, 10)];
    }

    public function migrateRows(array $rows, int $institutionId): array
    {
        $dryRun = $this->dryRun($rows);
        if ($dryRun['invalid'] > 0) {
            return $dryRun + ['created' => 0, 'status' => 'blocked'];
        }

        $created = DB::transaction(function () use ($dryRun, $institutionId): int {
            $count = 0;
            foreach ($dryRun['mapped_rows'] as $mapped) {
                $file = InmateFile::firstOrCreate(
                    ['institution_id' => $institutionId, 'legacy_id' => $mapped['target']['legacy_id']],
                    $mapped['target'] + ['registered_at' => now()->toDateString(), 'entered_at' => now()->toDateString(), 'is_active' => true],
                );
                $this->syncHousing($file, $mapped['source'], $institutionId);
                $this->syncWork($file, $mapped['source'], $institutionId);
                $this->syncUnitRecords($file, $mapped['source']);
                $count += $file->wasRecentlyCreated ? 1 : 0;
            }

            return $count;
        });

        return $dryRun + ['created' => $created, 'status' => 'completed'];
    }

    public function compareCounts(array $source, array $target): array
    {
        $keys = array_unique(array_merge(array_keys($source), array_keys($target)));

        return collect($keys)->mapWithKeys(fn ($key) => [$key => ['source' => Arr::get($source, $key, 0), 'target' => Arr::get($target, $key, 0), 'matched' => Arr::get($source, $key, 0) === Arr::get($target, $key, 0)]])->all();
    }

    public function inspectPrefixedTables(string $prefix = 'legacy_src_'): array
    {
        $tables = collect(self::EXPECTED_TABLES)->mapWithKeys(function (string $table) use ($prefix): array {
            $source = $prefix.$table;
            $exists = Schema::hasTable($source);

            return [$table => [
                'source_table' => $source,
                'exists' => $exists,
                'columns' => $exists ? Schema::getColumnListing($source) : [],
                'count' => $exists ? DB::table($source)->count() : 0,
            ]];
        });

        return [
            'tables' => $tables->all(),
            'missing' => $tables->where('exists', false)->keys()->values()->all(),
            'total_rows' => $tables->sum('count'),
            'total_columns' => $tables->sum(fn (array $table) => count($table['columns'])),
        ];
    }

    public function migratePrefixedTables(int $institutionId, string $prefix = 'legacy_src_'): array
    {
        Institution::findOrFail($institutionId);
        $inspect = $this->inspectPrefixedTables($prefix);
        if ($inspect['missing'] !== []) {
            return $inspect + ['status' => 'blocked', 'reason' => 'Eksik kaynak tablolar var.'];
        }

        return DB::transaction(function () use ($institutionId, $prefix, $inspect): array {
            $userMap = $this->migrateLegacyUsers($institutionId, $prefix);
            $inmateReport = $this->migrateLegacyInmates($institutionId, $prefix);
            $counts = [
                'users' => DB::table($prefix.'users')->count(),
                'duyuru' => $this->migrateAnnouncements($institutionId, $prefix, $userMap),
                'duyuru_okundu' => $this->migrateAnnouncementReads($institutionId, $prefix, $userMap),
                'infaz_nobet' => $this->migrateExecutionDuties($institutionId, $prefix, $userMap),
                'kismi_arama' => $this->migratePartialSearches($institutionId, $prefix, $userMap),
                'kullanici_durum' => $this->migrateUserStatuses($prefix, $userMap),
                'kurul_komisyon' => $this->migrateCommittees($institutionId, $prefix),
                'mudur_nobet' => $this->migrateManagerDuties($institutionId, $prefix, $userMap),
                'sohbet' => $this->migrateChats($institutionId, $prefix, $userMap),
                'mesaj_okunma' => $this->migrateMessageReads($institutionId, $prefix, $userMap),
                'vardiya_nobet' => $this->migrateShiftDuties($institutionId, $prefix, $userMap),
                'yemek_listesi' => $this->migrateMeals($institutionId, $prefix),
                'tablo' => $inmateReport['resolved'],
            ];

            return $inspect + [
                'status' => 'completed',
                'migrated' => $counts,
                'inmates' => $inmateReport,
                'inmate_source_rows' => $inmateReport['source'],
                'inmate_matches' => $inmateReport['resolved'],
                'note' => 'Mevcut güncel hükümlü kayıtları korunarak legacy ID, TC ve ad eşleşmeleri doğrulandı; yalnız eksik kişiler eklendi.',
            ];
        });
    }

    private function migrateLegacyInmates(int $institutionId, string $prefix): array
    {
        $existing = InmateFile::where('institution_id', $institutionId)->get();
        $byLegacyId = $existing->filter(fn (InmateFile $file) => $file->legacy_id !== null)
            ->keyBy(fn (InmateFile $file) => (string) $file->legacy_id);
        $byIdentity = $existing->filter(fn (InmateFile $file) => filled($file->identity_number))
            ->keyBy(fn (InmateFile $file) => preg_replace('/\D/', '', (string) $file->identity_number));
        $byName = $existing->keyBy(fn (InmateFile $file) => $this->normalizeName($file->full_name));

        $matchedByLegacyId = 0;
        $matchedByIdentity = 0;
        $matchedByName = 0;
        $missing = [];

        foreach (DB::table($prefix.'tablo')->orderBy('id')->get() as $row) {
            $source = (array) $row;
            $legacyId = (string) $row->id;
            $identity = preg_replace('/\D/', '', (string) $row->huk_tc);
            $name = $this->normalizeName($row->huk_adi);

            if ($byLegacyId->has($legacyId)) {
                $matchedByLegacyId++;
            } elseif ($identity !== '' && $byIdentity->has($identity)) {
                $matchedByIdentity++;
            } elseif ($name !== '' && $byName->has($name)) {
                $matchedByName++;
            } else {
                $missing[] = $source;
            }
        }

        $migration = $this->migrateRows($missing, $institutionId);
        if ($migration['status'] !== 'completed') {
            throw new \RuntimeException('Legacy hükümlü aktarımı doğrulama hataları nedeniyle tamamlanamadı.');
        }

        $sourceCount = DB::table($prefix.'tablo')->count();
        $resolved = $matchedByLegacyId + $matchedByIdentity + $matchedByName + $migration['created'];

        return [
            'source' => $sourceCount,
            'matched_by_legacy_id' => $matchedByLegacyId,
            'matched_by_identity' => $matchedByIdentity,
            'matched_by_name' => $matchedByName,
            'created' => $migration['created'],
            'resolved' => $resolved,
            'unresolved' => $sourceCount - $resolved,
        ];
    }

    private function migrateLegacyUsers(int $institutionId, string $prefix): array
    {
        $protectedAdmin = User::where('is_protected_superadmin', true)->first();
        $map = [];
        foreach (DB::table($prefix.'users')->orderBy('id')->get() as $row) {
            $username = trim((string) $row->username);
            if ($username === '') {
                continue;
            }
            if (in_array(mb_strtolower($username), ['turker', 'türker', 'admin'], true) && $protectedAdmin) {
                $protectedAdmin->forceFill([
                    'last_login_at' => $row->last_login ?: $protectedAdmin->last_login_at,
                    'last_activity_at' => $row->last_login ?: $protectedAdmin->last_activity_at,
                ])->saveQuietly();
                $map[$username] = $protectedAdmin->id;
                $map[mb_strtolower($username)] = $protectedAdmin->id;
                $map[trim((string) $row->isim)] = $protectedAdmin->id;
                $map[mb_strtolower(trim((string) $row->isim))] = $protectedAdmin->id;

                continue;
            }

            $roleCode = $this->legacyRoleCode($row->yetki);
            $role = Role::where('institution_id', $institutionId)->where('code', $roleCode)->first()
                ?? Role::where('institution_id', $institutionId)->where('code', 'standart')->firstOrFail();
            $identity = ctype_digit($username) ? ['personnel_number' => $username] : ['login_name' => $username];
            $user = User::updateOrCreate($identity, [
                'institution_id' => $institutionId,
                'role_id' => $role->id,
                'full_name' => trim((string) ($row->isim ?: $username)),
                'email' => $row->email ?: null,
                'password' => bin2hex(random_bytes(24)),
                'is_active' => (bool) $row->is_active,
                'is_protected_superadmin' => false,
                'must_change_password' => false,
                'last_login_at' => $row->last_login,
                'last_activity_at' => $row->last_login,
                'password_changed_at' => $row->updated_at ?: $row->created_at,
            ]);
            DB::table('users')->where('id', $user->id)->update(['password' => $row->password]);
            $map[$username] = $user->id;
            $map[mb_strtolower($username)] = $user->id;
            if (trim((string) $row->isim) !== '') {
                $map[trim((string) $row->isim)] = $user->id;
                $map[mb_strtolower(trim((string) $row->isim))] = $user->id;
            }
        }

        return $map;
    }

    private function migrateAnnouncements(int $institutionId, string $prefix, array $userMap): int
    {
        foreach (DB::table($prefix.'duyuru')->get() as $row) {
            $this->operation($institutionId, 'duyurular', 'Genel duyuru', $row->baslik, 'duyuru', $row->id, [
                'Öncelik' => 'Normal',
                'Legacy oluşturan' => $row->olusturan_kullanici,
            ], $row->icerik, $row->olusturma_tarihi, ! (bool) $row->aktif, $this->legacyUserId($row->olusturan_kullanici, $userMap));
        }

        return DB::table($prefix.'duyuru')->count();
    }

    private function migrateAnnouncementReads(int $institutionId, string $prefix, array $userMap): int
    {
        $count = 0;
        foreach (DB::table($prefix.'duyuru_okundu')->get() as $row) {
            $record = OperationRecord::where('institution_id', $institutionId)->where('module_code', 'duyurular')->where('status_code', 'LEGACY:duyuru:'.$row->duyuru_id)->first();
            $userId = $this->legacyUserId($row->kullanici_adi, $userMap);
            if ($record && $userId) {
                $record->readUsers()->syncWithoutDetaching([$userId => ['read_at' => $row->okundu_tarihi]]);
            }

            $this->operation(
                $institutionId,
                'duyurular',
                'Duyuru okunma kaydı',
                'Legacy duyuru '.$row->duyuru_id.' okunma kaydı',
                'duyuru_okundu',
                $row->id,
                [
                    'Duyuru legacy ID' => (string) $row->duyuru_id,
                    'Okuyan' => $userId ?: $row->kullanici_adi,
                    'Duyuru kaydı mevcut' => $record ? 'Evet' : 'Hayır',
                ],
                null,
                $row->okundu_tarihi,
                false,
                $userId,
            );
            $count++;
        }

        return $count;
    }

    private function migrateExecutionDuties(int $institutionId, string $prefix, array $userMap): int
    {
        foreach (DB::table($prefix.'infaz_nobet')->get() as $row) {
            $person = $row->memur_adi ?? $row->personel_adi ?? '';
            $personId = $this->legacyUserId($person, $userMap);
            $this->operation($institutionId, 'nobet', 'İnfaz nöbeti', 'İnfaz nöbeti - '.$person, 'infaz_nobet', $row->id, [
                'Personel' => $personId ? (string) $personId : $person,
                'Görev' => 'İnfaz nöbetçisi',
                'Vardiya' => $row->nobet_gunu,
            ], null, $row->nobet_tarihi.' 08:00:00', false, $personId, $row->nobet_tarihi.' 17:00:00');
        }

        return DB::table($prefix.'infaz_nobet')->count();
    }

    private function migratePartialSearches(int $institutionId, string $prefix, array $userMap): int
    {
        $yearCounters = [];
        foreach (DB::table($prefix.'kismi_arama')->orderBy('arama_tarihi')->orderBy('id')->get() as $row) {
            $year = substr((string) $row->arama_tarihi, 0, 4);
            $yearCounters[$year] = ($yearCounters[$year] ?? 0) + 1;
            $this->operation($institutionId, 'kismi-arama', 'Koğuş arama', $row->blok.' / '.$row->kogus.' kısmi arama', 'kismi_arama', $row->id, [
                'Blok' => $row->blok,
                'Koğuş' => $row->kogus,
                'Arama sonucu' => 'Arama yapıldı',
                'Yıllık sıra' => (string) $yearCounters[$year],
                'Legacy kullanıcı' => $row->kullanici,
            ], null, $row->arama_tarihi.' 12:00:00', false, $this->legacyUserId($row->kullanici, $userMap));
        }

        return DB::table($prefix.'kismi_arama')->count();
    }

    private function migrateUserStatuses(string $prefix, array $userMap): int
    {
        $count = 0;
        foreach (DB::table($prefix.'kullanici_durum')->get() as $row) {
            $userId = $this->legacyUserId($row->kullanici_adi, $userMap);
            if ($userId) {
                User::where('id', $userId)->update(['last_activity_at' => $row->son_aktivite]);
                $count++;
            }
        }

        return $count;
    }

    private function migrateCommittees(int $institutionId, string $prefix): int
    {
        foreach (DB::table($prefix.'kurul_komisyon')->get() as $row) {
            $data = ['Sıra' => (string) $row->sira_no];
            foreach ((array) $row as $key => $value) {
                if (in_array($key, ['id', 'sira_no', 'created_at', 'updated_at'], true) || trim((string) $value) === '') {
                    continue;
                }
                $data[$this->committeeLabel($key)] = trim((string) $value);
            }
            $this->operation($institutionId, 'kurul-komisyon', 'Kurul', 'Kurul ve komisyon sıra '.$row->sira_no, 'kurul_komisyon', $row->id, $data, null, $row->created_at);
        }

        return DB::table($prefix.'kurul_komisyon')->count();
    }

    private function migrateChats(int $institutionId, string $prefix, array $userMap): int
    {
        foreach (DB::table($prefix.'sohbet')->get() as $row) {
            $senderId = $this->legacyUserId($row->kullanici_adi, $userMap);
            $receiverId = $this->legacyUserId($row->alici_adi, $userMap);
            $this->operation($institutionId, 'sohbet', $row->alici_adi ? 'Özel mesaj' : 'Genel mesaj', 'Legacy mesaj', 'sohbet', $row->id, [
                'Gönderen' => $senderId ? (string) $senderId : $row->kullanici_adi,
                'Alıcı' => $receiverId ? (string) $receiverId : $row->alici_adi,
                'Okundu durumu' => (bool) $row->aktif ? 'okunmadi' : 'okundu',
            ], $row->mesaj, $row->tarih, ! (bool) $row->aktif, $senderId);
        }

        return DB::table($prefix.'sohbet')->count();
    }

    private function migrateMessageReads(int $institutionId, string $prefix, array $userMap): int
    {
        $count = 0;
        foreach (DB::table($prefix.'mesaj_okunma')->get() as $row) {
            $record = OperationRecord::where('institution_id', $institutionId)->where('module_code', 'sohbet')->where('status_code', 'LEGACY:sohbet:'.$row->mesaj_id)->first();
            $reader = $this->legacyUserId($row->kullanici_adi, $userMap);
            if ($record) {
                $data = $record->data ?? [];
                $data['Okundu durumu'] = 'okundu';
                $data['Okuyan'] = $reader ?: $row->kullanici_adi;
                $data['Okunma tarihi'] = $row->okunma_tarihi;
                $record->update(['data' => $data]);
            }

            $this->operation(
                $institutionId,
                'sohbet',
                'Mesaj okunma kaydı',
                'Legacy mesaj '.$row->mesaj_id.' okunma kaydı',
                'mesaj_okunma',
                $row->id,
                [
                    'Mesaj legacy ID' => (string) $row->mesaj_id,
                    'Okuyan' => $reader ?: $row->kullanici_adi,
                    'Mesaj kaydı mevcut' => $record ? 'Evet' : 'Hayır',
                ],
                null,
                $row->okunma_tarihi,
                false,
                $reader,
            );
            $count++;
        }

        return $count;
    }

    private function migrateManagerDuties(int $institutionId, string $prefix, array $userMap): int
    {
        foreach (DB::table($prefix.'mudur_nobet')->get() as $row) {
            $personId = $this->legacyUserId($row->mudur_adi, $userMap);
            $this->operation($institutionId, 'nobet', 'Müdür nöbeti', 'Müdür nöbeti - '.$row->mudur_adi, 'mudur_nobet', $row->id, [
                'Personel' => $personId ? (string) $personId : $row->mudur_adi,
                'Görev' => 'Nöbetçi müdür',
                'Vardiya' => $row->nobet_gunu,
                'Vekil' => $row->vekil_nobetci,
            ], null, $row->nobet_tarihi.' 08:00:00', false, $personId, $row->nobet_tarihi.' 17:00:00');
        }

        return DB::table($prefix.'mudur_nobet')->count();
    }

    private function migrateShiftDuties(int $institutionId, string $prefix, array $userMap): int
    {
        foreach (DB::table($prefix.'vardiya_nobet')->get() as $row) {
            $personId = $this->legacyUserId($row->basmemur_adi, $userMap);
            $this->operation($institutionId, 'nobet', 'Vardiya', 'Vardiya - '.$row->basmemur_adi, 'vardiya_nobet', $row->id, [
                'Personel' => $personId ? (string) $personId : $row->basmemur_adi,
                'Görev' => 'Vardiya başmemuru',
                'Vardiya' => 'Gündüz / Gece',
                'Gündüz tarihi' => $row->gunduz_tarih,
                'Gece tarihi' => $row->gece_tarih,
                'İzin 1' => $row->izin1_tarih,
                'İzin 2' => $row->izin2_tarih,
            ], null, $row->gunduz_tarih.' 08:00:00', false, $personId, $row->gece_tarih.' 23:59:59');
        }

        return DB::table($prefix.'vardiya_nobet')->count();
    }

    private function migrateMeals(int $institutionId, string $prefix): int
    {
        foreach (DB::table($prefix.'yemek_listesi')->get() as $row) {
            $data = ['Menü dönemi' => $row->hafta_baslangic, 'Öğün' => $row->gun_adi];
            foreach (['sabah', 'ogle', 'aksam'] as $meal) {
                $items = collect(range(1, 5))->map(fn ($index) => trim((string) $row->{$meal.'_'.$index}))->filter()->values();
                if ($items->isNotEmpty()) {
                    $data[match ($meal) {
                        'sabah' => 'Sabah',
                        'ogle' => 'Öğle',
                        default => 'Akşam',
                    }] = $items->join(' | ');
                }
            }
            $this->operation($institutionId, 'yemek', 'Haftalık menü', $row->hafta_baslangic.' '.$row->gun_adi, 'yemek_listesi', $row->id, $data, null, $row->created_at);
        }

        return DB::table($prefix.'yemek_listesi')->count();
    }

    private function operation(
        int $institutionId,
        string $module,
        string $type,
        string $title,
        string $sourceTable,
        int $sourceId,
        array $data,
        ?string $body = null,
        mixed $startsAt = null,
        bool $closed = false,
        ?int $createdBy = null,
        mixed $endsAt = null,
    ): OperationRecord {
        return OperationRecord::withTrashed()->firstOrCreate(
            ['institution_id' => $institutionId, 'module_code' => $module, 'status_code' => 'LEGACY:'.$sourceTable.':'.$sourceId],
            [
                'institution_id' => $institutionId,
                'record_type' => $type,
                'title' => mb_substr($title, 0, 255),
                'starts_at' => $startsAt ?: now(),
                'ends_at' => $endsAt,
                'is_published' => true,
                'is_closed' => $closed,
                'data' => Arr::where($data, fn ($value) => $value !== null && $value !== ''),
                'body' => $body,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
                'deleted_at' => null,
            ],
        );
    }

    private function legacyUserId(mixed $name, array $map): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        if (in_array(mb_strtolower($name), ['admin', 'turker', 'türker'], true)) {
            return User::where('is_protected_superadmin', true)->value('id');
        }

        return $map[$name] ?? $map[mb_strtolower($name)] ?? User::where('login_name', $name)->orWhere('personnel_number', $name)->orWhere('full_name', $name)->value('id');
    }

    private function normalizeName(mixed $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    private function legacyRoleCode(mixed $role): string
    {
        $role = mb_strtolower(trim((string) $role));

        return in_array($role, ['admin', 'superadmin'], true) ? 'superadmin'
            : (in_array($role, ['basmemur', 'sbasmemur', 'idari', 'infaz', 'infaz2', 'egitim', 'revir', 'ziyaret', 'manevi', 'psikososyal', 'mesem'], true) ? $role : 'standart');
    }

    private function committeeLabel(string $key): string
    {
        return str($key)->replace('_ust', ' üst unvan')->replace('_alt', ' personel')->replace('_', ' ')->title()->toString();
    }

    private function mapInmate(array $row): array
    {
        $errors = [];
        $fullName = trim((string) ($row['huk_adi'] ?? $row['full_name'] ?? ''));
        $identity = preg_replace('/\D/', '', (string) ($row['huk_tc'] ?? $row['identity_number'] ?? '')) ?: null;
        if ($fullName === '') {
            $errors[] = 'Ad soyad boş.';
        }
        if ($identity !== null && strlen($identity) !== 11) {
            $errors[] = 'TC 11 hane değil.';
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'source' => $row, 'target' => [
            'identity_number' => $identity,
            'full_name' => $fullName,
            'legacy_id' => $row['id'] ?? null,
            'registered_at' => $this->date($row['huk_kayit_tarih'] ?? null),
            'entered_at' => $this->date($row['huk_cezaevine_giris'] ?? $row['huk_kayit_tarih'] ?? null),
            'status_code' => $row['huk_durum'] ?? null,
            'risk_code' => $row['huk_risk'] ?? null,
            'decision_code' => $row['huk_karar'] ?? null,
            'health_status' => $row['huk_saglik'] ?? $row['huk_genel_saglik'] ?? null,
            'notes' => $row['huk_notlar'] ?? null,
            'is_active' => in_array($row['huk_durum'] ?? null, ['YENİ KAYIT', 'MEVCUT', 'MİSAFİR', 'HASTANE', 'ÖĞRENCİ', 'ÖZEL İZİN', 'VERİ DEĞİŞİKLİĞİ', 'aktif', 'active'], true),
        ]];
    }

    private function syncHousing(InmateFile $file, array $source, int $institutionId): void
    {
        if (! $file->is_active || $file->housingAssignments()->exists()) {
            return;
        }
        $blockName = trim((string) ($source['huk_blok'] ?? ''));
        $wardName = trim((string) ($source['huk_kogus'] ?? ''));
        if ($blockName === '' || $wardName === '') {
            return;
        }

        $block = Block::where('institution_id', $institutionId)->where('name', $blockName)->first();
        $ward = $block ? Ward::where('block_id', $block->id)->where('name', $wardName)->first() : null;
        if (! $block || ! $ward) {
            return;
        }

        $bedLabel = trim((string) ($source['huk_ranza'] ?? ''));
        $bed = $bedLabel !== '' ? Bed::where('ward_id', $ward->id)->where('label', $bedLabel)->first() : null;
        HousingAssignment::updateOrCreate(
            ['inmate_file_id' => $file->id, 'ended_at' => null],
            ['block_id' => $block->id, 'ward_id' => $ward->id, 'bed_id' => $bed?->id, 'started_at' => $file->entered_at ?? $file->registered_at ?? today()],
        );
    }

    private function syncWork(InmateFile $file, array $source, int $institutionId): void
    {
        if (! $file->is_active || $file->workAssignments()->exists()) {
            return;
        }
        $name = trim((string) ($source['huk_calis'] ?? ''));
        if ($name === '') {
            return;
        }
        $place = WorkPlace::where('institution_id', $institutionId)->where('name', $name)->first();
        if (! $place) {
            return;
        }

        WorkAssignment::updateOrCreate(
            ['inmate_file_id' => $file->id, 'ended_at' => null],
            ['work_place_id' => $place->id, 'decision_code' => $source['huk_karar'] ?? null, 'started_at' => $file->entered_at ?? $file->registered_at ?? today()],
        );
    }

    private function syncUnitRecords(InmateFile $file, array $source): void
    {
        $this->syncUnitRecord($file, 'sorumlu-basmemurluk', 'İlk görüş', 'Legacy ilk görüş', $source, [
            'Yaş' => 'huk_yas', 'Meslek' => 'huk_meslek', 'Genel sağlık' => 'huk_saglik',
            'Engellilik' => 'huk_engelli', 'Zarar verme durumu' => 'huk_zarar',
            'Önceki infaz durumu' => 'huk_infaz', 'Aile suç durumu' => 'huk_aile',
            'Bilgilendirme durumu' => 'huk_bilgi', 'Tanıdık durumu' => 'huk_tanidik',
            'Kendine zarar riski' => 'huk_kendine_zarar', 'DS işlem durumu' => 'huk_ds_durum',
        ]);
        $this->syncUnitRecord($file, 'degerlendirme', 'Davranış', 'Legacy hükümlü değerlendirmesi', $source, [
            'Personel uyumu' => 'huk_personel_uyum', 'Oda/yatak uyumu' => 'huk_oda_yatak_uyum',
            'Temizlik ve düzen' => 'huk_temizlik_duzen', 'Tasarruf' => 'huk_tasarruf',
            'Kişisel bakım' => 'huk_kisisel_bakim', 'Sağlık kurallarına uyum' => 'huk_saglik_kural',
            'Görevli tutumu' => 'huk_gorevli_tutum', 'Tutum ve davranış' => 'huk_tutum_davranis',
        ]);
        $this->syncUnitRecord($file, 'revir', 'Sağlık profili', 'Legacy revir profili', $source, [
            'Statü' => 'revir_statu', 'Genel sağlık' => 'huk_saglik', 'Kronik rahatsızlık' => 'huk_kronik',
            'Bulaşıcı hastalık' => 'revir_bulasici', 'Portör durumu' => 'huk_portor',
            'Portör tarihi 1' => 'revir_portor_tarihi_1', 'Portör tarihi 2' => 'revir_portor_tarihi_2',
            'UYAP sağlık kaydı' => 'revir_uyap', 'Çalışma durumu' => 'calisma_durum', 'Ceza tehir' => 'ceza_tehir',
        ]);
        $this->syncUnitRecord($file, 'infaz', 'UYAP', 'Legacy infaz profili', $source, [
            'UYAP No' => 'infaz_uyap_no', 'Cezaevine giriş tarihi' => 'huk_cezaevine_giris',
            'Doğum Tarihi' => 'infaz_dogum_tarihi', 'Yaş' => 'infaz_yasi', 'DS Tarihi' => 'infaz_ds_tarihi',
            'Tahliye Tarihi' => 'infaz_tahliye_tarihi', 'İnfaz durumu' => 'infaz_durumu',
            'Geliş Nedeni' => 'infaz_gelis_nedeni', 'Cinsel suç yazı tarihi' => 'infaz_cinsel_yazi_tarihi',
            'Kamu mahkemesi' => 'infaz_kamu_mahkeme',
            'Cinsel suç' => 'infaz_cinsel_suc', 'Ceza yıl' => 'infaz_ceza_yil',
            'Ceza ay' => 'infaz_ceza_ay', 'Ceza gün' => 'infaz_ceza_gun',
            'Özel kurul' => 'infaz_ozel_kurul', 'Özel kurul durumu' => 'infaz_ozel_kurul_durumu',
            'Özel kurul tarihi' => 'infaz_ozel_kurul_tarihi', 'İlave DS' => 'infaz_ilave',
            'İnfaz yılı' => 'infaz_yil', 'Satır rengi' => 'infaz_satir_rengi', 'Kurul notu' => 'infaz_not',
        ]);
        $this->syncUnitRecord($file, 'egitim', 'Profil', 'Legacy eğitim profili', $source, [
            'Statü' => 'egt_statu', 'Mezuniyet' => 'egt_mezuniyet', 'Öğrenim' => 'egt_ogrenim',
            'Okul' => 'egt_okul', 'Öğretim' => 'egt_ogretim', 'Sınıf' => 'egt_sinif',
            'Okul şifresi' => 'egt_okulsifre', 'Domain şifresi' => 'egt_domainsifre',
            'Fakülte' => 'egt_fakulte', 'Bölüm' => 'egt_bolum', 'Öğrenci No' => 'egt_ogrencino',
            'İletişim' => 'egt_iletisim',
        ]);
        $this->syncUnitRecord($file, 'kurs-hijyen-isg', 'Kurs', 'Legacy kurs ve hijyen profili', $source, [
            'Eğitim statüsü' => 'egt_statu', 'Kurs adı' => 'kurs_adi', 'Öğretmen/Eğitmen' => 'kurs_ogretmen',
            'Süre' => 'kurs_suresi', 'Başlangıç tarihi' => 'kurs_baslangic', 'Bitiş tarihi' => 'kurs_bitis',
            'Hijyen statüsü' => 'hijyen_statu', 'Hijyen eğitimi' => 'hijyen_hijyen',
            'İş güvenliği eğitimi' => 'hijyen_is',
        ]);
        $this->syncUnitRecord($file, 'manevi-rehberlik', 'Profil', 'Legacy manevi rehberlik profili', $source, [
            'Statü' => 'manevi_statu', 'Görüşme' => 'manevi_gorusme', 'Dini bilgi' => 'manevi_dini',
            'Öğrenim' => 'manevi_egitim', 'Kuran bilgisi' => 'manevi_kuran_bilgi', 'Hafızlık' => 'manevi_hafiz',
            'Talep' => 'manevi_talebi', 'Faaliyet 1' => 'manevi_faaliyet1', 'Faaliyet 2' => 'manevi_faaliyet2',
            'Faaliyet 3' => 'manevi_faaliyet3', 'Kurs' => 'manevi_kurs', 'Belge' => 'manevi_belge',
            'Ödül' => 'manevi_odul', 'Açıklama' => 'manevi_aciklama',
        ]);
        $this->syncUnitRecord($file, 'mesem', 'Profil', 'Legacy MESEM profili', $source, [
            'Statü' => 'mesem_statu', 'Görüşme' => 'mesem_gorusme', 'Durum' => 'mesem_durumu',
            'Sınıf' => 'mesem_sinif', 'Dal' => 'mesem_dali', 'Mevcut Belge' => 'mesem_mevcut_belge',
            'Belge Türü' => 'mesem_belge_turu', 'Mezuniyet' => ['mesem_mezuniyet', 'mesem_nezuniyet'],
            'Ayrıldığı Okul' => 'mesem_ayrildigi_okul', 'SGK' => 'mesem_sgk', 'Dilekçe' => 'mesem_dilekce',
            'Öğrenim Belgesi' => 'mesem_ogrenim_belgesi', 'İşe Giriş Tarihi' => 'mesem_ise_giris_tarihi',
        ]);
        $this->syncUnitRecord($file, 'izin', 'Ödül', 'Legacy izin kaydı', $source, [
            'İzin türü' => 'izin_turu', 'Gün sayısı' => 'izin_gun_sayisi', 'Çıkış' => 'izin_cikis_tarihi',
            'Çıkış Saati' => 'izin_cikis_saati', 'Dönüş' => 'izin_donus_tarihi', 'Dönüş Saati' => 'izin_donus_saati',
        ], occurredAt: $this->date($source['izin_cikis_tarihi'] ?? null), endedAt: $this->date($source['izin_donus_tarihi'] ?? null));

        foreach ([1, 2, 3] as $index) {
            $this->syncUnitRecord($file, 'ziyaret', 'Ziyaretçi', 'Legacy ziyaretçi '.$index, $source, [
                'Ziyaretçi adı' => 'ziyaret_ziyaretci'.$index,
                'Ziyaret tarihi' => 'ziyaret_tarih'.$index,
                'Ziyaret notu' => 'ziyaret_not'.$index,
            ], occurredAt: $this->date($source['ziyaret_tarih'.$index] ?? null));
        }
    }

    private function syncUnitRecord(
        InmateFile $file,
        string $module,
        string $type,
        string $title,
        array $source,
        array $mapping,
        ?string $occurredAt = null,
        ?string $endedAt = null,
    ): void {
        $data = [];
        foreach ($mapping as $label => $sourceKeys) {
            foreach ((array) $sourceKeys as $sourceKey) {
                $value = trim((string) ($source[$sourceKey] ?? ''));
                if ($value !== '' && $value !== '0000-00-00') {
                    $data[$label] = $value;
                    break;
                }
            }
        }
        if ($data === []) {
            return;
        }

        UnitRecord::firstOrCreate(
            ['inmate_file_id' => $file->id, 'module_code' => $module, 'title' => $title],
            [
                'record_type' => $type,
                'occurred_at' => $occurredAt ?? $file->registered_at ?? today(),
                'ended_at' => $endedAt,
                'status_code' => 'LEGACY',
                'is_sensitive' => in_array($module, ['revir', 'psikososyal', 'egitim', 'mesem'], true),
                'is_closed' => false,
                'data' => $data,
                'notes' => null,
            ],
        );
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
