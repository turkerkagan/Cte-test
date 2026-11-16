<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLog;
use App\Services\People\PersonConflict;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class MealsController extends Controller
{
    public function index(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'yemek');
        $week = $r->validate(['week' => ['nullable', 'date_format:Y-m-d']])['week'] ?? now()->startOfWeek()->format('Y-m-d');

        return $this->noStore(Inertia::render('Meals', ['applicationName' => config('app.name'), 'baseUrl' => route('meals.index'), 'logoutUrl' => route('logout'), 'week' => $week, 'currentWeek' => now()->startOfWeek()->format('Y-m-d'), 'files' => DB::table('meal_files')->where('week', $week)->get(['id', 'type', 'name', 'mime', 'revision']), 'weeks' => DB::table('meal_files')->distinct()->orderByDesc('week')->pluck('week'), 'canWrite' => app(WorkflowAccess::class)->canWrite($r->user(), 'yemek')])->toResponse($r));
    }

    public function file(Request $r, int $file)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'yemek');
        $row = DB::table('meal_files')->where('id', $file)->first();
        abort_if($row === null || ! Storage::disk('local')->exists($row->path), 404);
        abort_unless(in_array($row->mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 415, 'Dosya türü desteklenmiyor.');
        $response = response()->file(Storage::disk('local')->path($row->path), ['Content-Type' => $row->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="meal-'.$row->id.'.'.(['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$row->mime]).'"']);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function save(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'yemek', 'yazma');
        $rules = ['week' => ['required', 'date_format:Y-m-d'], 'revisions' => ['required', 'array:weekly,diet,vegetarian']];
        foreach (['weekly', 'diet', 'vegetarian'] as $type) {
            $rules[$type] = ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'];
            $rules['revisions.'.$type] = ['required', 'integer', 'min:0'];
        }$d = $r->validate($rules);
        if ($d['week'] !== now()->startOfWeek()->format('Y-m-d')) {
            throw ValidationException::withMessages(['week' => 'Yükleme yalnız mevcut haftanın Pazartesi tarihi içindir.']);
        }$new = [];
        $old = [];
        try {
            foreach (['weekly', 'diet', 'vegetarian'] as $type) {
                if (! $r->hasFile($type)) {
                    continue;
                }$f = $r->file($type);
                $ext = strtolower($f->getClientOriginalExtension());
                if (($map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'])[$ext] ?? null) {
                    if ($map[$ext] !== ((new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath()))) {
                        throw ValidationException::withMessages([$type => 'Dosya uzantısı ve türü uyuşmuyor.']);
                    }
                } else {
                    throw ValidationException::withMessages([$type => 'Geçerli dosya uzantısı seçin.']);
                }$path = 'meals/'.Str::uuid().'.'.$ext;
                $stored = Storage::disk('local')->putFileAs('meals', $f, basename($path));
                if ($stored === false) {
                    throw new \RuntimeException('Dosya saklanamadı.');
                }$new[$type] = ['path' => $stored, 'mime' => $map[$ext], 'name' => mb_substr(basename($f->getClientOriginalName()), 0, 180)];
            }if (! $new) {
                throw ValidationException::withMessages(['files' => 'En az bir dosya seçin.']);
            }
            DB::transaction(function () use ($r, $d, $new, &$old) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
                $a = app(WorkflowAccess::class)->authorize($r->user(), 'yemek', 'yazma', true);
                foreach ($new as $type => $f) {
                    $row = DB::table('meal_files')->where('week', $d['week'])->where('type', $type)->lockForUpdate()->first();
                    if ((int) ($row->revision ?? 0) !== (int) $d['revisions'][$type]) {
                        throw new PersonConflict;
                    }if ($row) {
                        $old[] = $row->path;
                    }DB::table('meal_files')->updateOrInsert(['week' => $d['week'], 'type' => $type], $f + ['revision' => (int) ($row->revision ?? 0) + 1, 'created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
                }app(AuditLog::class)->record($a, 'meal_files', 0, 'updated', ['week' => $d['week'], 'types' => array_keys($new)]);
            }, 3);
        } catch (\Throwable $e) {
            foreach ($new as $f) {
                Storage::disk('local')->delete($f['path']);
            }if ($e instanceof PersonConflict) {
                return response()->json(['message' => $e->getMessage()], 409);
            }throw $e;
        }foreach ($old as $path) {
            Storage::disk('local')->delete($path);
        }

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }
}
