<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountingAndShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_counting_screen_calculates_total_leave_and_ready_population(): void
    {
        $user = $this->admin();
        [$block, $ward] = $this->location();
        $present = $this->inmate('Hazır Mevcut', 'MEVCUT');
        $leave = $this->inmate('Özel İzinli', 'ÖZEL İZİN');
        foreach ([$present, $leave] as $inmate) {
            HousingAssignment::create([
                'inmate_file_id' => $inmate->id,
                'block_id' => $block->id,
                'ward_id' => $ward->id,
                'started_at' => '2026-06-01',
            ]);
        }

        $this->actingAs($user)->get(route('counting.index'))
            ->assertOk()
            ->assertSee('Koğuş Sayımı')
            ->assertSee('A Blok')
            ->assertSee('A1 Koğuş')
            ->assertSeeInOrder(['Kişi', 'Özel İzin', 'Hazır Mevcut'])
            ->assertSee('Tüm Koğuşlar Toplamı');
    }

    public function test_counting_snapshot_is_saved_and_difference_is_shown(): void
    {
        $user = $this->admin();
        [$block, $ward] = $this->location();
        $first = $this->inmate('İlk Sayım', 'MEVCUT');
        HousingAssignment::create(['inmate_file_id' => $first->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'started_at' => '2026-06-01']);

        $this->actingAs($user)->post(route('counting.store'))->assertRedirect(route('counting.index'));
        $this->assertDatabaseHas('operation_records', [
            'module_code' => 'kogus-sayim-calisma',
            'record_type' => 'Sayım anlık görüntüsü',
        ]);

        $second = $this->inmate('İkinci Sayım', 'MEVCUT');
        HousingAssignment::create(['inmate_file_id' => $second->id, 'block_id' => $block->id, 'ward_id' => $ward->id, 'started_at' => '2026-06-02']);

        $this->actingAs($user)->get(route('counting.index'))
            ->assertOk()
            ->assertSee('Karşılaştırılan son kayıt')
            ->assertSee('+1');
    }

    public function test_shortcut_previews_and_updates_all_matching_statuses(): void
    {
        $user = $this->admin();
        $first = $this->inmate('Kısayol Bir', 'YENİ KAYIT');
        $second = $this->inmate('Kısayol İki', 'YENİ KAYIT');
        $other = $this->inmate('Dokunulmayacak', 'MEVCUT');

        $this->actingAs($user)->get(route('inmates.shortcuts.index', [
            'from_status' => 'YENİ KAYIT',
            'to_status' => 'MEVCUT',
        ]))->assertOk()
            ->assertSee('Etkilenecek kayıt')
            ->assertSee('value="2"', false);

        $this->actingAs($user)->post(route('inmates.shortcuts.update'), [
            'from_status' => 'YENİ KAYIT',
            'to_status' => 'MEVCUT',
            'confirm_count' => 2,
        ])->assertRedirect(route('inmates.shortcuts.index'));

        $this->assertSame('MEVCUT', $first->fresh()->status_code);
        $this->assertSame('MEVCUT', $second->fresh()->status_code);
        $this->assertSame('MEVCUT', $other->fresh()->status_code);
    }

    public function test_shortcut_rejects_stale_preview_count(): void
    {
        $user = $this->admin();
        $this->inmate('Değişen Sayı', 'YENİ KAYIT');

        $this->actingAs($user)->post(route('inmates.shortcuts.update'), [
            'from_status' => 'YENİ KAYIT',
            'to_status' => 'MEVCUT',
            'confirm_count' => 0,
        ])->assertSessionHasErrors('confirm_count');
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create(['name' => 'Sayım Test Kurumu', 'short_name' => 'SAYIM', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Sayım Yöneticisi',
            'login_name' => 'sayim-admin-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmate(string $name, string $status): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'full_name' => $name,
            'status_code' => $status,
            'registered_at' => '2026-06-01',
            'is_active' => true,
        ]);
    }

    private function location(): array
    {
        $block = Block::create(['institution_id' => $this->institution()->id, 'code' => 'A', 'name' => 'A Blok', 'is_active' => true]);
        $ward = Ward::create(['block_id' => $block->id, 'code' => 'A1', 'name' => 'A1 Koğuş', 'capacity' => 12, 'is_active' => true]);

        return [$block, $ward];
    }
}
