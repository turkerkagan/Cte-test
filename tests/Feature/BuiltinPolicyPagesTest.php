<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\BuiltinPageCatalog;
use App\Services\Access\PageAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class BuiltinPolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    public function test_missing_pages_are_registered_once_without_replacing_existing_definitions_or_granting_access(): void
    {
        $this->admin();
        $id = DB::table('portal_pages')->insertGetId(['code' => 'revir', 'name' => 'Korunacak ad', 'path' => '/revir', 'is_active' => false, 'revision' => 7]);
        $this->post('/yonetim/erisim-politikalari/sabit-sayfalar')->assertRedirect(route('page-access.index'));
        $this->assertDatabaseHas('portal_pages', ['id' => $id, 'name' => 'Korunacak ad', 'is_active' => false, 'revision' => 7]);
        $this->assertDatabaseHas('portal_pages', ['code' => 'hukumlu_kayit', 'path' => '/portal']);
        $this->assertDatabaseHas('portal_pages', ['code' => 'infaz', 'path' => '/infaz']);
        $this->assertDatabaseHas('portal_pages', ['code' => 'hassas_risk']);
        $this->assertDatabaseMissing('portal_pages', ['code' => 'alan_inceleme']);
        $count = DB::table('portal_pages')->count();
        $this->assertGreaterThan(30, $count);
        $this->assertSame(0, app(BuiltinPageCatalog::class)->sync());
        $this->assertSame($count, DB::table('portal_pages')->count());
        $this->assertSame(0, DB::table('page_permissions')->count());
        $staff = User::factory()->create()->refresh();
        $this->assertTrue(app(PageAccess::class)->allows($staff, 'hukumlu_kayit'));
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'infaz'));
        $this->assertFalse(app(PageAccess::class)->allows($staff, 'hassas_risk'));
    }

    public function test_legacy_policy_url_preserves_page_selection_and_requires_administrator(): void
    {
        $this->get('/admin/politikalar.php')->assertRedirect(route('login'));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/admin/politikalar.php')->assertForbidden();
        $this->post('/yonetim/erisim-politikalari/sabit-sayfalar')->assertForbidden();
        $this->admin();
        $this->get('/admin/politikalar.php?sayfa_id=12&hassas=1')->assertRedirect(route('page-access.index', ['sayfa_id' => 12, 'hassas' => 1]));
        $this->get('/yonetim/politikalar')->assertRedirect(route('page-access.index'));
    }

    public function test_selected_page_has_its_own_header_editor_with_current_global_revision(): void
    {
        $this->admin();
        app(BuiltinPageCatalog::class)->sync();
        $id = DB::table('portal_pages')->where('code', 'revir')->value('id');
        $this->getJson('/yonetim/erisim-politikalari/'.$id.'/alanlar')->assertOk()
            ->assertJsonPath('group', 'revir')->assertJsonPath('supported', true)
            ->assertJsonPath('saveUrl', route('management.fields'))
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('fieldKeys', fn ($keys) => in_array('huk_saglik', $keys->all()))->etc());
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->getJson('/yonetim/erisim-politikalari/'.$id.'/alanlar')->assertForbidden();
    }
}
