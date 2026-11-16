<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ChatRooms
{
    private function room(User $actor, int $id): object
    {
        $r = DB::table('chat_rooms')->where('id', $id)->first();
        abort_if($r === null, 404);
        abort_unless($r->is_general || $r->owner_id === $actor->id || DB::table('chat_members')->where('room_id', $id)->where('user_id', $actor->id)->exists(), 403);

        return $r;
    }

    public function read(User $actor, array $input): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'sohbet');
        $d = Validator::make($input, ['room' => ['nullable', 'integer', 'min:1'], 'recipient' => ['nullable', 'integer', 'min:1']])->validate();
        foreach (['room', 'recipient'] as $key) {
            if (isset($d[$key])) {
                $d[$key] = (int) $d[$key];
            }
        }if (isset($d['room']) && isset($d['recipient'])) {
            throw ValidationException::withMessages(['room' => 'Oda veya özel konuşma seçin.']);
        }$rooms = DB::table('chat_rooms')->where(function ($q) use ($actor) {
            $q->where('is_general', true)->orWhere('owner_id', $actor->id)->orWhereIn('id', DB::table('chat_members')->where('user_id', $actor->id)->select('room_id'));
        })->orderBy('id')->get(['id', 'name', 'owner_id', 'is_general']);
        $room = $d['room'] ?? (isset($d['recipient']) ? null : $rooms->first()?->id);
        $recipient = $d['recipient'] ?? null;
        $messages = DB::table('chat_messages');
        if ($recipient !== null) {
            abort_unless(DB::table('users')->where('id', $recipient)->where('is_active', true)->exists() && $recipient !== $actor->id, 404);
            $messages->whereNull('room_id')->where(fn ($q) => $q->where(fn ($x) => $x->where('sender_id', $actor->id)->where('recipient_id', $recipient))->orWhere(fn ($x) => $x->where('sender_id', $recipient)->where('recipient_id', $actor->id)));
        } elseif ($room !== null) {
            $this->room($actor, (int) $room);
            $messages->where('room_id', $room);
        } else {
            $messages->whereRaw('1=0');
        }

        return ['canWrite' => app(WorkflowAccess::class)->canWrite($actor, 'sohbet'), 'rooms' => $rooms, 'users' => DB::table('users')->where('is_active', true)->where('id', '!=', $actor->id)->orderBy('full_name')->get(['id', 'full_name as name']), 'messages' => $messages->orderByDesc('id')->limit(80)->get(['id', 'sender_id', 'sender_name', 'body', 'created_at'])->reverse()->values(), 'room' => $room, 'recipient' => $recipient, 'actorId' => $actor->id];
    }

    public function mutate(User $actor, array $input): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'sohbet', 'yazma');
        $d = Validator::make($input, ['action' => ['required', 'in:send,create,invite'], 'body' => ['required_if:action,send', 'nullable', 'string', 'max:1000', 'regex:/\S/u'], 'name' => ['required_if:action,create', 'nullable', 'string', 'max:150', 'regex:/\S/u'], 'room' => ['required_if:action,invite', 'nullable', 'integer', 'min:1'], 'recipient' => ['nullable', 'integer', 'min:1'], 'members' => ['required_if:action,invite', 'nullable', 'array', 'list', 'max:200'], 'members.*' => ['integer', 'min:1', 'distinct']])->validate();
        foreach (['room', 'recipient'] as $key) {
            if (isset($d[$key])) {
                $d[$key] = (int) $d[$key];
            }
        }

        /** @var \Closure(): array{id: int} $operation */
        $operation = function () use ($actor, $d): array {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = app(WorkflowAccess::class)->authorize($actor, 'sohbet', 'yazma', true);
            $name = $actor->full_name ?: $actor->username;
            if ($d['action'] === 'send') {
                $room = $d['room'] ?? null;
                $recipient = $d['recipient'] ?? null;
                if (($room === null) === ($recipient === null)) {
                    throw ValidationException::withMessages(['room' => 'Bir oda veya alıcı seçin.']);
                }if ($room !== null) {
                    $this->room($actor, $room);
                }if ($recipient !== null) {
                    abort_unless($recipient !== $actor->id && DB::table('users')->where('id', $recipient)->where('is_active', true)->exists(), 422);
                }$id = DB::table('chat_messages')->insertGetId(['room_id' => $room, 'recipient_id' => $recipient, 'sender_id' => $actor->id, 'sender_name' => $name, 'body' => trim($d['body']), 'created_at' => now(), 'updated_at' => now()]);
                app(AuditLog::class)->record($actor, 'chat_message', $id, 'created', ['room_id' => $room, 'recipient_id' => $recipient]);

                return ['id' => (int) $id];
            }
            if ($d['action'] === 'create') {
                $room = DB::table('chat_rooms')->insertGetId(['name' => trim($d['name']), 'owner_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $r = $this->room($actor, $d['room']);
                abort_unless($r->is_general || $r->owner_id === $actor->id, 403);
                $room = $r->id;
            }
            foreach ($d['members'] ?? [] as $user) {
                abort_unless(DB::table('users')->where('id', $user)->where('is_active', true)->exists(), 422);
                DB::table('chat_members')->updateOrInsert(['room_id' => $room, 'user_id' => $user], []);
            }app(AuditLog::class)->record($actor, 'chat_room', $room, $d['action'], ['member_ids' => $d['members'] ?? []]);

            return ['id' => (int) $room];
        };

        return DB::transaction($operation, 3);
    }
}
