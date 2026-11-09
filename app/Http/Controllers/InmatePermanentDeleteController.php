<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Services\DeletionQuarantine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class InmatePermanentDeleteController extends Controller
{
    private const DISK = 'local';

    private const QUARANTINE_DIRECTORY = 'quarantine/permanent-delete';

    private const CONFIRM_TEXT = 'KALICI SIL';

    public function create(InmateFile $inmate): View
    {
        abort_unless(auth()->user()?->hasPermission('inmates.delete') || auth()->user()?->hasPermission('admin.manage'), 403);

        $inmate->loadCount(['housingAssignments', 'workAssignments', 'privateFiles']);

        return view('inmates.permanent-delete', [
            'inmate' => $inmate,
            'confirmText' => self::CONFIRM_TEXT,
        ]);
    }

    public function destroy(Request $request, InmateFile $inmate): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.delete') || auth()->user()?->hasPermission('admin.manage'), 403);

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'confirm_text' => ['required', 'string', 'in:'.self::CONFIRM_TEXT],
            'confirm_permanent' => ['accepted'],
        ]);

        if (! Hash::check($validated['password'], auth()->user()?->password ?? '')) {
            throw ValidationException::withMessages([
                'password' => 'Parola doğrulanamadı.',
            ]);
        }

        $movedFiles = [];

        try {
            DB::transaction(function () use ($inmate, &$movedFiles): void {
                $inmate = InmateFile::whereKey($inmate->id)->lockForUpdate()->firstOrFail();
                $inmate->load('privateFiles');

                foreach ($inmate->privateFiles as $file) {
                    if ($file->storage_disk !== self::DISK || ! $this->isSafePrivatePath($file->storage_path)) {
                        throw ValidationException::withMessages([
                            'delete' => 'Private dosya yolu güvenli değil. Silme işlemi durduruldu.',
                        ]);
                    }

                    if (! Storage::disk(self::DISK)->exists($file->storage_path)) {
                        continue;
                    }

                    $movedFiles[] = app(DeletionQuarantine::class)->move($file->storage_path);

                }

                $inmate->forceDelete();
            });
        } catch (Throwable $exception) {
            $this->restoreMovedFiles($movedFiles);
            throw $exception;
        }

        $this->purgeQuarantineFiles($movedFiles);

        return redirect()->route('inmates.index')->with('status', 'Hükümlü dosyası ve bağlı private dosyalar kalıcı olarak silindi.');
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
