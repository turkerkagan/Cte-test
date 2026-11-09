<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInstitutionProfileRequest;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class InstitutionProfileController extends Controller
{
    public function edit(): View
    {
        $institution = $this->institution();

        return view('admin.institution.edit', compact('institution'));
    }

    public function update(UpdateInstitutionProfileRequest $request): RedirectResponse
    {
        $institution = $this->institution();
        $data = $request->validated();

        unset($data['logo'], $data['remove_logo']);

        if ($request->boolean('remove_logo') && $institution->logo_path) {
            Storage::disk('public')->delete($institution->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($institution->logo_path) {
                Storage::disk('public')->delete($institution->logo_path);
            }

            $data['logo_path'] = $request->file('logo')->store('institution-logos', 'public');
        }

        $institution->update($data);

        return back()->with('status', 'Kurum profili güncellendi.');
    }

    private function institution(): Institution
    {
        return Institution::query()->orderBy('id')->firstOrFail();
    }
}
