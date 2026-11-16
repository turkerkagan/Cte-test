<?php

use App\Models\User;
use App\Services\Administration\SqlExports;
use App\Services\Operations\OperationalState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Application commands are discovered from app/Console/Commands.

Schedule::call(function () {
    app(OperationalState::class)->result('scheduler', true);
})->everyMinute()->name('portal.scheduler-heartbeat');

Schedule::command('cte:administration-backup --scheduled')->everyMinute()->withoutOverlapping(120);
Schedule::command('portal:clean-private-documents')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('cte:prune-logs', function () {
    foreach (['audit_events', 'portal_login_events'] as $table) {
        DB::table($table)->where('created_at', '<', now()->subDays(20))->delete();
    }
    $this->info('20 günden eski işlem ve giriş kayıtları temizlendi.');
});
Schedule::command('cte:prune-logs')->daily()->withoutOverlapping();

Artisan::command('cte:recovery-schema', function () {
    $actor = User::where('is_system_admin', true)->where('is_active', true)->where('must_change_password', false)->firstOrFail();
    $result = app(SqlExports::class)->recoverySchema($actor);
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
});
