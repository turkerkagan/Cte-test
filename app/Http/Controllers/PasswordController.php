<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Security\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return $this->noStore(Inertia::render('Password', ['submitUrl' => route('password.update'), 'logoutUrl' => route('logout'), 'homeUrl' => route('portal'), 'messagesUrl' => route('personal-messages.index'), 'profileUrl' => route('profile.update'), 'applicationName' => config('app.name'), 'mustChangePassword' => $user->must_change_password, 'profile' => $user->only(['username', 'full_name', 'email', 'revision'])])->toResponse($request));
    }

    public function profile(Request $request): RedirectResponse
    {
        Validator::make(['profile' => $request->all()], ['profile' => ['required', 'array:full_name,email,revision']])->validate();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:100'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && ! $user->must_change_password && $user->session_version === $request->session()->get('auth_session_version'), 403);
            if ($user->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Profil başka bir işlemde değişti. Girdileriniz korunuyor; güncel profili inceleyip yeniden deneyin.']);
            }
            $user->full_name = $data['full_name'];
            $user->email = $data['email'] ?? null;
            $user->revision++;
            $user->save();
            app(AuditLog::class)->record($user, 'users', $user->id, 'profile_updated', ['fields' => ['full_name', 'email']]);
        });

        return redirect()->route('password.edit')->with('notice', 'Kişisel bilgileriniz kaydedildi.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => [...PasswordPolicy::rules(), 'confirmed', 'different:current_password'],
        ]);
        $version = DB::transaction(function () use ($request, $data): int {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_active || $user->session_version !== $request->session()->get('auth_session_version')) {
                abort(403);
            }
            if (! password_verify($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Mevcut parola doğrulanamadı.']);
            }
            $user->password = Hash::make($data['password']);
            $user->must_change_password = false;
            $user->session_version++;
            $user->revision++;
            $user->save();
            app(AuditLog::class)->record($user, 'users', $user->id, 'password_changed');

            return $user->session_version;
        });
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $version);

        return redirect()->route('portal');
    }
}
