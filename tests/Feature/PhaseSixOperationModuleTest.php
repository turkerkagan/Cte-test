<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationModuleController;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\OperationRecord;
use App\Models\User;
use App\Models\Ward;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseSixOperationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_phase_six_modules_are_registered(): void
    {
        $this->assertSame(['kogus-sayim-calisma', 'kismi-arama', 'duyurular', 'nobet', 'kurul-komisyon', 'yemek', 'sohbet'], array_keys(OperationModuleController::MODULES));
    }

    public function test_operation_record_can_be_created_for_each_editable_phase_six_module(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        foreach (OperationModuleController::MODULES as $module => $config) {
            if ($config['readonly']) {
                continue;
            }

            $response = $this->actingAs($user)->post(route('operations.store', $module), [
                'inmate_file_id' => $inmate->id,
                'record_type' => $config['types'][0],
                'title' => $config['title'].' test kaydı',
                'starts_at' => '2026-06-22 09:00',
                'ends_at' => '2026-06-22 17:00',
                'status_code' => 'acik',
                'data' => $module === 'nobet' ? ['Personel' => (string) $user->id] : [$config['fields'][0] => 'Alan değeri'],
                'body' => $config['task'].' test açıklaması',
            ]);

            $response->assertRedirect(route('operations.index', $module));
        }

        $this->assertSame(6, OperationRecord::count());
        $this->assertDatabaseHas('operation_records', ['module_code' => 'duyurular', 'record_type' => 'Genel duyuru']);
        $this->assertDatabaseHas('operation_records', ['module_code' => 'sohbet', 'record_type' => 'Genel mesaj']);
    }

    public function test_operation_index_filters_search_type_date_and_closed_state(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'inmate_file_id' => $inmate->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'kismi-arama',
            'record_type' => 'Blok arama',
            'title' => 'Aranacak operasyon kaydı',
            'starts_at' => '2026-06-22 09:00:00',
            'is_closed' => false,
            'data' => ['Blok' => 'A'],
        ]);

        OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'kismi-arama',
            'record_type' => 'Tutanak',
            'title' => 'Başka operasyon',
            'starts_at' => '2026-05-01 09:00:00',
            'is_closed' => true,
        ]);

        $response = $this->actingAs($user)->get(route('operations.index', 'kismi-arama').'?q=Aranacak&record_type=Blok%20arama&from=2026-06-01&to=2026-06-30&is_closed=0');

        $response->assertOk();
        $response->assertSee('Aranacak operasyon kaydı');
        $response->assertDontSee('Başka operasyon');
    }

    public function test_operation_record_can_be_closed_and_deleted(): void
    {
        $user = $this->adminUser();
        $record = OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'duyurular',
            'record_type' => 'Genel duyuru',
            'title' => 'Kapatılacak duyuru',
            'starts_at' => '2026-06-22 09:00:00',
            'is_closed' => false,
        ]);

        $this->actingAs($user)->patch(route('operations.close', ['duyurular', $record]))->assertRedirect(route('operations.index', 'duyurular'));
        $this->assertDatabaseHas('operation_records', ['id' => $record->id, 'is_closed' => true]);

        $this->actingAs($user)->delete(route('operations.destroy', ['duyurular', $record]))->assertRedirect(route('operations.index', 'duyurular'));
        $this->assertSoftDeleted('operation_records', ['id' => $record->id]);
    }

    public function test_housing_work_readonly_screen_uses_active_location_and_work(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile('Aktif Konumlu');
        $block = Block::create(['institution_id' => $this->institution()->id, 'code' => 'A', 'name' => 'A Blok', 'sort_order' => 1, 'is_active' => true]);
        $ward = Ward::create(['block_id' => $block->id, 'code' => 'A1', 'name' => 'A1 Koğuş', 'sort_order' => 1, 'is_active' => true]);
        $place = WorkPlace::create(['institution_id' => $this->institution()->id, 'code' => 'MUTFAK', 'name' => 'Mutfak', 'is_active' => true]);

        HousingAssignment::create(['inmate_file_id' => $inmate->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'bed_id' => null, 'started_at' => '2026-06-01', 'ended_at' => null, 'created_by' => $user->id]);
        WorkAssignment::create(['inmate_file_id' => $inmate->id, 'work_place_id' => $place->id, 'decision_code' => 'gorevli', 'started_at' => '2026-06-01', 'ended_at' => null, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->get(route('operations.index', 'kogus-sayim-calisma').'?q=Aktif');

        $response->assertOk();
        $response->assertSee('Aktif Konumlu');
        $response->assertSee('A Blok');
        $response->assertSee('A1 Koğuş');
        $response->assertSee('Mutfak');
    }

    public function test_announcements_can_be_marked_as_read(): void
    {
        $user = $this->adminUser();
        $announcement = OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'duyurular',
            'record_type' => 'Genel duyuru',
            'title' => 'Test Duyurusu',
            'starts_at' => now(),
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)->post(route('operations.announcements.read', ['duyurular', $announcement]));
        $response->assertRedirect();
        $this->assertTrue($announcement->readUsers->contains($user->id));
    }

    public function test_all_announcements_can_be_marked_as_read_at_once(): void
    {
        $user = $this->adminUser();
        $announcement1 = OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'duyurular',
            'record_type' => 'Genel duyuru',
            'title' => 'Test Duyurusu 1',
            'starts_at' => now(),
            'is_published' => true,
        ]);
        $announcement2 = OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'duyurular',
            'record_type' => 'Genel duyuru',
            'title' => 'Test Duyurusu 2',
            'starts_at' => now(),
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)->post(route('operations.announcements.read-all', 'duyurular'));
        $response->assertRedirect();

        $this->assertTrue($announcement1->fresh()->readUsers->contains($user->id));
        $this->assertTrue($announcement2->fresh()->readUsers->contains($user->id));
    }

    public function test_nobet_conflicts_and_unsuitability_throw_validation_error(): void
    {
        $user = $this->adminUser();
        $personel = $this->adminUser();

        OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'nobet',
            'record_type' => 'Uygun değil',
            'title' => 'Uygun Olmayan Personel',
            'starts_at' => '2026-06-22 08:00:00',
            'ends_at' => '2026-06-22 17:00:00',
            'data' => [
                'Personel' => (string) $personel->id,
                'Uygun olmayan tarih' => '2026-06-22',
            ],
            'is_closed' => false,
        ]);

        $response = $this->actingAs($user)->post(route('operations.store', 'nobet'), [
            'record_type' => 'Vardiya',
            'title' => 'Vardiya Nöbeti',
            'starts_at' => '2026-06-22 08:00',
            'ends_at' => '2026-06-22 17:00',
            'data' => [
                'Personel' => (string) $personel->id,
                'Görev' => 'Vardiya Memuru',
                'Vardiya' => '08:00-17:00',
            ],
        ]);

        $response->assertSessionHasErrors('data.Personel');
    }

    public function test_nobet_auto_plan_creates_records_adilli_manner(): void
    {
        $user = $this->adminUser();
        $personel1 = $this->adminUser();
        $personel2 = $this->adminUser();

        $response = $this->actingAs($user)->post(route('operations.nobet.auto-plan'), [
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-23',
            'record_type' => 'Vardiya',
            'personnel_ids' => [$personel1->id, $personel2->id],
        ]);

        $response->assertRedirect(route('operations.index', 'nobet'));
        $this->assertEquals(2, OperationRecord::where('module_code', 'nobet')->where('record_type', 'Vardiya')->count());
    }

    public function test_kurul_members_are_synced_successfully(): void
    {
        $user = $this->adminUser();
        $member1 = $this->adminUser();
        $member2 = $this->adminUser();

        $response = $this->actingAs($user)->post(route('operations.store', 'kurul-komisyon'), [
            'record_type' => 'Kurul',
            'title' => 'Haftalık Değerlendirme Kurulu',
            'starts_at' => '2026-06-22 10:00',
            'ends_at' => '2026-06-22 12:00',
            'member_ids' => [$member1->id, $member2->id],
            'data' => [
                'Kurul türü' => 'Disiplin',
                'Sıra' => '1',
            ],
        ]);

        $record = OperationRecord::where('module_code', 'kurul-komisyon')->first();
        $this->assertNotNull($record);
        $this->assertEquals(2, $record->members->count());
        $this->assertTrue($record->members->contains($member1->id));
        $this->assertTrue($record->members->contains($member2->id));
    }

    public function test_yemek_menu_file_upload_and_download(): void
    {
        $user = $this->adminUser();
        Storage::fake('local');
        $file = UploadedFile::fake()->create('menu.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post(route('operations.store', 'yemek'), [
            'record_type' => 'Haftalık menü',
            'title' => 'Haziran 4. Hafta Yemek Menüsü',
            'starts_at' => '2026-06-22 00:00',
            'menu_file' => $file,
            'data' => [
                'Menü dönemi' => '22.06.2026 - 28.06.2026',
            ],
        ]);

        $record = OperationRecord::where('module_code', 'yemek')->first();
        $this->assertNotNull($record);
        $this->assertEquals(1, $record->privateFiles->count());

        $pfile = $record->privateFiles->first();
        Storage::assertExists('private/operations/'.$pfile->filename);

        $downloadResponse = $this->actingAs($user)->get(route('operations.yemek.download-file', $pfile->id));
        $downloadResponse->assertOk();
    }

    public function test_chat_message_sending_and_reading(): void
    {
        $user1 = $this->adminUser();
        $user2 = $this->adminUser();

        $response = $this->actingAs($user1)->post(route('operations.chat.send'), [
            'body' => 'Merhaba, nasılsın?',
            'receiver_id' => $user2->id,
        ]);

        $response->assertRedirect();

        $msg = OperationRecord::where('module_code', 'sohbet')->first();
        $this->assertNotNull($msg);
        $this->assertEquals('Özel mesaj', $msg->record_type);
        $this->assertEquals('Merhaba, nasılsın?', $msg->body);
        $this->assertEquals('okunmadi', $msg->data['Okundu durumu']);

        $this->actingAs($user2)->get(route('operations.index', ['sohbet', 'receiver_id' => $user1->id]));
        $this->assertEquals('okunmadi', $msg->fresh()->data['Okundu durumu']);
        $this->actingAs($user2)->post(route('operations.chat.read', $msg))->assertRedirect();
        $this->assertEquals('okundu', $msg->fresh()->data['Okundu durumu']);
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create(['name' => 'Test Kurumu', 'short_name' => 'TEST', 'is_active' => true]);
    }

    private function adminUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Faz Altı Yönetici',
            'login_name' => 'phase-six-admin-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(string $name = 'Faz Altı Test Hükümlü'): InmateFile
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
}
