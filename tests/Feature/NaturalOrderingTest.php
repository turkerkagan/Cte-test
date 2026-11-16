<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Housing\HousingDefinitions;
use App\Services\Housing\HousingSummary;
use App\Services\Housing\PersonPlacement;
use App\Services\Institution\CountReport;
use App\Services\Institution\InstitutionRecords;
use App\Services\People\HomeRecords;
use App\Services\People\PersonList;
use App\Services\People\PersonRecords;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NaturalOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function person(User $actor, string $room): int
    {
        $id = app(PersonRecords::class)->save($actor, ['full_name' => 'SENTETİK SIRALAMA', 'tc_identity' => null, 'admitted_on' => null, 'registered_on' => null]);
        $roomId = DB::table('housing_rooms')->where('block_id', 1)->where('name', $room)->value('id');
        app(PersonPlacement::class)->save($actor, $id, ['status_id' => 1, 'block_id' => 1, 'room_id' => $roomId, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);

        return $id;
    }

    public function test_all_housing_projections_ignore_import_position_and_show_1_through_24(): void
    {
        $actor = $this->actor();
        foreach (DB::table('housing_rooms')->get() as $room) {
            DB::table('housing_rooms')->where('id', $room->id)->update(['position' => 1000 - $room->id]);
        }
        $expected = array_map('strval', range(1, 24));
        $options = app(HomeRecords::class)->options($actor);
        $this->assertSame(['A', 'B', 'C'], array_slice(array_column($options['blocks'], 'name'), 0, 3));
        foreach ([1, 3] as $block) {
            $this->assertSame($expected, collect($options['rooms'])->where('block_id', $block)->pluck('name')->all());
            $this->assertSame($expected, collect(app(HousingDefinitions::class)->snapshot($actor)['rooms'])->where('block_id', $block)->pluck('name')->all());
        }
        $this->assertSame($expected, collect(app(HousingSummary::class)->read()['rooms'])->where('block', 'B')->pluck('room')->all());
        $this->assertSame(array_map(fn ($number) => 'B · '.$number, range(1, 24)), collect(app(InstitutionRecords::class)->rooms())->where('block', 'B')->pluck('name')->all());
        $this->assertSame(array_map(fn ($number) => 'B · '.$number, range(1, 24)), collect(app(CountReport::class)->read()['rows'])->filter(fn ($row) => str_starts_with($row['key'], 'B-'))->map(fn ($row) => $row['system'][0]['room'])->values()->all());
        $this->get('/koguslar')->assertInertia(fn (Assert $page) => $page->where('rooms', fn ($rooms) => collect($rooms)->where('block', 'B')->pluck('name')->all() === $expected));
        $person = $this->person($actor, '24');
        $placement = app(PersonPlacement::class)->detailForHomeRead($actor, $person);
        $this->assertSame($expected, collect($placement['rooms'])->where('block_id', 3)->pluck('name')->all());
    }

    public function test_room_sort_and_filter_values_are_natural_before_pagination_and_export(): void
    {
        $actor = $this->actor();
        $ids = [];
        foreach (['10', '2', '1'] as $room) {
            for ($i = 0; $i < 10; $i++) {
                $ids[$room][] = $this->person($actor, $room);
            }
        }
        $expected = [...$ids[1], ...$ids[2], ...$ids[10]];
        $people = app(PersonList::class);
        $state = ['sort_field' => 'room', 'per_page' => 7];
        $this->assertSame(array_slice($expected, 0, 7), array_column($people->page($actor, $state)['rows'], 'id'));
        $this->assertSame(array_slice($expected, 7, 7), array_column($people->page($actor, $state + ['page' => 2])['rows'], 'id'));
        $this->assertSame(['1', '2', '10'], array_column($people->values($actor, 'room', [])['values'], 'label'));
        $case = app(CaseList::class);
        $state = ['sort' => 'room', 'direction' => 'asc', 'per_page' => 25];
        // Unit lists show every matching record after pagination controls were removed.
        $this->assertSame($expected, array_column($case->page($actor, 'infaz', $state)['rows'], 'id'));
        $this->assertSame($expected, array_column($case->page($actor, 'infaz', $state + ['page' => 2])['rows'], 'id'));
        $export = $case->page($actor, 'infaz', $state, true);
        $roomColumn = array_search('room', array_column($export['fields'], 'id'));
        $this->assertSame([...array_fill(0, 10, '1'), ...array_fill(0, 10, '2'), ...array_fill(0, 10, '10')], array_column($export['rows'], $roomColumn));
        $descending = $case->page($actor, 'infaz', ['sort' => 'room', 'direction' => 'desc'], true);
        $this->assertSame([...array_fill(0, 10, '10'), ...array_fill(0, 10, '2'), ...array_fill(0, 10, '1')], array_column($descending['rows'], $roomColumn));
        $this->assertSame(['1', '2', '10'], array_column($case->columnValues($actor, 'infaz', ['field' => 'room'])['values'], 'label'));
    }

    public function test_numeric_option_labels_sort_by_displayed_number_rather_than_option_id(): void
    {
        $actor = $this->actor();
        $list = DB::table('case_option_lists')->where('code', 'huk-risk')->value('id');
        $ids = [];
        foreach ([24, 10, 2, 1] as $number) {
            $option = DB::table('case_option_items')->insertGetId(['option_list_id' => $list, 'label' => (string) $number, 'legacy_value' => 'synthetic-'.$number, 'position' => 100 - $number, 'is_active' => true]);
            $id = $this->person($actor, '1');
            DB::table('person_case_data')->updateOrInsert(['person_id' => $id], ['huk_risk' => $option]);
            $ids[$number] = $id;
        }
        $people = app(PersonList::class);
        $this->assertSame([$ids[1], $ids[2], $ids[10], $ids[24]], array_column($people->page($actor, ['sort_field' => 'risk'])['rows'], 'id'));
        $case = app(CaseList::class);
        $this->assertSame([$ids[1], $ids[2], $ids[10], $ids[24]], array_column($case->page($actor, 'custom_rapor', ['sort' => 'huk_risk', 'direction' => 'asc'])['rows'], 'id'));
        $this->assertSame(['1', '2', '10', '24'], array_column($case->columnValues($actor, 'custom_rapor', ['field' => 'huk_risk'])['values'], 'label'));
        $choices = array_column(app(CaseRecords::class)->options()['huk_risk'], 'label');
        $this->assertSame(['1', '1', '2', '2', '3', '10', '24'], $choices);
    }

    public function test_bed_availability_current_assignment_and_bedless_option_are_preserved(): void
    {
        $actor = $this->actor();
        $person = $this->person($actor, '1');
        $roomId = DB::table('housing_rooms')->where('block_id', 1)->where('name', '1')->value('id');
        $beds = DB::table('housing_beds')->where('room_id', $roomId)->pluck('id', 'number');
        DB::table('housing_occupancies')->insert(['person_id' => $person, 'bed_id' => $beds[1], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('person_locations')->where('person_id', $person)->update(['bed_id' => $beds[1]]);
        $options = app(HomeRecords::class)->options($actor);
        $roomBeds = collect($options['beds'])->where('room_id', $roomId);
        $this->assertSame(array_map('strval', range(1, 12)), $roomBeds->pluck('label')->all());
        $this->assertSame(array_map('strval', range(2, 12)), $roomBeds->where('available', true)->pluck('label')->all());
        $this->assertTrue(collect(app(PersonPlacement::class)->detail($actor, $person)['beds'])->firstWhere('id', $beds[1])['available']);
        $this->assertContains('Ranzasız', array_column($options['beds'], 'label'));
        $mixed = collect([['name' => 'Koğuş 24'], ['name' => 'Koğuş 2'], ['name' => 'Koğuş 10'], ['name' => 'Koğuş 1']]);
        $this->assertSame(['Koğuş 1', 'Koğuş 2', 'Koğuş 10', 'Koğuş 24'], NaturalOrder::rows($mixed, ['name'])->pluck('name')->all());
        $this->assertSame(['-10', '-2', '0', '2.5', '10'], NaturalOrder::rows(collect(array_map(fn ($n) => ['n' => $n], ['10', '2.5', '-2', '0', '-10'])), ['n'])->pluck('n')->all());
    }
}
