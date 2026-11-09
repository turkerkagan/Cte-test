<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EditJobPlaceRequest;
use App\Http\Requests\StoreJobPlaceRequest;
use App\Models\Institution;
use App\Models\WorkPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class JobPlaceController extends Controller
{
    public function index(): View
    {
        $places = WorkPlace::with('institution')->orderBy('sort_order')->orderBy('name')->paginate(30);

        return view('admin.job-places.index', compact('places'));
    }

    public function create(): View
    {
        return view('admin.job-places.create', $this->formData());
    }

    public function store(StoreJobPlaceRequest $request): RedirectResponse
    {
        WorkPlace::create($this->placeData($request->validated()));

        return redirect()->route('admin.job-places.index')->with('status', 'Çalışma yeri oluşturuldu.');
    }

    public function edit(WorkPlace $place): View
    {
        return view('admin.job-places.edit', $this->formData() + ['place' => $place]);
    }

    public function update(EditJobPlaceRequest $request, WorkPlace $place): RedirectResponse
    {
        $place->update($this->placeData($request->validated()));

        return redirect()->route('admin.job-places.index')->with('status', 'Çalışma yeri güncellendi.');
    }

    private function formData(): array
    {
        return ['institutions' => Institution::orderBy('name')->get()];
    }

    private function placeData(array $data): array
    {
        return [
            'institution_id' => $data['institution_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
