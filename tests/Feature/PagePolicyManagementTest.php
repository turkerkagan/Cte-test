<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PagePolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/yonetim/erisim-politikalari';

    private function admin(): User
    {
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0]);

        return $admin;
    }

    private function page(string $code = 'synthetic'): int
    {
        return DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => 'SENTETİK SAYFA', 'path' => '/portal']);
    }

    private function batch(int $id, int $revision = 1): array
    {
        return ['pages' => [['id' => $id, 'revision' => $revision]], 'target_type' => 'herkes', 'target_id' => 0, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris', 'reason' => 'Sentetik erişim gerekçesi'];
    }

    public function test_guest_staff_and_force_change_admin_cannot_use_any_endpoint(): void
    {
        $this->get($this->base)->assertRedirect(route('login'));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get($this->base)->assertForbidden();
        $this->get($this->base.'/1/ac')->assertForbidden();
        $this->post($this->base)->assertForbidden();
        $this->post($this->base.'/toplu')->assertForbidden();
        $this->put($this->base.'/1')->assertForbidden();
        $this->delete($this->base.'/1')->assertForbidden();
        $this->post($this->base.'/1/geri-al')->assertForbidden();
        $this->post('/yonetim/kullanicilar/'.$staff->id.'/sayfa-erisimleri')->assertForbidden();
        $staff->is_system_admin = true;
        $staff->must_change_password = true;
        $staff->save();
        $this->get($this->base)->assertRedirect(route('password.edit'));
        $this->assertDatabaseCount('portal_pages', 0);
    }

    public function test_csrf_protection_blocks_batch_and_personal_mutations_before_writing(): void
    {
        $admin = $this->admin();
        $id = $this->page();
        $this->app->instance('env', 'production');
        $this->post($this->base.'/toplu', $this->batch($id))->assertStatus(419);
        $this->post('/yonetim/kullanicilar/'.$admin->id.'/sayfa-erisimleri', $this->batch($id))->assertStatus(419);
        $this->delete($this->base.'/'.$id, ['revision' => 1, 'permission_count' => 0, 'confirmed' => true])->assertStatus(419);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_catalog_create_edit_archive_restore_preserves_file_and_does_not_restore_permissions(): void
    {
        $this->admin();
        $input = ['code' => 'synthetic', 'name' => 'SENTETİK', 'legacy_file' => 'index.php', 'path' => null];
        $this->post($this->base, $input)->assertRedirect();
        $id = DB::table('portal_pages')->value('id');
        $this->put($this->base.'/'.$id, $input + ['revision' => 1])->assertRedirect();
        $this->assertDatabaseHas('portal_pages', ['revision' => 1]); // no-op
        $this->post($this->base.'/toplu', $this->batch($id))->assertRedirect();
        $this->delete($this->base.'/'.$id, ['revision' => 2, 'confirmed' => true, 'permission_count' => 1])->assertRedirect();
        $this->assertDatabaseHas('portal_pages', ['is_active' => false, 'revision' => 3]);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->get($this->base.'/'.$id.'/ac')->assertNotFound();
        $this->post($this->base.'/'.$id.'/geri-al', ['revision' => 3, 'confirmed' => true, 'permission_count' => 0])->assertRedirect();
        $this->assertDatabaseHas('portal_pages', ['is_active' => true, 'revision' => 4]);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->get($this->base)->assertInertia(fn (Assert $page) => $page->component('PagePolicies')->has('rows', 1)->where('rows.0.legacy_file', 'index.php')->has('rows.0.open_url')->has('navigation', 9)->where('navigation.0.label', 'Ana Sayfa'));
    }

    public function test_links_use_deployment_base_and_reject_invalid_stored_destinations(): void
    {
        $this->admin();
        config(['app.url' => 'http://synthetic-server:8080/cte/portal-next/public']);
        $catalog = app(PageCatalog::class);
        $this->assertSame('http://synthetic-server:8080/cte/portal-next/public/portal', $catalog->destination((object) ['is_active' => true, 'path' => null, 'legacy_file' => 'index.php']));
        $this->assertSame('http://synthetic-server:8080/cte/portal-next/public/portal', $catalog->destination((object) ['is_active' => true, 'path' => '/portal', 'legacy_file' => null]));
        $id = $this->page();
        $this->get($this->base.'/'.$id.'/ac')->assertRedirect('http://synthetic-server:8080/cte/portal-next/public/portal');
        DB::table('portal_pages')->where('id', $id)->update(['path' => '//external.example']);
        $this->get($this->base.'/'.$id.'/ac')->assertNotFound();
        $this->get($this->base.'/999/ac')->assertNotFound();
    }

    public function test_bad_paths_files_duplicates_and_changed_codes_are_rejected(): void
    {
        $this->admin();
        $input = ['code' => 'synthetic', 'name' => 'SENTETİK', 'legacy_file' => null, 'path' => '/portal'];
        foreach (['https://external.example', '//external.example', '/portal/../giris', '/portal?x=1', '/unknown', '/yonetim/kullanicilar/{id}', '/cikis'] as $path) {
            $this->post($this->base, array_replace($input, ['path' => $path]))->assertSessionHasErrors('path');
        }
        foreach (['../index.php', '/index.php', 'missing.php', 'admin/file_manager.php', 'portal-next/artisan.php', 'index.php?x=1', 'https://external.example/index.php'] as $file) {
            $this->post($this->base, array_replace($input, ['path' => null, 'legacy_file' => $file]))->assertSessionHasErrors('legacy_file');
        }
        $this->post($this->base, array_replace($input, ['path' => null]))->assertSessionHasErrors('path');
        $this->post($this->base, array_replace($input, ['legacy_file' => 'index.php']))->assertSessionHasErrors('path');
        $id = $this->page();
        $this->post($this->base, $input)->assertSessionHasErrors('code');
        $this->put($this->base.'/'.$id, array_replace($input, ['code' => 'different', 'revision' => 1]))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('portal_pages', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_optional_source_fields_can_be_omitted_and_catalog_edits_keep_permissions(): void
    {
        $this->admin();
        $this->post($this->base, ['code' => 'synthetic', 'name' => 'SENTETİK', 'path' => '/portal'])->assertRedirect();
        $id = (int) DB::table('portal_pages')->value('id');
        $this->post($this->base.'/toplu', $this->batch($id))->assertRedirect();
        $this->put($this->base.'/'.$id, ['code' => 'synthetic', 'name' => 'SENTETİK YENİ', 'legacy_file' => 'index.php', 'revision' => 2, 'is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('portal_pages', ['name' => 'SENTETİK YENİ', 'legacy_file' => 'index.php', 'path' => null, 'is_active' => true, 'revision' => 3]);
        $this->assertDatabaseHas('page_permissions', ['page_id' => $id, 'permission' => 'giris']);
    }

    public function test_archive_requires_confirmation_fresh_revision_count_and_preserves_homepage(): void
    {
        $this->admin();
        $id = $this->page();
        $this->delete($this->base.'/'.$id, ['revision' => 1, 'permission_count' => 0])->assertSessionHasErrors('confirmed');
        $this->delete($this->base.'/'.$id, ['revision' => 2, 'permission_count' => 0, 'confirmed' => true])->assertSessionHasErrors('revision');
        $this->delete($this->base.'/'.$id, ['revision' => 1, 'permission_count' => 1, 'confirmed' => true])->assertSessionHasErrors('permission_count');
        $home = $this->page('hukumlu_kayit');
        $this->delete($this->base.'/'.$home, ['revision' => 1, 'permission_count' => 0, 'confirmed' => true])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_personal_route_uses_url_person_and_shows_effective_sources_without_other_people_rules(): void
    {
        $this->admin();
        $id = $this->page();
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $person = User::factory()->create(['department_id' => $department])->refresh();
        $other = User::factory()->create()->refresh();
        $batch = array_replace($this->batch($id), ['target_type' => 'birim', 'target_id' => $department, 'permission' => 'yazma']);
        $this->post($this->base.'/toplu', $batch)->assertRedirect();
        $personal = '/yonetim/kullanicilar/'.$person->id.'/sayfa-erisimleri';
        $this->post($personal, array_replace($this->batch($id, 2), ['target_type' => 'kisi', 'target_id' => $other->id]))->assertRedirect(route('users.show', $person->id));
        $this->assertDatabaseHas('page_permissions', ['target_type' => 'kisi', 'user_id' => $person->id]);
        $this->assertDatabaseMissing('page_permissions', ['user_id' => $other->id]);
        $this->get('/yonetim/kullanicilar/'.$person->id)->assertInertia(fn (Assert $page) => $page->where('accessRows.0.personal_permission', 'giris')->where('accessRows.0.effective.yazma', true)->has('accessRows.0.effective.sources', 2)->where('accessRows.0.rules', []));
        $this->post($personal, ['pages' => [['id' => $id, 'revision' => 3]], 'operation' => 'remove'])->assertRedirect();
        $this->get('/yonetim/kullanicilar/'.$person->id)->assertInertia(fn (Assert $page) => $page->where('accessRows.0.personal_permission', null)->where('accessRows.0.effective.yazma', true)->has('accessRows.0.effective.sources', 1));
    }

    public function test_audit_failure_rolls_back_archive_and_hides_sql_response(): void
    {
        $this->admin();
        $id = $this->page();
        $this->post($this->base.'/toplu', $this->batch($id));
        DB::unprepared("CREATE TRIGGER synthetic_archive_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SECRET SQL'); END");
        $this->delete($this->base.'/'.$id, ['revision' => 2, 'confirmed' => true, 'permission_count' => 1])->assertSessionHasErrors('operation');
        $this->assertDatabaseHas('portal_pages', ['is_active' => true, 'revision' => 2]);
        $this->assertDatabaseCount('page_permissions', 1);
        $this->assertStringNotContainsString('SYNTHETIC SECRET SQL', json_encode(session('errors')->getBag('default')->messages()));
    }
}
