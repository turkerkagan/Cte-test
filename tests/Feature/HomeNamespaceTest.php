<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\People\PersonRecords;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeNamespaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections.sqlite.prefix' => 'cte_next_']);
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateFunction('FLOOR', static fn ($v) => $v === null ? null : floor((float) $v), 1);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_prefixed_empty_installation_renders_home_and_column_values_without_raw_alias_failures(): void
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);
        $this->get('/portal')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 0)->where('homeSummary.today', 0));
        foreach (['full_name', 'block', 'risk', 'registered_on'] as $field) {
            $this->getJson('/ana-kayitlar/degerler?field='.$field)->assertOk()->assertJsonPath('values', []);
        }
    }

    public function test_prefixed_person_placement_and_interview_editors_render_without_alias_errors(): void
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $person = app(PersonRecords::class)->save($actor, ['full_name' => 'SENTETİK ÖNEK TESTİ', 'tc_identity' => '00000000001', 'admitted_on' => null, 'registered_on' => null]);
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $this->get('/kisiler/'.$person.'/yerlesim')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PlacementEditor')->where('person.id', $person));
        $this->getJson('/ilk-gorus/kisiler/'.$person)->assertOk()->assertJsonPath('person.id', $person);
    }
}
