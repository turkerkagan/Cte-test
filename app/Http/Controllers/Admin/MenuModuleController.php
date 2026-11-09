<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Models\MenuItem;
use App\Models\Module;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MenuModuleController extends Controller
{
    public function index(): View
    {
        $menuItems = MenuItem::query()
            ->with('parent')
            ->orderByRaw('parent_id is not null')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $modules = Module::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.menus.index', compact('menuItems', 'modules'));
    }

    public function create(): View
    {
        return view('admin.menus.create', $this->formData());
    }

    public function store(StoreMenuItemRequest $request): RedirectResponse
    {
        MenuItem::create($this->menuData($request->validated()));

        return redirect()->route('admin.menus.index')->with('status', 'Menü kaydı oluşturuldu.');
    }

    public function edit(MenuItem $menu): View
    {
        return view('admin.menus.edit', $this->formData($menu) + ['menuItem' => $menu]);
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menu): RedirectResponse
    {
        $menu->update($this->menuData($request->validated()));

        return redirect()->route('admin.menus.index')->with('status', 'Menü kaydı güncellendi.');
    }

    public function destroy(MenuItem $menu): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('admin.manage'), 403);

        $menu->children()->update(['parent_id' => null]);
        $menu->delete();

        return redirect()->route('admin.menus.index')->with('status', 'Menü kaydı silindi.');
    }

    private function formData(?MenuItem $current = null): array
    {
        return [
            'parents' => MenuItem::query()
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get(),
            'permissions' => Permission::query()->orderBy('module')->orderBy('action')->get(),
            'modules' => Module::query()->orderBy('sort_order')->orderBy('name')->get(),
        ];
    }

    private function menuData(array $validated): array
    {
        return [
            'parent_id' => $validated['parent_id'] ?? null,
            'module_code' => $validated['module_code'] ?: null,
            'title' => $validated['title'],
            'icon' => $validated['icon'] ?: null,
            'route_name' => $validated['route_name'] ?: null,
            'permission_key' => $validated['permission_key'] ?: null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'is_visible' => (bool) ($validated['is_visible'] ?? false),
        ];
    }
}
