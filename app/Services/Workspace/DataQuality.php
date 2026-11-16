<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Administration\ManagementAccess;
use App\Services\People\HomeRecords;
use App\Services\Workflows\CaseFields;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class DataQuality
{
    private function queries(): array
    {
        $duplicates = DB::table('people')->whereNotNull('tc_identity')->where('tc_identity', '<>', '')
            ->select('tc_identity')->groupBy('tc_identity')->havingRaw('COUNT(*) > 1');
        $people = fn () => DB::table('people as p');

        return [
            'duplicate_identity' => ['Aynı TC ile birden fazla kayıt', $people()->whereIn('p.tc_identity', $duplicates)],
            'missing_name' => ['Adı soyadı eksik', $people()->where(fn ($q) => $q->whereNull('p.full_name')->orWhereRaw('TRIM('.DB::connection()->getQueryGrammar()->wrap('p.full_name').') = ?', ['']))],
            'missing_identity' => ['TC bilgisi eksik', $people()->where(fn ($q) => $q->whereNull('p.tc_identity')->orWhere('p.tc_identity', ''))],
            'missing_status' => ['Durum ataması eksik', $people()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('person_status_assignments as a')->whereColumn('a.person_id', 'p.id'))],
            'missing_location' => ['Koğuş gerektiren durumda yerleşim eksik', $people()->join('person_status_assignments as a', 'a.person_id', '=', 'p.id')
                ->join('person_statuses as s', 's.id', '=', 'a.status_id')->where('s.counts_room', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('person_locations as l')->whereColumn('l.person_id', 'p.id')->whereNotNull('l.room_id'))],
        ];
    }

    public function read(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $queries = $this->queries();
        $data = Validator::make($input, ['issue' => ['nullable', 'in:'.implode(',', array_keys($queries))], 'page' => ['nullable', 'integer', 'min:1']])->validate();
        $counts = array_map(fn ($entry) => ['label' => $entry[0], 'count' => (clone $entry[1])->count()], $queries);
        $issue = $data['issue'] ?? 'duplicate_identity';
        $count = $counts[$issue]['count'];
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($count / 50)));
        $rows = $queries[$issue][1]->orderBy('p.id')->offset(($page - 1) * 50)->limit(50)
            ->get(['p.id', 'p.full_name', 'p.tc_identity'])->all();

        return ['issues' => $counts, 'issue' => $issue, 'rows' => $rows, 'page' => $page, 'pages' => max(1, (int) ceil($count / 50)), 'generatedAt' => now()->toIso8601String()];
    }

    public function provenance(User $actor, int $person): array
    {
        app(ManagementAccess::class)->actor($actor);
        $detail = app(HomeRecords::class)->detail($actor, $person);
        $events = DB::table('audit_events')->where('resource_id', $person)
            ->whereIn('resource_type', ['person', 'person_home', 'person_placement', ...array_map(fn ($group) => 'person_case_'.$group, array_keys(WorkflowAccess::PAGES))])
            ->orderByDesc('id')->limit(200)->get(['id', 'resource_type', 'created_at', 'details']);
        $fields = [];
        foreach (['full_name' => 'Adı Soyadı', 'tc_identity' => 'TC Kimlik', 'registered_on' => 'Kayıt tarihi', 'admitted_on' => 'Kuruma giriş'] as $key => $label) {
            $latest = $events->first(fn ($event) => in_array($key, json_decode($event->details, true, flags: JSON_THROW_ON_ERROR)['changed_fields'] ?? [], true));
            $fields[] = ['key' => $key, 'label' => $label, 'value' => $detail['identity'][$key], 'source' => 'people.'.$key,
                'lastVerifiedChange' => $latest?->created_at, 'eventId' => $latest?->id];
        }
        $definitions = array_filter(app(CaseFields::class)->all(), fn ($field) => ! ($field['secret'] ?? false));
        $available = array_intersect(array_keys($definitions), Schema::getColumnListing('person_case_data'));
        $case = $available ? (array) DB::table('person_case_data')->where('person_id', $person)->first($available) : [];
        foreach ($available as $key) {
            $latest = $events->first(fn ($event) => in_array($key, json_decode($event->details, true, flags: JSON_THROW_ON_ERROR)['changed_fields'] ?? [], true));
            $fields[] = ['key' => $key, 'label' => $definitions[$key]['label'], 'value' => $case[$key] ?? null,
                'source' => 'person_case_data.'.$key, 'lastVerifiedChange' => $latest?->created_at, 'eventId' => $latest?->id];
        }
        $imports = DB::table('import_mappings')->where('target_table', 'people')->where('target_id', $person)
            ->get(['source_name', 'source_table', 'source_id', 'import_version', 'updated_at'])->all();

        return ['person' => ['id' => $person, 'name' => $detail['identity']['full_name']], 'fields' => $fields, 'imports' => $imports,
            'historyLimited' => true, 'note' => 'Son 200 ilgili olay incelenir. Değişiklik kaydı olmayan alanın kaynağı veya doğruluk tarihi varsayılmaz.'];
    }
}
