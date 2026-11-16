<?php

namespace App\Http\Controllers;

use App\Services\Access\PageAccess;
use App\Services\Housing\HousingConflict;
use App\Services\Housing\HousingSummary;
use App\Services\Housing\PersonPlacement;
use App\Services\Modules\ModuleExport;
use App\Services\People\HomeRecords;
use App\Services\People\HomeSummary;
use App\Services\People\PersonAccess;
use App\Services\People\PersonConflict;
use App\Services\People\PersonList;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseRecords;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PersonController extends Controller
{
    public function index(Request $request, PersonList $list): Response
    {
        try {
            $page = $list->page($request->user(), $request->only(['scope', 'q', 'tc', 'sort_field', 'sort_direction', 'page', 'per_page', 'filters', 'selections', 'selection_none']));
            $placements = app(PersonPlacement::class)->summaries(array_column($page['rows'], 'id'));
            $housingSummary = app(HousingSummary::class)->read();
        } catch (QueryException) {
            abort(503, 'Kişi listesi yüklenemedi. Kurulum durumunu kontrol edip yeniden deneyin.');
        }
        $matrix = app(PageAccess::class)->displayMatrix($request->user());
        $can = fn (string $code, string $permission = 'giris'): bool => $request->user()->is_system_admin || ($matrix[$code][$permission] ?? false);
        $riskVisible = $can('hassas_risk');
        $risks = $riskVisible ? DB::table('person_case_data as c')->leftJoin('case_option_items as o', 'o.id', '=', 'c.huk_risk')->whereIn('c.person_id', array_column($page['rows'], 'id'))->pluck('o.label', 'c.person_id')->all() : [];
        // Project only the four original public list fields; never select private notes/secrets.
        $caseRows = DB::table('person_case_data as c')
            ->leftJoin('case_option_items as w', 'w.id', '=', 'c.huk_calis')
            ->leftJoin('case_option_items as d', 'd.id', '=', 'c.huk_karar')
            ->whereIn('c.person_id', array_column($page['rows'], 'id'))
            ->get(['c.person_id', 'c.huk_saglik', 'c.huk_notlar', 'w.label as work_place', 'd.label as work_decision', 'c.revir_bulasici', 'c.infaz_satir_rengi'])->keyBy('person_id');
        $caseOptions = app(CaseRecords::class)->options();
        $colorOptions = collect($caseOptions['infaz_satir_rengi'] ?? [])->keyBy('id');
        $releaseVisible = $can('infaz') || $can('izin_rapor');
        $releases = $releaseVisible ? DB::table('person_case_data')->whereIn('person_id', array_column($page['rows'], 'id'))->get(['person_id', 'infaz_ds_tarihi', 'infaz_tahliye_tarihi'])->keyBy('person_id') : collect();
        $privateVisible = $can('hassas_ozel_not');
        $privateWritable = $can('hassas_ozel_not', 'yazma');
        $privateCounts = $privateVisible ? DB::table('person_notes')->whereIn('person_id', array_column($page['rows'], 'id'))->where('is_private', true)->selectRaw('person_id, COUNT(*) AS total')->groupBy('person_id')->pluck('total', 'person_id') : collect();
        $photoVisible = $can('ilkgorus') || $can('kimlik') || $can('fotograf');
        $photoRows = $photoVisible ? DB::table('person_photos')->whereIn('person_id', array_column($page['rows'], 'id'))->whereNotNull('path')->pluck('revision', 'person_id') : collect();
        $canWrite = $can('hukumlu_kayit', 'yazma');
        foreach ($page['rows'] as &$row) {
            if ($riskVisible) {
                $row['risk'] = $risks[$row['id']] ?? null;
            }
            $row['placement'] = $placements[$row['id']] ?? null;
            $case = $caseRows->get($row['id']);
            $row['work_place'] = $case?->work_place;
            $row['work_decision'] = $case?->work_decision;
            $row['health'] = $case?->huk_saglik;
            $row['notes'] = $case?->huk_notlar;
            $row['healthWarning'] = $case?->revir_bulasici && ! in_array(mb_strtoupper(trim($case->revir_bulasici)), ['YOK', 'HAYIR', '-']) ? $case->revir_bulasici : null;
            $row['color'] = $colorOptions->get($case?->infaz_satir_rengi)['semantic'] ?? $colorOptions->get($case?->infaz_satir_rengi)['label'] ?? null;
            if ($releaseVisible) {
                $row['release'] = ['ds' => $releases->get($row['id'])?->infaz_ds_tarihi, 'release' => $releases->get($row['id'])?->infaz_tahliye_tarihi];
            }
            if ($privateVisible) {
                $row['privateNoteCount'] = (int) ($privateCounts[$row['id']] ?? 0);
            }
            if (isset($photoRows[$row['id']])) {
                $row['photoUrl'] = route('home.photo', $row['id']).'?v='.$photoRows[$row['id']];
            }
            if ($canWrite) {
                $row['editUrl'] = route('people.edit', $row['id']);
                $row['placementUrl'] = route('placement.edit', $row['id']);
            }
        }
        unset($row);

        return $this->render($request, 'People', $this->common() + $page + ['privateWritable' => $privateWritable, 'valuesUrl' => route('home.values'), 'homeOptions' => app(HomeRecords::class)->options($request->user()), 'homeSummary' => app(HomeSummary::class)->read($request->user()), 'homeUrl' => route('home.store'), 'exportUrl' => route('home.export'), 'riskVisible' => $riskVisible, 'housingSummary' => $housingSummary, 'canWrite' => $canWrite, 'createUrl' => route('people.create')]);
    }

    public function homePhoto(Request $request, int $person): Response
    {
        app(PersonAccess::class)->authorize($request->user());
        $actor = $request->user();
        abort_unless($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'ilkgorus') || app(PageAccess::class)->allows($actor, 'kimlik') || app(PageAccess::class)->allows($actor, 'fotograf'), 403);
        $photo = DB::table('person_photos')->where('person_id', $person)->first();
        abort_if(! $photo?->path || ! Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function homeValues(Request $request): JsonResponse
    {
        return response()->json(app(PersonList::class)->values($request->user(), $request->input('field'), $request->except('field')))->header('Cache-Control', 'private, no-store');
    }

    public function homeDetail(Request $request, int $person): JsonResponse
    {
        return response()->json(app(HomeRecords::class)->detail($request->user(), $person))->header('Cache-Control', 'private, no-store');
    }

    public function homeSave(Request $request, ?int $person = null): JsonResponse
    {
        try {
            $id = app(HomeRecords::class)->save($request->user(), $request->all(), $person);
        } catch (PersonConflict|HousingConflict $error) {
            return response()->json(['message' => $error->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Kayıt kaydedilemedi. Girdileriniz korunuyor.']);
        }

        return response()->json(['id' => $id], $person === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function homeExport(Request $request): Response
    {
        $actor = $request->user();
        $risk = $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_risk');
        $fields = ['tc_identity' => 'TC Kimlik No', 'full_name' => 'Adı Soyadı', 'block' => 'Blok', 'room' => 'Koğuş', 'bed' => 'Ranza', 'work_place' => 'Çalışma Yeri', 'work_decision' => 'Çalışma Kararı', 'health' => 'Sağlık Durumu', 'registered_on' => 'Kayıt Tarihi', 'notes' => 'Notlar'];
        if ($risk) {
            $fields['risk'] = 'Değerlendirme';
        }
        $fields['status'] = 'Durum';
        $rows = DB::transaction(function () use ($actor, $request, $fields, $risk) {
            $rows = [];
            $bytes = 0;
            for ($page = 1; ; $page++) {
                $data = app(PersonList::class)->page($actor, array_replace($request->except(['page', 'per_page']), ['page' => $page, 'per_page' => 100]));
                if ($data['pagination']['total'] > 5000) {
                    throw ValidationException::withMessages(['export' => 'Çıktı en fazla 5000 kayıt içerebilir. Filtreleri daraltın.']);
                }
                $ids = array_column($data['rows'], 'id');
                $placements = app(PersonPlacement::class)->summaries($ids);
                $columns = ['c.person_id', 'c.huk_saglik as health', 'c.huk_notlar as notes', 'w.label as work_place', 'd.label as work_decision', 'c.revir_bulasici', 'c.infaz_satir_rengi'];
                $query = DB::table('person_case_data as c')->leftJoin('case_option_items as w', 'w.id', '=', 'c.huk_calis')->leftJoin('case_option_items as d', 'd.id', '=', 'c.huk_karar');
                if ($risk) {
                    $query->leftJoin('case_option_items as v', 'v.id', '=', 'c.huk_risk');
                    $columns[] = 'v.label as risk';
                }
                $cases = $query->whereIn('c.person_id', $ids)->get($columns)->keyBy('person_id');
                foreach ($data['rows'] as $person) {
                    $record = $person + ($placements[$person['id']] ?? []) + (array) $cases->get($person['id']);
                    $cells = array_map(fn ($key) => (string) ($record[$key] ?? ''), array_keys($fields));
                    $bytes += array_sum(array_map('strlen', $cells));
                    if ($bytes > 10485760) {
                        throw ValidationException::withMessages(['export' => 'Çıktı en fazla 10 MB veri içerebilir.']);
                    }
                    $rows[] = $cells;
                }
                if ($page >= $data['pagination']['last_page']) {
                    return $rows;
                }
            }
        });
        $csv = app(ModuleExport::class)->csv(['fields' => array_map(fn ($label) => ['label' => $label, 'type' => 'text'], array_values($fields)), 'rows' => $rows]);

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="kurum-kayitlari.csv"')->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, PersonAccess $access): Response
    {
        $access->authorize($request->user(), 'yazma');

        return $this->render($request, 'PersonEditor', $this->common() + ['record' => null, 'defaultRegisteredOn' => now()->toDateString(), 'submitUrl' => route('people.store')]);
    }

    public function edit(Request $request, int $person, PersonRecords $records): Response
    {
        try {
            $record = $records->runtimeDetail($request->user(), $person);
        } catch (QueryException) {
            abort(503, 'Kişi kaydı yüklenemedi. Yeniden deneyin.');
        }

        return $this->render($request, 'PersonEditor', $this->common() + ['record' => $record, 'defaultRegisteredOn' => null, 'submitUrl' => route('people.update', $person)]);
    }

    public function store(Request $request, PersonRecords $records): JsonResponse
    {
        return $this->save($request, $records);
    }

    public function update(Request $request, int $person, PersonRecords $records): JsonResponse
    {
        return $this->save($request, $records, $person);
    }

    private function save(Request $request, PersonRecords $records, ?int $person = null): JsonResponse
    {
        try {
            $id = $records->runtimeSave($request->user(), $request->all(), $person);
        } catch (PersonConflict $error) {
            return response()->json(['message' => $error->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Kişi kaydı kaydedilemedi. Girdilerinizi kontrol edip yeniden deneyin.']);
        }

        return response()->json(['id' => $id], $person === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    private function render(Request $request, string $component, array $props): Response
    {
        $response = Inertia::render($component, $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function common(): array
    {
        return ['applicationName' => config('app.name'), 'baseUrl' => route('portal'), 'logoutUrl' => route('logout')];
    }
}
