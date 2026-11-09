<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InmatePrivateFileController extends Controller
{
    private const DISK = 'local';

    private const BASE_DIRECTORY = 'inmate-private-files';

    private const MAX_KILOBYTES = 10240;

    /** @var array<int, string> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function store(Request $request, InmateFile $inmate): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:photo,document'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'private_file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
            ],
        ]);

        $upload = $request->file('private_file');
        abort_if($upload === null || ! $upload->isValid(), 422);

        $mimeType = $upload->getMimeType() ?: 'application/octet-stream';
        abort_unless(in_array($mimeType, self::ALLOWED_MIME_TYPES, true), 422);

        $realPath = $upload->getRealPath();
        abort_unless(is_string($realPath) && is_file($realPath), 422);
        $sha256 = hash_file('sha256', $realPath);
        abort_unless(is_string($sha256), 422);

        $extension = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension() ?: 'bin');
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $storedName = (string) Str::uuid().'.'.$extension;
        $directory = self::BASE_DIRECTORY.'/'.$inmate->id;
        $storagePath = $directory.'/'.$storedName;

        $storedPath = Storage::disk(self::DISK)->putFileAs($directory, $upload, $storedName);
        abort_unless($storedPath === $storagePath && Storage::disk(self::DISK)->exists($storagePath), 500);

        try {
            InmatePrivateFile::create([
                'inmate_file_id' => $inmate->id,
                'uploaded_by' => auth()->id(),
                'category' => $validated['category'],
                'display_name' => $validated['display_name'] ?? null,
                'original_name' => $upload->getClientOriginalName(),
                'storage_disk' => self::DISK,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size' => $upload->getSize() ?: Storage::disk(self::DISK)->size($storagePath),
                'sha256' => $sha256,
                'extension' => $extension,
                'description' => $validated['description'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($storagePath);
            throw $exception;
        }

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Private dosya güvenli alana yüklendi.');
    }

    public function download(InmateFile $inmate, InmatePrivateFile $file): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.view') || auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($file->inmate_file_id === $inmate->id, 404);
        abort_unless($this->isSafeStoragePath($file->storage_path), 404);
        abort_unless($file->storage_disk === self::DISK, 404);
        abort_unless(Storage::disk(self::DISK)->exists($file->storage_path), 404);

        return Storage::disk(self::DISK)->download(
            $file->storage_path,
            $this->safeDownloadName($file),
            ['Content-Type' => $file->mime_type]
        );
    }

    private function isSafeStoragePath(string $path): bool
    {
        return Str::startsWith($path, self::BASE_DIRECTORY.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/');
    }

    private function safeDownloadName(InmatePrivateFile $file): string
    {
        $name = trim($file->display_name ?: $file->original_name);
        $name = str_replace(['/', '\\', "\0", "\r", "\n"], '-', $name);

        return $name !== '' ? $name : 'private-dosya-'.$file->id.'.'.$file->extension;
    }
}
