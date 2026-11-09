<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_own_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('profile.password.edit'))
            ->assertOk()
            ->assertSee('Şifrenizi değiştirin')
            ->assertSee('Kullanıcı İşlemleri');

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'Eski1234',
            'password' => 'Yeni5678',
            'password_confirmation' => 'Yeni5678',
        ])->assertRedirect(route('profile.password.edit'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('Yeni5678', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_current_password_and_confirmation_are_required(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'Yanlis1234',
            'password' => 'Yeni5678',
            'password_confirmation' => 'Yeni5678',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'Eski1234',
            'password' => 'Yeni5678',
            'password_confirmation' => 'Farkli5678',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Eski1234', $user->fresh()->password));
    }

    public function test_guest_cannot_open_password_change_page(): void
    {
        $this->get(route('profile.password.edit'))->assertRedirect(route('login'));
    }

    public function test_user_with_temporary_password_must_change_it_before_opening_portal(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('profile.password.edit'))
            ->assertSessionHas('password_notice');

        $this->actingAs($user)->get(route('profile.password.edit'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    }

    private function user(): User
    {
        $institution = Institution::create([
            'name' => 'Şifre Test Kurumu',
            'short_name' => 'ŞİFRE',
            'is_active' => true,
        ]);

        return User::create([
            'institution_id' => $institution->id,
            'full_name' => 'Şifre Test Kullanıcısı',
            'login_name' => 'sifre-test',
            'password' => 'Eski1234',
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }
}
