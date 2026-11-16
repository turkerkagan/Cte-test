<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Institution\InstitutionRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DutyScenarios
{
    private function input(array $input): array
    {
        $data = Validator::make($input, ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'exclude' => ['sometimes', 'array', 'list', 'max:1000'], 'exclude.*' => ['integer', 'distinct', 'min:1']])->validate();
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 92) {
            throw ValidationException::withMessages(['to' => 'Senaryo karşılaştırması en fazla 93 günlük olabilir.']);
        }
        $data['exclude'] = array_map('intval', $data['exclude'] ?? []);
        sort($data['exclude']);

        return $data;
    }

    public function preview(User $actor, string $kind, array $input): array
    {
        abort_unless(in_array($kind, ['mudurnobet', 'vardiyanobet', 'infaznobet'], true), 404);
        $records = app(InstitutionRecords::class);
        $actor = $records->authorize($actor, $kind);
        $data = $this->input($input);
        $all = $records->candidates($kind);
        abort_if(array_diff($data['exclude'], array_column($all, 'id')) !== [], 422, 'Senaryodan çıkarılan personel bu nöbetin adayları arasında olmalı.');
        $candidates = array_values(array_filter($all, fn ($candidate) => ! in_array((int) $candidate['id'], $data['exclude'], true)));
        abort_if($candidates === [], 422, 'En az bir uygun personel seçilmeli.');
        $historyFrom = CarbonImmutable::parse($data['from'])->subDays(90)->toDateString();
        $existing = DB::table($records->table($kind))->where('kind', $kind)->where('is_active', true)
            ->whereBetween('natural_key', [$historyFrom, $data['to']])->orderBy('id')->get(['id', 'natural_key', 'revision', 'values'])->all();
        $unavailable = DB::table('duty_unavailable')->whereBetween('date', [$data['from'], $data['to']])
            ->whereIn('user_id', array_column($candidates, 'id'))->orderBy('date')->orderBy('user_id')->get(['user_id', 'date'])->all();
        $token = hash_hmac('sha256', json_encode([$actor->id, $kind, $data, $all, $existing, $unavailable], JSON_THROW_ON_ERROR), config('app.key'));

        return ['token' => $token, 'kind' => $kind, 'input' => $data, 'candidates' => $all,
            'scenarios' => [
                $this->simulate('balanced', $data, $candidates, $existing, $unavailable),
                $this->simulate('round_robin', $data, $candidates, $existing, $unavailable),
            ]];
    }

    private function simulate(string $strategy, array $data, array $candidates, array $existing, array $unavailable): array
    {
        $counts = $weekends = $newCounts = array_fill_keys(array_column($candidates, 'id'), 0);
        $dates = [];
        foreach ($existing as $row) {
            $values = json_decode($row->values, true, flags: JSON_THROW_ON_ERROR);
            $id = $values['user_id'] ?? null;
            if (isset($counts[$id])) {
                $counts[$id]++;
                $weekends[$id] += CarbonImmutable::parse($row->natural_key)->isWeekend() ? 1 : 0;
            }
            if ($row->natural_key >= $data['from']) {
                $dates[$row->natural_key] = true;
            }
        }
        $blocked = [];
        foreach ($unavailable as $row) {
            $blocked[$row->date][(int) $row->user_id] = true;
        }
        $cursor = 0;
        $rows = $skipped = [];
        for ($date = CarbonImmutable::parse($data['from']); $date->toDateString() <= $data['to']; $date = $date->addDay()) {
            $day = $date->toDateString();
            if (isset($dates[$day])) {
                continue;
            }
            $eligible = array_values(array_filter($candidates, fn ($candidate) => ! isset($blocked[$day][(int) $candidate['id']])));
            if ($eligible === []) {
                $skipped[] = $day;

                continue;
            }
            if ($strategy === 'balanced') {
                usort($eligible, fn ($left, $right) => [$counts[$left['id']], $date->isWeekend() ? $weekends[$left['id']] : 0, $left['id']]
                    <=> [$counts[$right['id']], $date->isWeekend() ? $weekends[$right['id']] : 0, $right['id']]);
                $user = $eligible[0];
            } else {
                do {
                    $user = $candidates[$cursor++ % count($candidates)];
                } while (isset($blocked[$day][(int) $user['id']]));
            }
            $counts[$user['id']]++;
            $newCounts[$user['id']]++;
            $weekends[$user['id']] += $date->isWeekend() ? 1 : 0;
            $rows[] = ['date' => $day, 'user_id' => (int) $user['id'], 'name' => $user['name']];
        }

        return ['strategy' => $strategy, 'label' => $strategy === 'balanced' ? '90 günlük yükü dengele' : 'Sırayla dağıt',
            'rows' => $rows, 'skipped' => $skipped, 'preserved' => count($dates),
            'spread' => max($counts) - min($counts),
            'workload' => array_map(fn ($candidate) => ['id' => $candidate['id'], 'name' => $candidate['name'], 'new' => $newCounts[$candidate['id']], 'total' => $counts[$candidate['id']], 'weekends' => $weekends[$candidate['id']]], $candidates)];
    }

    public function apply(User $actor, string $kind, array $input): array
    {
        $data = Validator::make($input, ['token' => ['required', 'string', 'size:64'], 'strategy' => ['required', 'in:balanced,round_robin'], 'confirmed' => ['accepted']])->validate();

        return DB::transaction(function () use ($actor, $kind, $input, $data) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $records = app(InstitutionRecords::class);
            $actor = $records->authorize($actor, $kind, true, true);
            $preview = $this->preview($actor, $kind, $input);
            abort_unless(hash_equals($preview['token'], $data['token']), 409, 'Adaylar, plan veya uygunluk değişti. Senaryoları yeniden hesaplayın.');
            $selected = collect($preview['scenarios'])->firstWhere('strategy', $data['strategy']);
            foreach ($selected['rows'] as $row) {
                $records->save($actor, $kind, null, ['values' => ['date' => $row['date'], 'user_id' => $row['user_id'], ...($kind === 'mudurnobet' ? ['deputy' => ''] : [])]]);
            }
            app(AuditLog::class)->record($actor, 'duty_scenario', 0, 'applied', ['kind' => $kind, 'strategy' => $data['strategy'], 'created' => count($selected['rows'])]);

            return ['created' => count($selected['rows']), 'skipped' => $selected['skipped']];
        }, 3);
    }
}
