<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EditLookupGroupRequest;
use App\Http\Requests\EditLookupItemRequest;
use App\Http\Requests\StoreLookupGroupRequest;
use App\Http\Requests\StoreLookupItemRequest;
use App\Models\LookupGroup;
use App\Models\LookupItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LookupController extends Controller
{
    public function index(): View
    {
        $groups = LookupGroup::with('items')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.lookups.index', compact('groups'));
    }

    public function createGroup(): View
    {
        return view('admin.lookups.groups.create');
    }

    public function storeGroup(StoreLookupGroupRequest $request): RedirectResponse
    {
        LookupGroup::create($this->groupData($request->validated()));

        return redirect()->route('admin.lookups.index')->with('status', 'Tanım grubu oluşturuldu.');
    }

    public function editGroup(LookupGroup $group): View
    {
        return view('admin.lookups.groups.edit', compact('group'));
    }

    public function updateGroup(EditLookupGroupRequest $request, LookupGroup $group): RedirectResponse
    {
        $group->update($this->groupData($request->validated()));

        return redirect()->route('admin.lookups.index')->with('status', 'Tanım grubu güncellendi.');
    }

    public function createItem(LookupGroup $group): View
    {
        return view('admin.lookups.items.create', compact('group'));
    }

    public function storeItem(StoreLookupItemRequest $request, LookupGroup $group): RedirectResponse
    {
        $group->items()->create($this->itemData($request->validated()));

        return redirect()->route('admin.lookups.index')->with('status', 'Tanım kalemi oluşturuldu.');
    }

    public function editItem(LookupGroup $group, LookupItem $item): View
    {
        abort_unless($item->definition_group_id === $group->id, 404);

        return view('admin.lookups.items.edit', compact('group', 'item'));
    }

    public function updateItem(EditLookupItemRequest $request, LookupGroup $group, LookupItem $item): RedirectResponse
    {
        abort_unless($item->definition_group_id === $group->id, 404);

        $item->update($this->itemData($request->validated()));

        return redirect()->route('admin.lookups.index')->with('status', 'Tanım kalemi güncellendi.');
    }

    public function toggleGroup(LookupGroup $group): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('admin.manage'), 403);

        $group->update(['is_active' => ! $group->is_active]);

        return back()->with('status', 'Tanım grubu durumu değiştirildi.');
    }

    public function toggleItem(LookupGroup $group, LookupItem $item): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('admin.manage'), 403);
        abort_unless($item->definition_group_id === $group->id, 404);

        $item->update(['is_active' => ! $item->is_active]);

        return back()->with('status', 'Tanım kalemi durumu değiştirildi.');
    }

    private function groupData(array $validated): array
    {
        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    private function itemData(array $validated): array
    {
        return [
            'code' => $validated['code'],
            'label' => $validated['label'],
            'color' => $validated['color'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }
}
