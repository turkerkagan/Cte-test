<?php

namespace App\Http\Controllers;

use App\Services\Operations\PortalHealth;
use Inertia\Inertia;
use Inertia\Response;

final class PortalHealthController extends Controller
{
    public function __invoke(PortalHealth $health): Response
    {
        return Inertia::render('PortalHealth', ['applicationName' => config('app.name'), 'logoutUrl' => route('logout'), 'health' => $health->snapshot()]);
    }
}
