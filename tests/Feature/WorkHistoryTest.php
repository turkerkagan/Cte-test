<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_job_place(): void
    {
        [$user, $institution] = $this->makeContext(['admin.manage']);

        $this->actingAs($user)->post(route('admin.job-places.store'), [
            'institution_id' => $institution->id,
            'code' => 'atolye',
            'name' => 'Atölye',
            'description' => 'Mutfak birimi',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.job-places.index'));

        $place = WorkPlace::where('code', 'atolye')->firstOrFail();

        $this->actingAs($user)->put(route('admin.job-places.update', $place), [
            'institution_id' => $institution->id,
            'code' => 'atolye',
            'name' => 'Atölye Güncel',
            'description' => '',
            'sort_order' => 20,
            'is_active' => '1',
        ])->assertRedirect(route('admin.job-places.index'));

        $this->assertDatabaseHas('work_places', ['id' => $place->id, 'name' => 'Atölye Güncel', 'sort_order' => 20]);
    }

    public function test_user_can_create_close_and_update_job_assignment(): void
    {
        [$user, , $file, $place] = $this->makeContext(['inmates.view', 'inmates.update']);

        $this->actingAs($user)->post(route('inmates.jobs.store', $file), [
            'inmate_file_id' => $file->id,
            'work_place_id' => $place->id,
            'decision_code' => 'approved',
            'started_at' => '2026-06-22',
            'notes' => 'İlk kayıt',
        ])->assertRedirect(route('inmates.edit', $file));

        $assignment = WorkAssignment::where('inmate_file_id', $file->id)->firstOrFail();

        $this->actingAs($user)->patch(route('inmates.jobs.close', [$file, $assignment]))->assertRedirect();
        $this->assertNotNull($assignment->fresh()->ended_at);

        $this->actingAs($user)->put(route('inmates.jobs.update', [$file, $assignment]), [
            'inmate_file_id' => $file->id,
            'work_place_id' => $place->id,
            'decision_code' => 'closed',
            'started_at' => '2026-06-21',
            'ended_at' => '2026-06-22',
            'notes' => 'Güncellendi',
        ])->assertRedirect(route('inmates.edit', $file));

        $this->assertDatabaseHas('work_assignments', ['id' => $assignment->id, 'decision_code' => 'closed', 'notes' => 'Güncellendi']);
    }

    public function test_second_active_job_assignment_for_same_file_is_rejected(): void
    {
        [$user, , $file, $place] = $this->makeContext(['inmates.view', 'inmates.update']);
        WorkAssignment::create(['inmate_file_id' => $file->id, 'work_place_id' => $place->id, 'started_at' => '2026-06-20']);

        $this->actingAs($user)->post(route('inmates.jobs.store', $file), [
            'inmate_file_id' => $file->id,
            'work_place_id' => $place->id,
            'started_at' => '2026-06-22',
        ])->assertSessionHasErrors('inmate_file_id');
    }

    private function makeContext(array $permissions): array
    {
        $institution = Institution::create(['short_name' => 'CTE', 'name' => 'Ceza İnfaz Kurumu', 'primary_color' => '#312e81', 'accent_color' => '#7c3aed', 'login_eyebrow' => 'Portal', 'login_heading' => 'Portal', 'login_security_note' => 'Yerel', 'is_active' => true]);
        $department = Department::create(['institution_id' => $institution->id, 'code' => 'infaz', 'name' => 'İnfaz']);
        $role = Role::create(['institution_id' => $institution->id, 'code' => 'test-role', 'name' => 'Test Rol', 'is_active' => true]);
        $ids = [];
        foreach ($permissions as $key) {
            [$module, $action] = explode('.', $key, 2);
            $permission = Permission::create(['key' => $key, 'module' => $module, 'action' => $action, 'label' => $key, 'is_sensitive' => false]);
            $ids[] = $permission->id;
        }
        $role->syncPermissionsWithCacheClear($ids);
        $user = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $role->id, 'is_active' => true]);
        $file = InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'Test Kayıt', 'is_active' => true]);
        $place = WorkPlace::create(['institution_id' => $institution->id, 'code' => 'mutfak', 'name' => 'Mutfak', 'is_active' => true]);

        return [$user, $institution, $file, $place];
    }
}
