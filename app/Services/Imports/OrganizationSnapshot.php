<?php

namespace App\Services\Imports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OrganizationSnapshot
{
    public const SOURCE_TABLES = ['departments' => 'birimler', 'job_titles' => 'unvanlar', 'users' => 'kullanicilar'];

    private function __construct(public readonly string $source, public readonly array $tables) {}

    public static function fromArray(array $input): self
    {
        Validator::make($input, [
            'format_version' => ['required', 'integer', 'in:1'],
            'source' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'birimler' => ['present', 'array', 'max:10000'],
            'birimler.*' => ['array:id,kod,ad,aciklama,aktif,created_at,updated_at'],
            'birimler.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'birimler.*.kod' => ['required', 'string', 'max:50', 'distinct'],
            'birimler.*.ad' => ['required', 'string', 'max:120'],
            'birimler.*.aciklama' => ['nullable', 'string', 'max:255'],
            'birimler.*.created_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'birimler.*.updated_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'birimler.*.aktif' => ['required', 'boolean'],
            'unvanlar' => ['present', 'array', 'max:10000'],
            'unvanlar.*' => ['array:id,kod,ad,aktif,created_at,updated_at'],
            'unvanlar.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'unvanlar.*.kod' => ['required', 'string', 'max:50', 'distinct'],
            'unvanlar.*.ad' => ['required', 'string', 'max:120'],
            'unvanlar.*.created_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'unvanlar.*.updated_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'unvanlar.*.aktif' => ['required', 'boolean'],
            'kullanicilar' => ['present', 'array', 'max:10000'],
            'kullanicilar.*' => ['array:id,kullanici_adi,sicil,ad_soyad,email,telefon,sifre,birim_id,unvan_id,sistem_yonetici,aktif,sifre_degistir,oturum_surumu,hatali_giris,kilitli_baslangic,son_giris,olusturma_zamani,guncelleme_zamani'],
            'kullanicilar.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'kullanicilar.*.kullanici_adi' => ['required', 'string', 'max:100', 'distinct'],
            'kullanicilar.*.sicil' => ['nullable', 'string', 'max:50'],
            'kullanicilar.*.ad_soyad' => ['nullable', 'string', 'max:150'],
            'kullanicilar.*.email' => ['nullable', 'string', 'max:100'],
            'kullanicilar.*.telefon' => ['nullable', 'string', 'max:30'],
            'kullanicilar.*.sifre' => ['required', 'string', 'max:255'],
            'kullanicilar.*.birim_id' => ['nullable', 'integer', 'min:1'],
            'kullanicilar.*.unvan_id' => ['nullable', 'integer', 'min:1'],
            'kullanicilar.*.sistem_yonetici' => ['required', 'boolean'],
            'kullanicilar.*.aktif' => ['required', 'boolean'],
            'kullanicilar.*.sifre_degistir' => ['required', 'boolean'],
            'kullanicilar.*.olusturma_zamani' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'kullanicilar.*.guncelleme_zamani' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'kullanicilar.*.hatali_giris' => ['required', 'integer', 'min:0'],
            'kullanicilar.*.kilitli_baslangic' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'kullanicilar.*.son_giris' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'kullanicilar.*.oturum_surumu' => ['required', 'integer', 'min:0'],
        ])->validate();

        if (array_diff(array_keys($input), ['format_version', 'source', 'birimler', 'unvanlar', 'kullanicilar'])) {
            throw ValidationException::withMessages(['snapshot' => 'Bilinmeyen veri grubu.']);
        }
        foreach (['birimler', 'unvanlar', 'kullanicilar'] as $group) {
            if (! array_is_list($input[$group])) {
                throw ValidationException::withMessages([$group => 'Kayıt grubu bir liste olmalı.']);
            }
        }

        $departments = [];
        foreach ($input['birimler'] as $row) {
            $departments[(int) $row['id']] = [
                'id' => (int) $row['id'], 'code' => $row['kod'], 'name' => $row['ad'],
                'description' => $row['aciklama'] ?? null, 'is_active' => (bool) $row['aktif'], 'revision' => 1,
            ] + self::timestamps($row, 'created_at', 'updated_at');
        }
        $titles = [];
        foreach ($input['unvanlar'] as $row) {
            $titles[(int) $row['id']] = [
                'id' => (int) $row['id'], 'code' => $row['kod'], 'name' => $row['ad'],
                'is_active' => (bool) $row['aktif'], 'revision' => 1,
            ] + self::timestamps($row, 'created_at', 'updated_at');
        }
        $users = [];
        foreach ($input['kullanicilar'] as $index => $row) {
            if (! in_array(password_get_info($row['sifre'])['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true)) {
                throw ValidationException::withMessages(['kullanicilar.'.$index.'.sifre' => 'Desteklenmeyen parola özeti; kontrollü sıfırlama planı gerekir.']);
            }
            $department = isset($row['birim_id']) ? (int) $row['birim_id'] : null;
            $title = isset($row['unvan_id']) ? (int) $row['unvan_id'] : null;
            if (($department !== null && ! isset($departments[$department])) || ($title !== null && ! isset($titles[$title]))) {
                throw ValidationException::withMessages(['kullanicilar.'.$index => 'Eksik organizasyon ilişkisi.']);
            }
            $users[(int) $row['id']] = [
                'id' => (int) $row['id'], 'username' => $row['kullanici_adi'],
                'registry_number' => $row['sicil'] ?? null, 'full_name' => $row['ad_soyad'] ?? null,
                'email' => $row['email'] ?? null, 'phone' => $row['telefon'] ?? null,
                'password' => $row['sifre'], 'department_id' => $department, 'job_title_id' => $title,
                'is_system_admin' => (bool) $row['sistem_yonetici'], 'is_active' => (bool) $row['aktif'],
                'must_change_password' => (bool) $row['sifre_degistir'], 'session_version' => (int) $row['oturum_surumu'],
                'failed_login_attempts' => (int) $row['hatali_giris'], 'locked_at' => $row['kilitli_baslangic'],
                'last_login_at' => $row['son_giris'], 'revision' => 1,
            ] + self::timestamps($row, 'olusturma_zamani', 'guncelleme_zamani');
        }

        return new self($input['source'], ['departments' => $departments, 'job_titles' => $titles, 'users' => $users]);
    }

    private static function timestamps(array $row, string $createdKey, string $updatedKey): array
    {
        $result = [];
        foreach ([$createdKey => 'created_at', $updatedKey => 'updated_at'] as $source => $target) {
            if (array_key_exists($source, $row)) {
                $result[$target] = $row[$source];
            }
        }

        return $result;
    }

    public function counts(): array
    {
        return array_map('count', $this->tables);
    }
}
