<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestEnvironmentInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_test_database_gets_an_administrator(): void
    {
        $this->artisan('cte:initialize-test')->assertSuccessful();

        $admin = User::where('login_name', 'türker')->firstOrFail();
        $this->assertTrue($admin->is_protected_superadmin);
        $this->assertTrue(Hash::check('cte-test-admin-password', $admin->password));
    }

    public function test_repeated_initialization_preserves_existing_password(): void
    {
        $this->seed();
        $admin = User::where('login_name', 'türker')->firstOrFail();
        $admin->update(['password' => 'changed-test-password']);
        $count = User::count();

        $this->artisan('cte:initialize-test')
            ->expectsOutput('Existing users found; initialization skipped.')
            ->assertSuccessful();

        $this->assertSame($count, User::count());
        $this->assertTrue(Hash::check('changed-test-password', $admin->fresh()->password));
    }
}
