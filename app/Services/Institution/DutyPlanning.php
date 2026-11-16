<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DutyPlanning
{
    public function generate(User $actor, string $kind, array $input): array
    {
        abort_unless(in_array($kind, ['mudurnobet', 'vardiyanobet', 'infaznobet'], true), 404);
        $records = app(InstitutionRecords::class);
        $records->authorize($actor, $kind, true);
        $d = Validator::make($input, ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'fill' => ['sometimes', 'boolean']])->validate();
        $start = CarbonImmutable::parse($d['from']);
        $end = CarbonImmutable::parse($d['to']);
        if ($start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['to' => 'En fazla 366 günlük aralık seçin.']);
        }

        return $records->writeBatch($actor, $kind, function () use ($actor, $kind, $d, $start, $end, $records) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $records->authorize($actor, $kind, true, true);
            $candidates = $records->candidates($kind);
            if (! $candidates && ($kind !== 'vardiyanobet' || ($d['fill'] ?? false))) {
                throw ValidationException::withMessages(['operation' => 'Bu nöbet için uygun etkin personel bulunamadı.']);
            }
            $existing = DB::table(app(InstitutionRecords::class)->table($kind))->where('kind', $kind)->where('is_active', true)->get();
            $dates = $existing->keyBy('natural_key');
            $index = $existing->count();
            $counts = array_fill_keys(array_column($candidates, 'id'), 0);
            foreach ($existing as $r) {
                $v = json_decode($r->values, true, 64, JSON_THROW_ON_ERROR);
                if ($r->natural_key >= $d['from'] && $r->natural_key <= $d['to'] && isset($counts[$v['user_id'] ?? 0])) {
                    $counts[$v['user_id']]++;
                }
            }
            $unavailable = DB::table('duty_unavailable')->whereBetween('date', [$d['from'], $d['to']])->get()->groupBy('date');
            $created = 0;
            $skipped = [];
            for ($date = $start; $date <= $end; $date = $date->addDay()) {
                $day = $date->format('Y-m-d');
                if ($dates->has($day)) {
                    continue;
                }$user = null;
                if ($kind === 'mudurnobet') {
                    $user = $candidates[$index++ % count($candidates)];
                } elseif ($kind === 'infaznobet') {
                    $blocked = ($unavailable->get($day, collect()))->pluck('user_id')->all();
                    $eligible = array_values(array_filter($candidates, fn ($u) => ! in_array($u['id'], $blocked, true)));
                    if (! $eligible) {
                        $skipped[] = $day;

                        continue;
                    }$minimum = min(array_map(fn ($u) => $counts[$u['id']], $eligible));
                    $best = array_values(array_filter($eligible, fn ($u) => $counts[$u['id']] === $minimum));
                    $user = $best[random_int(0, count($best) - 1)];
                    $counts[$user['id']]++;
                }
                $records->save($actor, $kind, null, ['values' => ['date' => $day, 'user_id' => $user['id'] ?? null, ...($kind === 'mudurnobet' ? ['deputy' => ''] : [])]]);
                $created++;
            }
            if ($kind === 'vardiyanobet' && ($d['fill'] ?? false)) {
                foreach (DB::table(app(InstitutionRecords::class)->table($kind))->where('kind', $kind)->where('is_active', true)->orderBy('natural_key')->get() as $i => $r) {
                    $v = json_decode($r->values, true, 64, JSON_THROW_ON_ERROR);
                    if (($v['user_id'] ?? null) === null) {
                        $records->save($actor, $kind, (int) $r->id, ['revision' => (int) $r->revision, 'values' => ['user_id' => $candidates[$i % count($candidates)]['id']]]);
                    }
                }
            }
            app(AuditLog::class)->record($actor, 'duty_plan', 0, 'generated', ['kind' => $kind, 'from' => $d['from'], 'to' => $d['to'], 'created' => $created, 'unavailable_days' => $skipped]);

            return ['created' => $created, 'unavailable_days' => $skipped];
        });
    }

    public function unavailable(User $actor, array $input): void
    {
        $d = Validator::make($input, ['user_id' => ['required', 'integer', 'min:1'], 'dates' => ['required', 'array', 'list', 'min:1', 'max:366'], 'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'], 'reason' => ['required', 'string', 'max:500'], 'remove' => ['sometimes', 'boolean']])->validate();
        DB::transaction(function () use ($actor, $d) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $r = app(InstitutionRecords::class);
            $actor = $r->authorize($actor, 'infaznobet', true, true);
            if (! ($d['remove'] ?? false) && ! collect($r->candidates('infaznobet'))->contains('id', (int) $d['user_id'])) {
                throw ValidationException::withMessages(['user_id' => 'Etkin infaz personeli seçin.']);
            }foreach ($d['dates'] as $date) {
                $key = ['user_id' => (int) $d['user_id'], 'date' => $date];
                if ($d['remove'] ?? false) {
                    DB::table('duty_unavailable')->where($key)->delete();
                } else {
                    DB::table('duty_unavailable')->updateOrInsert($key, ['reason' => $d['reason']]);
                }
            }app(AuditLog::class)->record($actor, 'duty_unavailable', $d['user_id'], 'updated', ['dates' => $d['dates'], 'remove' => $d['remove'] ?? false]);
        }, 3);
    }
}
