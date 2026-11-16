<?php

namespace App\Http\Controllers;

use App\Services\Organization\StaffTree;
use App\Services\People\PersonConflict;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class StaffTreeController extends Controller
{
    public function index(Request $r)
    {
        if ($r->expectsJson()) {
            return response()->json(app(StaffTree::class)->read($r->user()))->header('Cache-Control', 'private, no-store');
        }

        return $this->noStore(Inertia::render('StaffTree', app(StaffTree::class)->read($r->user()) + ['applicationName' => config('app.name'), 'management' => (bool) $r->route('management'), 'baseUrl' => $r->route('management') ? route('management.staff') : route('staff-tree.index'), 'logoutUrl' => route('logout')])->toResponse($r));
    }

    public function save(Request $r, ?int $node = null)
    {
        try {
            $result = app(StaffTree::class)->save($r->user(), $node, $r->all());
        } catch (PersonConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
