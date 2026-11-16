<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Housing\PersonPlacement;
use App\Services\People\PersonRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $a = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function person(User $a): int
    {
        return app(PersonRecords::class)->save($a, ['tc_identity' => null, 'full_name' => 'SENTETİK YERLEŞİM', 'admitted_on' => null, 'registered_on' => null]);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['status_id' => 1, 'block_id' => 1, 'room_id' => 1, 'bed_id' => DB::table('housing_beds')->where('room_id', 1)->where('number', 1)->value('id'), 'revision' => 0, 'status_revision' => 0], $changes);
    }

    private function current(User $a, int $p, array $changes = []): array
    {
        return array_replace(app(PersonPlacement::class)->detail($a, $p)['record'], $changes);
    }

    public function test_atomic_assignment_no_op_stale_and_shared_list_summary(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $url = '/kisiler/'.$p.'/yerlesim';
        $this->putJson($url, $this->input())->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('status_revision', 1);
        $this->assertDatabaseHas('housing_occupancies', ['person_id' => $p, 'bed_id' => $this->input()['bed_id']]);
        $audit = DB::table('audit_events')->count();
        $this->putJson($url, $this->current($a, $p))->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame($audit, DB::table('audit_events')->count());
        $this->putJson($url, $this->input())->assertConflict();
        $this->get($url)->assertInertia(fn (Assert $v) => $v->component('PlacementEditor')->where('person.id', $p)->where('record.room_id', 1)->missing('person.admitted_on'));
        $this->get('/portal')->assertInertia(fn (Assert $v) => $v->where('rows.0.placement.status', 'YENİ KAYIT')->where('rows.0.placement.bed', '1')->where('rows.0.placementUrl', route('placement.edit', $p)));
    }

    public function test_competing_people_are_rejected_and_move_releases_old_bed(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $two = $this->person($a);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertOk();
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input())->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->assertDatabaseMissing('person_locations', ['person_id' => $two]);
        $next = DB::table('housing_beds')->where('room_id', 1)->where('number', 2)->value('id');
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->current($a, $p, ['bed_id' => $next]))->assertOk();
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input())->assertOk();
        $this->assertDatabaseCount('housing_occupancies', 2);
        $this->assertDatabaseHas('housing_occupancies', ['person_id' => $p, 'bed_id' => $next]);
    }

    public function test_status_switch_releases_reservation_and_rename_does_not_change_behavior(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertOk();
        $s = $this->postJson('/yonetim/durumlar', ['code' => 'synthetic_inactive', 'label' => 'SENTETİK PASİF', 'is_active' => true, 'included_in_active_list' => false, 'counts_room' => false, 'reserves_bed' => false])->assertCreated()->json('id');
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->current($a, $p, ['status_id' => $s]))->assertOk();
        $this->assertDatabaseCount('housing_occupancies', 0);
        $this->assertDatabaseHas('person_locations', ['person_id' => $p, 'room_id' => 1]);
        DB::table('person_statuses')->where('id', $s)->update(['label' => 'SENTETİK YENİ AD']);
        $this->assertSame('SENTETİK YENİ AD', app(PersonPlacement::class)->summaries([$p])[$p]['status']);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->current($a, $p, ['status_id' => 1]))->assertOk();
        $this->assertDatabaseCount('housing_occupancies', 1);
    }

    public function test_bedless_special_zero_capacity_and_partial_locations_are_preserved(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $two = $this->person($a);
        $bed = DB::table('housing_beds')->where('room_id', 60)->where('number', 0)->value('id');
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input(['block_id' => 6, 'room_id' => 60, 'bed_id' => $bed]))->assertOk();
        $this->assertSame('Ranzasız', app(PersonPlacement::class)->summaries([$p])[$p]['bed']);
        $this->assertDatabaseHas('housing_rooms', ['id' => 60, 'capacity' => 0, 'bed_count' => 0]);
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input(['block_id' => 6, 'room_id' => 60, 'bed_id' => $bed]))->assertUnprocessable();
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input(['block_id' => 6, 'room_id' => 60, 'bed_id' => null]))->assertOk();
        $this->assertDatabaseMissing('housing_occupancies', ['person_id' => $two]);
        $room = (array) DB::table('housing_rooms')->where('id', 60)->first();
        $edit = array_intersect_key($room, array_flip(['code', 'name', 'block_id', 'bed_count', 'capacity', 'kind', 'is_active', 'revision']));
        $this->putJson('/yonetim/yerlesim/rooms/60', $edit + [])->assertOk();
        $this->putJson('/yonetim/yerlesim/rooms/60', array_replace($edit, ['is_active' => false]))->assertUnprocessable();
        $this->putJson('/yonetim/yerlesim/rooms/60', array_replace($edit, ['bed_count' => 5]))->assertUnprocessable();
        $this->putJson('/yonetim/yerlesim/blocks/6', ['code' => 'HASTANE', 'name' => 'HASTANE', 'is_active' => false, 'revision' => 1])->assertUnprocessable();
    }

    public function test_capacity_checks_unreserved_counting_people_and_hierarchy_validation(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $two = $this->person($a);
        DB::table('housing_rooms')->where('id', 1)->update(['capacity' => 1]);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input(['bed_id' => null]))->assertOk();
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input())->assertUnprocessable()->assertJsonValidationErrors('room_id');
        foreach ([['block_id' => 2], ['room_id' => 999], ['bed_id' => 999], ['status_id' => 999], ['person_id' => 999]] as $changes) {
            $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input($changes))->assertUnprocessable();
        }
        $room = (array) DB::table('housing_rooms')->where('id', 1)->first();
        $edit = array_intersect_key($room, array_flip(['code', 'name', 'block_id', 'bed_count', 'capacity', 'kind', 'is_active', 'revision']));
        $this->putJson('/yonetim/yerlesim/rooms/1', array_replace($edit, ['capacity' => 0]))->assertUnprocessable();
        $this->putJson('/yonetim/yerlesim/rooms/1', array_replace($edit, ['is_active' => false]))->assertUnprocessable();
        $this->putJson('/yonetim/yerlesim/rooms/1', array_replace($edit, ['block_id' => 7]))->assertUnprocessable();
    }

    public function test_archived_status_is_retained_for_owner_but_not_new_assignments(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $two = $this->person($a);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertOk();
        DB::table('person_statuses')->where('id', 1)->update(['is_active' => false]);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->current($a, $p, ['bed_id' => null]))->assertOk();
        $this->putJson('/kisiler/'.$two.'/yerlesim', $this->input())->assertUnprocessable()->assertJsonValidationErrors('status_id');
    }

    public function test_audit_failure_rolls_back_reservation_status_and_location_without_leaking_sql(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertOk();
        $before = $this->current($a, $p);
        $reservation = DB::table('housing_occupancies')->where('person_id', $p)->first();
        $audit = DB::table('audit_events')->count();
        DB::statement("CREATE TRIGGER synthetic_placement_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SENTETİK SQL SECRET'); END");
        $response = $this->putJson('/kisiler/'.$p.'/yerlesim', $this->current($a, $p, ['status_id' => null, 'bed_id' => null]));
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SQL SECRET', $response->getContent());
        $this->assertSame($before, $this->current($a, $p));
        $this->assertEquals($reservation, DB::table('housing_occupancies')->where('person_id', $p)->first());
        $this->assertSame($audit, DB::table('audit_events')->count());
    }

    public function test_missing_placement_schema_returns_installation_message(): void
    {
        $a = $this->actor();
        $p = $this->person($a);
        DB::statement('DROP TABLE person_locations');
        $this->get('/kisiler/'.$p.'/yerlesim')->assertStatus(503);
        $this->get('/portal')->assertStatus(503);
    }

    public function test_read_write_auth_fresh_actor_and_csrf_guard_placement(): void
    {
        $this->get('/kisiler/1/yerlesim')->assertRedirect(route('login'));
        $a = $this->actor();
        $p = $this->person($a);
        $this->actor(false);
        $this->get('/kisiler/'.$p.'/yerlesim')->assertForbidden();
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertForbidden();
        $this->get('/portal')->assertInertia(fn (Assert $v) => $v->missing('rows.0.placementUrl'));
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);
        $this->get('/kisiler/999/yerlesim')->assertNotFound();
        $this->app->instance('env', 'production');
        try {
            $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        DB::table('users')->where('id', $a->id)->update(['is_system_admin' => false]);
        $this->putJson('/kisiler/'.$p.'/yerlesim', $this->input())->assertForbidden();
        $this->assertDatabaseCount('person_locations', 0);
    }
}
