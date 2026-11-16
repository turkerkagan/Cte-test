<?php

namespace App\Http\Controllers;

use App\Services\Administration\AccountRequests;
use App\Services\Security\PortalReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class AccountRequestController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(app(PortalReadiness::class)->ready(), 503, 'İlk kurulum tamamlanmalıdır.');
        $kind = $r->route('request_kind');

        return $this->noStore(Inertia::render('AccountRequest', ['kind' => $kind, 'applicationName' => config('app.name'), 'submitUrl' => $r->url(), 'loginUrl' => route('login'), 'departments' => DB::table('departments')->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'jobTitles' => DB::table('job_titles')->where('is_active', true)->orderBy('name')->get(['id', 'name'])])->toResponse($r));
    }

    public function store(Request $r)
    {
        app(AccountRequests::class)->submit($r->route('request_kind'), $r->all());

        return redirect()->route('login')->with('notice', 'Talebiniz alındı. Kurum yöneticisi inceleyecek.');
    }
}
