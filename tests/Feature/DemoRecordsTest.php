<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_explicit_idempotent_and_preserves_existing_records_and_permissions(): void
    {
        User::factory()->create(['is_system_admin' => true]);
        $this->artisan('portal:demo-records')->assertFailed();
        $permissions = DB::table('page_permissions')->count();
        $this->artisan('portal:demo-records', ['--local-test' => true, '--with-pages' => true])->assertSuccessful();
        $this->assertDatabaseHas('portal_pages', ['code' => 'hukumlu_kayit', 'is_active' => true]);
        $this->assertDatabaseHas('portal_pages', ['code' => 'hassas_risk', 'is_active' => true]);
        $pageCount = DB::table('portal_pages')->count();
        $this->assertSame(5, DB::table('people')->where('full_name', 'like', '%(Deneme)')->count());
        $this->assertSame(5, DB::table('housing_occupancies')->count());
        $this->assertSame(5, DB::table('staff_nodes')->count());
        $users = User::where('username', 'like', 'deneme.personel%')->get();
        $this->assertCount(5, $users);
        foreach ($users as $user) {
            $this->assertFalse($user->is_system_admin);
            $this->assertTrue($user->must_change_password);
            $this->assertNotNull($user->department_id);
            $this->assertNotNull($user->job_title_id);
        }
        $before = $users->map->getAttributes()->all();
        $this->artisan('portal:demo-records', ['--local-test' => true, '--with-pages' => true])->assertSuccessful();
        $this->assertDatabaseCount('portal_pages', $pageCount);
        $this->assertSame($before, User::where('username', 'like', 'deneme.personel%')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('people', 5);
        $this->assertDatabaseCount('staff_nodes', 5);
        $this->assertSame($permissions, DB::table('page_permissions')->count());
    }
}
