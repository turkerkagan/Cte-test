<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $current = $request->user() ? User::with('institution')->find($request->user()->id) : null;
        $version = (int) $current?->session_version;
        if ($request->user() && (! $current || ! $current->is_active || ! $current->institution?->is_active || ($request->session()->has('user_session_version') && (int) $request->session()->get('user_session_version') !== $version))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($current) {
            $request->session()->put('user_session_version', $version);
        }

        $response = $next($request);
        if ($request->user() && (! $request->user()->last_activity_at || $request->user()->last_activity_at->lt(now()->subMinute()))) {
            $request->user()->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
