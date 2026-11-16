<?php

namespace App\Http\Middleware;

use App\Services\Operations\OperationalState;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class MeasurePortalRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('operations.measure_requests')) {
            return $next($request);
        }
        $started = hrtime(true);
        $queries = 0;
        $queryMs = 0;
        DB::listen(function ($event) use (&$queries, &$queryMs) {
            $queries++;
            $queryMs += $event->time;
        });
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $error) {
            $status = match (true) {
                $error instanceof ValidationException => $error->status,
                $error instanceof AuthorizationException => $error->hasStatus() ? $error->status() : 403,
                $error instanceof AuthenticationException => 401,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            throw $error;
        } finally {
            try {
                // Route templates only: never URLs, query parameters, users, bindings or exception text.
                $route = $request->route();
                $key = $request->method().' '.($route?->getName() ?? $route?->uri() ?? 'unmatched');
                app(OperationalState::class)->request($key, $status, (hrtime(true) - $started) / 1e6, $queries, $queryMs);
            } catch (Throwable) {
                // Monitoring must not turn an otherwise successful business write into a failure.
            }
        }
    }
}
