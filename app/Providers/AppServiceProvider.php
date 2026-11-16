<?php

namespace App\Providers;

use App\Console\SafeMigrations\FreshCommand;
use App\Models\User;
use App\Services\Imports\ImportTargetGuard;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend(\Illuminate\Database\Console\Migrations\FreshCommand::class,
            fn ($command, $app) => new FreshCommand($app['migrator']));
        $this->app->extend(WipeCommand::class,
            fn () => new \App\Console\SafeMigrations\WipeCommand);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(ImportTargetGuard::class)->assertSafe();
        Gate::define('manage-organization', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
        Gate::define('manage-users', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
        Gate::define('manage-housing', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
        Gate::define('manage-people', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
        Gate::define('manage-modules', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
        Gate::define('manage-page-access', fn (User $user): bool => $user->is_active && $user->is_system_admin && ! $user->must_change_password);
    }
}
