<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\People\PersonList;
use App\Services\People\PersonRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $changes = []): array
    {
        return array_replace(['tc_identity' => '00000000001', 'full_name' => 'SENTETİK IŞIK İNCİ', 'admitted_on' => '2024-02-29', 'registered_on' => null], $changes);
    }

    private function account(bool $admin = true): User
    {
        $actor = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function grant(User $admin, User $staff, ?string $permission): void
    {
        $page = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
        if ($page === null) {
            app(PageCatalog::class)->save($admin, ['code' => 'hukumlu_kayit', 'name' => 'Ana sayfa', 'path' => '/portal']);
            $page = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
        }
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => $permission === null ? 'remove' : 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission]);
    }

    public function test_original_home_columns_are_projected_without_case_secrets_or_risk_for_staff(): void
    {
        $admin = $this->account();
        $id = app(PersonRecords::class)->save($admin, $this->input());
        DB::table('person_case_data')->insert(['person_id' => $id, 'huk_saglik' => 'SENTETİK SAĞLIK', 'huk_notlar' => 'SENTETİK GENEL NOT', 'egt_domainsifre' => 'SYNTHETIC_SECRET']);
        $this->account(false);
        $this->get('/portal?scope=all')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('rows.0.health', 'SENTETİK SAĞLIK')->where('rows.0.notes', 'SENTETİK GENEL NOT')
            ->has('rows.0.work_place')->has('rows.0.work_decision')->missing('rows.0.risk')->missing('rows.0.egt_domainsifre'))
            ->assertDontSee('SYNTHETIC_SECRET');
    }

    public function test_guests_cannot_list_read_create_or_update_people(): void
    {
        foreach (['/portal', '/kisiler/yeni', '/kisiler/1/duzenle'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post('/kisiler', $this->input())->assertRedirect(route('login'));
        $this->put('/kisiler/1', $this->input(['revision' => 1]))->assertRedirect(route('login'));
        $this->assertDatabaseCount('people', 0);
    }

    public function test_staff_home_read_is_fixed_but_all_write_routes_require_explicit_permission(): void
    {
        $admin = $this->account();
        $id = app(PersonRecords::class)->save($admin, $this->input());
        $staff = $this->account(false);
        $this->get('/portal?scope=all')->assertOk()->assertInertia(fn (Assert $p) => $p->component('People')->where('canWrite', false)->has('rows', 1)->where('rows.0.id', $id)->missing('rows.0.editUrl')->missing('rows.0.created_by')->missing('rows.0.revision'));
        $this->get('/kisiler/yeni')->assertForbidden();
        $this->get('/kisiler/'.$id.'/duzenle')->assertForbidden();
        $this->postJson('/kisiler', $this->input())->assertForbidden();
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 1]))->assertForbidden();
        $this->grant($admin, $staff, 'giris');
        $this->postJson('/kisiler', $this->input())->assertForbidden();
        $this->grant($admin, $staff, 'yazma');
        $this->get('/portal?scope=all')->assertInertia(fn (Assert $p) => $p->where('canWrite', true)->where('rows.0.editUrl', route('people.edit', $id)));
        $this->postJson('/kisiler', $this->input(['tc_identity' => null]))->assertCreated();
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 1, 'full_name' => 'SENTETİK YENİ']))->assertOk();
        $this->grant($admin, $staff, null);
        $this->get('/kisiler/'.$id.'/duzenle')->assertForbidden();
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 2]))->assertForbidden();
        $this->assertDatabaseHas('people', ['id' => $id, 'full_name' => 'SENTETİK YENİ', 'revision' => 2]);
    }

    public function test_admin_defaults_only_new_registration_date_and_preserves_null_and_duplicate_tc(): void
    {
        $this->account();
        $this->travelTo(new \DateTimeImmutable('2026-10-04 00:15:00', new \DateTimeZone('Europe/Istanbul')));
        $this->get('/kisiler/yeni')->assertInertia(fn (Assert $p) => $p->component('PersonEditor')->where('record', null)->where('defaultRegisteredOn', '2026-10-04'));
        $one = $this->postJson('/kisiler', $this->input())->assertCreated()->json('id');
        $two = $this->postJson('/kisiler', $this->input())->assertCreated()->json('id');
        $this->assertNotSame($one, $two);
        $this->get('/kisiler/'.$one.'/duzenle')->assertInertia(fn (Assert $p) => $p->where('record.registered_on', null)->where('defaultRegisteredOn', null)->where('record.revision', 1));
        $this->get('/')->assertRedirect(route('portal'));
        $this->get('/portal?scope=all')->assertHeader('Cache-Control', 'no-store, private');
        $this->travelBack();
    }

    public function test_search_filters_before_paging_supports_turkish_literal_wildcards_stable_null_last_sort(): void
    {
        $admin = $this->account();
        $service = app(PersonRecords::class);
        for ($i = 0; $i < 30; $i++) {
            $service->save($admin, $this->input(['full_name' => 'SENTETİK IŞIK %_!', 'admitted_on' => $i === 29 ? null : '2024-02-29']));
        }
        $service->save($admin, $this->input(['full_name' => 'SENTETİK BAŞKA', 'tc_identity' => '00000000002']));
        $query = app(PersonList::class);
        $state = ['scope' => 'all', 'q' => 'ışık %_!', 'tc' => '00000000001', 'per_page' => 25, 'sort_field' => 'admitted_on', 'sort_direction' => 'desc'];
        $first = $query->page($admin, $state);
        $second = $query->page($admin, $state + ['page' => 2]);
        $this->assertSame(30, $first['pagination']['total']);
        $this->assertCount(25, $first['rows']);
        $this->assertCount(5, $second['rows']);
        $this->assertSame(range(1, 25), array_column($first['rows'], 'id'));
        $this->assertSame(range(26, 30), array_column($second['rows'], 'id'));
        $this->assertNull($second['rows'][4]['admitted_on']);
        $this->assertSame(['id', 'tc_identity', 'full_name', 'admitted_on', 'registered_on'], array_keys($first['rows'][0]));
        foreach ([['tc' => '1'], ['q' => str_repeat('a', 151)], ['sort_field' => 'password'], ['sort_direction' => 'SQL'], ['per_page' => 100001], ['page' => 0]] as $invalid) {
            $this->getJson('/portal?'.http_build_query($invalid))->assertUnprocessable();
        }
        $this->assertCount(30, $query->page($admin, array_replace($state, ['per_page' => 1000]))['rows']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $query->page($admin, ['per_page' => 25]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $query->page($admin, ['per_page' => 100]);
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_http_revision_no_op_validation_and_audit_failure_preserve_latest_record(): void
    {
        $this->account();
        $id = $this->postJson('/kisiler', $this->input())->assertCreated()->json('id');
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 1]))->assertOk();
        $this->assertDatabaseCount('audit_events', 1);
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 1, 'full_name' => 'SENTETİK YENİ']))->assertOk();
        $this->putJson('/kisiler/'.$id, $this->input(['revision' => 1]))->assertConflict();
        $this->postJson('/kisiler', $this->input(['admitted_on' => '2023-02-29']))->assertUnprocessable()->assertJsonValidationErrors('admitted_on');
        $this->postJson('/kisiler', $this->input(['tc_identity' => '1234567890']))->assertUnprocessable()->assertJsonValidationErrors('tc_identity');
        $this->postJson('/kisiler', $this->input(['id' => 999]))->assertUnprocessable();
        $this->postJson('/kisiler', $this->input(['revision' => null]))->assertUnprocessable();
        $this->assertDatabaseHas('people', ['id' => $id, 'full_name' => 'SENTETİK YENİ', 'revision' => 2]);
        DB::statement("CREATE TRIGGER synthetic_runtime_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SENTETİK SQL SECRET'); END");
        foreach ([null, $id] as $target) {
            $response = $target === null ? $this->postJson('/kisiler', $this->input()) : $this->putJson('/kisiler/'.$id, $this->input(['revision' => 2]));
            $response->assertUnprocessable()->assertJsonValidationErrors('operation');
            $this->assertStringNotContainsString('SQL SECRET', $response->getContent());
            $this->assertDatabaseCount('people', 1);
            $this->assertDatabaseCount('audit_events', 2);
        }
    }

    public function test_fresh_actor_and_session_invalidation_protect_person_routes(): void
    {
        $admin = $this->account();
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => false]);
        $this->postJson('/kisiler', $this->input())->assertForbidden();
        DB::table('users')->where('id', $admin->id)->update(['must_change_password' => true]);
        $this->actingAs($admin->fresh())->get('/portal?scope=all')->assertRedirect(route('password.edit'));
        DB::table('users')->where('id', $admin->id)->update(['must_change_password' => false, 'session_version' => 1]);
        $this->actingAs($admin->fresh())->get('/kisiler/yeni')->assertRedirect(route('login'));
        $this->assertDatabaseCount('people', 0);
    }

    public function test_csrf_and_missing_record_are_closed_without_mutations(): void
    {
        $this->account();
        $this->get('/kisiler/999/duzenle')->assertNotFound();
        $this->putJson('/kisiler/999', $this->input(['revision' => 1]))->assertNotFound();
        $incomplete = $this->input();
        unset($incomplete['registered_on']);
        $this->postJson('/kisiler', $incomplete)->assertUnprocessable()->assertJsonValidationErrors('registered_on');
        $this->app->instance('env', 'production');
        try {
            $this->postJson('/kisiler', $this->input())->assertStatus(419);
            $this->putJson('/kisiler/999', $this->input(['revision' => 1]))->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
