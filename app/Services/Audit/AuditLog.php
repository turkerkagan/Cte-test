<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AuditLog
{
    public function record(User $actor, string $resource, int $id, string $action, array $details = []): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'resource_type' => $resource, 'resource_id' => $id,
            'action' => $action, 'details' => json_encode($details + ['actor_username' => $actor->username, 'actor_name' => $actor->full_name, 'ip_address' => app()->runningInConsole() ? null : request()->ip()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
