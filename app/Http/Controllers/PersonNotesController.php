<?php

namespace App\Http\Controllers;

use App\Services\Access\PageAccess;
use App\Services\Audit\AuditLog;
use App\Services\People\PersonAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PersonNotesController extends Controller
{
    public function index(Request $r, int $person)
    {
        $actor = app(PersonAccess::class)->authorize($r->user());
        abort_unless(DB::table('people')->where('id', $person)->exists(), 404);
        $private = $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_ozel_not');
        $data = $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', 'in:all,general,private']]);
        $page = (int) ($data['page'] ?? 1);
        $kind = $data['kind'] ?? 'all';
        abort_if($kind === 'private' && ! $private, 403);
        $query = DB::table('person_notes')->where('person_id', $person)->when(! $private, fn ($q) => $q->where('is_private', false));
        if ($kind !== 'all') {
            $query->where('is_private', $kind === 'private');
        }
        $total = (clone $query)->count();
        $notes = $query->orderByDesc('id')->offset(($page - 1) * 100)->limit(100)->get(['id', 'body', 'is_private', 'author_name', 'created_at'])->map(function ($n) {
            $n->body = Crypt::decryptString($n->body);

            return $n;
        });

        return response()->json(['page' => $page, 'total' => $total, 'notes' => $notes, 'canWrite' => app(PersonAccess::class)->canWrite($actor), 'canWritePrivate' => $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_ozel_not', 'yazma')])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, int $person)
    {
        $data = Validator::make($r->all(), ['body' => ['required', 'string', 'max:10000', 'regex:/\S/u'], 'is_private' => ['required', 'boolean'], 'request_id' => ['required', 'uuid']])->validate();
        $result = DB::transaction(function () use ($r, $person, $data) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = app(PersonAccess::class)->authorize($r->user(), $data['is_private'] ? 'giris' : 'yazma', true);
            if ($data['is_private']) {
                abort_unless($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_ozel_not', 'yazma'), 403);
            }
            abort_unless(DB::table('people')->where('id', $person)->exists(), 404);
            $existing = DB::table('person_notes')->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                abort_unless($existing->person_id === $person && $existing->author_id === $actor->id && (bool) $existing->is_private === (bool) $data['is_private'] && Crypt::decryptString($existing->body) === $data['body'], 409);

                return ['id' => $existing->id];
            }
            $id = DB::table('person_notes')->insertGetId(['person_id' => $person, 'author_id' => $actor->id, 'author_name' => $actor->full_name ?: $actor->username, 'is_private' => $data['is_private'], 'body' => Crypt::encryptString($data['body']), 'request_id' => $data['request_id'], 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'person_note', $id, 'created', ['person_id' => $person, 'is_private' => $data['is_private']]);

            return ['id' => $id];
        }, 3);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
