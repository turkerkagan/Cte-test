<?php

namespace App\Http\Controllers;

use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class PersonDocumentsController extends Controller
{
    public function index(Request $r)
    {
        $kind = $r->route('document');
        $a = app(WorkflowAccess::class)->authorize($r->user(), $kind);
        $input = $r->validate(['q' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'page' => ['nullable', 'integer', 'min:1'], 'preview' => ['nullable', 'boolean'], 'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer', 'distinct', 'min:1']]);
        $q = app(CaseList::class)->query()->where('p.full_name', '<>', '');
        if (in_array($kind, ['teblig', 'bakanlik_sevk'])) {
            $q->where('s.included_in_active_list', true);
        }if ($input['q'] ?? '') {
            $q->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$input['q'].'%')->orWhere('p.tc_identity', 'like', '%'.$input['q'].'%'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
            if (! empty($input[$bound])) {
                $q->whereRaw('COALESCE(p.registered_on, p.admitted_on) '.$operator.' ?', [$input[$bound]]);
            }
        }
        $photoFilter = $r->validate(['photo' => ['nullable', 'in:all,with,without']])['photo'] ?? 'all';
        if ($kind === 'fotograf' && $photoFilter !== 'all') {
            $method = $photoFilter === 'with' ? 'whereExists' : 'whereNotExists';
            $q->$method(fn ($photos) => $photos->selectRaw('1')->from('person_photos as photo_filter')->whereColumn('photo_filter.person_id', 'p.id')->whereNotNull('photo_filter.path'));
        }
        $total = (clone $q)->count();
        $page = (int) ($input['page'] ?? 1);
        $ids = array_map('intval', $input['ids'] ?? []);
        if (($input['preview'] ?? false) && $ids) {
            $q->whereIn('p.id', $ids);
        } else {
            $q->offset(($page - 1) * 50)->limit(50);
        }$options = app(CaseRecords::class)->options();
        $q->orderBy('p.full_name')->orderBy('p.id');
        $photos = DB::table('person_photos')->whereIn('person_id', (clone $q)->pluck('p.id'))->get()->keyBy('person_id');
        $people = [];
        foreach ($q->get(app(CaseList::class)->columns()) as $row) {
            $photo = $photos->get($row->person_id);
            $reason = collect($options['infaz_gelis_nedeni'])->firstWhere('id', (int) $row->infaz_gelis_nedeni)['label'] ?? null;
            $people[] = ['id' => (int) $row->person_id, 'name' => $row->full_name, 'tc' => $row->tc_identity, 'uyap' => $row->infaz_uyap_no, 'age' => $row->huk_yas, 'reason' => $reason, 'ds' => $row->infaz_ds_tarihi, 'release' => $row->infaz_tahliye_tarihi, 'block' => $row->block, 'room' => $row->room, 'status' => $row->status, 'registered' => $row->registered_on ?: $row->admitted_on, 'photo' => $photo?->path ? route('documents.photo', ['document' => $kind, 'person' => $row->person_id]) : null, 'photoRevision' => (int) ($photo->revision ?? 0)];
            $index = count($people) - 1;
            $allowed = match ($kind) {
                'bakanlik_sevk' => ['id', 'name', 'tc', 'uyap', 'age', 'reason', 'ds', 'release'],'teblig' => ['id', 'name', 'tc', 'uyap', 'block', 'room'],default => ['id', 'name', 'tc', 'block', 'room', 'status', 'registered', 'photo', 'photoRevision']
            };
            $people[$index] = array_intersect_key($people[$index], array_flip($allowed));
        }

        return $this->noStore(Inertia::render('PersonDocuments', ['applicationName' => config('app.name'), 'title' => WorkflowAccess::PAGES[$kind][0], 'kind' => $kind, 'baseUrl' => route(WorkflowAccess::PAGES[$kind][1]), 'logoutUrl' => route('logout'), 'people' => $people, 'selected' => $ids, 'q' => $input['q'] ?? '', 'from' => $input['from'] ?? '', 'to' => $input['to'] ?? '', 'page' => $page, 'total' => $total, 'today' => now()->format('Y-m-d'), 'photoFilter' => $photoFilter, 'previewReady' => (bool) ($input['preview'] ?? false), 'canWrite' => app(WorkflowAccess::class)->canWrite($a, $kind)])->toResponse($r));
    }
}
