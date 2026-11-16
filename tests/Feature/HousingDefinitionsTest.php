<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Housing\HousingDefinitions;
use App\Services\People\PersonRecords;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HousingDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $a = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function room(int $id = 1, array $changes = []): array
    {
        $r = DB::table('housing_rooms')->where('id', $id)->first();

        return array_replace(array_intersect_key((array) $r, array_flip(['code', 'name', 'block_id', 'bed_count', 'capacity', 'kind', 'is_active', 'revision'])), $changes);
    }

    private function occupy(User $actor, int $room = 1, int $number = 12): int
    {
        $person = app(PersonRecords::class)->save($actor, ['tc_identity' => null, 'full_name' => 'SENTETİK DOLU RANZA', 'admitted_on' => null, 'registered_on' => null]);
        $bed = DB::table('housing_beds')->where('room_id', $room)->where('number', $number)->value('id');
        DB::table('housing_occupancies')->insert(['person_id' => $person, 'bed_id' => $bed, 'created_at' => now(), 'updated_at' => now()]);

        return $bed;
    }

    public function test_clean_install_has_exact_legacy_configuration_and_no_person_data(): void
    {
        $a = $this->actor();
        $this->assertDatabaseCount('housing_blocks', 7);
        $this->assertDatabaseCount('housing_rooms', 62);
        $this->assertDatabaseCount('housing_beds', 824);
        $this->assertDatabaseCount('housing_occupancies', 0);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseHas('housing_rooms', ['block_id' => 2, 'code' => '8', 'bed_count' => 128]);
        $this->assertDatabaseHas('housing_rooms', ['block_id' => 2, 'code' => 'GEÇİCİ KOĞUŞ', 'bed_count' => 0, 'kind' => 'ozel']);
        $this->get('/yonetim/yerlesim')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->component('Housing')->has('blocks', 7)->has('rooms', 62)->missing('rooms.0.person_id'));
        $this->assertDatabaseHas('housing_rooms', ['id' => 32, 'block_id' => 2, 'code' => '8', 'name' => '8', 'bed_count' => 128, 'capacity' => 128]);
        $before = app(HousingDefinitions::class)->snapshot($a);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, app(HousingDefinitions::class)->snapshot($a));
    }

    public function test_admin_can_create_hierarchy_with_independent_room_counts_and_duplicate_codes_only_across_blocks(): void
    {
        $this->actor();
        $b = $this->postJson('/yonetim/yerlesim/blocks', ['code' => 'sentetik', 'name' => 'SENTETİK Blok', 'is_active' => true])->assertCreated()->json('id');
        $input = ['code' => '1', 'name' => 'SENTETİK Koğuş', 'block_id' => $b, 'bed_count' => 3, 'capacity' => 6, 'kind' => 'normal', 'is_active' => true];
        $r = $this->postJson('/yonetim/yerlesim/rooms', $input)->assertCreated()->json('id');
        $this->postJson('/yonetim/yerlesim/rooms', $input)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/yonetim/yerlesim/rooms', array_replace($input, ['code' => '2', 'bed_count' => 7]))->assertCreated();
        $this->assertSame(3, DB::table('housing_beds')->where('room_id', $r)->where('is_active', true)->count());
        $this->assertDatabaseHas('housing_rooms', ['id' => $r, 'capacity' => 6]);
        $this->postJson('/yonetim/yerlesim/rooms', array_replace($input, ['block_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('block_id');
        $this->postJson('/yonetim/yerlesim/blocks', ['code' => 'A', 'name' => 'DUP', 'is_active' => true])->assertUnprocessable();
        $this->postJson('/yonetim/yerlesim/blocks', ['code' => 'NEW', 'name' => 'DUP', 'is_active' => true, 'id' => 999])->assertUnprocessable();
    }

    public function test_capacity_shrink_preserves_bed_ids_and_regrowth_restores_existing_ids(): void
    {
        $a = $this->actor();
        $before = DB::table('housing_beds')->where('room_id', 1)->pluck('id', 'number')->all();
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['bed_count' => 8]))->assertOk();
        $this->assertSame(8, DB::table('housing_beds')->where('room_id', 1)->where('is_active', true)->count());
        $snapshot = app(HousingDefinitions::class)->snapshot($a);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, app(HousingDefinitions::class)->snapshot($a));
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['bed_count' => 12]))->assertOk();
        $this->assertSame($before, DB::table('housing_beds')->where('room_id', 1)->pluck('id', 'number')->all());
        $this->assertDatabaseCount('housing_beds', 824);
        $auditCount = DB::table('audit_events')->count();
        $revision = DB::table('housing_rooms')->where('id', 1)->value('revision');
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room())->assertOk();
        $this->assertSame($auditCount, DB::table('audit_events')->count());
        $this->assertSame($revision, DB::table('housing_rooms')->where('id', 1)->value('revision'));
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['revision' => 1, 'bed_count' => 2]))->assertConflict();
    }

    public function test_occupied_upper_bed_capacity_parent_and_archival_are_protected(): void
    {
        $a = $this->actor();
        $bed = $this->occupy($a);
        $before = DB::table('housing_rooms')->where('id', 1)->first();
        foreach ([['bed_count' => 11], ['capacity' => 0], ['block_id' => 7], ['is_active' => false]] as $changes) {
            $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, $changes))->assertUnprocessable()->assertJsonValidationErrors('operation');
        }
        $this->putJson('/yonetim/yerlesim/blocks/1', ['code' => 'A', 'name' => 'A', 'is_active' => false, 'revision' => 1])->assertUnprocessable();
        $this->assertEquals($before, DB::table('housing_rooms')->where('id', 1)->first());
        $this->assertDatabaseHas('housing_beds', ['id' => $bed, 'is_active' => true]);
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['name' => 'SENTETİK YENİ AD']))->assertOk();
        $this->assertDatabaseHas('housing_occupancies', ['bed_id' => $bed]);
        try {
            DB::table('housing_beds')->where('id', $bed)->delete();
            $this->fail('Occupied bed must have restrictive FK');
        } catch (QueryException) {
            $this->assertDatabaseHas('housing_beds', ['id' => $bed]);
        }
        $other = app(PersonRecords::class)->save($a, ['tc_identity' => null, 'full_name' => 'SENTETİK İKİNCİ KİŞİ', 'admitted_on' => null, 'registered_on' => null]);
        $occupied = DB::table('housing_occupancies')->first();
        $freeBed = DB::table('housing_beds')->where('room_id', 1)->where('number', 11)->value('id');
        foreach ([['person_id' => $other, 'bed_id' => $bed], ['person_id' => $occupied->person_id, 'bed_id' => $freeBed]] as $duplicate) {
            try {
                DB::table('housing_occupancies')->insert($duplicate);
                $this->fail('Person and bed uniqueness must both hold independently.');
            } catch (QueryException) {
                $this->assertDatabaseCount('housing_occupancies', 1);
            }
        }
    }

    public function test_archive_restore_and_room_move_keep_identity_without_resetting_counts(): void
    {
        $this->actor();
        $this->putJson('/yonetim/yerlesim/blocks/5', ['code' => 'HAFTA SONU KOĞUŞU', 'name' => 'SENTETİK ARŞİV', 'is_active' => false, 'revision' => 1])->assertOk();
        $input = $this->room(1, ['block_id' => 5]);
        unset($input['revision']);
        $this->postJson('/yonetim/yerlesim/rooms', $input)->assertUnprocessable()->assertJsonValidationErrors('block_id');
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['block_id' => 7, 'is_active' => false, 'bed_count' => 4]))->assertOk();
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['is_active' => true]))->assertOk();
        $this->assertDatabaseHas('housing_rooms', ['id' => 1, 'block_id' => 7, 'bed_count' => 4]);
    }

    public function test_audit_failure_rolls_back_definition_and_beds_and_redacts_sql(): void
    {
        $this->actor();
        $before = DB::table('housing_rooms')->where('id', 1)->first();
        $beds = DB::table('housing_beds')->get()->toArray();
        DB::statement("CREATE TRIGGER synthetic_housing_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SENTETİK SQL SECRET'); END");
        $response = $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, ['bed_count' => 15]));
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SQL SECRET', $response->getContent());
        $this->assertEquals($before, DB::table('housing_rooms')->where('id', 1)->first());
        $this->assertEquals($beds, DB::table('housing_beds')->get()->toArray());
        $this->postJson('/yonetim/yerlesim/blocks', ['code' => 'SYN', 'name' => 'SENTETİK', 'is_active' => true])->assertUnprocessable();
        $this->assertDatabaseCount('housing_blocks', 7);
    }

    public function test_http_auth_fresh_actor_validation_and_csrf_cannot_change_housing(): void
    {
        $this->get('/yonetim/yerlesim')->assertRedirect(route('login'));
        $this->actor(false);
        $this->get('/yonetim/yerlesim')->assertForbidden();
        $this->postJson('/yonetim/yerlesim/blocks', [])->assertForbidden();
        $a = $this->actor();
        foreach ([['bed_count' => 501], ['bed_count' => -1], ['kind' => 'other'], ['capacity' => 'abc'], ['name' => '   '], ['created_by' => $a->id]] as $changes) {
            $this->putJson('/yonetim/yerlesim/rooms/1', $this->room(1, $changes))->assertUnprocessable();
        }
        $this->putJson('/yonetim/yerlesim/rooms/999', $this->room())->assertNotFound();
        DB::table('users')->where('id', $a->id)->update(['is_system_admin' => false]);
        $this->putJson('/yonetim/yerlesim/rooms/1', $this->room())->assertForbidden();
        $this->actor();
        $this->app->instance('env', 'production');
        try {
            $this->putJson('/yonetim/yerlesim/rooms/1', $this->room())->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('housing_rooms', 62);
    }
}
