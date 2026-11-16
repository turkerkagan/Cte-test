<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PortalActivity
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->is_guest) {
            abort_if(! in_array($request->method(), ['GET', 'HEAD']) && ! $request->routeIs('logout'), 403, 'Misafir oturumu yalnız okuma içindir.');
        }
        $response = $next($request);
        if ($user && ! $user->must_change_password && $response->getStatusCode() < 400) {
            if (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinute())) {
                DB::table('users')->where('id', $user->id)->update(['last_activity_at' => now()]);
            }
            if ($request->isMethod('GET') && ! $request->wantsJson() && ! $request->routeIs('portal.indicators') && ! $request->headers->has('Purpose')) {
                $route = $request->route()?->getName() ?? 'page';
                if ($request->session()->get('activity_page') !== $route || $request->session()->get('activity_time', 0) < time() - 60) {
                    app(AuditLog::class)->record($user, 'page_visit', 0, 'opened', ['page' => $route]);
                    $request->session()->put(['activity_page' => $route, 'activity_time' => time()]);
                }
            }
        }

        return $response;
    }
}
