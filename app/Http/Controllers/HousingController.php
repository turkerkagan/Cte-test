<?php

namespace App\Http\Controllers;

use App\Services\Housing\HousingConflict;
use App\Services\Housing\HousingDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class HousingController extends Controller
{
    public function index(Request $request, HousingDefinitions $service): Response
    {
        if ($request->expectsJson()) {
            return response()->json($service->snapshot($request->user()))->header('Cache-Control', 'private, no-store');
        }
        $response = Inertia::render('Housing', ['applicationName' => config('app.name'), 'baseUrl' => route('housing.index'), 'logoutUrl' => route('logout'), ...$service->snapshot($request->user())])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function bulk(Request $request, HousingDefinitions $service): Response
    {
        return response()->json($service->bulk($request->user(), $request->all()), 201)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $kind, HousingDefinitions $service): Response
    {
        return $this->save($request, $kind, $service);
    }

    public function update(Request $request, string $kind, int $id, HousingDefinitions $service): Response
    {
        return $this->save($request, $kind, $service, $id);
    }

    private function save(Request $request, string $kind, HousingDefinitions $service, ?int $id = null): Response
    {
        try {
            $saved = $service->save($request->user(), $kind, $request->all(), $id);
        } catch (HousingConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Yerleşim ayarı kaydedilemedi. Kodun benzersizliğini ve güncel yerleşimleri kontrol edin.']);
        }

        return response()->json(['id' => $saved], $id === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
