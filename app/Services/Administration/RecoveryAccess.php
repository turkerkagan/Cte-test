<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;

final class RecoveryAccess
{
    public function issue(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);
        $token = bin2hex(random_bytes(32));
        $root = storage_path('app/private/administration/emergency');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $file = $root.'/access.json';
        file_put_contents($file, json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 600, 'actor_id' => $actor->id, 'issued_at' => time()], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($file, 0600);
        app(AuditLog::class)->record($actor, 'administration_recovery', 0, 'key_issued');

        return ['recovery_key' => $token, 'expires_in_minutes' => 10, 'url' => url('/recovery.php')];
    }
}
