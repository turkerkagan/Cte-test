<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EditPhoneDirectoryEntryRequest;
use App\Http\Requests\StorePhoneDirectoryEntryRequest;
use App\Models\Department;
use App\Models\Institution;
use App\Models\PhoneDirectoryEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PhoneDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $entries = PhoneDirectoryEntry::with(['department', 'institution'])
            ->when(! $request->routeIs('admin.*'), fn ($q) => $q->where('is_active', true))
            ->search($request->input('q'))
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString();

        return view('admin.phone-directory.index', [
            'entries' => $entries,
            'departments' => Department::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.phone-directory.create', $this->formData());
    }

    public function store(StorePhoneDirectoryEntryRequest $request): RedirectResponse
    {
        PhoneDirectoryEntry::create($this->entryData($request->validated()));

        return redirect()->route('admin.phone-directory.index')->with('status', 'Telefon rehberi kaydı oluşturuldu.');
    }

    public function edit(PhoneDirectoryEntry $entry): View
    {
        return view('admin.phone-directory.edit', $this->formData() + ['entry' => $entry]);
    }

    public function update(EditPhoneDirectoryEntryRequest $request, PhoneDirectoryEntry $entry): RedirectResponse
    {
        $entry->update($this->entryData($request->validated()));

        return redirect()->route('admin.phone-directory.index')->with('status', 'Telefon rehberi kaydı güncellendi.');
    }

    public function toggle(PhoneDirectoryEntry $entry): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('admin.manage'), 403);

        $entry->update(['is_active' => ! $entry->is_active]);

        return back()->with('status', 'Telefon rehberi kaydı durumu değiştirildi.');
    }

    private function formData(): array
    {
        return [
            'institutions' => Institution::orderBy('name')->get(),
            'departments' => Department::orderBy('sort_order')->orderBy('name')->get(),
        ];
    }

    private function entryData(array $data): array
    {
        return [
            'institution_id' => $data['institution_id'],
            'department_id' => $data['department_id'] ?? null,
            'title' => $data['title'],
            'person_name' => $data['person_name'] ?? null,
            'internal_number' => $data['internal_number'] ?? null,
            'external_number' => $data['external_number'] ?? null,
            'mobile_number' => $data['mobile_number'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
