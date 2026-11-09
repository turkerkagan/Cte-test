<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\LookupGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InmateShortcutController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('inmates.update'), 403);
        $filters = $request->validate([
            'from_status' => ['nullable', 'string', 'max:100'],
            'to_status' => ['nullable', 'string', 'max:100'],
        ]);
        $count = isset($filters['from_status'])
            ? InmateFile::where('status_code', $filters['from_status'])->count()
            : 0;

        if (isset($filters['from_status'], $filters['to_status'])) {
            $request->session()->put('status_transition_preview', [
                'from' => $filters['from_status'], 'to' => $filters['to_status'],
                'rows' => InmateFile::where('status_code', $filters['from_status'])->orderBy('id')->get(['id', 'updated_at'])->toArray(),
                'expires' => now()->addMinutes(10)->timestamp,
            ]);
        }

        return view('inmates.shortcuts', [
            'statuses' => $this->statuses(),
            'fromStatus' => $filters['from_status'] ?? null,
            'toStatus' => $filters['to_status'] ?? null,
            'affectedCount' => $count,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('inmates.update'), 403);
        $validated = $request->validate([
            'from_status' => ['required', 'string', 'max:100', 'different:to_status'],
            'to_status' => ['required', 'string', 'max:100'],
            'confirm_count' => ['required', 'integer', 'min:0'],
        ]);
        $preview = $request->session()->get('status_transition_preview');
        $updated = DB::transaction(function () use ($validated, $preview): int {
            $files = InmateFile::where('status_code', $validated['from_status'])->orderBy('id')->lockForUpdate()->get();
            $rows = $files->map(fn ($file) => ['id' => $file->id, 'updated_at' => $file->updated_at?->toJSON()])->all();
            if (! $preview || $preview['expires'] < now()->timestamp || $preview['from'] !== $validated['from_status'] || $preview['to'] !== $validated['to_status'] || $preview['rows'] !== $rows || $files->count() !== (int) $validated['confirm_count']) {
                throw ValidationException::withMessages(['confirm_count' => 'Önizleme geçersiz veya kayıtlar değişti. Lütfen yeniden önizleyin.']);
            }
            foreach ($files as $file) {
                $file->update(['status_code' => $validated['to_status']]);
            }

            return $files->count();
        }, 3);
        $request->session()->forget('status_transition_preview');

        return redirect()->route('inmates.shortcuts.index')->with('status', $updated.' hükümlünün durumu güncellendi.');
    }

    private function statuses()
    {
        $lookup = LookupGroup::where('code', 'inmate_status')->with('items')->first()?->items?->pluck('label', 'code') ?? collect();
        $existing = InmateFile::whereNotNull('status_code')->distinct()->orderBy('status_code')->pluck('status_code', 'status_code');

        return $lookup->union($existing);
    }
}
