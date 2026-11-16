<?php

namespace App\Http\Controllers;

use App\Services\Housing\HousingConflict;
use App\Services\Housing\PersonPlacement;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PlacementController extends Controller
{
    public function edit(Request $request, int $person, PersonPlacement $service): Response
    {
        try {
            $detail = $service->detail($request->user(), $person);
        } catch (QueryException) {
            abort(503, 'Yerleşim yüklenemedi. Kurulum durumunu kontrol edip yeniden deneyin.');
        }
        if ($request->expectsJson()) {
            return response()->json($detail)->header('Cache-Control', 'private, no-store');
        }
        $response = Inertia::render('PlacementEditor', ['applicationName' => config('app.name'), 'baseUrl' => route('portal'), 'submitUrl' => route('placement.update', $person), 'logoutUrl' => route('logout'), ...$detail])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function update(Request $request, int $person, PersonPlacement $service): Response
    {
        try {
            $result = $service->save($request->user(), $person, $request->all());
        } catch (HousingConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Yerleşim kaydedilemedi. Güncel yerleşimleri kontrol edip yeniden deneyin.']);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
