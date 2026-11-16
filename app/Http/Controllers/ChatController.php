<?php

namespace App\Http\Controllers;

use App\Services\Institution\ChatRooms;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ChatController extends Controller
{
    public function index(Request $r)
    {
        return $this->noStore(Inertia::render('Chat', app(ChatRooms::class)->read($r->user(), $r->all()) + ['applicationName' => config('app.name'), 'baseUrl' => route('chat.index'), 'logoutUrl' => route('logout')])->toResponse($r));
    }

    public function messages(Request $r)
    {
        return response()->json(app(ChatRooms::class)->read($r->user(), $r->all()))->header('Cache-Control', 'private, no-store');
    }

    public function mutate(Request $r)
    {
        return response()->json(app(ChatRooms::class)->mutate($r->user(), $r->all()))->header('Cache-Control', 'private, no-store');
    }
}
