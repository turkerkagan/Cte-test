<?php

namespace App\Http\Middleware;

use App\Services\Security\SessionGeneration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user->is_active || $request->session()->get('auth_session_version') !== $user->session_version
            || ! hash_equals(SessionGeneration::current(storage_path()), (string) $request->session()->get('auth_generation', ''))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => 'Oturum sonlandırıldı. Tekrar giriş yapın.']);
        }
        if ($user->must_change_password && ! $request->routeIs('password.edit', 'password.update', 'logout')) {
            return redirect()->route('password.edit');
        }

        $request->attributes->set('portal.display_access', []);
        try {
            return app(PortalActivity::class)->handle($request, $next);
        } finally {
            $request->attributes->remove('portal.display_access');
        }
    }
}
