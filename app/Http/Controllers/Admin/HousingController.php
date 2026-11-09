<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EditBedRequest;
use App\Http\Requests\EditHousingBlockRequest;
use App\Http\Requests\EditWardRequest;
use App\Http\Requests\StoreBedRequest;
use App\Http\Requests\StoreHousingBlockRequest;
use App\Http\Requests\StoreWardRequest;
use App\Models\Bed;
use App\Models\Block;
use App\Models\Institution;
use App\Models\Ward;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HousingController extends Controller
{
    public function index(): View
    {
        $blocks = Block::with('wards.beds')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.housing.index', compact('blocks'));
    }

    public function createBlock(): View
    {
        return view('admin.housing.blocks.create', ['institutions' => Institution::orderBy('name')->get()]);
    }

    public function storeBlock(StoreHousingBlockRequest $request): RedirectResponse
    {
        Block::create($this->blockData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Blok oluşturuldu.');
    }

    public function editBlock(Block $block): View
    {
        return view('admin.housing.blocks.edit', ['block' => $block, 'institutions' => Institution::orderBy('name')->get()]);
    }

    public function updateBlock(EditHousingBlockRequest $request, Block $block): RedirectResponse
    {
        $block->update($this->blockData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Blok güncellendi.');
    }

    public function createWard(): View
    {
        return view('admin.housing.wards.create', ['blocks' => Block::orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeWard(StoreWardRequest $request): RedirectResponse
    {
        Ward::create($this->wardData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Koğuş oluşturuldu.');
    }

    public function editWard(Ward $ward): View
    {
        return view('admin.housing.wards.edit', ['ward' => $ward, 'blocks' => Block::orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function updateWard(EditWardRequest $request, Ward $ward): RedirectResponse
    {
        $ward->update($this->wardData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Koğuş güncellendi.');
    }

    public function createBed(): View
    {
        return view('admin.housing.beds.create', ['wards' => Ward::with('block')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeBed(StoreBedRequest $request): RedirectResponse
    {
        Bed::create($this->bedData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Ranza oluşturuldu.');
    }

    public function editBed(Bed $bed): View
    {
        return view('admin.housing.beds.edit', ['bed' => $bed, 'wards' => Ward::with('block')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function updateBed(EditBedRequest $request, Bed $bed): RedirectResponse
    {
        $bed->update($this->bedData($request->validated()));

        return redirect()->route('admin.housing.index')->with('status', 'Ranza güncellendi.');
    }

    private function blockData(array $data): array
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

    private function wardData(array $data): array
    {
        return [
            'block_id' => $data['block_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'capacity' => (int) $data['capacity'],
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    private function bedData(array $data): array
    {
        return [
            'ward_id' => $data['ward_id'],
            'code' => $data['code'],
            'label' => $data['label'],
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
