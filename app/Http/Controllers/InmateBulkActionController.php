<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\Ward;
use App\Services\DeletionQuarantine;
use App\Support\StateDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class InmateBulkActionController extends Controller
{
    private const DISK = 'local';

    private const CONFIRM_TEXT = 'TOPLU SIL';

    private const QUARANTINE_DIRECTORY = 'quarantine/bulk-delete';

    public function index(): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);

        return view('inmates.bulk.index', $this->bulkData());
    }

    public function import(Request $request): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.create') || auth()->user()?->hasPermission('admin.manage'), 403);

        $validated = $request->validate([
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'csv_file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $realPath = $request->file('csv_file')?->getRealPath();
        abort_unless(is_string($realPath), 422);

        $report = $this->importCsv($realPath, (int) $validated['institution_id']);

        return view('inmates.bulk.import-report', $this->bulkData() + ['report' => $report]);
    }

    public function status(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);
        $this->normalizeSelectedIds($request);

        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'exists:inmate_files,id'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'risk_code' => ['nullable', 'string', 'max:100'],
            'decision_code' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'in:0,1'],
        ]);

        $data = array_filter([
            'status_code' => $validated['status_code'] ?? null,
            'risk_code' => $validated['risk_code'] ?? null,
            'decision_code' => $validated['decision_code'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (array_key_exists('is_active', $validated)) {
            $data['is_active'] = (bool) $validated['is_active'];
        }

        if ($data === []) {
            throw ValidationException::withMessages(['status_code' => 'En az bir toplu güncelleme alanı seçilmelidir.']);
        }

        $updated = DB::transaction(function () use ($validated, $data): int {
            $files = InmateFile::whereIn('id', $validated['selected_ids'])->orderBy('id')->lockForUpdate()->get();
            foreach ($files as $file) {
                $file->update($data);
            }

            return $files->count();
        }, 3);

        return redirect()->route('inmates.bulk.index')->with('status', $updated.' hükümlü dosyası toplu güncellendi.');
    }

    public function housing(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);
        $this->normalizeSelectedIds($request);

        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'exists:inmate_files,id'],
            'block_id' => ['required', 'integer', 'exists:blocks,id'],
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
            'started_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ids = array_values(array_unique($validated['selected_ids']));
        if (count($ids) > 1 && ! empty($validated['bed_id'])) {
            throw ValidationException::withMessages(['bed_id' => 'Toplu konumda tek ranza birden fazla hükümlüye atanamaz.']);
        }

        $ward = Ward::findOrFail($validated['ward_id']);
        if ($ward->block_id !== (int) $validated['block_id']) {
            throw ValidationException::withMessages(['ward_id' => 'Seçilen koğuş seçilen bloğa bağlı değil.']);
        }

        if (! empty($validated['bed_id'])) {
            $bed = Bed::findOrFail($validated['bed_id']);
            if ($bed->ward_id !== $ward->id) {
                throw ValidationException::withMessages(['bed_id' => 'Seçilen ranza seçilen koğuşa bağlı değil.']);
            }

            $bedBusy = HousingAssignment::where('bed_id', $bed->id)->whereNull('ended_at')->whereNotIn('inmate_file_id', $ids)->exists();
            if ($bedBusy) {
                throw ValidationException::withMessages(['bed_id' => 'Seçilen ranza başka aktif konum kaydında kullanılıyor.']);
            }
        }

        DB::transaction(function () use ($ids, $validated): void {
            InmateFile::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach (HousingAssignment::whereIn('inmate_file_id', $ids)->whereNull('ended_at')->get() as $assignment) {
                $assignment->update(['ended_at' => $validated['started_at']]);
            }

            foreach ($ids as $id) {
                HousingAssignment::create([
                    'inmate_file_id' => $id,
                    'block_id' => $validated['block_id'],
                    'ward_id' => $validated['ward_id'],
                    'bed_id' => $validated['bed_id'] ?? null,
                    'started_at' => $validated['started_at'],
                    'ended_at' => null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }
        });

        return redirect()->route('inmates.bulk.index')->with('status', count($ids).' hükümlü için toplu konum kaydı oluşturuldu.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.delete') || auth()->user()?->hasPermission('admin.manage'), 403);
        $this->normalizeSelectedIds($request);

        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'exists:inmate_files,id'],
            'password' => ['required', 'string'],
            'confirm_text' => ['required', 'string', 'in:'.self::CONFIRM_TEXT],
        ]);

        if (! Hash::check($validated['password'], auth()->user()?->password ?? '')) {
            throw ValidationException::withMessages(['password' => 'Parola doğrulanamadı.']);
        }

        $movedFiles = [];
        $count = 0;

        try {
            DB::transaction(function () use ($validated, &$movedFiles, &$count): void {
                $files = InmateFile::with('privateFiles')->whereIn('id', $validated['selected_ids'])->orderBy('id')->lockForUpdate()->get();

                foreach ($files as $inmate) {
                    foreach ($inmate->privateFiles as $file) {
                        if ($file->storage_disk !== self::DISK || ! $this->isSafePrivatePath($file->storage_path)) {
                            throw ValidationException::withMessages(['delete' => 'Private dosya yolu güvenli değil. Toplu silme durduruldu.']);
                        }

                        if (! Storage::disk(self::DISK)->exists($file->storage_path)) {
                            continue;
                        }

                        $movedFiles[] = app(DeletionQuarantine::class)->move($file->storage_path);

                    }

                    $inmate->forceDelete();
                    $count++;
                }
            });
        } catch (Throwable $exception) {
            $this->restoreMovedFiles($movedFiles);
            throw $exception;
        }

        $this->purgeQuarantineFiles($movedFiles);

        return redirect()->route('inmates.index')->with('status', $count.' hükümlü dosyası toplu kalıcı silindi.');
    }

    private function normalizeSelectedIds(Request $request): void
    {
        $raw = $request->input('selected_ids_text', $request->input('selected_ids', []));
        $values = is_array($raw) ? $raw : [$raw];
        $ids = [];

        foreach ($values as $value) {
            foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (ctype_digit($part)) {
                    $ids[] = (int) $part;
                }
            }
        }

        $request->merge(['selected_ids' => array_values(array_unique($ids))]);
    }

    private function bulkData(): array
    {
        return [
            'files' => InmateFile::orderBy('full_name')->limit(250)->get(),
            'institutions' => Institution::orderBy('name')->get(),
            'statuses' => $this->items('inmate_status'),
            'risks' => $this->items('risk_status'),
            'decisions' => $this->items('decision'),
            'blocks' => Block::orderBy('sort_order')->orderBy('name')->get(),
            'wards' => Ward::with('block')->orderBy('sort_order')->orderBy('name')->get(),
            'beds' => Bed::with('ward.block')->orderBy('sort_order')->orderBy('label')->get(),
            'confirmText' => self::CONFIRM_TEXT,
        ];
    }

    private function importCsv(string $path, int $institutionId): array
    {
        $handle = fopen($path, 'rb');
        abort_unless($handle !== false, 422);

        $headers = fgetcsv($handle);
        $headers = array_map(fn ($header) => trim(strtolower((string) $header)), $headers ?: []);
        $allowed = ['full_name', 'identity_number', 'registered_at', 'entered_at', 'status_code', 'risk_code', 'decision_code', 'legacy_id', 'is_active'];
        $seenIdentityNumbers = [];
        $report = ['created' => 0, 'skipped' => 0, 'rows' => []];
        $line = 1;

        if (! in_array('full_name', $headers, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['csv_file' => 'CSV dosyasında full_name başlığı zorunludur.']);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            $data = [];
            foreach ($headers as $index => $header) {
                if (in_array($header, $allowed, true)) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }
            }

            $errors = $this->validateImportRow($data, $institutionId, $seenIdentityNumbers);
            if ($errors !== []) {
                $report['skipped']++;
                $report['rows'][] = ['line' => $line, 'name' => $data['full_name'] ?? '', 'status' => 'Atlandı', 'message' => implode(' ', $errors)];

                continue;
            }

            InmateFile::create([
                'institution_id' => $institutionId,
                'identity_number' => ($data['identity_number'] ?? '') !== '' ? $data['identity_number'] : null,
                'full_name' => $data['full_name'],
                'registered_at' => ($data['registered_at'] ?? '') !== '' ? $data['registered_at'] : null,
                'entered_at' => ($data['entered_at'] ?? '') !== '' ? $data['entered_at'] : null,
                'status_code' => ($data['status_code'] ?? '') !== '' ? $data['status_code'] : null,
                'risk_code' => ($data['risk_code'] ?? '') !== '' ? $data['risk_code'] : null,
                'decision_code' => ($data['decision_code'] ?? '') !== '' ? $data['decision_code'] : null,
                'legacy_id' => ($data['legacy_id'] ?? '') !== '' ? (int) $data['legacy_id'] : null,
                'is_active' => $this->csvBoolean($data['is_active'] ?? '1'),
            ]);

            if (($data['identity_number'] ?? '') !== '') {
                $seenIdentityNumbers[] = $data['identity_number'];
            }

            $report['created']++;
            $report['rows'][] = ['line' => $line, 'name' => $data['full_name'], 'status' => 'Aktarıldı', 'message' => 'Kayıt oluşturuldu.'];
        }

        fclose($handle);

        return $report;
    }

    private function validateImportRow(array $data, int $institutionId, array $seenIdentityNumbers): array
    {
        $errors = [];
        try {
            StateDefinitions::validate($data);
        } catch (ValidationException $exception) {
            $errors = array_merge($errors, array_merge(...array_values($exception->errors())));
        }
        if (($data['full_name'] ?? '') === '') {
            $errors[] = 'Ad soyad zorunlu.';
        }

        $identity = $data['identity_number'] ?? '';
        if ($identity !== '' && ! preg_match('/^\d{11}$/', $identity)) {
            $errors[] = 'TC 11 haneli olmalı.';
        }

        if ($identity !== '' && in_array($identity, $seenIdentityNumbers, true)) {
            $errors[] = 'CSV içinde mükerrer TC.';
        }

        if ($identity !== '' && InmateFile::where('institution_id', $institutionId)->where('identity_number', $identity)->exists()) {
            $errors[] = 'Sistemde aynı TC mevcut.';
        }

        foreach (['registered_at', 'entered_at'] as $field) {
            if (($data[$field] ?? '') !== '' && strtotime($data[$field]) === false) {
                $errors[] = $field.' tarih formatı geçersiz.';
            }
        }

        return $errors;
    }

    private function csvBoolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'evet', 'aktif', 'yes'], true);
    }

    private function items(string $groupCode)
    {
        return LookupGroup::where('code', $groupCode)->where('is_active', true)->with(['items' => fn ($q) => $q->where('is_active', true)])->first()?->items ?? collect();
    }

    private function restoreMovedFiles(array $movedFiles): void
    {
        app(DeletionQuarantine::class)->finish($movedFiles, rollback: true);
    }

    private function purgeQuarantineFiles(array $movedFiles): void
    {
        app(DeletionQuarantine::class)->finish($movedFiles);
    }

    private function isSafePrivatePath(string $path): bool
    {
        return Str::startsWith($path, 'inmate-private-files/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }
}
