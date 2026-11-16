<?php

namespace App\Http\Controllers;

use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\OptionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OptionCatalogController extends Controller
{
    public function index(Request $request, OptionCatalog $catalog): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['lists' => $catalog->all()])->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('OptionLists', ['applicationName' => config('app.name'), 'lists' => $catalog->all(), 'baseUrl' => route('options.index'), 'modulesUrl' => route('modules.index'), 'logoutUrl' => route('logout')]);
    }

    public function store(Request $request, OptionCatalog $catalog): RedirectResponse|JsonResponse
    {
        $id = $this->mutate(fn () => $catalog->create($request->user(), $request->all()));

        return $request->expectsJson() ? response()->json(['id' => $id], 201) : redirect()->route('options.index');
    }

    public function update(Request $request, int $id, OptionCatalog $catalog): RedirectResponse|JsonResponse
    {
        try {
            $this->mutate(fn () => $catalog->save($request->user(), $id, $request->all()));
        } catch (DefinitionConflict $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw ValidationException::withMessages(['revision' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['saved' => true]) : redirect()->route('options.index');
    }

    private function mutate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['operation' => 'Seçenek listesi kaydedilemedi. Güncel tanımı kontrol edip tekrar deneyin.']);
        }
    }
}
