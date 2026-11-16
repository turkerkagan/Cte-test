<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Access\PageAccessView;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdministrationAccessDialogTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $code): int
    {
        return DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => 'SENTETİK '.$code]);
    }

    private function actor(): User
    {
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0]);

        return $admin;
    }

    private function grant(User $actor, int $page, string $type, int $id, string $permission = 'giris'): void
    {
        app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $page, 'revision' => DB::table('portal_pages')->where('id', $page)->value('revision')]],
            'target_type' => $type, 'target_id' => $id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission]);
    }

    public function test_personal_json_uses_url_person_preserves_inherited_access_and_rejects_stale_save(): void
    {
        $actor = $this->actor();
        $page = $this->page('synthetic');
        $department = DB::table('departments')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK BİRİM']);
        $person = User::factory()->create(['department_id' => $department])->refresh();
        $other = User::factory()->create()->refresh();
        $this->grant($actor, $page, 'birim', $department, 'yazma');
        $url = '/yonetim/kullanicilar/'.$person->id.'/sayfa-erisimleri';
        $input = ['pages' => [['id' => $page, 'revision' => 2]], 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris',
            'target_type' => 'kisi', 'target_id' => $other->id, 'reason' => 'SENTETİK GEREKÇE'];
        $this->postJson($url, $input)->assertOk()->assertJsonPath('id', $person->id);
        $this->assertDatabaseHas('page_permissions', ['page_id' => $page, 'target_type' => 'kisi', 'target_id' => $person->id, 'permission' => 'giris']);
        $this->assertDatabaseMissing('page_permissions', ['target_type' => 'kisi', 'target_id' => $other->id]);
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('pages');
        $this->postJson($url, ['pages' => [['id' => $page, 'revision' => 3]], 'operation' => 'remove', 'permission' => null])->assertOk();
        $row = collect(app(PageAccessView::class)->rows($person))->firstWhere('id', $page);
        $this->assertNull($row['personal_permission']);
        $this->assertTrue($row['effective']['giris']);
        $this->assertTrue($row['effective']['yazma']);
        $this->assertSame('birim', $row['effective']['sources'][0]['type']);
        $this->assertDatabaseCount('page_permissions', 1);
    }

    public function test_reason_is_validated_and_page_history_is_bounded_and_whitelisted(): void
    {
        $actor = $this->actor();
        $page = $this->page('synthetic');
        $input = ['pages' => [['id' => $page, 'revision' => 1]], 'target_type' => 'herkes', 'target_id' => 0, 'operation' => 'grant', 'permission' => 'giris'];
        $this->postJson('/yonetim/erisim-politikalari/toplu', $input + ['reason' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('page_permissions', 0);
        $this->post('/yonetim/erisim-politikalari/toplu', $input + ['reason' => 'SENTETİK GEREKÇE', 'password' => 'injected'])->assertRedirect();
        $details = json_decode(DB::table('audit_events')->value('details'), true);
        $this->assertSame('SENTETİK GEREKÇE', $details['reason']);
        $this->assertArrayNotHasKey('password', $details);
        for ($i = 0; $i < 20; $i++) {
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'resource_type' => 'page_access', 'resource_id' => $page,
                'action' => 'permission_changed', 'details' => json_encode(['target_type' => 'kisi', 'target_id' => 42, 'before' => null, 'after' => 'giris', 'reason' => 'SENTETİK '.$i, 'internal_secret' => 'omit']), 'created_at' => now()]);
        }
        $row = collect(app(PageAccessView::class)->rows())->firstWhere('id', $page);
        $this->assertCount(15, $row['history']);
        $this->assertSame('SENTETİK 19', $row['history'][0]['details']['reason']);
        $this->assertArrayNotHasKey('internal_secret', $row['history'][0]['details']);
        $this->assertSame([], collect(app(PageAccessView::class)->rows(User::factory()->create()->refresh()))->firstWhere('id', $page)['history']);
    }

    public function test_sensitive_policy_definitions_need_no_fake_route_and_grant_no_access_on_creation(): void
    {
        $this->actor();
        $person = User::factory()->create()->refresh();
        foreach (PageCatalog::SENSITIVE_PAGES as $code => $name) {
            $this->post('/yonetim/erisim-politikalari', ['code' => $code, 'name' => $name, 'path' => null, 'legacy_file' => null])->assertRedirect();
            $page = DB::table('portal_pages')->where('code', $code)->first();
            $this->assertNotNull($page);
            $this->assertNull(app(PageCatalog::class)->destination($page));
            $this->assertFalse(app(PageAccess::class)->allows($person, $code));
            $this->get('/yonetim/erisim-politikalari/'.$page->id.'/ac')->assertNotFound();
        }
        $this->assertDatabaseCount('page_permissions', 0);
        $this->post('/yonetim/erisim-politikalari', ['code' => 'hassas_unknown', 'name' => 'SENTETİK', 'path' => null, 'legacy_file' => null])->assertSessionHasErrors('path');
        $this->assertDatabaseCount('portal_pages', 2);
    }

    public function test_service_rejects_actor_whose_session_was_terminated_during_the_request(): void
    {
        $actor = $this->actor();
        $page = $this->page('synthetic');
        DB::table('users')->where('id', $actor->id)->update(['session_version' => 1]);
        try {
            $this->grant($actor, $page, 'herkes', 0);
            $this->fail('Revoked session authorized a permission change');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame(1, DB::table('portal_pages')->where('id', $page)->value('revision'));
    }

    public function test_effective_display_matches_workflow_alias_archive_readonly_and_admin_constraints(): void
    {
        $actor = $this->actor();
        $person = User::factory()->create()->refresh();
        $source = $this->page('kogus');
        $alias = $this->page('koguslar');
        $readOnly = $this->page('psikososyal');
        $adminOnly = $this->page('custom_rapor');
        $fixedHomeAlias = $this->page('uyap_karsilastirma');
        foreach ([$source, $readOnly, $adminOnly] as $page) {
            $this->grant($actor, $page, 'kisi', $person->id, 'yazma');
        }
        $rows = collect(app(PageAccessView::class)->rows($person))->keyBy('id');
        $this->assertTrue($rows[$fixedHomeAlias]['effective']['giris']);
        $this->assertFalse($rows[$fixedHomeAlias]['effective']['yazma']);
        $this->assertSame('homepage', $rows[$fixedHomeAlias]['effective']['sources'][0]['type']);
        $this->assertTrue($rows[$alias]['effective']['giris']);
        $this->assertTrue($rows[$alias]['effective']['yazma']);
        $this->assertSame('SENTETİK kogus', $rows[$alias]['effective']['sources'][0]['page_name']);
        $this->assertTrue($rows[$readOnly]['effective']['giris']);
        $this->assertFalse($rows[$readOnly]['effective']['yazma']);
        $this->assertTrue($rows[$readOnly]['read_only']);
        $this->assertFalse($rows[$adminOnly]['effective']['giris']);
        DB::table('portal_pages')->where('id', $source)->update(['is_active' => false]);
        $rows = collect(app(PageAccessView::class)->rows($person))->keyBy('id');
        $this->assertFalse($rows[$alias]['effective']['giris']);
        $this->assertFalse($rows[$alias]['effective']['yazma']);
        $this->assertSame([], $rows[$alias]['effective']['sources']);
    }
}
