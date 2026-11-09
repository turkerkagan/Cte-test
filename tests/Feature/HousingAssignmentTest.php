<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Block;
use App\Models\Department;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_close_and_update_housing_assignment(): void
    {
        [$user, $file, $block, $ward, $bed] = $this->makeContext(['inmates.view', 'inmates.update']);

        $this->actingAs($user)->post(route('inmates.housing.store', $file), [
            'inmate_file_id' => $file->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed->id,
            'started_at' => '2026-06-22',
            'notes' => 'İlk yerleşim',
        ])->assertRedirect(route('inmates.edit', $file));

        $assignment = HousingAssignment::where('inmate_file_id', $file->id)->firstOrFail();
        $this->assertNull($assignment->ended_at);

        $this->actingAs($user)->patch(route('inmates.housing.close', [$file, $assignment]))->assertRedirect();
        $this->assertNotNull($assignment->fresh()->ended_at);

        $this->actingAs($user)->put(route('inmates.housing.update', [$file, $assignment]), [
            'inmate_file_id' => $file->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed->id,
            'started_at' => '2026-06-21',
            'ended_at' => '2026-06-22',
            'notes' => 'Güncellendi',
        ])->assertRedirect(route('inmates.edit', $file));

        $this->assertDatabaseHas('housing_assignments', ['id' => $assignment->id, 'notes' => 'Güncellendi']);
    }

    public function test_active_bed_conflict_is_rejected(): void
    {
        [$user, $file, $block, $ward, $bed, $otherFile] = $this->makeContext(['inmates.view', 'inmates.update']);
        HousingAssignment::create(['inmate_file_id' => $otherFile->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'bed_id' => $bed->id, 'started_at' => '2026-06-20']);

        $this->actingAs($user)->post(route('inmates.housing.store', $file), [
            'inmate_file_id' => $file->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed->id,
            'started_at' => '2026-06-22',
        ])->assertSessionHasErrors('bed_id');
    }

    public function test_active_file_conflict_is_rejected(): void
    {
        [$user, $file, $block, $ward, $bed] = $this->makeContext(['inmates.view', 'inmates.update']);
        HousingAssignment::create(['inmate_file_id' => $file->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'started_at' => '2026-06-20']);

        $this->actingAs($user)->post(route('inmates.housing.store', $file), [
            'inmate_file_id' => $file->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed->id,
            'started_at' => '2026-06-22',
        ])->assertSessionHasErrors('inmate_file_id');
    }

    private function makeContext(array $permissions): array
    {
        $institution = Institution::create(['short_name' => 'CTE', 'name' => 'Ceza İnfaz Kurumu', 'primary_color' => '#312e81', 'accent_color' => '#7c3aed', 'login_eyebrow' => 'Portal', 'login_heading' => 'Portal', 'login_security_note' => 'Yerel', 'is_active' => true]);
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
        $file = InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'Birinci Kayıt', 'is_active' => true]);
        $otherFile = InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'İkinci Kayıt', 'is_active' => true]);
        $block = Block::create(['institution_id' => $institution->id, 'code' => 'a', 'name' => 'A Blok', 'is_active' => true]);
        $ward = Ward::create(['block_id' => $block->id, 'code' => 'a1', 'name' => 'A1', 'capacity' => 10, 'is_active' => true]);
        $bed = Bed::create(['ward_id' => $ward->id, 'code' => 'r1', 'label' => 'Ranza 1', 'is_active' => true]);

        return [$user, $file, $block, $ward, $bed, $otherFile];
    }
}
