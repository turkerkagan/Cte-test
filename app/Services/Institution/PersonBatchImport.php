<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Housing\PersonPlacement;
use App\Services\People\PersonAccess;
use App\Services\People\PersonRecords;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PersonBatchImport
{
    public function import(User $a, array $records, array $selected): array
    {
        app(WorkflowAccess::class)->authorize($a, 'uyap_karsilastirma');
        app(PersonAccess::class)->authorize($a, 'yazma');
        Validator::make(['selected' => $selected], ['selected' => ['required', 'array', 'list', 'max:500'], 'selected.*' => ['string', 'regex:/^(?:[0-9]{11}|name:[0-9]+)$/D', 'distinct']])->validate();
        $map = collect($records)->groupBy(fn ($r) => $r['key'] ?? $r['tc']);
        foreach ($selected as $tc) {
            $rows = $map->get($tc);
            abort_unless($rows && $rows->count() === 1 && trim($rows->first()['ad_soyad']) !== '', 422, 'Seçilen TC dosyada tekil, adlı bir kayıt olmalı.');
        }

        return DB::transaction(function () use ($a, $map, $selected) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = app(PersonAccess::class)->authorize($a, 'yazma', true);
            app(WorkflowAccess::class)->authorize($a, 'uyap_karsilastirma', 'giris', true);
            $created = 0;
            $skipped = 0;
            $status = DB::table('person_statuses')->where('code', 'new_record')->where('is_active', true)->value('id');
            abort_if(! $status, 422, 'Yeni kayıt durumu etkin değil.');
            foreach ($selected as $tc) {
                $row = $map->get($tc)->first();
                $identity = $row['tc'] ?? null;
                $name = mb_strtoupper(strtr(trim($row['ad_soyad']), ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
                if (($identity !== null && DB::table('people')->where('tc_identity', $identity)->exists()) || ($identity === null && DB::table('people')->where('full_name', $name)->exists())) {
                    $skipped++;

                    continue;
                }
                $id = app(PersonRecords::class)->runtimeSave($a, ['tc_identity' => $identity, 'full_name' => $name, 'admitted_on' => null, 'registered_on' => now()->format('Y-m-d')]);
                app(PersonPlacement::class)->save($a, $id, ['status_id' => $status, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);
                $decision = DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('l.code', 'huk-karar')->where('i.legacy_value', 'KARARSIZ')->where('i.is_active', true)->value('i.id');
                $riskAllowed = $a->is_system_admin || app(PageAccess::class)->allows($a, 'hassas_risk', 'yazma');
                $risk = $riskAllowed ? DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('l.code', 'huk-risk')->where('i.legacy_value', '1')->where('i.is_active', true)->value('i.id') : null;
                DB::table('person_case_data')->insert(['person_id' => $id, 'huk_karar' => $decision, 'huk_saglik' => 'SAĞLIKLI', 'huk_risk' => $risk, 'created_at' => now(), 'updated_at' => now()]);
                $created++;
            }

            return ['saved' => true, 'created' => $created, 'skipped' => $skipped];
        }, 3);
    }

    public function text(string $text): array
    {
        $lines = preg_split('/\R/u', $text);
        abort_if(count($lines) > 500, 422, 'En fazla 500 satır olabilir.');
        $records = [];
        $review = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }if (preg_match('/^([0-9]{11})[\s;,\t]+(.+)$/u', $line, $m)) {
                $records[] = ['tc' => $m[1], 'ad_soyad' => trim($m[2])];
            } elseif (! preg_match('/^[0-9]/', $line) && mb_strlen($line) <= 255) {
                $records[] = ['key' => 'name:'.($i + 1), 'tc' => null, 'ad_soyad' => $line];
                $review[] = ['line' => $i + 1, 'value' => $line, 'reason' => 'TC yok: seçimle yeni TC’siz kişi eklenebilir. Mevcut kişi ile otomatik birleştirilmez.'];
            } else {
                $review[] = ['line' => $i + 1, 'value' => $line, 'reason' => 'TC ve adı soyadı birlikte girin; yalnız adla otomatik eşleme yapılmaz.'];
            }
        }

        return ['records' => $records, 'review' => $review];
    }
}
