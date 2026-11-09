<?php

namespace Tests\Feature;

use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InmatePermanentDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_permanent_delete_requires_password_and_double_confirmation(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $response = $this->actingAs($user)
            ->from(route('inmates.permanent-delete.create', $inmate))
            ->delete(route('inmates.permanent-delete.destroy', $inmate), [
                'password' => 'wrong-password',
                'confirm_text' => 'KALICI SIL',
                'confirm_permanent' => '1',
            ]);

        $response->assertRedirect(route('inmates.permanent-delete.create', $inmate));
        $response->assertSessionHasErrors('password');
        $this->assertDatabaseHas('inmate_files', ['id' => $inmate->id, 'deleted_at' => null]);
    }

    public function test_unauthorized_user_cannot_access_permanent_delete(): void
    {
        $user = $this->standardUser();
        $inmate = $this->inmateFile();

        $this->actingAs($user)->get(route('inmates.permanent-delete.create', $inmate))->assertForbidden();
        $this->actingAs($user)->delete(route('inmates.permanent-delete.destroy', $inmate), [
            'password' => 'test-password',
            'confirm_text' => 'KALICI SIL',
            'confirm_permanent' => '1',
        ])->assertForbidden();

        $this->assertDatabaseHas('inmate_files', ['id' => $inmate->id, 'deleted_at' => null]);
    }

    public function test_permanent_delete_removes_database_records_and_private_files(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $path = 'inmate-private-files/'.$inmate->id.'/safe.pdf';
        Storage::disk('local')->put($path, 'private-content');

        InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'document',
            'display_name' => 'Silinecek belge',
            'original_name' => 'belge.pdf',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 15,
            'sha256' => hash('sha256', 'private-content'),
            'extension' => 'pdf',
        ]);

        $response = $this->actingAs($user)->delete(route('inmates.permanent-delete.destroy', $inmate), [
            'password' => 'test-password',
            'confirm_text' => 'KALICI SIL',
            'confirm_permanent' => '1',
        ]);

        $response->assertRedirect(route('inmates.index'));
        $this->assertDatabaseMissing('inmate_files', ['id' => $inmate->id]);
        $this->assertDatabaseMissing('inmate_private_files', ['inmate_file_id' => $inmate->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], Storage::disk('local')->allFiles('quarantine'));
    }

    public function test_unsafe_private_file_path_blocks_delete_and_keeps_record(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'document',
            'display_name' => 'Bozuk path',
            'original_name' => 'belge.pdf',
            'storage_disk' => 'local',
            'storage_path' => '../.env',
            'mime_type' => 'application/pdf',
            'file_size' => 15,
            'sha256' => str_repeat('a', 64),
            'extension' => 'pdf',
        ]);

        $response = $this->actingAs($user)
            ->from(route('inmates.permanent-delete.create', $inmate))
            ->delete(route('inmates.permanent-delete.destroy', $inmate), [
                'password' => 'test-password',
                'confirm_text' => 'KALICI SIL',
                'confirm_permanent' => '1',
            ]);

        $response->assertRedirect(route('inmates.permanent-delete.create', $inmate));
        $response->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('inmate_files', ['id' => $inmate->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('inmate_private_files', ['inmate_file_id' => $inmate->id, 'storage_path' => '../.env']);
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create([
            'name' => 'Test Kurumu',
            'short_name' => 'TEST',
            'is_active' => true,
        ]);
    }

    private function adminUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Test Yönetici',
            'login_name' => 'test-admin-delete',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function standardUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Standart Kullanıcı',
            'login_name' => 'standard-delete-user',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => false,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => 'Silme Test Hükümlü',
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
