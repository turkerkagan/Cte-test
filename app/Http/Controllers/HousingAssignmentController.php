<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditHousingAssignmentRequest;
use App\Http\Requests\StoreHousingAssignmentRequest;
use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Ward;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HousingAssignmentController extends Controller
{
    public function create(InmateFile $inmate): View
    {
        return view('housing-assignments.create', $this->formData($inmate));
    }

    public function store(StoreHousingAssignmentRequest $request, InmateFile $inmate): RedirectResponse
    {
        HousingAssignment::create($this->assignmentData($request->validated(), $inmate));

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Konum kaydı oluşturuldu.');
    }

    public function edit(InmateFile $inmate, HousingAssignment $assignment): View
    {
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        return view('housing-assignments.edit', $this->formData($inmate) + ['assignment' => $assignment]);
    }

    public function update(EditHousingAssignmentRequest $request, InmateFile $inmate, HousingAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        $assignment->update($this->assignmentData($request->validated(), $inmate));

        return redirect()->route('inmates.edit', $inmate)->with('status', 'Konum kaydı güncellendi.');
    }

    public function close(InmateFile $inmate, HousingAssignment $assignment): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.update') || auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($assignment->inmate_file_id === $inmate->id, 404);

        DB::transaction(function () use ($assignment): void {
            $assignment = $assignment->newQuery()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $assignment->ended_at) {
                $assignment->update(['ended_at' => now()->toDateString()]);
            }
        });

        return back()->with('status', 'Aktif konum kapatıldı.');
    }

    private function formData(InmateFile $inmate): array
    {
        return [
            'inmate' => $inmate,
            'blocks' => Block::orderBy('sort_order')->orderBy('name')->get(),
            'wards' => Ward::with('block')->orderBy('sort_order')->orderBy('name')->get(),
            'beds' => Bed::with('ward.block')->orderBy('sort_order')->orderBy('label')->get(),
        ];
    }

    private function assignmentData(array $data, InmateFile $inmate): array
    {
        return [
            'inmate_file_id' => $inmate->id,
            'block_id' => $data['block_id'],
            'ward_id' => $data['ward_id'],
            'bed_id' => $data['bed_id'] ?? null,
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ];
    }
}
