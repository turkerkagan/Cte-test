<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditJobAssignmentRequest;
use App\Http\Requests\StoreWorkAssignmentRequest;
use App\Models\InmateFile;
use App\Models\LookupGroup;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkAssignmentController extends Controller
{
    public function create(InmateFile $inmate): View
    {
        return view('work-assignments.create', $this->formData($inmate));
    }

    public function store(StoreWorkAssignmentRequest $request, InmateFile $inmate): RedirectResponse
    {
        WorkAssignment::create($this->assignmentData($request->validated(), $inmate));

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Çalışma kaydı oluşturuldu.');
    }

    public function edit(InmateFile $inmate, WorkAssignment $assignment): View
    {
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        return view('work-assignments.edit', $this->formData($inmate) + ['assignment' => $assignment]);
    }

    public function update(EditJobAssignmentRequest $request, InmateFile $inmate, WorkAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        $assignment->update($this->assignmentData($request->validated(), $inmate));

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Çalışma kaydı güncellendi.');
    }

    public function close(InmateFile $inmate, WorkAssignment $assignment): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        DB::transaction(function () use ($assignment): void {
            $assignment = $assignment->newQuery()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $assignment->ended_at) {
                $assignment->update(['ended_at' => now()->toDateString()]);
            }
        });

        return back()->with('status', 'Aktif çalışma kaydı kapatıldı.');
    }

    private function formData(InmateFile $inmate): array
    {
        return [
            'inmate' => $inmate,
            'places' => WorkPlace::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'decisions' => LookupGroup::where('code', 'work_decision')->where('is_active', true)->with(['items' => fn ($q) => $q->where('is_active', true)])->first()?->items ?? collect(),
        ];
    }

    private function assignmentData(array $data, InmateFile $inmate): array
    {
        return [
            'inmate_file_id' => $inmate->id,
            'work_place_id' => $data['work_place_id'],
            'decision_code' => $data['decision_code'] ?? null,
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ];
    }
}
