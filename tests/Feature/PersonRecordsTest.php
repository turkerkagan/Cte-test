<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\People\PersonConflict;
use App\Services\People\PersonRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PersonRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $changes = []): array
    {
        return array_replace(['tc_identity' => '00000000001', 'full_name' => 'SENTETİK IŞIK İNCİ', 'admitted_on' => '2024-02-29', 'registered_on' => null], $changes);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_system_admin' => true])->refresh();
    }

    public function test_distinct_identity_preserves_duplicate_tc_nulls_unicode_and_names_longer_than_display_width(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        $one = $service->save($actor, $this->input());
        $two = $service->save($actor, $this->input(['full_name' => 'SENTETİK İKİNCİ KİŞİ']));
        $three = $service->save($actor, $this->input(['tc_identity' => null, 'full_name' => str_repeat('İ', 255), 'admitted_on' => null]));
        $four = $service->save($actor, $this->input(['tc_identity' => '', 'admitted_on' => '', 'registered_on' => '1000-01-01']));
        $this->assertNotSame($one, $two);
        $this->assertDatabaseCount('people', 4);
        $this->assertSame('00000000001', $service->detail($actor, $one)['tc_identity']);
        $this->assertSame('2024-02-29', $service->detail($actor, $one)['admitted_on']);
        $this->assertSame(255, mb_strlen($service->detail($actor, $three)['full_name']));
        $this->assertNull($service->detail($actor, $four)['tc_identity']);
        $this->assertNull($service->detail($actor, $four)['admitted_on']);
        $this->assertSame('1000-01-01', $service->detail($actor, $four)['registered_on']);
        $audit = DB::table('audit_events')->where('resource_type', 'person')->pluck('details')->implode('');
        $this->assertStringNotContainsString('00000000001', $audit);
        $this->assertStringNotContainsString('SENTETİK', $audit);
        $this->assertStringNotContainsString('2024-02-29', $audit);
    }

    public function test_invalid_dates_identity_and_system_overposting_never_mutate_people_or_audit(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        foreach ([
            ['tc_identity' => 12345678901], ['tc_identity' => '1234567890'], ['tc_identity' => '1234567890A'],
            ['full_name' => '   '], ['full_name' => str_repeat('a', 256)],
            ['admitted_on' => '0000-00-00'], ['admitted_on' => '2023-02-29'], ['admitted_on' => '2024-04-31'],
            ['admitted_on' => '2024-2-01'], ['admitted_on' => '2024-01-01T14:00:00'], ['registered_on' => '0999-12-31'],
            ['revision' => null], ['id' => 999], ['created_by' => $actor->id], ['huk_risk' => 'SENTETİK'],
        ] as $invalid) {
            try {
                $service->save($actor, $this->input($invalid));
                $this->fail('Geçersiz kayıt reddedilmeli.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach (['tc_identity', 'admitted_on', 'registered_on'] as $missing) {
            $input = $this->input();
            unset($input[$missing]);
            try {
                $service->save($actor, $input);
                $this->fail('Eksik alan reddedilmeli.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revision_no_op_and_stale_update_preserve_the_latest_record(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        $id = $service->save($actor, $this->input());
        $service->save($actor, $this->input(['revision' => 1]), $id);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame(1, $service->detail($actor, $id)['revision']);
        $updated = $this->input(['revision' => 1, 'full_name' => '  SENTETİK YENİ AD  ', 'registered_on' => '9999-12-31']);
        $service->save($actor, $updated, $id);
        $this->assertSame(2, $service->detail($actor, $id)['revision']);
        try {
            $service->save($actor, $this->input(['revision' => 1]), $id);
            $this->fail('Eski revision reddedilmeli.');
        } catch (PersonConflict) {
            $this->assertSame('SENTETİK YENİ AD', $service->detail($actor, $id)['full_name']);
        }
        $this->assertDatabaseCount('audit_events', 2);
        $event = json_decode(DB::table('audit_events')->latest('id')->value('details'), true);
        $this->assertSame(['full_name', 'registered_on'], $event['changed_fields']);
        $before = $service->detail($actor, $id);
        try {
            $service->save($actor, $this->input(), $id);
            $this->fail('Düzenlemede revision zorunlu.');
        } catch (ValidationException) {
            $this->assertSame($before, $service->detail($actor, $id));
        }
    }

    public function test_late_audit_failure_rolls_back_create_and_update(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        $id = $service->save($actor, $this->input());
        $before = (array) DB::table('people')->where('id', $id)->first();
        DB::statement("CREATE TRIGGER synthetic_person_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        foreach ([null, $id] as $target) {
            try {
                $service->save($actor, $this->input($target === null ? [] : ['revision' => 1, 'full_name' => 'SENTETİK DEĞİŞİM']), $target);
                $this->fail('Audit hatası işlemi geri almalı.');
            } catch (QueryException) {
                $this->assertSame($before, (array) DB::table('people')->where('id', $id)->first());
                $this->assertDatabaseCount('people', 1);
                $this->assertDatabaseCount('audit_events', 1);
            }
        }
    }

    public function test_fresh_actor_rejects_revoked_inactive_and_password_change_accounts_before_read_or_write(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        $id = $service->save($actor, $this->input());
        foreach ([['is_system_admin' => false], ['is_active' => false], ['must_change_password' => true]] as $revoked) {
            DB::table('users')->where('id', $actor->id)->update(array_replace(['is_system_admin' => true, 'is_active' => true, 'must_change_password' => false], $revoked));
            foreach (['read', 'write'] as $operation) {
                try {
                    $operation === 'read' ? $service->detail($actor, $id) : $service->save($actor, $this->input());
                    $this->fail('Güncel yetki kontrolü gerekli.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('people', 1);
                }
            }
        }
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_missing_people_and_deleted_actor_do_not_create_records_and_actor_fk_preserves_identity(): void
    {
        $actor = $this->admin();
        $service = app(PersonRecords::class);
        foreach (['read', 'write'] as $operation) {
            try {
                $operation === 'read' ? $service->detail($actor, 999) : $service->save($actor, $this->input(['revision' => 1]), 999);
                $this->fail('Olmayan kişi 404 olmalı.');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
        $id = $service->save($actor, $this->input());
        $actor->delete();
        $this->assertDatabaseHas('people', ['id' => $id, 'created_by' => null, 'updated_by' => null, 'full_name' => 'SENTETİK IŞIK İNCİ']);
        try {
            $service->save($actor, $this->input());
            $this->fail('Silinmiş aktör reddedilmeli.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertTrue(Schema::hasTable('people'));
        $this->assertDatabaseCount('people', 1);
    }
}
