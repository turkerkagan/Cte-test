<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PermissionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->is_active && $user->is_protected_superadmin ? true : null;
        });

        Gate::define('permission', [PermissionPolicy::class, 'check']);
    }
}
