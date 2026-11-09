<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    private const REDACTED_KEYS = ['password', 'token', 'secret', 'identity_number', 'full_name', 'notes', 'body', 'description'];

    public function record(string $eventType, ?Model $subject = null, array $metadata = [], ?Request $request = null): AuditEvent
    {
        return AuditEvent::create([
            'user_id' => Auth::id(),
            'inmate_file_id' => $metadata['inmate_file_id'] ?? null,
            'event_type' => $eventType,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_hash' => $request?->ip() ? hash('sha256', $request->ip()) : null,
            'user_agent_hash' => $request?->userAgent() ? hash('sha256', $request->userAgent()) : null,
            'metadata' => $this->redact($metadata),
        ]);
    }

    public function redact(array $metadata): array
    {
        return collect($metadata)->mapWithKeys(function ($value, $key): array {
            if (in_array((string) $key, self::REDACTED_KEYS, true)) {
                return [$key => '[redacted]'];
            }

            if (is_array($value)) {
                return [$key => $this->redact($value)];
            }

            return [$key => $value];
        })->all();
    }

    public function diff(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        return collect($keys)->filter(fn ($key) => Arr::get($before, $key) !== Arr::get($after, $key))->mapWithKeys(fn ($key) => [$key => ['before' => Arr::get($before, $key), 'after' => Arr::get($after, $key)]])->all();
    }
}
