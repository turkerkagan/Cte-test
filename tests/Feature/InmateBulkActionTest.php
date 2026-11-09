<?php

namespace Tests\Feature;

use App\Models\Bed;
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

class InmateBulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_csv_import_creates_valid_rows_and_reports_invalid_rows(): void
    {
        $user = $this->adminUser();
        $institution = $this->institution();
        $csv = "full_name,identity_number,registered_at,is_active\n".
            "Toplu Bir,12345678901,2026-01-01,1\n".
            "Toplu İki,,2026-01-02,evet\n".
            "Toplu Mükerrer,12345678901,2026-01-03,1\n";

        $response = $this->actingAs($user)->post(route('inmates.bulk.import'), [
            'institution_id' => $institution->id,
            'csv_file' => UploadedFile::fake()->createWithContent('toplu.csv', $csv),
        ]);

        $response->assertOk();
        $response->assertSee('Oluşturulan: 2');
        $response->assertSee('Atlanan: 1');
        $response->assertSee('CSV içinde mükerrer TC');
        $this->assertDatabaseHas('inmate_files', ['full_name' => 'Toplu Bir', 'identity_number' => '12345678901']);
        $this->assertDatabaseHas('inmate_files', ['full_name' => 'Toplu İki', 'identity_number' => null]);
        $this->assertDatabaseMissing('inmate_files', ['full_name' => 'Toplu Mükerrer']);
    }

    public function test_bulk_status_update_changes_selected_records_only(): void
    {
        $user = $this->adminUser();
        $first = $this->inmateFile('Birinci');
        $second = $this->inmateFile('İkinci');
        $third = $this->inmateFile('Üçüncü');

        $response = $this->actingAs($user)->patch(route('inmates.bulk.status'), [
            'selected_ids_text' => $first->id.','.$second->id,
            'status_code' => 'arsiv',
            'risk_code' => 'dusuk',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('inmates.bulk.index'));
        $this->assertDatabaseHas('inmate_files', ['id' => $first->id, 'status_code' => 'arsiv', 'risk_code' => 'dusuk', 'is_active' => false]);
        $this->assertDatabaseHas('inmate_files', ['id' => $second->id, 'status_code' => 'arsiv', 'risk_code' => 'dusuk', 'is_active' => false]);
        $this->assertDatabaseHas('inmate_files', ['id' => $third->id, 'status_code' => null, 'risk_code' => null, 'is_active' => true]);
    }

    public function test_bulk_housing_closes_existing_active_records_and_creates_new_ones(): void
    {
        $user = $this->adminUser();
        [$block, $ward] = $this->housingUnit();
        $first = $this->inmateFile('Birinci Konum');
        $second = $this->inmateFile('İkinci Konum');

        HousingAssignment::create([
            'inmate_file_id' => $first->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => null,
            'started_at' => '2026-01-01',
            'ended_at' => null,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('inmates.bulk.housing'), [
            'selected_ids_text' => $first->id.' '.$second->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'started_at' => '2026-02-01',
            'notes' => 'Toplu konum testi',
        ]);

        $response->assertRedirect(route('inmates.bulk.index'));
        $this->assertSame(2, HousingAssignment::whereNull('ended_at')->count());
        $this->assertSame('2026-02-01', $first->housingAssignments()->whereNull('ended_at')->firstOrFail()->started_at->toDateString());
        $this->assertSame('2026-02-01', $second->housingAssignments()->whereNull('ended_at')->firstOrFail()->started_at->toDateString());
    }

    public function test_bulk_housing_rejects_same_bed_for_multiple_inmates(): void
    {
        $user = $this->adminUser();
        [$block, $ward, $bed] = $this->housingUnit(withBed: true);
        $first = $this->inmateFile('Birinci Ranza');
        $second = $this->inmateFile('İkinci Ranza');

        $response = $this->actingAs($user)
            ->from(route('inmates.bulk.index'))
            ->post(route('inmates.bulk.housing'), [
                'selected_ids_text' => $first->id.','.$second->id,
                'block_id' => $block->id,
                'ward_id' => $ward->id,
                'bed_id' => $bed->id,
                'started_at' => '2026-02-01',
            ]);

        $response->assertRedirect(route('inmates.bulk.index'));
        $response->assertSessionHasErrors('bed_id');
        $this->assertDatabaseCount('housing_assignments', 0);
    }

    public function test_bulk_permanent_delete_removes_records_and_private_files(): void
    {
        Storage::fake('local');

        $user = $this->adminUser();
        $first = $this->inmateFile('Silinecek Bir');
        $second = $this->inmateFile('Silinecek İki');
        $path = 'inmate-private-files/'.$first->id.'/belge.pdf';
        Storage::disk('local')->put($path, 'private-content');

        InmatePrivateFile::create([
            'inmate_file_id' => $first->id,
            'uploaded_by' => $user->id,
            'category' => 'document',
            'display_name' => 'Belge',
            'original_name' => 'belge.pdf',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 15,
            'sha256' => hash('sha256', 'private-content'),
            'extension' => 'pdf',
        ]);

        $response = $this->actingAs($user)->delete(route('inmates.bulk.destroy'), [
            'selected_ids_text' => $first->id.','.$second->id,
            'password' => 'test-password',
            'confirm_text' => 'TOPLU SIL',
        ]);

        $response->assertRedirect(route('inmates.index'));
        $this->assertDatabaseMissing('inmate_files', ['id' => $first->id]);
        $this->assertDatabaseMissing('inmate_files', ['id' => $second->id]);
        $this->assertDatabaseMissing('inmate_private_files', ['inmate_file_id' => $first->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], Storage::disk('local')->allFiles('quarantine'));
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
            'full_name' => 'Toplu Test Yönetici',
            'login_name' => 'bulk-admin',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(string $name): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => $name,
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function housingUnit(bool $withBed = false): array
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
        $bed = $withBed ? Bed::create([
            'ward_id' => $ward->id,
            'code' => 'A1-1',
            'label' => '1',
            'sort_order' => 1,
            'is_active' => true,
        ]) : null;

        return [$block, $ward, $bed];
    }
}
