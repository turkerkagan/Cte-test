<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLog;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PersonPhotoController extends Controller
{
    public function show(Request $request)
    {
        $person = (int) $request->route('person');
        app(WorkflowAccess::class)->authorize($request->user(), $request->route('document') ?? 'ilkgorus');
        $photo = DB::table('person_photos')->where('person_id', $person)->first();
        abort_if($photo === null || $photo->path === null || ! Storage::disk('local')->exists($photo->path), 404);

        $response = response()->file(Storage::disk('local')->path($photo->path), ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function save(Request $request)
    {
        $person = (int) $request->route('person');
        app(WorkflowAccess::class)->authorize($request->user(), $request->route('document') ?? 'ilkgorus', 'yazma');
        $deleting = $request->isMethod('DELETE');
        $request->validate(['revision' => ['required', 'integer', 'min:0'], 'photo' => $deleting ? ['prohibited'] : ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120', 'dimensions:max_width=8000,max_height=8000']]);
        $path = null;
        if (! $deleting) {
            $file = $request->file('photo');
            $path = $file->storeAs('person-photos', Str::uuid().'.'.$file->extension(), 'local');
            abort_if($path === false, 503, 'Fotoğraf kaydedilemedi.');
        }
        try {
            $result = DB::transaction(function () use ($request, $person, $path, $deleting): array {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
                abort_unless(DB::table('people')->where('id', $person)->lockForUpdate()->exists(), 404);
                $actor = app(WorkflowAccess::class)->authorize($request->user(), $request->route('document') ?? 'ilkgorus', 'yazma', true);
                $old = DB::table('person_photos')->where('person_id', $person)->lockForUpdate()->first();
                if ((int) ($old->revision ?? 0) !== (int) $request->input('revision')) {
                    return ['conflict' => true];
                }
                if ($deleting && $old?->path === null) {
                    return ['revision' => (int) ($old->revision ?? 0), 'old' => null];
                }
                $revision = (int) ($old->revision ?? 0) + 1;
                DB::table('person_photos')->updateOrInsert(['person_id' => $person], ['path' => $path, 'mime' => $path === null ? null : $request->file('photo')->getMimeType(), 'revision' => $revision, 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);
                app(AuditLog::class)->record($actor, 'person_photo', $person, $deleting ? 'deleted' : 'updated', ['revision' => $revision]);

                return ['revision' => $revision, 'old' => $old?->path];
            }, 3);
        } catch (\Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
        if ($result['conflict'] ?? false) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            return response()->json(['message' => 'Fotoğraf değişti; güncel kaydı yükleyin.'], 409)->header('Cache-Control', 'private, no-store');
        }
        if ($result['old'] !== null) {
            Storage::disk('local')->delete($result['old']);
        }

        return response()->json(['revision' => $result['revision']])->header('Cache-Control', 'private, no-store');
    }
}
