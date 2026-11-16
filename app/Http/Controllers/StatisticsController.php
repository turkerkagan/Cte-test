<?php

namespace App\Http\Controllers;

use App\Services\Institution\PersonStatistics;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class StatisticsController extends Controller
{
    public function index(Request $r)
    {
        $kind = $r->route('statistics');

        return $this->noStore(Inertia::render('Statistics', app(PersonStatistics::class)->read($r->user(), $kind) + ['applicationName' => config('app.name'), 'logoutUrl' => route('logout')])->toResponse($r));
    }
}
