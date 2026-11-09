<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\Module;
use App\Models\OperationPrivateFile;
use App\Models\OperationRecord;
use App\Models\User;
use App\Models\Ward;
use App\Services\DutyRules;
use App\Services\OperationWriter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationModuleController extends Controller
{
    public const MODULES = [
        'kogus-sayim-calisma' => ['task' => 'CTE-070', 'title' => 'Koğuş / Sayım / Çalışma', 'description' => 'Aktif konum ve çalışma verisinden salt okunur ekranlar.', 'readonly' => true, 'types' => ['Sayım notu', 'Çalışma özeti'], 'fields' => ['Blok', 'Koğuş', 'Görev yeri', 'Sayım notu']],
        'kismi-arama' => ['task' => 'CTE-071', 'title' => 'Kısmi Arama', 'description' => 'Blok-koğuş-tarih-kullanıcı ve yıllık sayaçlar.', 'readonly' => false, 'types' => ['Blok arama', 'Koğuş arama', 'Yıllık sayaç', 'Tutanak'], 'fields' => ['Blok', 'Koğuş', 'Arama sonucu', 'Yıllık sıra']],
        'duyurular' => ['task' => 'CTE-072', 'title' => 'Duyurular', 'description' => 'Oluşturma, silme, tek/tümü okundu ve okunmamış sayaç.', 'readonly' => false, 'types' => ['Genel duyuru', 'Birim duyurusu', 'Acil duyuru', 'Okundu bilgisi'], 'fields' => ['Hedef birim', 'Öncelik', 'Okundu sayısı', 'Unvan']],
        'nobet' => ['task' => 'CTE-073', 'title' => 'Müdür / Vardiya / İnfaz Nöbeti', 'description' => 'Personel havuzu, uygun olmayan tarih, otomatik program ve manuel düzeltme.', 'readonly' => false, 'types' => ['Müdür nöbeti', 'Vardiya', 'İnfaz nöbeti', 'Uygun değil'], 'fields' => ['Personel', 'Görev', 'Vardiya', 'Uygun olmayan tarih']],
        'kurul-komisyon' => ['task' => 'CTE-074', 'title' => 'Kurul / Komisyon', 'description' => 'Normalize kurul türü, sıra ve üye atamaları.', 'readonly' => false, 'types' => ['Kurul', 'Komisyon', 'Üye atama', 'Karar'], 'fields' => ['Kurul türü', 'Sıra', 'Üyeler', 'Karar özeti']],
        'yemek' => ['task' => 'CTE-075', 'title' => 'Yemek', 'description' => 'Haftalık, diyet ve vejetaryen private belge yönetimi.', 'readonly' => false, 'types' => ['Haftalık menü', 'Diyet menü', 'Vejetaryen menü', 'Belge'], 'fields' => ['Menü dönemi', 'Öğün', 'Diyet türü', 'Belge notu']],
        'sohbet' => ['task' => 'CTE-076', 'title' => 'Sohbet', 'description' => 'Portal oturumu, genel/özel mesaj, okunma ve çevrimiçi durum.', 'readonly' => false, 'types' => ['Genel mesaj', 'Özel mesaj', 'Okundu', 'Çevrimiçi durum'], 'fields' => ['Gönderen', 'Alıcı', 'Okundu durumu', 'Kanal']],
    ];

    public function index(Request $request, string $module): View
    {
        $config = $this->module($module);
        $this->authorizeModule($module, 'view');

        if ($module === 'sohbet') {
            return $this->chatIndex($request);
        }

        if ($module === 'kogus-sayim-calisma') {
            return $this->housingWorkIndex($request, $module, $config);
        }

        if ($module === 'kismi-arama') {
            return $this->partialSearchIndex($request, $config);
        }

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'record_type' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'is_closed' => ['nullable', 'in:0,1']]);
        $records = OperationRecord::with(['inmate', 'creator', 'readUsers', 'members', 'privateFiles'])->module($module)
            ->when(! auth()->user()->hasPermission(self::permissionModule($module).'.update'), fn ($q) => $q->visible())->search($filters['q'] ?? null)
            ->when(isset($filters['record_type']), fn ($query) => $query->where('record_type', $filters['record_type']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('starts_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('starts_at', '<=', $filters['to']))
            ->when(isset($filters['is_closed']), fn ($query) => $query->where('is_closed', (bool) $filters['is_closed']))
            ->latest('starts_at')->latest()->paginate(20)->withQueryString();
        $stats = ['total' => OperationRecord::module($module)->count(), 'open' => OperationRecord::module($module)->where('is_closed', false)->count(), 'closed' => OperationRecord::module($module)->where('is_closed', true)->count()];

        return view('operations.index', ['module' => $module, 'config' => $config, 'permissionModule' => self::permissionModule($module), 'modules' => self::MODULES, 'records' => $records, 'stats' => $stats]);
    }

    public function create(string $module): View
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'update');

        return view('operations.create', ['module' => $module, 'config' => $config, 'permissionModule' => self::permissionModule($module), 'record' => null, 'inmates' => InmateFile::orderBy('full_name')->limit(500)->get()]);
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'update');
        $validated = $this->validated($request, $config);
        if ($module === 'sohbet') {
            return $this->chatSend($request);
        }
        app(OperationWriter::class)->save(null,
            $this->recordData($validated, $module) + ['created_by' => auth()->id()],
            $module === 'kurul-komisyon' ? $request->input('member_ids', []) : null,
            $module === 'yemek' ? $request->file('menu_file') : null);

        return redirect()->route('operations.index', $module)->with('status', $config['title'].' kaydı oluşturuldu.');
    }

    public function edit(string $module, OperationRecord $record): View
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'update');
        abort_unless($record->module_code === $module, 404);

        return view('operations.create', ['module' => $module, 'config' => $config, 'permissionModule' => self::permissionModule($module), 'record' => $record, 'inmates' => InmateFile::orderBy('full_name')->limit(500)->get()]);
    }

    public function update(Request $request, string $module, OperationRecord $record): RedirectResponse
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'update');
        abort_unless($record->module_code === $module, 404);
        $validated = $this->validated($request, $config);
        abort_if($module === 'sohbet', 403, 'Gönderilmiş mesaj değiştirilemez.');
        $validated['is_closed'] = $validated['is_closed'] ?? $record->is_closed;
        $validated['is_published'] = $validated['is_published'] ?? $record->is_published;
        app(OperationWriter::class)->save($record,
            $this->recordData($validated, $module),
            $module === 'kurul-komisyon' ? $request->input('member_ids', []) : null,
            $module === 'yemek' ? $request->file('menu_file') : null);

        return redirect()->route('operations.index', $module)->with('status', $config['title'].' kaydı güncellendi.');
    }

    public function close(string $module, OperationRecord $record): RedirectResponse
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'update');
        abort_unless($record->module_code === $module, 404);
        abort_if($module === 'sohbet' && $record->created_by !== auth()->id(), 403);
        DB::transaction(function () use ($record): void {
            $record = OperationRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (! $record->is_closed) {
                $record->update(['is_closed' => true, 'updated_by' => auth()->id()]);
            }
        });

        return redirect()->route('operations.index', $module)->with('status', $config['title'].' kaydı kapatıldı.');
    }

    public function destroy(string $module, OperationRecord $record): RedirectResponse
    {
        $config = $this->module($module);
        abort_if($config['readonly'], 404);
        $this->authorizeModule($module, 'delete');
        abort_unless($record->module_code === $module, 404);
        abort_if($module === 'sohbet' && $record->created_by !== auth()->id(), 403);
        $record->delete();

        return redirect()->route('operations.index', $module)->with('status', $config['title'].' kaydı silindi.');
    }

    private function housingWorkIndex(Request $request, string $module, array $config): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $inmates = InmateFile::with(['institution', 'activeHousingAssignment.block', 'activeHousingAssignment.ward', 'activeHousingAssignment.bed', 'activeWorkAssignment.place'])->search($filters['q'] ?? null)->where('is_active', true)->orderBy('full_name')->paginate(30)->withQueryString();

        return view('operations.housing-work', ['module' => $module, 'config' => $config, 'modules' => self::MODULES, 'inmates' => $inmates]);
    }

    private function validated(Request $request, array $config): array
    {
        return $request->validate([
            'menu_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,xls,xlsx,doc,docx'],
            'inmate_file_id' => ['nullable', 'integer', 'exists:inmate_files,id'],
            'record_type' => ['required', 'string', 'max:100', Rule::in($config['types'])],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'is_published' => ['nullable', 'boolean'],
            'is_closed' => ['nullable', 'boolean'],
            'body' => ['nullable', 'string', 'max:4000'],
            'data' => ['nullable', 'array'],
            'data.*' => ['nullable', 'string', 'max:1000'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    private function recordData(array $validated, string $module): array
    {
        return ['institution_id' => auth()->user()?->institution_id, 'inmate_file_id' => $validated['inmate_file_id'] ?? null, 'module_code' => $module, 'record_type' => $validated['record_type'], 'title' => $validated['title'], 'starts_at' => $validated['starts_at'] ?? now(), 'ends_at' => $validated['ends_at'] ?? null, 'status_code' => $validated['status_code'] ?? null, 'is_published' => (bool) ($validated['is_published'] ?? true), 'is_closed' => (bool) ($validated['is_closed'] ?? false), 'data' => Arr::where($validated['data'] ?? [], fn ($value) => $value !== null && $value !== ''), 'body' => $validated['body'] ?? null, 'updated_by' => auth()->id()];
    }

    public function markAsRead(string $module, OperationRecord $record): RedirectResponse
    {
        $this->authorizeModule($module, 'view');
        abort_unless($module === 'duyurular', 404);
        abort_unless($record->module_code === 'duyurular', 404);

        $record->markAnnouncementRead(auth()->user());

        return redirect()->back()->with('status', 'Duyuru okundu olarak işaretlendi.');
    }

    public function markAllAsRead(string $module): RedirectResponse
    {
        $this->authorizeModule($module, 'view');
        abort_unless($module === 'duyurular', 404);

        $unreadAnnouncements = OperationRecord::where('module_code', 'duyurular')
            ->where('is_published', true)
            ->where('is_closed', false)
            ->whereDoesntHave('readUsers', function ($q) {
                $q->where('user_id', auth()->id());
            })
            ->get();

        foreach ($unreadAnnouncements as $announcement) {
            $announcement->markAnnouncementRead(auth()->user());
        }

        return redirect()->back()->with('status', 'Tüm duyurular okundu olarak işaretlendi.');
    }

    public function autoPlanForm(): View
    {
        $this->authorizeModule('nobet', 'update');
        $users = User::where('is_active', true)->where('institution_id', auth()->user()->institution_id)->orderBy('full_name')->get();

        return view('operations.nobet.auto-plan', [
            'module' => 'nobet',
            'config' => self::MODULES['nobet'],
            'users' => $users,
        ]);
    }

    public function autoPlan(Request $request): RedirectResponse
    {
        $this->authorizeModule('nobet', 'update');
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'record_type' => ['required', Rule::in(DutyRules::TYPES)],
            'personnel_ids' => ['required', 'array', 'min:1'],
            'personnel_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)->where('institution_id', auth()->user()->institution_id)],
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $recordType = $validated['record_type'];
        $personnelIds = $validated['personnel_ids'];

        $createdCount = DB::transaction(function () use ($startDate, $endDate, $recordType, $personnelIds): int {
            Institution::whereKey(auth()->user()->institution_id)->lockForUpdate()->firstOrFail();
            $currentDate = $startDate->copy();
            $createdCount = 0;

            while ($currentDate->lte($endDate)) {
                // Personelleri nöbet sayılarına göre sırala (en az nöbet yazılan en üstte olsun - adillik için)
                $personnelWithCounts = [];
                foreach ($personnelIds as $id) {
                    $count = OperationRecord::where('module_code', 'nobet')
                        ->where('record_type', $recordType)
                        ->where('data->Personel', (string) $id)
                        ->count();
                    $personnelWithCounts[$id] = $count;
                }
                asort($personnelWithCounts);

                $sortedPersonnelIds = array_keys($personnelWithCounts);
                $sortedPersonnel = User::whereIn('id', $sortedPersonnelIds)
                    ->get()
                    ->sortBy(function ($user) use ($personnelWithCounts) {
                        return $personnelWithCounts[$user->id];
                    });

                foreach ($sortedPersonnel as $personnel) {
                    // Bu personel için o güne nöbet yazabilir miyiz?
                    $startsAt = $currentDate->copy()->setTime(8, 0, 0);
                    $endsAt = $currentDate->copy()->setTime(17, 0, 0);

                    // Çakışma ve uygunluk kontrolü
                    $hasUnsuitability = OperationRecord::where('module_code', 'nobet')
                        ->where('record_type', 'Uygun değil')
                        ->where('is_closed', false)
                        ->where('data->Personel', (string) $personnel->id)
                        ->where(function ($query) use ($startsAt, $endsAt) {
                            $query->where(function ($q) use ($startsAt, $endsAt) {
                                $q->where('starts_at', '<', $endsAt)
                                    ->where('ends_at', '>', $startsAt);
                            })->orWhere('data->Uygun olmayan tarih', $startsAt->toDateString())
                                ->orWhere('data->Uygun olmayan tarih', $endsAt->toDateString());
                        })
                        ->exists();

                    $hasConflict = OperationRecord::where('module_code', 'nobet')
                        ->whereIn('record_type', ['Müdür nöbeti', 'Vardiya', 'İnfaz nöbeti'])
                        ->where('is_closed', false)
                        ->where('data->Personel', (string) $personnel->id)
                        ->where('starts_at', '<', $endsAt)
                        ->where('ends_at', '>', $startsAt)
                        ->exists();

                    if (! $hasUnsuitability && ! $hasConflict) {
                        OperationRecord::create([
                            'institution_id' => auth()->user()->institution_id,
                            'module_code' => 'nobet',
                            'record_type' => $recordType,
                            'title' => $recordType.' - '.$personnel->full_name,
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'data' => [
                                'Personel' => (string) $personnel->id,
                                'Görev' => 'Nöbetçi',
                                'Vardiya' => '08:00 - 17:00',
                            ],
                            'is_published' => true,
                            'is_closed' => false,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                        ]);
                        $createdCount++;
                        break; // O gün için bir kişi atandı, diğer güne geç
                    }
                }

                $currentDate->addDay();
            }

            return $createdCount;
        }, 3);

        return redirect()->route('operations.index', 'nobet')->with('status', $createdCount.' adet otomatik nöbet kaydı oluşturuldu.');
    }

    public function downloadFile(int $fileId)
    {
        $this->authorizeModule('yemek', 'view');
        $file = OperationPrivateFile::findOrFail($fileId);

        $path = 'private/operations/'.$file->filename;
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Belge bulunamadı.');
        }

        return Storage::disk('local')->download($path, $file->original_filename);
    }

    public function chatIndex(Request $request): View
    {
        $this->authorizeModule('sohbet', 'view');

        $users = User::where('is_active', true)->where('institution_id', auth()->user()->institution_id)
            ->where('id', '!=', auth()->id())
            ->orderBy('full_name')
            ->get();

        $receiverId = $request->query('receiver_id');
        $receiver = $receiverId ? User::where('is_active', true)->where('institution_id', auth()->user()->institution_id)->findOrFail($receiverId) : null;

        if ($receiver) {
            $messages = OperationRecord::where('module_code', 'sohbet')->visible()->where('institution_id', auth()->user()->institution_id)
                ->where('record_type', 'Özel mesaj')
                ->where(function ($q) use ($receiverId) {
                    $q->where(function ($sub) use ($receiverId) {
                        $sub->where('created_by', auth()->id())
                            ->where('data->Alıcı', (string) $receiverId);
                    })->orWhere(function ($sub) use ($receiverId) {
                        $sub->where('created_by', $receiverId)
                            ->where('data->Alıcı', (string) auth()->id());
                    });
                })
                ->oldest('starts_at')
                ->get();

        } else {
            $messages = OperationRecord::where('module_code', 'sohbet')->visible()->where('institution_id', auth()->user()->institution_id)
                ->where('record_type', 'Genel mesaj')
                ->oldest('starts_at')
                ->limit(100)
                ->get();
        }

        return view('operations.chat', [
            'module' => 'sohbet',
            'config' => self::MODULES['sohbet'],
            'users' => $users,
            'messages' => $messages,
            'receiver' => $receiver,
            'modules' => self::MODULES,
        ]);
    }

    public function chatSend(Request $request): RedirectResponse
    {
        $this->authorizeModule('sohbet', 'update');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'receiver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('institution_id', auth()->user()->institution_id), Rule::notIn([auth()->id()])],
        ]);

        $receiverId = $validated['receiver_id'] ?? null;
        $recordType = $receiverId ? 'Özel mesaj' : 'Genel mesaj';

        OperationRecord::create([
            'institution_id' => auth()->user()->institution_id,
            'module_code' => 'sohbet',
            'record_type' => $recordType,
            'title' => 'Mesaj',
            'starts_at' => now(),
            'body' => $validated['body'],
            'data' => [
                'Gönderen' => (string) auth()->id(),
                'Alıcı' => $receiverId ? (string) $receiverId : null,
                'Okundu durumu' => $receiverId ? 'okunmadi' : 'okundu',
            ],
            'is_published' => true,
            'is_closed' => false,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('operations.index', ['sohbet', 'receiver_id' => $receiverId]);
    }

    public function chatMarkAsRead(OperationRecord $record): RedirectResponse|Response
    {
        $this->authorizeModule('sohbet', 'view');
        abort_unless($record->module_code === 'sohbet', 404);

        abort_unless($record->is_published && ! $record->is_closed && $record->institution_id === auth()->user()->institution_id, 404);
        abort_unless((string) ($record->data['Alıcı'] ?? '') === (string) auth()->id(), 403);
        DB::transaction(function () use ($record): void {
            $record = OperationRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->is_published && ! $record->is_closed && (string) ($record->data['Alıcı'] ?? '') === (string) auth()->id(), 404);
            $data = $record->data;
            if (($data['Okundu durumu'] ?? '') !== 'okundu') {
                $data['Okundu durumu'] = 'okundu';
                $data['Okunma tarihi'] = now()->toIso8601String();
                $record->update(['data' => $data]);
            }
        });

        return request()->expectsJson() ? response()->noContent() : redirect()->back();
    }

    private function partialSearchIndex(Request $request, array $config): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'period' => ['nullable', Rule::in(['3', '6', '12'])],
            'q' => ['nullable', 'string', 'max:255'],
            'record_type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'is_closed' => ['nullable', 'in:0,1'],
        ]);
        $date = Carbon::parse($filters['date'] ?? today()->toDateString());
        $year = (int) ($filters['year'] ?? $date->year);
        $period = (int) ($filters['period'] ?? 12);

        $recordsQuery = OperationRecord::with('creator')
            ->module('kismi-arama')
            ->when(! auth()->user()->hasPermission('counting.update'), fn ($query) => $query->visible())
            ->when(isset($filters['q']), fn ($query) => $query->search($filters['q']))
            ->when(isset($filters['record_type']), fn ($query) => $query->where('record_type', $filters['record_type']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('starts_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('starts_at', '<=', $filters['to']))
            ->when(isset($filters['is_closed']), fn ($query) => $query->where('is_closed', (bool) $filters['is_closed']));
        $hasListFilter = collect(['q', 'record_type', 'from', 'to', 'is_closed'])->contains(fn ($key) => array_key_exists($key, $filters));
        if (! $hasListFilter) {
            $recordsQuery->whereDate('starts_at', $date);
        }
        $records = $recordsQuery
            ->oldest('starts_at')
            ->get();
        $yearRecords = OperationRecord::module('kismi-arama')
            ->when(! auth()->user()->hasPermission('counting.update'), fn ($query) => $query->visible())
            ->whereYear('starts_at', $year)
            ->get();
        $periodRecords = OperationRecord::module('kismi-arama')
            ->when(! auth()->user()->hasPermission('counting.update'), fn ($query) => $query->visible())
            ->whereDate('starts_at', '>=', today()->subMonths($period)->startOfDay())
            ->get();
        $wards = Ward::with('block')->where('is_active', true)->orderBy('block_id')->orderBy('name')->get();

        $wardRows = $wards->map(function (Ward $ward) use ($records, $yearRecords): array {
            $daily = $records->filter(fn (OperationRecord $record) => ($record->data['Blok'] ?? null) === $ward->block?->name && ($record->data['Koğuş'] ?? null) === $ward->name);
            $annual = $yearRecords->filter(fn (OperationRecord $record) => ($record->data['Blok'] ?? null) === $ward->block?->name && ($record->data['Koğuş'] ?? null) === $ward->name);

            return ['ward' => $ward, 'daily' => $daily, 'annual_count' => $annual->count(), 'last' => $annual->sortByDesc('starts_at')->first()];
        });

        return view('operations.partial-search', [
            'module' => 'kismi-arama',
            'config' => $config,
            'permissionModule' => self::permissionModule('kismi-arama'),
            'date' => $date,
            'year' => $year,
            'period' => $period,
            'records' => $records,
            'wardRows' => $wardRows,
            'stats' => [
                'daily' => $records->count(),
                'searched_wards' => $wardRows->filter(fn ($row) => $row['daily']->isNotEmpty())->count(),
                'unsearched_wards' => $wardRows->filter(fn ($row) => $row['daily']->isEmpty())->count(),
                'annual' => $yearRecords->count(),
                'period' => $periodRecords->count(),
            ],
        ]);
    }

    private function module(string $module): array
    {
        abort_unless(array_key_exists($module, self::MODULES), 404);

        return self::MODULES[$module];
    }

    public static function permissionModule(string $module): string
    {
        return match ($module) {
            'kogus-sayim-calisma', 'kismi-arama' => 'counting',
            'duyurular' => 'announcement',
            'nobet' => 'duty',
            'kurul-komisyon' => 'committee',
            'yemek' => 'meal',
            'sohbet' => 'chat',
            default => abort(404),
        };
    }

    private function authorizeModule(string $module, string $action): void
    {
        $permissionModule = self::permissionModule($module);
        abort_if(Module::where('code', $permissionModule)->where('is_active', false)->exists(), 404);
        abort_unless(auth()->user()?->hasPermission($permissionModule.'.'.$action), 403);
    }
}
