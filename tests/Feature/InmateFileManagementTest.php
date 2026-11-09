<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Block;
use App\Models\Department;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use App\Models\WorkPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InmateFileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_update_and_search_main_file(): void
    {
        [$user, $institution] = $this->makeContext(['inmates.view', 'inmates.create', 'inmates.update']);

        $this->actingAs($user)->post(route('inmates.store'), [
            'institution_id' => $institution->id,
            'identity_number' => '12345678901',
            'full_name' => 'Test Kaydı',
            'registered_at' => '2026-06-22',
            'entered_at' => '2026-06-22',
            'status_code' => 'active',
            'risk_code' => 'low',
            'decision_code' => 'open',
            'notes' => 'Test notu',
            'legacy_id' => 55,
            'is_active' => '1',
        ])->assertRedirect();

        $file = InmateFile::where('identity_number', '12345678901')->firstOrFail();

        $this->actingAs($user)->put(route('inmates.update', $file), [
            'institution_id' => $institution->id,
            'identity_number' => '12345678901',
            'full_name' => 'Test Kaydı Güncel',
            'registered_at' => '2026-06-22',
            'entered_at' => '2026-06-22',
            'status_code' => 'passive',
            'risk_code' => 'high',
            'decision_code' => 'closed',
            'notes' => '',
            'legacy_id' => 56,
            'is_active' => '1',
        ])->assertRedirect(route('inmates.edit', $file));

        $this->assertDatabaseHas('inmate_files', ['id' => $file->id, 'full_name' => 'Test Kaydı Güncel', 'legacy_id' => 56]);

        $this->actingAs($user)->get(route('inmates.index', ['q' => 'Güncel']))->assertOk()->assertSee('Test Kaydı Güncel');
    }

    public function test_identity_number_is_optional_but_unique_when_present(): void
    {
        [$user, $institution] = $this->makeContext(['inmates.view', 'inmates.create']);

        InmateFile::create(['institution_id' => $institution->id, 'identity_number' => '12345678901', 'full_name' => 'Birinci', 'is_active' => true]);

        $this->actingAs($user)->post(route('inmates.store'), [
            'institution_id' => $institution->id,
            'identity_number' => null,
            'full_name' => 'TC Yok',
            'is_active' => '1',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('inmates.store'), [
            'institution_id' => $institution->id,
            'identity_number' => '12345678901',
            'full_name' => 'Mükerrer',
            'is_active' => '1',
        ])->assertSessionHasErrors('identity_number');
    }

    public function test_old_index_style_form_creates_file_location_and_work_in_one_transaction(): void
    {
        [$user, $institution] = $this->makeContext(['inmates.view', 'inmates.create']);
        $block = Block::create(['institution_id' => $institution->id, 'code' => 'a', 'name' => 'A', 'is_active' => true]);
        $ward = Ward::create(['block_id' => $block->id, 'code' => '1', 'name' => '1', 'is_active' => true]);
        $bed = Bed::create(['ward_id' => $ward->id, 'label' => '1', 'is_active' => true]);
        $place = WorkPlace::create(['institution_id' => $institution->id, 'code' => 'mutfak', 'name' => 'MUTFAK', 'is_active' => true]);

        $this->actingAs($user)->post(route('inmates.store'), [
            'institution_id' => $institution->id,
            'identity_number' => '11111111111',
            'full_name' => 'Tek Form Kaydı',
            'registered_at' => '2026-06-22',
            'status_code' => 'YENİ KAYIT',
            'risk_code' => '1',
            'decision_code' => 'İŞYURTLARI',
            'health_status' => 'SAĞLIKLI',
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed->id,
            'work_place_id' => $place->id,
            'is_active' => '1',
        ])->assertRedirect();

        $file = InmateFile::where('identity_number', '11111111111')->firstOrFail();
        $this->assertDatabaseHas('housing_assignments', ['inmate_file_id' => $file->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'bed_id' => $bed->id]);
        $this->assertDatabaseHas('work_assignments', ['inmate_file_id' => $file->id, 'work_place_id' => $place->id, 'decision_code' => 'İŞYURTLARI']);
    }

    public function test_user_without_permission_cannot_view_main_files(): void
    {
        [$user] = $this->makeContext(['dashboard.view']);

        $this->actingAs($user)->get(route('inmates.index'))->assertForbidden();
    }

    private function makeContext(array $permissions): array
    {
        $institution = Institution::create([
            'short_name' => 'CTE',
            'name' => 'Ceza İnfaz Kurumu',
            'primary_color' => '#312e81',
            'accent_color' => '#7c3aed',
            'login_eyebrow' => 'Kurum içi yönetim sistemi',
            'login_heading' => 'Bilgiye hızlı erişim',
            'login_security_note' => 'Veriler kurum sunucusundan dışarı çıkmaz.',
            'is_active' => true,
        ]);
        $department = Department::create(['institution_id' => $institution->id, 'code' => 'infaz', 'name' => 'İnfaz']);
        $role = Role::create(['institution_id' => $institution->id, 'code' => 'test-role', 'name' => 'Test Rol', 'is_active' => true]);

        $permissionIds = [];
        foreach ($permissions as $key) {
            [$module, $action] = explode('.', $key, 2);
            $permission = Permission::create(['key' => $key, 'module' => $module, 'action' => $action, 'label' => $key, 'is_sensitive' => false]);
            $permissionIds[] = $permission->id;
        }
        $role->syncPermissionsWithCacheClear($permissionIds);

        $user = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $role->id, 'is_active' => true]);

        return [$user, $institution];
    }
}
