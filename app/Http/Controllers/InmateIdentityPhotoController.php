<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use App\Services\DeletionQuarantine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InmateIdentityPhotoController extends Controller
{
    private const DISK = 'local';

    private const BASE_DIRECTORY = 'inmate-private-files';

    private const MAX_BYTES = 5_242_880;

    public function create(InmateFile $inmate): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);

        $inmate->load(['privateFiles' => fn ($query) => $query->where('category', 'photo')->latest()]);

        return view('inmates.identity-photo', [
            'inmate' => $inmate,
            'photos' => $inmate->privateFiles,
        ]);
    }

    public function store(Request $request, InmateFile $inmate): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);

        $request->validate([
            'photo_upload' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png'],
            'captured_photo' => ['nullable', 'string'],
        ]);

        [$content, $mimeType, $extension, $originalName] = $this->photoPayload($request);
        if ($content === '') {
            throw ValidationException::withMessages(['photo_upload' => 'Fotoğraf yükleyin veya webcam ile fotoğraf çekin.']);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo_upload' => 'Fotoğraf en fazla 5 MB olabilir.']);
        }

        $directory = self::BASE_DIRECTORY.'/'.$inmate->id;
        $storagePath = $directory.'/'.Str::uuid().'.'.$extension;
        abort_unless(Storage::disk(self::DISK)->put($storagePath, $content), 500);

        try {
            InmatePrivateFile::create([
                'inmate_file_id' => $inmate->id,
                'uploaded_by' => auth()->id(),
                'category' => 'photo',
                'display_name' => 'Kimlik fotoğrafı',
                'original_name' => $originalName,
                'storage_disk' => self::DISK,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size' => strlen($content),
                'sha256' => hash('sha256', $content),
                'extension' => $extension,
                'description' => 'Kimlik kartı fotoğrafı',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($storagePath);
            throw $exception;
        }

        return redirect()->route('inmates.identity-photo.create', $inmate)->with('status', 'Kimlik fotoğrafı private storage alanına kaydedildi.');
    }

    public function image(InmateFile $inmate, InmatePrivateFile $file): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.view') || auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($file->inmate_file_id === $inmate->id && $file->category === 'photo', 404);
        abort_unless(in_array($file->mime_type, ['image/jpeg', 'image/png'], true), 404);
        abort_unless($this->isSafeStoragePath($file->storage_path), 404);
        abort_unless($file->storage_disk === self::DISK && Storage::disk(self::DISK)->exists($file->storage_path), 404);

        return Storage::disk(self::DISK)->response($file->storage_path, null, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(InmateFile $inmate, InmatePrivateFile $file): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($file->inmate_file_id === $inmate->id && $file->category === 'photo', 404);
        abort_unless($this->isSafeStoragePath($file->storage_path), 404);

        $moved = [];
        try {
            DB::transaction(function () use ($file, &$moved): void {
                $file = InmatePrivateFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
                if ($file->storage_disk === self::DISK && Storage::disk(self::DISK)->exists($file->storage_path)) {
                    $moved[] = app(DeletionQuarantine::class)->move($file->storage_path);
                }
                $file->delete();
            });
        } catch (\Throwable $exception) {
            app(DeletionQuarantine::class)->finish($moved, rollback: true);
            throw $exception;
        }
        app(DeletionQuarantine::class)->finish($moved);

        return redirect()->route('inmates.identity-photo.create', $inmate)->with('status', 'Fotoğraf kaydı silindi.');
    }

    public function card(InmateFile $inmate): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.view') || auth()->user()?->hasPermission('admin.manage'), 403);

        $inmate->load(['institution', 'activeHousingAssignment.block', 'activeHousingAssignment.ward', 'activeHousingAssignment.bed']);
        $photo = $inmate->privateFiles()->where('category', 'photo')->latest()->first();

        return view('inmates.identity-card', [
            'inmate' => $inmate,
            'photo' => $photo,
            'activeHousing' => $inmate->activeHousingAssignment->first(),
        ]);
    }

    public function batch(Request $request): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.print') || auth()->user()?->hasPermission('admin.manage'), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'inmate_ids' => ['nullable', 'array', 'max:200'],
            'inmate_ids.*' => ['integer', 'exists:inmate_files,id'],
            'card_width' => ['nullable', 'numeric', 'min:50', 'max:150'],
            'card_height' => ['nullable', 'numeric', 'min:30', 'max:150'],
            'photo_width' => ['nullable', 'numeric', 'min:15', 'max:40'],
            'photo_height' => ['nullable', 'numeric', 'min:20', 'max:50'],
            'cards_per_row' => ['nullable', 'integer', Rule::in([2, 3, 4])],
            'institution_name' => ['nullable', 'string', 'max:80'],
        ]);

        $query = InmateFile::query()
            ->with([
                'institution',
                'activeHousingAssignment.block',
                'activeHousingAssignment.ward',
                'activeHousingAssignment.bed',
                'privateFiles' => fn ($query) => $query->where('category', 'photo')->latest(),
            ])
            ->where('is_active', true)
            ->when(isset($filters['q']), function ($query) use ($filters): void {
                $term = '%'.trim($filters['q']).'%';
                $query->where(fn ($query) => $query->where('full_name', 'like', $term)->orWhere('identity_number', 'like', $term));
            })
            ->orderBy('full_name');

        $inmates = $query->limit(1000)->get();
        $selectedIds = collect($filters['inmate_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $selected = $selectedIds === [] ? collect() : $inmates->whereIn('id', $selectedIds)->values();

        return view('inmates.identity-cards', [
            'inmates' => $inmates,
            'selected' => $selected,
            'selectedIds' => $selectedIds,
            'settings' => [
                'card_width' => $filters['card_width'] ?? 85.6,
                'card_height' => $filters['card_height'] ?? 53.98,
                'photo_width' => $filters['photo_width'] ?? 30,
                'photo_height' => $filters['photo_height'] ?? 40,
                'cards_per_row' => $filters['cards_per_row'] ?? 2,
                'institution_name' => $filters['institution_name'] ?? auth()->user()?->institution?->name ?? 'Ceza İnfaz Kurumu',
            ],
        ]);
    }

    private function photoPayload(Request $request): array
    {
        $upload = $request->file('photo_upload');
        if ($upload !== null && $upload->isValid()) {
            $realPath = $upload->getRealPath();
            abort_unless(is_string($realPath), 422);
            $content = file_get_contents($realPath);
            abort_unless(is_string($content), 422);
            $mimeType = $upload->getMimeType() ?: 'application/octet-stream';
            abort_unless(in_array($mimeType, ['image/jpeg', 'image/png'], true), 422);

            return [$content, $mimeType, $mimeType === 'image/png' ? 'png' : 'jpg', $upload->getClientOriginalName()];
        }

        $captured = trim((string) $request->input('captured_photo', ''));
        if ($captured === '') {
            return ['', '', '', ''];
        }

        if (! preg_match('/^data:(image\/(jpeg|png));base64,([A-Za-z0-9+\/]+=*)$/', $captured, $matches)) {
            throw ValidationException::withMessages(['captured_photo' => 'Webcam fotoğraf verisi geçersiz.']);
        }

        $content = base64_decode($matches[3], true);
        if (! is_string($content)) {
            throw ValidationException::withMessages(['captured_photo' => 'Webcam fotoğraf verisi okunamadı.']);
        }

        $mimeType = $matches[1];
        $extension = $matches[2] === 'png' ? 'png' : 'jpg';

        return [$content, $mimeType, $extension, 'webcam-capture.'.$extension];
    }

    private function isSafeStoragePath(string $path): bool
    {
        return Str::startsWith($path, self::BASE_DIRECTORY.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }
}
