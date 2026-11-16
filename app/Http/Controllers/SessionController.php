<?php

namespace App\Http\Controllers;

use App\Services\Access\GuestAccess;
use App\Services\Administration\ManagementReports;
use App\Services\Security\PortalLogin;
use App\Services\Security\PortalReadiness;
use App\Services\Security\SessionGeneration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SessionController extends Controller
{
    public function create(PortalReadiness $readiness): Response
    {
        return Inertia::render('Login', ['applicationName' => config('app.name'), 'submitUrl' => route('login.store'), 'setupRequired' => ! $readiness->ready(), 'guestEnabled' => $readiness->ready() && app(GuestAccess::class)->enabled()]);
    }

    public function store(Request $request, PortalLogin $login, PortalReadiness $readiness): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:128']]);
        if (! $readiness->ready()) {
            throw ValidationException::withMessages(['username' => 'İlk kurulum tamamlanmamış. Kurulum sorumlusuna başvurun.']);
        }
        $user = $login->verify($data['username'], $data['password']);
        if ($user === null) {
            app(ManagementReports::class)->loginEvent(null, $data['username'], 'failed', $request->ip());
            throw ValidationException::withMessages(['username' => 'Kullanıcı adı veya parola doğrulanamadı.']);
        }
        app(ManagementReports::class)->loginEvent($user, $user->username, 'login', $request->ip());
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $user->session_version);
        $request->session()->put('auth_generation', SessionGeneration::current(storage_path()));

        return redirect()->route($user->must_change_password ? 'password.edit' : 'portal');
    }

    public function guest(Request $request): RedirectResponse
    {
        $access = app(GuestAccess::class);
        $user = $access->account();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $user->session_version);
        $request->session()->put('auth_generation', SessionGeneration::current(storage_path()));

        return redirect()->to($access->destination($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            app(ManagementReports::class)->loginEvent($request->user(), $request->user()->username, 'logout', $request->ip());
        }
        if ($request->user()) {
            DB::table('users')->where('id', $request->user()->id)->update(['last_activity_at' => null]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
