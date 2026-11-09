<?php

namespace Tests\Feature;

use App\Console\Commands\CteRestoreCommand;
use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\InmatePrivateFile;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\MenuItem;
use App\Models\OperationRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitRecord;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\Ward;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use App\Services\BackupService;
use App\Services\DeletionQuarantine;
use App\Services\LegacyTransferService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class TransitionInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $admin;

    private InmateFile $inmate;

    private Block $block;

    private Ward $ward;

    private Bed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(12, 0));
        $this->institution = Institution::create(['name' => 'Kurum', 'short_name' => 'K', 'is_active' => true]);
        $this->admin = User::factory()->create(['institution_id' => $this->institution->id, 'is_active' => true, 'is_protected_superadmin' => true, 'must_change_password' => false]);
        $this->inmate = $this->inmate('Bir');
        $this->block = Block::create(['institution_id' => $this->institution->id, 'name' => 'A', 'code' => 'A', 'is_active' => true]);
        $this->ward = Ward::create(['block_id' => $this->block->id, 'name' => 'A1', 'code' => 'A1', 'capacity' => 10, 'is_active' => true]);
        $this->bed = Bed::create(['ward_id' => $this->ward->id, 'label' => '1', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    private function inmate(string $name): InmateFile
    {
        return InmateFile::create(['institution_id' => $this->institution->id, 'full_name' => $name, 'is_active' => true]);
    }

    private function housing(array $extra = []): array
    {
        return $extra + ['inmate_file_id' => $this->inmate->id, 'block_id' => $this->block->id, 'ward_id' => $this->ward->id, 'bed_id' => $this->bed->id, 'started_at' => '2026-11-01'];
    }

    private function duty(User $person, array $extra = []): array
    {
        return $extra + ['institution_id' => $this->institution->id, 'module_code' => 'nobet', 'record_type' => 'Vardiya', 'title' => 'Nöbet', 'starts_at' => '2026-11-08 08:00', 'ends_at' => '2026-11-08 17:00', 'data' => ['Personel' => (string) $person->id], 'is_closed' => false, 'is_published' => true];
    }

    public function test_body_cannot_substitute_assignment_inmate(): void
    {
        HousingAssignment::create($this->housing(['bed_id' => null]));
        $other = $this->inmate('Diğer');
        $this->post(route('inmates.housing.store', $this->inmate), $this->housing(['inmate_file_id' => $other->id]))->assertSessionHasErrors('inmate_file_id');
        $this->assertSame(1, HousingAssignment::count());
    }

    public function test_model_rejects_mismatched_housing_even_without_form_request(): void
    {
        $other = Block::create(['institution_id' => $this->institution->id, 'code' => 'B', 'name' => 'B', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        HousingAssignment::create($this->housing(['block_id' => $other->id]));
    }

    public function test_active_bed_is_unique_even_for_raw_sql_writes(): void
    {
        HousingAssignment::create($this->housing());
        $other = $this->inmate('Diğer');
        $this->expectException(QueryException::class);
        DB::table('housing_assignments')->insert($this->housing(['inmate_file_id' => $other->id]));
    }

    public function test_active_work_is_unique_even_for_raw_sql_writes(): void
    {
        $place = WorkPlace::create(['institution_id' => $this->institution->id, 'code' => 'W', 'name' => 'W', 'is_active' => true]);
        $data = ['inmate_file_id' => $this->inmate->id, 'work_place_id' => $place->id, 'started_at' => '2026-11-01'];
        WorkAssignment::create($data);
        $this->expectException(QueryException::class);
        DB::table('work_assignments')->insert($data);
    }

    public function test_repeated_close_preserves_date_and_closed_assignment_cannot_reopen(): void
    {
        $assignment = HousingAssignment::create($this->housing());
        $this->patch(route('inmates.housing.close', [$this->inmate, $assignment]))->assertRedirect();
        $this->travel(1)->days();
        $this->patch(route('inmates.housing.close', [$this->inmate, $assignment]))->assertRedirect();
        $this->assertSame('2026-11-07', $assignment->fresh()->ended_at->toDateString());
        $this->expectException(ValidationException::class);
        $assignment->fresh()->update(['ended_at' => null]);
    }

    public function test_future_active_assignment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        HousingAssignment::create($this->housing(['started_at' => '2027-01-01']));
    }

    public function test_capacity_and_inactive_locations_are_enforced(): void
    {
        $this->ward->update(['capacity' => 1]);
        HousingAssignment::create($this->housing());
        $this->expectException(ValidationException::class);
        HousingAssignment::create($this->housing(['inmate_file_id' => $this->inmate('İkinci')->id, 'bed_id' => null]));
    }

    public function test_deactivating_file_releases_its_active_assignments(): void
    {
        $assignment = HousingAssignment::create($this->housing());
        $this->inmate->update(['is_active' => false]);
        $this->assertNotNull($assignment->fresh()->ended_at);
    }

    public function test_inherit_transition_invalidates_a_cached_override(): void
    {
        $permission = Permission::create(['key' => 'inmates.view', 'module' => 'inmates', 'action' => 'view', 'label' => 'Görüntüle']);
        $role = Role::create(['institution_id' => $this->institution->id, 'code' => 'standard', 'name' => 'Standart', 'is_active' => true]);
        $user = User::factory()->create(['institution_id' => $this->institution->id, 'role_id' => $role->id, 'login_name' => 'test-user', 'is_active' => true]);
        UserPermissionOverride::create(['user_id' => $user->id, 'permission_id' => $permission->id, 'allowed' => true]);
        $this->assertTrue($user->hasPermission('inmates.view'));
        $this->put(route('admin.users.update', $user), ['full_name' => $user->full_name, 'login_name' => 'test-user', 'institution_id' => $this->institution->id, 'role_id' => $role->id, 'is_active' => 1, 'permissions' => [$permission->id => 'inherit']])->assertRedirect();
        $this->assertFalse($user->fresh()->hasPermission('inmates.view'));
    }

    public function test_exact_preview_rejects_same_count_with_different_people(): void
    {
        $this->inmate->update(['status_code' => 'YENİ KAYIT']);
        $this->get(route('inmates.shortcuts.index', ['from_status' => 'YENİ KAYIT', 'to_status' => 'MEVCUT']))->assertOk();
        $this->inmate->update(['status_code' => 'MEVCUT']);
        $this->inmate('Yeni kişi')->update(['status_code' => 'YENİ KAYIT']);
        $this->post(route('inmates.shortcuts.update'), ['from_status' => 'YENİ KAYIT', 'to_status' => 'MEVCUT', 'confirm_count' => 1])->assertSessionHasErrors('confirm_count');
    }

    public function test_duty_requires_a_real_active_person_and_complete_interval(): void
    {
        $data = $this->duty($this->admin, ['data' => ['Personel' => 'bilinmiyor']]);
        $this->post(route('operations.store', 'nobet'), $data)->assertSessionHasErrors();
        $this->admin->update(['last_activity_at' => now()]);
        $this->post(route('operations.store', 'nobet'), $this->duty($this->admin, ['ends_at' => null]))->assertSessionHasErrors();
        $this->assertSame(0, OperationRecord::count());
    }

    public function test_unavailability_cannot_be_added_over_existing_duty(): void
    {
        OperationRecord::create($this->duty($this->admin));
        $this->post(route('operations.store', 'nobet'), $this->duty($this->admin, ['record_type' => 'Uygun değil']))->assertSessionHasErrors('data.Personel');
    }

    public function test_unavailability_inside_multi_day_duty_is_detected(): void
    {
        OperationRecord::create($this->duty($this->admin, ['record_type' => 'Uygun değil', 'starts_at' => '2026-11-09 00:00', 'ends_at' => '2026-11-10 00:00', 'data' => ['Personel' => (string) $this->admin->id, 'Uygun olmayan tarih' => '2026-11-09']]));
        $this->post(route('operations.store', 'nobet'), $this->duty($this->admin, ['ends_at' => '2026-11-10 17:00']))->assertSessionHasErrors('data.Personel');
    }

    public function test_auto_plan_rejects_unknown_type(): void
    {
        $this->post(route('operations.nobet.auto-plan'), ['start_date' => '2026-11-08', 'end_date' => '2026-11-09', 'record_type' => 'Rastgele', 'personnel_ids' => [$this->admin->id]])->assertSessionHasErrors('record_type');
    }

    public function test_closing_leave_preserves_planned_return_and_creator(): void
    {
        $record = UnitRecord::create(['inmate_file_id' => $this->inmate->id, 'module_code' => 'izin', 'record_type' => 'Evci', 'title' => 'İzin', 'occurred_at' => '2026-11-01', 'data' => ['Çıkış' => '2026-11-01', 'Dönüş' => '2026-11-10'], 'created_by' => $this->admin->id, 'is_closed' => false]);
        $this->patch(route('unit-records.close', ['izin', $record]))->assertRedirect();
        $this->assertSame('2026-11-10', $record->fresh()->ended_at->toDateString());
        $this->assertSame('2026-11-07', $record->fresh()->closed_at->toDateString());
        $this->put(route('unit-records.update', ['izin', $record]), ['record_type' => 'Evci', 'title' => 'Düzeltildi', 'data' => ['Çıkış' => '2026-11-01', 'Dönüş' => '2026-11-10']])->assertRedirect();
        $this->assertTrue($record->fresh()->is_closed);
        $this->assertSame($this->admin->id, $record->fresh()->created_by);
    }

    public function test_open_ended_leave_cannot_overlap_a_future_leave(): void
    {
        UnitRecord::create(['inmate_file_id' => $this->inmate->id, 'module_code' => 'izin', 'record_type' => 'Evci', 'title' => 'Gelecek izin', 'occurred_at' => '2026-12-01', 'ended_at' => '2026-12-02']);
        $this->post(route('unit-records.store', ['izin', $this->inmate]), ['record_type' => 'Evci', 'title' => 'Açık izin', 'data' => ['Çıkış' => '2026-11-01']])->assertSessionHasErrors('data.Çıkış');
    }

    public function test_leave_dates_are_validated_as_dates_not_just_strings(): void
    {
        $this->post(route('unit-records.store', ['izin', $this->inmate]), ['record_type' => 'Evci', 'title' => 'İzin', 'data' => ['Çıkış' => 'tarih-değil']])->assertSessionHasErrors('data.Çıkış');
    }

    public function test_leave_count_uses_actual_open_records_over_legacy_label(): void
    {
        UnitRecord::create(['inmate_file_id' => $this->inmate->id, 'module_code' => 'izin', 'record_type' => 'Evci', 'title' => 'İzin', 'occurred_at' => '2026-11-01']);
        $this->assertTrue(InmateFile::whereKey($this->inmate->id)->onLeave()->exists());
        $record = $this->inmate->unitRecords()->first();
        $record->update(['is_closed' => true]);
        $this->inmate->update(['status_code' => 'ÖZEL İZİN']);
        $this->assertFalse(InmateFile::whereKey($this->inmate->id)->onLeave()->exists());
    }

    public function test_chat_get_does_not_mutate_and_only_receiver_can_mark_read(): void
    {
        $sender = User::factory()->create(['institution_id' => $this->institution->id, 'is_active' => true]);
        $record = OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'sohbet', 'record_type' => 'Özel mesaj', 'title' => 'Mesaj', 'created_by' => $sender->id, 'body' => 'Merhaba', 'data' => ['Alıcı' => (string) $this->admin->id, 'Okundu durumu' => 'okunmadi'], 'is_published' => true]);
        $this->get(route('operations.index', ['sohbet', 'receiver_id' => $sender->id]))->assertOk()->assertSee('data-chat-read', false);
        $this->assertSame('okunmadi', $record->fresh()->data['Okundu durumu']);
        $this->post(route('operations.chat.read', $record))->assertRedirect();
        $this->assertSame('okundu', $record->fresh()->data['Okundu durumu']);
        $this->put(route('operations.update', ['sohbet', $record]), ['record_type' => 'Özel mesaj', 'title' => 'Değiştir'])->assertForbidden();
    }

    public function test_hidden_announcement_cannot_be_marked_read(): void
    {
        $record = OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'duyurular', 'record_type' => 'Genel duyuru', 'title' => 'Taslak', 'is_published' => false]);
        $this->post(route('operations.announcements.read', ['duyurular', $record]))->assertNotFound();
        $this->assertSame(0, $record->readUsers()->count());
    }

    public function test_invalid_menu_upload_does_not_create_operation(): void
    {
        Storage::fake('local');
        $this->post(route('operations.store', 'yemek'), ['record_type' => 'Belge', 'title' => 'Menü', 'menu_file' => UploadedFile::fake()->create('virus.exe', 1)])->assertSessionHasErrors('menu_file');
        $this->assertSame(0, OperationRecord::count());
        $this->assertSame([], Storage::disk('local')->allFiles('private/operations'));
    }

    public function test_backup_includes_operation_documents_and_restores_generated_columns(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/operations/menu.pdf', 'menü');
        HousingAssignment::create($this->housing());
        $service = app(BackupService::class);
        $backup = $service->createArchive(storage_path('framework/testing-backups'));
        $this->assertContains('private/operations/menu.pdf', array_column($backup['manifest']['files'], 'restore_path'));
        // PHPUnit's RefreshDatabase owns an outer transaction; use a separate SQLite file for a full restore.
        $zip = new \ZipArchive;
        $zip->open($backup['path']);
        $sql = $zip->getFromName('database/database.sql');
        $zip->close();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec($sql);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM housing_assignments WHERE active_inmate_id IS NOT NULL')->fetchColumn());
        $this->expectException(\PDOException::class);
        $pdo->exec('INSERT INTO housing_assignments (inmate_file_id,block_id,ward_id,bed_id,started_at) SELECT inmate_file_id,block_id,ward_id,bed_id,started_at FROM housing_assignments');
    }

    public function test_interrupted_deletion_can_restore_or_purge_using_journal(): void
    {
        Storage::fake('local');
        $path = 'inmate-private-files/'.$this->inmate->id.'/photo.jpg';
        Storage::disk('local')->put($path, 'photo');
        $file = InmatePrivateFile::create(['inmate_file_id' => $this->inmate->id, 'category' => 'photo', 'original_name' => 'photo.jpg', 'storage_disk' => 'local', 'storage_path' => $path, 'mime_type' => 'image/jpeg', 'file_size' => 5, 'sha256' => hash('sha256', 'photo'), 'extension' => 'jpg']);
        $service = app(DeletionQuarantine::class);
        $entry = $service->move($path);
        $this->assertSame(1, $service->recover());
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertMissing($entry['journal']);
        $entry = $service->move($path);
        $file->delete();
        $this->assertSame(1, $service->recover());
        Storage::disk('local')->assertMissing($entry['quarantine']);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_menu_cycle_is_rejected(): void
    {
        $a = MenuItem::create(['title' => 'A']);
        $b = MenuItem::create(['title' => 'B', 'parent_id' => $a->id]);
        $this->expectException(ValidationException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_session_revocation_works_with_non_database_session_driver(): void
    {
        $user = User::factory()->create(['institution_id' => $this->institution->id, 'is_active' => true, 'must_change_password' => false]);
        $this->delete(route('admin.users.sessions.destroy', $user))->assertRedirect();
        $this->assertSame(1, (int) $user->fresh()->session_version);
        $this->actingAs($user)->withSession(['user_session_version' => 0])->get(route('profile.password.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_inactive_definition_cannot_be_used_for_a_new_transition(): void
    {
        $group = LookupGroup::create(['code' => 'inmate_status', 'name' => 'Durum', 'is_active' => true]);
        $group->items()->create(['code' => 'MEVCUT', 'label' => 'Mevcut', 'is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->inmate->update(['status_code' => 'MEVCUT']);
    }

    public function test_resource_cannot_be_disabled_while_occupied(): void
    {
        HousingAssignment::create($this->housing());
        $this->expectException(ValidationException::class);
        $this->bed->update(['is_active' => false]);
    }

    public function test_inactive_role_no_longer_grants_cached_role_permissions(): void
    {
        $permission = Permission::create(['key' => 'inmates.view', 'module' => 'inmates', 'action' => 'view', 'label' => 'Görüntüle']);
        $role = Role::create(['institution_id' => $this->institution->id, 'code' => 'reader', 'name' => 'Okuyucu', 'is_active' => true]);
        $role->syncPermissionsWithCacheClear([$permission->id]);
        $user = User::factory()->create(['institution_id' => $this->institution->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->assertTrue($user->hasPermission('inmates.view'));
        $role->update(['is_active' => false]);
        $this->assertFalse($user->hasPermission('inmates.view'));
    }

    public function test_failed_restore_and_failed_rollback_never_reopen_application(): void
    {
        $service = \Mockery::mock(BackupService::class);
        $service->shouldReceive('verifyArchive')->with('/backup.zip')->once()->andReturn(['valid' => true]);
        $service->shouldReceive('createArchive')->once()->andReturn(['path' => '/before.zip']);
        $service->shouldReceive('restoreArchive')->with('/backup.zip')->once()->andThrow(new \RuntimeException('restore failed'));
        $service->shouldReceive('restoreArchive')->with('/before.zip')->once()->andThrow(new \RuntimeException('rollback failed'));
        $this->app->instance(BackupService::class, $service);
        Artisan::shouldReceive('call')->once()->with('down', ['--retry' => 60])->andReturn(0);
        Artisan::shouldNotReceive('call')->with('up');
        $command = new CteRestoreCommand;
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput(['path' => '/backup.zip', '--confirm' => 'RESTORE_BACKUP']), $output);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('bakım modunda bırakıldı', $output->fetch());
    }

    public function test_legacy_operation_reimport_does_not_restore_a_deleted_record(): void
    {
        $record = OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'duyurular', 'record_type' => 'Genel duyuru', 'title' => 'Yerel başlık', 'status_code' => 'LEGACY:duyuru:15', 'is_published' => false]);
        $record->delete();
        $method = new \ReflectionMethod(LegacyTransferService::class, 'operation');
        $method->invoke(app(LegacyTransferService::class), $this->institution->id, 'duyurular', 'Genel duyuru', 'Kaynak başlık', 'duyuru', 15, []);
        $this->assertTrue($record->fresh()->trashed());
        $this->assertSame('Yerel başlık', $record->fresh()->title);
        $this->assertFalse($record->fresh()->is_published);
    }

    public function test_chat_hides_closed_and_unpublished_messages(): void
    {
        OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'sohbet', 'record_type' => 'Genel mesaj', 'title' => 'Gizli', 'body' => 'Yayınlanmayan içerik', 'is_published' => false]);
        OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'sohbet', 'record_type' => 'Genel mesaj', 'title' => 'Kapalı', 'body' => 'Kapatılan içerik', 'is_published' => true, 'is_closed' => true]);
        $this->get(route('operations.index', 'sohbet'))->assertOk()->assertDontSee('Yayınlanmayan içerik')->assertDontSee('Kapatılan içerik');
    }

    public function test_chat_json_receipt_returns_no_content(): void
    {
        $sender = User::factory()->create(['institution_id' => $this->institution->id, 'is_active' => true]);
        $record = OperationRecord::create(['institution_id' => $this->institution->id, 'module_code' => 'sohbet', 'record_type' => 'Özel mesaj', 'title' => 'Mesaj', 'created_by' => $sender->id, 'data' => ['Alıcı' => (string) $this->admin->id, 'Okundu durumu' => 'okunmadi'], 'is_published' => true]);
        $this->postJson(route('operations.chat.read', $record))->assertNoContent();
        $this->assertSame('okundu', $record->fresh()->data['Okundu durumu']);
    }

    public function test_early_actual_return_releases_the_remaining_planned_leave_interval(): void
    {
        $record = UnitRecord::create(['inmate_file_id' => $this->inmate->id, 'module_code' => 'izin', 'record_type' => 'Evci', 'title' => 'İzin', 'occurred_at' => '2026-11-01', 'ended_at' => '2026-11-20', 'is_closed' => false]);
        $record->update(['is_closed' => true]);
        $this->post(route('unit-records.store', ['izin', $this->inmate]), ['record_type' => 'Evci', 'title' => 'Yeni izin', 'data' => ['Çıkış' => '2026-11-08', 'Dönüş' => '2026-11-09']])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-11-20', $record->fresh()->ended_at->toDateString());
    }

    public function test_future_end_cannot_hide_a_current_bed_reservation(): void
    {
        $this->expectException(ValidationException::class);
        HousingAssignment::create($this->housing(['ended_at' => '2026-12-01']));
    }

    public function test_ended_assignment_still_checks_historical_overlap(): void
    {
        HousingAssignment::create($this->housing(['ended_at' => '2026-11-05']));
        $this->expectException(ValidationException::class);
        HousingAssignment::create($this->housing(['inmate_file_id' => $this->inmate('Başka')->id, 'started_at' => '2026-11-02', 'ended_at' => '2026-11-04']));
    }
}
