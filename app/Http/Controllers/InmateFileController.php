<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditInmateFileRequest;
use App\Http\Requests\StoreInmateFileRequest;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\OperationRecord;
use App\Models\UnitRecord;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InmateFileController extends Controller
{
    /** @var array<int, string> */
    private const DETAIL_TABS = ['overview', 'identity', 'location', 'jobs', 'files', 'danger'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'status_code' => ['nullable'],
            'status_code.*' => ['string', 'max:100'],
            'risk_code' => ['nullable'],
            'risk_code.*' => ['string', 'max:100'],
            'decision_code' => ['nullable'],
            'decision_code.*' => ['string', 'max:100'],
            'block_id' => ['nullable', 'integer', 'exists:blocks,id'],
            'ward_id' => ['nullable', 'integer', 'exists:wards,id'],
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
            'work_place_id' => ['nullable', 'integer', 'exists:work_places,id'],
            'health_status' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'in:0,1'],
            'on_leave' => ['nullable', 'in:0,1'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date', 'after_or_equal:registered_from'],
            'registered_on' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:full_name_asc,full_name_desc,registered_at_desc,registered_at_asc,created_at_desc,created_at_asc'],
        ]);

        $files = InmateFile::with(['institution', 'activeHousingAssignment.block', 'activeHousingAssignment.ward', 'activeWorkAssignment.place'])
            ->withCount(['housingAssignments', 'workAssignments', 'privateFiles'])
            ->search($filters['q'] ?? null)
            ->when(isset($filters['institution_id']), fn ($query) => $query->where('institution_id', $filters['institution_id']))
            ->when(array_filter((array) ($filters['status_code'] ?? []), fn ($v) => $v !== '' && $v !== null), fn ($query, $values) => $query->whereIn('status_code', $values))
            ->when(array_filter((array) ($filters['risk_code'] ?? []), fn ($v) => $v !== '' && $v !== null), fn ($query, $values) => $query->whereIn('risk_code', $values))
            ->when(array_filter((array) ($filters['decision_code'] ?? []), fn ($v) => $v !== '' && $v !== null), fn ($query, $values) => $query->whereIn('decision_code', $values))
            ->when(isset($filters['block_id']), fn ($query) => $query->whereHas('activeHousingAssignment', fn ($housing) => $housing->where('block_id', $filters['block_id'])))
            ->when(isset($filters['ward_id']), fn ($query) => $query->whereHas('activeHousingAssignment', fn ($housing) => $housing->where('ward_id', $filters['ward_id'])))
            ->when(isset($filters['bed_id']), fn ($query) => $query->whereHas('activeHousingAssignment', fn ($housing) => $housing->where('bed_id', $filters['bed_id'])))
            ->when(isset($filters['work_place_id']), fn ($query) => $query->whereHas('activeWorkAssignment', fn ($work) => $work->where('work_place_id', $filters['work_place_id'])))
            ->when(isset($filters['health_status']), fn ($query) => $query->where('health_status', 'like', '%'.$filters['health_status'].'%'))
            ->when(isset($filters['notes']), fn ($query) => $query->where('notes', 'like', '%'.$filters['notes'].'%'))
            ->when(($filters['on_leave'] ?? null) === '1', fn ($query) => $query->where('is_active', true)->onLeave())
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['registered_from']), fn ($query) => $query->whereDate('registered_at', '>=', $filters['registered_from']))
            ->when(isset($filters['registered_to']), fn ($query) => $query->whereDate('registered_at', '<=', $filters['registered_to']));
        $files->when(isset($filters['registered_on']), fn ($query) => $query->whereDate('registered_at', $filters['registered_on']));

        $sort = $filters['sort'] ?? 'created_at_asc';
        $this->applySort($files, $sort);

        // Eski index.php gibi tüm liste tek ekranda; en son eklenen en altta.
        $files = $files->get();
        $files->load([
            'unitRecords' => fn ($query) => $query->where('module_code', 'infaz')->latest('occurred_at')->latest(),
        ]);

        return view('inmates.index', $this->lookupData() + [
            'files' => $files,
            'sort' => $sort,
            'summary' => $this->summary(),
            'emptyRooms' => $this->emptyRooms(),
            'todayLeaveLists' => $this->todayLeaveLists(),
            'isHome' => $request->routeIs('dashboard'),
        ]);
    }

    public function create(): View
    {
        return view('inmates.create', $this->lookupData());
    }

    public function store(StoreInmateFileRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $file = DB::transaction(function () use ($validated): InmateFile {
            $file = InmateFile::create($this->fileData($validated));
            $startedAt = $validated['entered_at'] ?? $validated['registered_at'] ?? now()->toDateString();

            if (! empty($validated['block_id']) && ! empty($validated['ward_id'])) {
                HousingAssignment::create([
                    'inmate_file_id' => $file->id,
                    'block_id' => $validated['block_id'],
                    'ward_id' => $validated['ward_id'],
                    'bed_id' => $validated['bed_id'] ?? null,
                    'started_at' => $startedAt,
                    'created_by' => auth()->id(),
                ]);
            }

            if (! empty($validated['work_place_id'])) {
                WorkAssignment::create([
                    'inmate_file_id' => $file->id,
                    'work_place_id' => $validated['work_place_id'],
                    'decision_code' => $validated['decision_code'] ?? null,
                    'started_at' => $startedAt,
                    'created_by' => auth()->id(),
                ]);
            }

            return $file;
        });

        if (($validated['return_to'] ?? null) === 'dashboard') {
            return redirect()->route('dashboard')->with('status', 'Hükümlü kaydı oluşturuldu ve ana listeye eklendi.');
        }

        if (($validated['return_to'] ?? null) === 'index') {
            return redirect()->route('inmates.index')->with('status', 'Hükümlü kaydı oluşturuldu.');
        }

        return redirect()->route('inmates.edit', $file)->with('status', 'Hükümlü ana dosyası oluşturuldu.');
    }

    public function edit(Request $request, InmateFile $inmate): View
    {
        $tab = $request->input('tab', 'overview');
        $tab = in_array($tab, self::DETAIL_TABS, true) ? $tab : 'overview';

        if ($tab === 'danger' && ! (auth()->user()?->hasPermission('inmates.delete') || auth()->user()?->hasPermission('admin.manage'))) {
            $tab = 'identity';
        }

        $visibleUnitModules = $this->visibleUnitModules();
        $inmate->load([
            'institution',
            'housingAssignments.block',
            'housingAssignments.ward',
            'housingAssignments.bed',
            'workAssignments.place',
            'privateFiles.uploader',
            'unitRecords' => fn ($query) => $query->whereIn('module_code', array_keys($visibleUnitModules))->with('creator')->latest('occurred_at')->latest(),
        ])->loadCount(['housingAssignments', 'workAssignments', 'privateFiles']);

        return view('inmates.edit', $this->lookupData() + [
            'inmate' => $inmate,
            'activeTab' => $tab,
            'visibleUnitModules' => $visibleUnitModules,
        ]);
    }

    public function update(EditInmateFileRequest $request, InmateFile $inmate): RedirectResponse
    {
        $inmate->update($this->fileData($request->validated()));

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Hükümlü ana dosyası güncellendi.');
    }

    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'full_name_desc' => $query->orderByDesc('full_name'),
            'registered_at_desc' => $query->orderByDesc('registered_at')->orderBy('full_name'),
            'registered_at_asc' => $query->orderBy('registered_at')->orderBy('full_name'),
            'created_at_desc' => $query->orderByDesc('created_at')->orderBy('full_name'),
            'created_at_asc' => $query->orderBy('created_at')->orderBy('full_name'),
            default => $query->orderBy('full_name'),
        };
    }

    private function lookupData(): array
    {
        return [
            'institutions' => Institution::orderBy('name')->get(),
            'statuses' => $this->items('inmate_status'),
            'risks' => $this->items('risk_status'),
            'decisions' => $this->items('decision'),
            'blocks' => Block::with(['wards' => fn ($q) => $q->where('is_active', true), 'wards.beds' => fn ($q) => $q->where('is_active', true)])->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'workPlaces' => WorkPlace::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ];
    }

    private function items(string $groupCode)
    {
        return LookupGroup::where('code', $groupCode)->where('is_active', true)->with(['items' => fn ($q) => $q->where('is_active', true)])->first()?->items ?? collect();
    }

    /** @return array<string, array<string, mixed>> */
    private function visibleUnitModules(): array
    {
        return collect(app(\App\Services\UnitRegistry::class)->visible())
            ->filter(fn (array $module) => auth()->user()?->hasPermission($module['permission'].'.view'))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function emptyRooms(): array
    {
        return Block::with([
            'wards' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
            'wards.beds' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('label'),
        ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->flatMap(function (Block $block) {
                return $block->wards->map(function ($ward) use ($block): array {
                    $beds = $ward->beds;
                    $occupiedBedIds = HousingAssignment::active()
                        ->where('ward_id', $ward->id)
                        ->whereNotNull('bed_id')
                        ->pluck('bed_id')
                        ->all();
                    $emptyBeds = $beds
                        ->reject(fn ($bed) => in_array($bed->id, $occupiedBedIds, true))
                        ->pluck('label')
                        ->values()
                        ->all();
                    $occupiedWithoutBed = HousingAssignment::active()
                        ->where('ward_id', $ward->id)
                        ->whereNull('bed_id')
                        ->count();
                    $capacity = (int) ($ward->capacity ?: $beds->count());

                    return [
                        'block' => $block->name,
                        'ward' => $ward->name,
                        'capacity' => $capacity,
                        'occupied' => count($occupiedBedIds) + $occupiedWithoutBed,
                        'empty' => $capacity > 0 ? max($capacity - count($occupiedBedIds), 0) : 1,
                        'beds' => $capacity > 0 ? $emptyBeds : ['Ranzasız'],
                    ];
                });
            })
            ->filter(fn (array $room) => $room['empty'] > 0 || $room['capacity'] === 0)
            ->values()
            ->all();
    }

    /** @return array{leaving: array<int, array<string, mixed>>, returning: array<int, array<string, mixed>>} */
    private function todayLeaveLists(): array
    {
        $today = now()->toDateString();

        return [
            'leaving' => $this->leaveRows($today, 'Çıkış', 'occurred_at'),
            'returning' => $this->leaveRows($today, 'Dönüş', 'ended_at'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function leaveRows(string $today, string $dataKey, string $dateColumn): array
    {
        return UnitRecord::with(['inmate.activeHousingAssignment.block', 'inmate.activeHousingAssignment.ward', 'inmate.activeHousingAssignment.bed', 'inmate.activeWorkAssignment.place'])
            ->module('izin')
            ->where(function (Builder $query) use ($today, $dataKey, $dateColumn): void {
                $query->whereDate($dateColumn, $today)
                    ->orWhere("data->{$dataKey}", $today);
            })
            ->latest($dateColumn)
            ->limit(100)
            ->get()
            ->map(function (UnitRecord $record): array {
                $housing = $record->inmate?->activeHousingAssignment->first();
                $work = $record->inmate?->activeWorkAssignment->first();

                return [
                    'identity_number' => $record->inmate?->identity_number,
                    'full_name' => $record->inmate?->full_name,
                    'work_place' => $work?->place?->name,
                    'block' => $housing?->block?->name,
                    'ward' => $housing?->ward?->name,
                    'bed' => $housing?->bed?->label,
                    'leave_date' => $record->occurred_at?->format('d.m.Y'),
                    'return_date' => $record->ended_at?->format('d.m.Y'),
                    'leave_time' => $record->data['Çıkış saati'] ?? $record->data['Çıkış Saati'] ?? null,
                    'return_time' => $record->data['Dönüş saati'] ?? $record->data['Dönüş Saati'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, int|string|null> */
    private function summary(): array
    {
        $today = now()->toDateString();
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $leaveRecords = UnitRecord::module('izin')
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('occurred_at', $today)
                    ->orWhereDate('ended_at', $today)
                    ->orWhere('data->Çıkış', $today)
                    ->orWhere('data->Dönüş', $today);
            });

        $housingChanges = HousingAssignment::query()
            ->where(function (Builder $query) use ($today): void {
                $query->whereDate('started_at', $today)
                    ->orWhereDate('ended_at', $today);
            })
            ->count();

        $managerDuty = $this->dutyOfficerName(['Müdür nöbeti'], $todayStart, $todayEnd);
        $executionDuty = $this->dutyOfficerName(['İnfaz nöbeti'], $todayStart, $todayEnd);

        return [
            'active' => InmateFile::where('is_active', true)->count(),
            'registered_today' => InmateFile::whereDate('registered_at', $today)->count(),
            'special_leave' => InmateFile::where('is_active', true)->onLeave()->count(),
            'new_record' => InmateFile::whereIn('status_code', ['yeni-kayit', 'yeni-kayıt', 'YENİ KAYIT'])->count(),
            'leave_today' => (clone $leaveRecords)->count(),
            'leaving_today' => (clone $leaveRecords)->where(function (Builder $query) use ($today): void {
                $query->whereDate('occurred_at', $today)
                    ->orWhere('data->Çıkış', $today);
            })->count(),
            'returning_today' => UnitRecord::module('izin')->where(function (Builder $query) use ($today): void {
                $query->whereDate('ended_at', $today)
                    ->orWhere('data->Dönüş', $today);
            })->count(),
            'housing_changes_today' => $housingChanges,
            'duty_today' => OperationRecord::module('nobet')
                ->where('starts_at', '<=', $todayEnd)
                ->where('ends_at', '>=', $todayStart)
                ->where('is_closed', false)
                ->count(),
            'manager_duty' => $managerDuty,
            'execution_duty' => $executionDuty,
        ];
    }

    /**
     * @param  array<int, string>  $types
     */
    private function dutyOfficerName(array $types, mixed $startsAt, mixed $endsAt): ?string
    {
        $record = OperationRecord::module('nobet')
            ->whereIn('record_type', $types)
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->where('is_closed', false)
            ->orderBy('starts_at')
            ->first();

        $personnelId = $record?->data['Personel'] ?? null;
        if (! $personnelId) {
            return null;
        }

        return User::find($personnelId)?->full_name;
    }

    private function fileData(array $data): array
    {
        return [
            'institution_id' => $data['institution_id'],
            'identity_number' => $data['identity_number'] ?? null,
            'full_name' => $data['full_name'],
            'registered_at' => $data['registered_at'] ?? null,
            'entered_at' => $data['entered_at'] ?? null,
            'status_code' => $data['status_code'] ?? null,
            'risk_code' => $data['risk_code'] ?? null,
            'decision_code' => $data['decision_code'] ?? null,
            'health_status' => $data['health_status'] ?? null,
            'notes' => $data['notes'] ?? null,
            'legacy_id' => $data['legacy_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
