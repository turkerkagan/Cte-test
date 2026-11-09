<?php

namespace App\Http\Controllers;

use App\Support\ProductionSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class StatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = false;
        }
        $storage = is_dir(storage_path('app/private')) && is_writable(storage_path('app/private'));
        $security = ProductionSecurity::passes();
        $healthy = $database && $storage && (! app()->environment('production') || $security);

        return response()->json([
            'application' => config('app.name'),
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'private_storage' => $storage,
                'production_security' => $security,
            ],
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
