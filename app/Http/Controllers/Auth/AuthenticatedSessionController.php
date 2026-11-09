<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        $institution = Institution::query()->orderBy('id')->first();

        return view('auth.login', compact('institution'));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = Str::lower(trim((string) $request->input('identifier'))).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['identifier' => 'Çok fazla hatalı deneme yapıldı. Lütfen kısa süre sonra tekrar deneyin.']);
        }

        $identifier = trim((string) $request->input('identifier'));
        $user = User::query()
            ->where('is_active', true)
            ->whereHas('institution', fn ($q) => $q->where('is_active', true))
            ->where(fn ($query) => $query->where('login_name', $identifier)->orWhere('personnel_number', $identifier))
            ->first();

        if (! $user || ! Auth::validate(['id' => $user->id, 'password' => $request->input('password')])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['identifier' => 'Giriş bilgileri doğrulanamadı.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('user_session_version', (int) $user->session_version);
        $user->forceFill(['last_login_at' => now(), 'last_activity_at' => now()])->save();
        RateLimiter::clear($key);

        // Giriş yapan herkes, önceki oturumda hedeflenen yönetim adresinden
        // bağımsız olarak kurumun hükümlü ana ekranına gelir.
        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()?->forceFill(['last_activity_at' => null])->saveQuietly();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
