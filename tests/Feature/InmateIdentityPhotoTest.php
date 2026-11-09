<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InmateIdentityPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uploads_identity_photo_to_private_storage(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $response = $this->actingAs($user)->post(route('inmates.identity-photo.store', $inmate), [
            'photo_upload' => UploadedFile::fake()->image('kimlik.jpg', 480, 600),
        ]);

        $response->assertRedirect(route('inmates.identity-photo.create', $inmate));

        $photo = InmatePrivateFile::firstOrFail();
        $this->assertSame('photo', $photo->category);
        $this->assertSame('Kimlik fotoğrafı', $photo->display_name);
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertStringStartsWith('inmate-private-files/'.$inmate->id.'/', $photo->storage_path);
        $this->assertStringNotContainsString('kimlik.jpg', $photo->storage_path);
        Storage::disk('local')->assertExists($photo->storage_path);
    }

    public function test_user_stores_webcam_base64_identity_photo(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $payload = 'data:image/jpeg;base64,'.base64_encode('fake-jpeg-content');

        $response = $this->actingAs($user)->post(route('inmates.identity-photo.store', $inmate), [
            'captured_photo' => $payload,
        ]);

        $response->assertRedirect(route('inmates.identity-photo.create', $inmate));
        $photo = InmatePrivateFile::firstOrFail();
        $this->assertSame(hash('sha256', 'fake-jpeg-content'), $photo->sha256);
        Storage::disk('local')->assertExists($photo->storage_path);
    }

    public function test_identity_photo_image_is_served_only_for_matching_photo(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $path = 'inmate-private-files/'.$inmate->id.'/photo.jpg';
        Storage::disk('local')->put($path, 'photo-content');

        $photo = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'photo',
            'display_name' => 'Kimlik fotoğrafı',
            'original_name' => 'photo.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 13,
            'sha256' => hash('sha256', 'photo-content'),
            'extension' => 'jpg',
        ]);

        $this->actingAs($user)->get(route('inmates.identity-photo.image', [$inmate, $photo]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_identity_photo_delete_removes_private_file_and_metadata(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $path = 'inmate-private-files/'.$inmate->id.'/photo.jpg';
        Storage::disk('local')->put($path, 'photo-content');

        $photo = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'photo',
            'display_name' => 'Kimlik fotoğrafı',
            'original_name' => 'photo.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 13,
            'sha256' => hash('sha256', 'photo-content'),
            'extension' => 'jpg',
        ]);

        $response = $this->actingAs($user)->delete(route('inmates.identity-photo.destroy', [$inmate, $photo]));

        $response->assertRedirect(route('inmates.identity-photo.create', $inmate));
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('inmate_private_files', ['id' => $photo->id]);
    }

    public function test_identity_card_renders_latest_photo_and_housing(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        [$block, $ward] = $this->housingUnit();
        HousingAssignment::create([
            'inmate_file_id' => $inmate->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => null,
            'started_at' => '2026-01-01',
            'ended_at' => null,
            'created_by' => $user->id,
        ]);

        $photo = InmatePrivateFile::create([
            'inmate_file_id' => $inmate->id,
            'uploaded_by' => $user->id,
            'category' => 'photo',
            'display_name' => 'Kimlik fotoğrafı',
            'original_name' => 'photo.jpg',
            'storage_disk' => 'local',
            'storage_path' => 'inmate-private-files/'.$inmate->id.'/photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 13,
            'sha256' => str_repeat('a', 64),
            'extension' => 'jpg',
        ]);

        $this->actingAs($user)->get(route('inmates.identity-card', $inmate))
            ->assertOk()
            ->assertSee('Hükümlü Kimlik Kartı')
            ->assertSee($inmate->full_name)
            ->assertSee('A Blok')
            ->assertSee(route('inmates.identity-photo.image', [$inmate, $photo]));
    }

    public function test_batch_identity_card_screen_preserves_legacy_settings_and_selection(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $this->actingAs($user)->get(route('inmates.identity-cards', [
            'inmate_ids' => [$inmate->id],
            'card_width' => 85.6,
            'card_height' => 53.98,
            'cards_per_row' => 3,
            'institution_name' => 'Test Ceza İnfaz Kurumu',
        ]))->assertOk()
            ->assertSee('Toplu kimlik kartı hazırlama')
            ->assertSee('Test Ceza İnfaz Kurumu')
            ->assertSee($inmate->full_name);
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
            'full_name' => 'Kimlik Test Yönetici',
            'login_name' => 'identity-admin',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => 'Kimlik Test Hükümlü',
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function housingUnit(): array
    {
        $block = Block::create([
            'institution_id' => $this->institution()->id,
            'code' => 'A',
            'name' => 'A Blok',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $ward = Ward::create([
            'block_id' => $block->id,
            'code' => 'A1',
            'name' => 'A1 Koğuş',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return [$block, $ward];
    }
}
