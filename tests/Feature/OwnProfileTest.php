<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_update_only_own_name_and_email_without_changing_credentials_or_permissions(): void
    {
        $actor = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $before = $actor->getRawOriginal();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $view = $this->get('/parola')->assertOk()->assertInertia(fn (Assert $v) => $v->where('profile.username', $actor->username)->where('profile.revision', 1)->where('profileUrl', url('/profil')));
        $this->assertStringContainsString('no-store', $view->headers->get('Cache-Control'));
        $this->post('/profil', ['full_name' => 'SENTETİK PROFİL', 'email' => 'synthetic@example.invalid', 'revision' => 1])->assertRedirect('/parola')->assertSessionHas('notice');
        $fresh = $actor->fresh()->getRawOriginal();
        $this->assertSame('SENTETİK PROFİL', $fresh['full_name']);
        $this->assertSame('synthetic@example.invalid', $fresh['email']);
        $this->assertSame(2, $fresh['revision']);
        foreach (['password', 'username', 'is_system_admin', 'department_id', 'job_title_id', 'session_version', 'must_change_password'] as $field) {
            $this->assertSame($before[$field], $fresh[$field]);
        }
        $this->assertSame($other->full_name, $other->fresh()->full_name);
        $event = DB::table('audit_events')->where('action', 'profile_updated')->first();
        $this->assertSame($actor->id, $event->resource_id);
        $this->assertStringNotContainsString('synthetic@example.invalid', $event->details);
        $this->get('/profil.php')->assertRedirect('/parola');
    }

    public function test_profile_rejects_overposting_invalid_values_and_stale_revision(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $valid = ['full_name' => 'SENTETİK', 'email' => null, 'revision' => 1];
        $this->postJson('/profil', $valid + ['is_system_admin' => true, 'id' => 999])->assertUnprocessable();
        $this->postJson('/profil', [...$valid, 'full_name' => str_repeat('x', 151), 'email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'email']);
        $this->postJson('/profil', [...$valid, 'revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(1, $actor->fresh()->revision);
        $this->assertFalse($actor->fresh()->is_system_admin);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'profile_updated')->count());
    }

    public function test_mandatory_password_change_cannot_be_bypassed_by_profile_update(): void
    {
        $actor = User::factory()->create(['must_change_password' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $this->get('/parola')->assertInertia(fn (Assert $v) => $v->where('mustChangePassword', true));
        $this->post('/profil', ['full_name' => 'SENTETİK', 'email' => null, 'revision' => 1])->assertRedirect('/parola');
        $this->assertTrue($actor->fresh()->must_change_password);
        $this->assertSame(1, $actor->fresh()->revision);
    }
}
