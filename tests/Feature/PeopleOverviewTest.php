<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Housing\HousingSummary;
use App\Services\Housing\PersonPlacement;
use App\Services\People\PersonList;
use App\Services\People\PersonRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeopleOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function person(User $actor, ?int $status, ?int $room = null, ?int $bed = null, int $block = 1): int
    {
        $id = app(PersonRecords::class)->save($actor, ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK IŞIK', 'admitted_on' => null, 'registered_on' => null]);
        if ($status !== null) {
            app(PersonPlacement::class)->save($actor, $id, ['status_id' => $status, 'block_id' => $room === null ? null : $block, 'room_id' => $room, 'bed_id' => $bed, 'revision' => 0, 'status_revision' => 0]);
        }

        return $id;
    }

    private function createStatus(array $flags): int
    {
        return $this->postJson('/yonetim/durumlar', $flags + ['code' => 'synthetic', 'label' => 'SENTETİK', 'is_active' => true])->assertCreated()->json('id');
    }

    public function test_default_active_scope_filters_before_pagination_and_keeps_archived_status_membership(): void
    {
        $actor = $this->actor();
        $inactive = $this->createStatus(['included_in_active_list' => false, 'counts_room' => false, 'reserves_bed' => false]);
        $this->person($actor, null);
        $this->person($actor, $inactive);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->person($actor, 1);
        }
        DB::table('person_statuses')->where('id', 1)->update(['label' => 'SENTETİK YENİ AD', 'is_active' => false]);
        $service = app(PersonList::class);
        $state = ['q' => 'ışık', 'tc' => '00000000001', 'per_page' => 25];
        $first = $service->page($actor, $state);
        $second = $service->page($actor, $state + ['page' => 2]);
        $this->assertSame('active', $first['queryState']['scope']);
        $this->assertSame(30, $first['pagination']['total']);
        $this->assertSame(array_slice($ids, 0, 25), array_column($first['rows'], 'id'));
        $this->assertSame(array_slice($ids, 25), array_column($second['rows'], 'id'));
        $this->assertSame(32, $service->page($actor, ['scope' => 'all'])['pagination']['total']);
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->where('queryState.scope', 'active')->where('pagination.total', 30)->where('rows.0.placement.status', 'SENTETİK YENİ AD'));
        $this->getJson('/portal?scope=SQL')->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->getJson('/portal?scope=')->assertUnprocessable();
    }

    public function test_occupancy_is_independent_of_list_membership_search_and_physical_bed_reservation(): void
    {
        $actor = $this->actor();
        $status = $this->createStatus(['included_in_active_list' => false, 'counts_room' => true, 'reserves_bed' => false]);
        $bed = DB::table('housing_beds')->where('room_id', 1)->where('number', 1)->value('id');
        $this->person($actor, 1, 1, $bed);
        $this->person($actor, $status, 1);
        $this->person($actor, null);
        $virtual = DB::table('housing_beds')->where('room_id', 60)->where('number', 0)->value('id');
        $this->person($actor, 1, 60, $virtual, 6);
        $this->person($actor, 1, 60, null, 6);
        $summary = app(HousingSummary::class)->read();
        foreach (['total' => 5, 'active' => 3, 'unassigned' => 1, 'present' => 4, 'physical' => 820, 'reserved' => 1, 'available' => 819, 'bedless_reserved' => 1] as $key => $value) {
            $this->assertSame($value, $summary[$key], $key);
        }
        $rooms = collect($summary['rooms'])->keyBy('id');
        $this->assertSame(2, $rooms[1]['present']);
        $this->assertSame(11, $rooms[1]['available']);
        $this->assertSame(2, $rooms[60]['present']);
        $this->assertSame(0, $rooms[60]['physical']);
        $this->assertSame(0, $rooms[60]['capacity']);
        foreach (['/portal?q=ABSENT', '/portal?scope=all&page=2&per_page=25'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $p) => $p->has('rows', 0)->where('housingSummary', $summary));
        }
        $staff = User::factory()->create(['is_system_admin' => false])->refresh();
        $this->actingAs($staff)->get('/portal')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canWrite', false)->where('housingSummary', $summary));
    }

    public function test_empty_overview_and_archived_empty_rooms_and_inactive_beds(): void
    {
        $this->actor();
        DB::table('housing_rooms')->where('id', 1)->update(['is_active' => false]);
        DB::table('housing_beds')->where('room_id', 2)->where('number', 1)->update(['is_active' => false]);
        $summary = app(HousingSummary::class)->read();
        $this->assertSame(0, $summary['present']);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(807, $summary['physical']);
        $this->assertSame(807, $summary['available']);
        $this->assertNotContains(1, array_column($summary['rooms'], 'id'));
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->has('rows', 0)->where('housingSummary.total', 0));
    }

    public function test_overview_query_count_does_not_grow_with_people(): void
    {
        $actor = $this->actor();
        $measure = function () use ($actor) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(PersonList::class)->page($actor);
            app(HousingSummary::class)->read();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $this->person($actor, 1);
        $before = $measure();
        for ($i = 0; $i < 30; $i++) {
            $this->person($actor, 1);
        }
        $this->assertSame($before, $measure());
    }
}
