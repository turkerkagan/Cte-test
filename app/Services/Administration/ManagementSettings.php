<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Modules\DefinitionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class ManagementSettings
{
    public function read(string $key, array $defaults = []): array
    {
        try {
            $row = Schema::hasTable('administration_settings') ? DB::table('administration_settings')->where('key', $key)->first() : null;
        } catch (QueryException|\InvalidArgumentException) {
            $row = null;
        }

        return ['revision' => (int) ($row->revision ?? 0), 'values' => array_replace($defaults, $row ? json_decode($row->values, true, 64, JSON_THROW_ON_ERROR) : [])];
    }

    public function appearance(): array
    {
        return $this->read('appearance', ['login_badge' => 'T.C. Adalet Bakanlığı', 'login_title' => config('app.institution_name') ?: config('app.name'), 'login_accent' => '',
            'login_description' => 'Kurum verilerini tek noktadan güvenli, hızlı ve düzenli biçimde yönetin.', 'login_features' => [],
            'footer_left' => '© 2026 CTE Bilgi İşlem', 'footer_center' => config('app.institution_name') ?: config('app.name'), 'footer_version' => 'v2.6', 'footer_contact' => '']);
    }

    public function saveAppearance(User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'values' => ['required', 'array:login_badge,login_title,login_accent,login_description,login_features,footer_left,footer_center,footer_version,footer_contact'],
            'values.login_badge' => ['present', 'nullable', 'string', 'max:150'], 'values.login_title' => ['required', 'string', 'max:200'], 'values.login_accent' => ['present', 'nullable', 'string', 'max:200'],
            'values.login_description' => ['present', 'nullable', 'string', 'max:1000'], 'values.login_features' => ['present', 'array', 'list', 'max:10'], 'values.login_features.*' => ['required', 'string', 'max:200'],
            'values.footer_left' => ['present', 'nullable', 'string', 'max:200'], 'values.footer_center' => ['required', 'string', 'max:200'], 'values.footer_version' => ['present', 'nullable', 'string', 'max:50'], 'values.footer_contact' => ['present', 'nullable', 'email', 'max:150']])->validate();
        $this->save($actor, 'appearance', $data['values'], $data['revision']);
    }

    public function save(User $actor, string $key, array $values, int $revision): void
    {
        DB::transaction(function () use ($actor, $key, $values, $revision) {
            $actor = app(ManagementAccess::class)->actor($actor, true);
            $before = DB::table('administration_settings')->where('key', $key)->lockForUpdate()->first();
            if ((int) ($before->revision ?? 0) !== $revision) {
                throw new DefinitionConflict;
            }
            $old = $before ? json_decode($before->values, true, 64, JSON_THROW_ON_ERROR) : [];
            if ($old === $values) {
                return;
            }
            $write = ['values' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'revision' => $revision + 1, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($before) {
                DB::table('administration_settings')->where('key', $key)->update($write);
            } else {
                DB::table('administration_settings')->insert($write + ['key' => $key, 'created_at' => now()]);
            }
            app(AuditLog::class)->record($actor, 'administration_settings', 0, 'updated', ['key' => $key, 'revision' => $revision + 1, 'changed_fields' => array_keys($values)]);
        }, 3);
    }
}
