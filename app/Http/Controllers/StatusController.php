<?php

namespace App\Http\Controllers;

use App\Services\Statuses\StatusConflict;
use App\Services\Statuses\StatusDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class StatusController extends Controller
{
    public function index(Request $request, StatusDefinitions $service): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['rows' => $service->rows($request->user())])->header('Cache-Control', 'private, no-store');
        }
        $response = Inertia::render('Statuses', ['applicationName' => config('app.name'), 'rows' => $service->rows($request->user()), 'baseUrl' => route('statuses.index'), 'logoutUrl' => route('logout')])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(Request $request, StatusDefinitions $service): Response
    {
        return $this->save($request, $service);
    }

    public function update(Request $request, int $id, StatusDefinitions $service): Response
    {
        return $this->save($request, $service, $id);
    }

    private function save(Request $request, StatusDefinitions $service, ?int $id = null): Response
    {
        try {
            $saved = $service->save($request->user(), $request->all(), $id);
        } catch (StatusConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Durum kaydedilemedi. Güncel durumu kontrol edip yeniden deneyin.']);
        }

        return response()->json(['id' => $saved], $id === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
