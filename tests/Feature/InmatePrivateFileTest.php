<?php

namespace Tests\Feature;

use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InmatePrivateFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_uploads_private_file_with_metadata(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $upload = UploadedFile::fake()->create('karar.pdf', 12, 'application/pdf');

        $response = $this->actingAs($user)->post(route('inmates.private-files.store', $inmate), [
            'category' => 'document',
            'display_name' => 'Mahkeme kararı',
            'description' => 'Test belgesi',
            'private_file' => $upload,
        ]);

        $response->assertRedirect(route('inmates.edit', $inmate));

        $record = InmatePrivateFile::firstOrFail();
        $this->assertSame($inmate->id, $record->inmate_file_id);
        $this->assertSame($user->id, $record->uploaded_by);
        $this->assertSame('karar.pdf', $record->original_name);
        $this->assertSame('application/pdf', $record->mime_type);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record->sha256);
        $this->assertStringStartsWith('inmate-private-files/'.$inmate->id.'/', $record->storage_path);
        $this->assertStringNotContainsString('karar.pdf', $record->storage_path);

        Storage::disk('local')->assertExists($record->storage_path);
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $response = $this->actingAs($user)->from(route('inmates.edit', $inmate))->post(route('inmates.private-files.store', $inmate), [
            'category' => 'document',
            'private_file' => UploadedFile::fake()->create('calistir.exe', 4, 'application/x-msdownload'),
        ]);

        $response->assertRedirect(route('inmates.edit', $inmate));
        $response->assertSessionHasErrors('private_file');
        $this->assertDatabaseCount('inmate_private_files', 0);
    }

    public function test_authorized_user_downloads_private_file_by_id_without_public_path(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        Storage::disk('local')->put('inmate-private-files/'.$inmate->id.'/safe.pdf', 'private-test-content');

        $file = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'document',
            'display_name' => 'Güvenli belge.pdf',
            'original_name' => 'orijinal.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'inmate-private-files/'.$inmate->id.'/safe.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'sha256' => hash('sha256', 'private-test-content'),
            'extension' => 'pdf',
        ]);

        $response = $this->actingAs($user)->get(route('inmates.private-files.download', [$inmate, $file]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_unauthorized_user_cannot_upload_or_download_private_files(): void
    {
        Storage::fake('local');

        $user = $this->standardUser();
        $inmate = $this->inmateFile();
        Storage::disk('local')->put('inmate-private-files/'.$inmate->id.'/safe.pdf', 'private-test-content');

        $file = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => null,
            'category' => 'document',
            'display_name' => null,
            'original_name' => 'orijinal.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'inmate-private-files/'.$inmate->id.'/safe.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'sha256' => hash('sha256', 'private-test-content'),
            'extension' => 'pdf',
        ]);

        $this->actingAs($user)->post(route('inmates.private-files.store', $inmate), [
            'category' => 'document',
            'private_file' => UploadedFile::fake()->create('ret.pdf', 4, 'application/pdf'),
        ])->assertForbidden();

        $this->actingAs($user)->get(route('inmates.private-files.download', [$inmate, $file]))->assertForbidden();
    }

    public function test_tampered_storage_path_is_not_downloaded(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $file = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'document',
            'display_name' => 'Bozuk path',
            'original_name' => 'orijinal.pdf',
            'storage_disk' => 'local',
            'storage_path' => '../.env',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'sha256' => str_repeat('a', 64),
            'extension' => 'pdf',
        ]);

        $this->actingAs($user)->get(route('inmates.private-files.download', [$inmate, $file]))->assertNotFound();
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
            'login_name' => 'test-admin',
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
            'login_name' => 'standart-kullanici',
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
            'full_name' => 'Test Hükümlü',
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
