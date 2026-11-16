<?php

namespace App\Http\Controllers;

use App\Services\Administration\BuiltinMenu;
use App\Services\Modules\DefinitionConflict;
use App\Services\Navigation\MenuConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MenuController extends Controller
{
    public function index(Request $request, MenuConfiguration $service): Response
    {
        return Inertia::render('MenuEditor', ['applicationName' => config('app.name'), 'saveUrl' => route('menu.update'), 'logoutUrl' => route('logout'), 'builtinMenu' => app(BuiltinMenu::class)->state(), ...$service->state($request->user())]);
    }

    public function builtin(Request $request): JsonResponse
    {
        try {
            app(BuiltinMenu::class)->save($request->user(), $request->all());
        } catch (DefinitionConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, MenuConfiguration $service): JsonResponse
    {
        try {
            $service->save($request->user(), $request->all());
        } catch (DefinitionConflict) {
            return response()->json(['message' => 'Menü başka bir işlemde değişti. Girdileriniz korunuyor; güncel menüyü yeniden inceleyin.'], 409);
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Menü kaydedilemedi. Güncel hedefleri kontrol edip yeniden deneyin.']);
        }

        return response()->json($service->state($request->user()));
    }
}
