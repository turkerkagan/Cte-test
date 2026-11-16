<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class FoundationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('portal');
        }

        return redirect()->route('login');
    }
}
