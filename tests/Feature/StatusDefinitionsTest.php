<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\People\PersonRecords;
use App\Services\Statuses\StatusDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatusDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $a = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function input(array $changes = []): array
    {
        return array_replace(['code' => 'synthetic', 'label' => 'SENTETİK DURUM', 'included_in_active_list' => false, 'counts_room' => false, 'reserves_bed' => false, 'is_active' => true], $changes);
    }

    private function edit(int $id = 1, array $changes = []): array
    {
        return array_replace(array_intersect_key((array) DB::table('person_statuses')->where('id', $id)->first(), array_flip(['code', 'label', 'included_in_active_list', 'counts_room', 'reserves_bed', 'is_active', 'revision'])), $changes);
    }

    public function test_default_statuses_match_legacy_and_read_is_admin_only(): void
    {
        $this->get('/yonetim/durumlar')->assertRedirect(route('login'));
        $this->actor(false);
        $this->get('/yonetim/durumlar')->assertForbidden();
        $a = $this->actor();
        $this->get('/yonetim/durumlar')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->component('Statuses')->has('rows', 7)->where('rows.0.label', 'YENİ KAYIT')->where('rows.0.usage_count', 0));
        $rows = app(StatusDefinitions::class)->rows($a);
        $this->assertSame(['YENİ KAYIT', 'MEVCUT', 'MİSAFİR', 'HASTANE', 'ÖĞRENCİ', 'ÖZEL İZİN', 'VERİ DEĞİŞİKLİĞİ'], array_column($rows, 'label'));
        foreach ($rows as $r) {
            $this->assertTrue($r['included_in_active_list']);
            $this->assertTrue($r['counts_room']);
            $this->assertTrue($r['reserves_bed']);
        }
        $this->assertDatabaseCount('person_status_assignments', 0);
        $this->assertDatabaseCount('people', 0);
    }

    public function test_create_requires_explicit_flags_and_rejects_overposting_duplicates_and_revoked_actor(): void
    {
        $a = $this->actor();
        $this->postJson('/yonetim/durumlar', $this->input())->assertCreated();
        $this->postJson('/yonetim/durumlar', $this->input())->assertUnprocessable()->assertJsonValidationErrors('code');
        foreach (['included_in_active_list', 'counts_room', 'reserves_bed'] as $flag) {
            $input = $this->input(['code' => 'new_'.$flag]);
            unset($input[$flag]);
            $this->postJson('/yonetim/durumlar', $input)->assertUnprocessable()->assertJsonValidationErrors($flag);
        }
        foreach ([['code' => 'Bad'], ['label' => '   '], ['reserves_bed' => 'yes'], ['revision' => null], ['person_id' => 999]] as $changes) {
            $this->postJson('/yonetim/durumlar', $this->input($changes))->assertUnprocessable();
        }
        DB::table('users')->where('id', $a->id)->update(['is_system_admin' => false]);
        $this->postJson('/yonetim/durumlar', $this->input(['code' => 'revoked']))->assertForbidden();
        $this->assertDatabaseCount('person_statuses', 8);
    }

    public function test_label_archive_restore_preserve_used_behavior_and_person_link(): void
    {
        $a = $this->actor();
        $p = app(PersonRecords::class)->save($a, ['tc_identity' => null, 'full_name' => 'SENTETİK', 'admitted_on' => null, 'registered_on' => null]);
        $link = DB::table('person_status_assignments')->insertGetId(['person_id' => $p, 'status_id' => 1]);
        foreach (['included_in_active_list', 'counts_room', 'reserves_bed'] as $flag) {
            $this->putJson('/yonetim/durumlar/1', $this->edit(1, [$flag => false]))->assertUnprocessable()->assertJsonValidationErrors('operation');
        }
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['label' => 'SENTETİK YENİ ETİKET']))->assertOk();
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['is_active' => false]))->assertOk();
        $this->assertDatabaseHas('person_status_assignments', ['id' => $link, 'person_id' => $p, 'status_id' => 1]);
        $this->assertDatabaseHas('person_statuses', ['id' => 1, 'included_in_active_list' => true, 'reserves_bed' => true]);
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['is_active' => true]))->assertOk();
        $this->assertSame(1, app(StatusDefinitions::class)->rows($a)[0]['usage_count']);
        try {
            DB::table('person_statuses')->where('id', 1)->delete();
            $this->fail('Used status must retain FK');
        } catch (QueryException) {
            $this->assertDatabaseCount('person_status_assignments', 1);
        }
    }

    public function test_revision_no_op_code_identity_and_repeated_migration_preserve_admin_changes(): void
    {
        $a = $this->actor();
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['label' => 'SENTETİK ÖZEL']))->assertOk();
        $this->putJson('/yonetim/durumlar/1', $this->edit())->assertOk();
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('person_statuses', ['id' => 1, 'revision' => 2]);
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['revision' => 1]))->assertConflict();
        $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['code' => 'changed']))->assertUnprocessable();
        $this->putJson('/yonetim/durumlar/999', $this->edit())->assertNotFound();
        $before = app(StatusDefinitions::class)->rows($a);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, app(StatusDefinitions::class)->rows($a));
    }

    public function test_audit_failure_is_atomic_redacted_and_csrf_is_enforced(): void
    {
        $this->actor();
        $before = $this->edit();
        DB::statement("CREATE TRIGGER synthetic_status_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SENTETİK SQL SECRET'); END");
        $response = $this->putJson('/yonetim/durumlar/1', $this->edit(1, ['label' => 'SENTETİK HATA']));
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SQL SECRET', $response->getContent());
        $this->assertSame($before, $this->edit());
        $this->postJson('/yonetim/durumlar', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('person_statuses', 7);
        $this->app->instance('env', 'production');
        try {
            $this->postJson('/yonetim/durumlar', $this->input())->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
