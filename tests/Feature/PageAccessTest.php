<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Access\PagePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $code = 'synthetic'): int
    {
        return DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => 'SENTETİK '.$code]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_system_admin' => true])->refresh();
    }

    private function change(User $actor, array $ids, string $type, int $target, ?string $permission): void
    {
        app(PagePermissions::class)->apply($actor, [
            'pages' => array_map(fn ($id) => ['id' => $id, 'revision' => DB::table('portal_pages')->where('id', $id)->value('revision')], $ids),
            'target_type' => $type, 'target_id' => $target,
            'operation' => $permission === null ? 'remove' : 'grant', 'permission' => $permission, 'reason' => 'Sentetik erişim gerekçesi',
        ]);
    }

    public function test_empty_database_defaults_closed_except_fixed_homepage_read(): void
    {
        $staff = User::factory()->create()->refresh();
        $service = app(PageAccess::class);
        $this->assertFalse($service->allows($staff, 'missing'));
        $this->assertTrue($service->allows($staff, 'hukumlu_kayit'));
        $this->assertFalse($service->allows($staff, 'hukumlu_kayit', 'yazma'));
        $this->assertFalse($service->allows($staff, 'hukumlu_kayit', 'tam'));
        $home = $this->page('hukumlu_kayit');
        $this->assertSame([['type' => 'homepage', 'permission' => 'giris']], $service->matrix($staff)['hukumlu_kayit']['sources']);
        $this->change($this->admin(), [$home], 'kisi', $staff->id, 'yazma');
        $this->assertTrue($service->allows($staff, 'hukumlu_kayit', 'yazma'));
    }

    public function test_permissions_merge_by_or_and_removing_personal_permission_preserves_inheritance(): void
    {
        $page = $this->page();
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $title = DB::table('job_titles')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $staff = User::factory()->create(['department_id' => $department, 'job_title_id' => $title])->refresh();
        $admin = $this->admin();
        $this->change($admin, [$page], 'birim', $department, 'giris');
        $this->change($admin, [$page], 'unvan', $title, 'yazma');
        $this->change($admin, [$page], 'kisi', $staff->id, 'giris');
        $service = app(PageAccess::class);
        $access = $service->matrix($staff)['synthetic'];
        $this->assertTrue($access['giris']);
        $this->assertTrue($access['yazma']);
        $this->assertCount(3, $access['sources']);
        $this->change($admin, [$page], 'kisi', $staff->id, null);
        $this->assertTrue($service->allows($staff, 'synthetic', 'yazma'));
        $this->change($admin, [$page], 'unvan', $title, null);
        $this->assertTrue($service->allows($staff, 'synthetic'));
        $this->assertFalse($service->allows($staff, 'synthetic', 'yazma'));
        $this->change($admin, [$page], 'birim', $department, null);
        $this->assertFalse($service->allows($staff, 'synthetic'));
    }

    public function test_inactive_targets_and_reassigned_users_do_not_keep_inherited_permissions(): void
    {
        $page = $this->page();
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $staff = User::factory()->create(['department_id' => $department])->refresh();
        $this->change($this->admin(), [$page], 'birim', $department, 'yazma');
        $service = app(PageAccess::class);
        $this->assertTrue($service->allows($staff, 'synthetic', 'yazma'));
        DB::table('departments')->where('id', $department)->update(['is_active' => false]);
        $this->assertFalse($service->allows($staff, 'synthetic'));
        DB::table('departments')->where('id', $department)->update(['is_active' => true]);
        DB::table('users')->where('id', $staff->id)->update(['department_id' => null]);
        $this->assertFalse($service->allows($staff, 'synthetic'));
    }

    public function test_admin_exemption_requires_current_active_account_and_completed_password_change(): void
    {
        $this->page();
        $admin = $this->admin();
        $service = app(PageAccess::class);
        $this->assertTrue($service->allows($admin, 'synthetic', 'yazma'));
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => false]);
        $this->assertFalse($service->allows($admin, 'synthetic'));
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => true, 'must_change_password' => true]);
        $this->assertFalse($service->allows($admin, 'synthetic'));
        DB::table('users')->where('id', $admin->id)->update(['must_change_password' => false, 'is_active' => false]);
        $this->assertFalse($service->allows($admin, 'hukumlu_kayit'));
        DB::table('users')->where('id', $admin->id)->delete();
        $this->assertFalse($service->allows($admin, 'synthetic'));
    }

    public function test_everyone_can_only_receive_read_and_bad_database_rules_do_not_grant_write(): void
    {
        $page = $this->page();
        $admin = $this->admin();
        $staff = User::factory()->create()->refresh();
        $this->change($admin, [$page], 'herkes', 0, 'giris');
        $this->assertTrue(app(PageAccess::class)->allows($staff, 'synthetic'));
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'synthetic', 'yazma'));
        try {
            $this->change($admin, [$page], 'herkes', 0, 'yazma');
            $this->fail('Everyone write accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('permission', $error->errors());
        }
        DB::table('page_permissions')->update(['permission' => 'yazma']);
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'synthetic'));
    }

    public function test_personal_permissions_do_not_grant_other_people_access_and_archived_pages_close(): void
    {
        $page = $this->page();
        $staff = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $this->change($this->admin(), [$page], 'kisi', $staff->id, 'yazma');
        $service = app(PageAccess::class);
        $this->assertTrue($service->allows($staff, 'synthetic', 'yazma'));
        $this->assertFalse($service->allows($other, 'synthetic'));
        DB::table('portal_pages')->where('id', $page)->update(['is_active' => false]);
        $this->assertFalse($service->allows($staff, 'synthetic'));
        $this->assertDatabaseCount('page_permissions', 1);
    }

    public function test_batch_updates_are_atomic_audited_revision_checked_and_idempotent(): void
    {
        $pages = [$this->page('one'), $this->page('two')];
        $admin = $this->admin();
        $this->change($admin, $pages, 'herkes', 0, 'giris');
        $this->assertDatabaseCount('page_permissions', 2);
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertSame([2, 2], DB::table('portal_pages')->orderBy('id')->pluck('revision')->all());
        $this->change($admin, $pages, 'herkes', 0, 'giris');
        $this->assertDatabaseCount('audit_events', 2);
        try {
            app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $pages[0], 'revision' => 2], ['id' => $pages[1], 'revision' => 1]], 'target_type' => 'herkes', 'target_id' => 0, 'operation' => 'remove']);
            $this->fail('Stale batch accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('pages', $error->errors());
        }
        $this->assertDatabaseCount('page_permissions', 2);
        $this->change($admin, $pages, 'herkes', 0, null);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_missing_archived_duplicate_pages_bad_enum_and_target_are_rejected_before_changes(): void
    {
        $page = $this->page();
        $admin = $this->admin();
        $base = ['pages' => [['id' => $page, 'revision' => 1]], 'target_type' => 'herkes', 'target_id' => 0, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris'];
        foreach ([['pages' => []], ['pages' => [['id' => $page, 'revision' => 1], ['id' => 999, 'revision' => 1]]], ['pages' => [['id' => $page, 'revision' => 1], ['id' => $page, 'revision' => 1]]], ['permission' => 'okur'], ['target_type' => 'kisi', 'target_id' => 0], ['target_id' => 3], ['target_type' => 'kisi', 'target_id' => 999], ['operation' => 'deny']] as $override) {
            try {
                app(PagePermissions::class)->apply($admin, array_replace($base, $override));
                $this->fail('Invalid batch accepted');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        DB::table('portal_pages')->where('id', $page)->update(['is_active' => false]);
        try {
            app(PagePermissions::class)->apply($admin, $base);
            $this->fail('Archived page accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('pages', $error->errors());
        }
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_fresh_actor_is_checked_inside_transaction_even_when_supplied_model_is_stale(): void
    {
        $page = $this->page();
        $admin = $this->admin();
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => false]);
        try {
            $this->change($admin, [$page], 'herkes', 0, 'giris');
            $this->fail('Stale admin accepted');
        } catch (AuthorizationException $error) {
            $this->assertDatabaseCount('page_permissions', 0);
        }
        $staff = User::factory()->create()->refresh();
        $this->expectException(AuthorizationException::class);
        $this->change($staff, [$page], 'herkes', 0, 'giris');
    }

    public function test_foreign_keys_remove_target_rules_and_do_not_resurrect_deleted_definitions(): void
    {
        $page = $this->page();
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $this->change($this->admin(), [$page], 'birim', $department, 'giris');
        DB::table('departments')->where('id', $department)->delete();
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_malformed_target_links_do_not_match_and_inactive_targets_can_only_be_revoked(): void
    {
        $page = $this->page();
        $admin = $this->admin();
        $title = DB::table('job_titles')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $staff = User::factory()->create(['job_title_id' => $title])->refresh();
        $this->change($admin, [$page], 'unvan', $title, 'yazma');
        DB::table('page_permissions')->update(['target_id' => $title + 1]);
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'synthetic'));
        DB::table('page_permissions')->update(['target_id' => $title, 'user_id' => $staff->id]);
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'synthetic'));
        DB::table('page_permissions')->update(['user_id' => null]);
        DB::table('job_titles')->where('id', $title)->update(['is_active' => false]);
        try {
            $this->change($admin, [$page], 'unvan', $title, 'giris');
            $this->fail('Inactive target received new permission');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('target_id', $error->errors());
        }
        $this->change($admin, [$page], 'unvan', $title, null);
        $this->assertDatabaseCount('page_permissions', 0);
    }

    public function test_matrix_query_count_does_not_grow_with_page_count(): void
    {
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $title = DB::table('job_titles')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $staff = User::factory()->create(['department_id' => $department, 'job_title_id' => $title])->refresh();
        for ($i = 0; $i < 100; $i++) {
            $this->page('synthetic_'.$i);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $matrix = app(PageAccess::class)->matrix($staff);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(100, $matrix);
        $this->assertLessThanOrEqual(5, count($queries));
    }

    public function test_audit_failure_rolls_back_all_pages_and_revisions(): void
    {
        $pages = [$this->page('one'), $this->page('two')];
        $admin = $this->admin();
        DB::unprepared('CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON audit_events WHEN NEW.resource_id = '.$pages[1]." BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        try {
            $this->change($admin, $pages, 'herkes', 0, 'giris');
            $this->fail('Audit failure ignored');
        } catch (QueryException $error) {
            $this->assertDatabaseCount('page_permissions', 0);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertSame([1, 1], DB::table('portal_pages')->orderBy('id')->pluck('revision')->all());
        }
    }
}
