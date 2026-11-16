<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PagePermissions;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModulePublication;
use App\Services\Navigation\MenuConfiguration;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MenuConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        foreach (['one', 'two'] as $name) {
            $designer = app(ModuleDefinitions::class);
            $id = $designer->create($actor, ['code' => 'synthetic-menu-'.$name, 'name' => 'SENTETİK '.$name, 'record_mode' => 'standalone']);
            $version = DB::table('module_versions')->where('module_id', $id)->first();
            $designer->save($actor, $id, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK '.$name, 'fields' => [['id' => null, 'type' => 'text', 'label' => 'SENTETİK', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true]]]);
            $publication = app(ModulePublication::class);
            $publication->publish($actor, $id, $publication->inspect($actor, $id) + ['confirmed' => true]);
        }

        return [$actor, DB::table('portal_pages')->orderBy('id')->get()];
    }

    private function item(string $key, ?int $page = null, ?string $parent = null): array
    {
        return ['id' => null, 'key' => $key, 'parent_key' => $parent, 'page_id' => $page, 'label' => 'SENTETİK '.$key, 'is_active' => true];
    }

    public function test_original_menu_hierarchy_preserves_every_allowed_owned_link_without_duplicates(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $service = app(WorkflowAccess::class);
        $flatten = function (array $items) use (&$flatten): array {
            $links = [];
            foreach ($items as $item) {
                $links = [...$links, ...(isset($item['children']) ? $flatten($item['children']) : [$item['url']])];
            }

            return $links;
        };
        $groups = $service->groupedNavigation($admin);
        $links = $flatten($groups);
        $expected = array_column($service->navigation($admin), 'url');
        sort($links);
        sort($expected);
        $this->assertSame($expected, $links);
        $this->assertCount(count(array_unique($links)), $links);
        $this->assertSame(['Hükümlü İşlemleri', 'Kurum Birimleri', 'Kurum Hakkında', 'İstatistik & Analiz', 'Sohbet Odası', 'Telefon Rehberi', 'Araçlar'], array_column($groups, 'label'));
        $units = collect($groups)->firstWhere('label', 'Kurum Birimleri');
        $this->assertSame(['Sorumlu Başmemurluk', 'Revir Birimi', 'İnfaz Birimi', 'Ziyaret Birimi', 'Manevi Rehberlik', 'Eğitim Servisi', 'Psiko Sosyal Servisi', 'Mesleki Eğitim Merkezi'], array_column($units['children'], 'label'));
        $infaz = collect($units['children'])->firstWhere('label', 'İnfaz Birimi');
        $this->assertSame(['İnfaz', 'İzinler', 'Tebliğler', 'Bakanlık Sevk', 'Sevk Raporu', 'İzin Raporu'], array_column($infaz['children'], 'label'));
        $education = collect($units['children'])->firstWhere('label', 'Eğitim Servisi');
        $this->assertSame(['Eğitim', 'Kurslar', 'Hijyen ve İSG'], array_column($education['children'], 'label'));
        $prisoners = collect($groups)->firstWhere('label', 'Hükümlü İşlemleri');
        $this->assertSame(['Koğuşlar', 'Çalışma Yeri', 'Sayım', 'Kısmi Arama', 'Kısayollar', 'Fotoğraf', 'Kimlik Kartı Baskısı', 'Özel Rapor', 'UYAP Karşılaştırması'], array_column($prisoners['children'], 'label'));
        $institution = collect($groups)->firstWhere('label', 'Kurum Hakkında');
        $this->assertSame(['Duyurular', 'Nöbetler', 'Kurul ve Komisyonlar', 'Haftalık Yemek Listesi', 'Personel Kadrosu', 'Mesajlar'], array_column($institution['children'], 'label'));
        $staff = User::factory()->create()->refresh();
        $this->assertSame($flatten($service->groupedNavigation($staff)), array_column($service->navigation($staff), 'url'));
    }

    public function test_home_is_first_on_portal_workflow_and_management_pages(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0]);
        foreach (['/portal', '/infaz', '/yonetim/yerlesim'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('navigation.0.label', 'Ana Sayfa')
                ->where('navigation.0.url', route('portal'))
                ->where('navigation.1.label', 'Hükümlü İşlemleri')
                ->where('navigation.2.label', 'Kurum Birimleri')
                ->where('navigation.2.children.2.label', 'İnfaz Birimi')
                ->where('navigation.2.children.5.label', 'Eğitim Servisi')
                ->where('navigation.3.label', 'Kurum Hakkında')
                ->where('navigation.4.label', 'İstatistik & Analiz')
                ->where('navigation.5.label', 'Sohbet Odası')
                ->where('navigation.6.label', 'Telefon Rehberi')
                ->where('navigation.7.label', 'Kullanıcı İşlemleri')
                ->where('navigation.8.label', 'Yönetim Paneli')
            );
        }
    }

    public function test_grouped_navigation_keeps_only_authorized_children_and_management_is_grouped(): void
    {
        [$admin,$pages] = $this->fixture();
        $service = app(MenuConfiguration::class);
        $this->putJson('/yonetim/menu', ['revision' => 1, 'items' => [$this->item('group'), $this->item('one', $pages[0]->id, 'group'), $this->item('two', $pages[1]->id, 'group')]])->assertOk()->assertJsonPath('configured', true);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->get('/yonetim/menu')->assertInertia(fn (Assert $v) => $v->component('MenuEditor')->has('items', 3)->where('navigation.0.label', 'Ana Sayfa')->where('navigation', fn ($items) => collect($items)->firstWhere('url', '#management')['children'][1]['label'] === 'Erişim Politikaları'));
        $staff = User::factory()->create()->refresh();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $pages[0]->id, 'revision' => $pages[0]->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $navigation = $service->navigation($staff);
        $this->assertCount(1, $navigation);
        $this->assertCount(1, $navigation[0]['children']);
        $this->assertStringContainsString('synthetic-menu-one', $navigation[0]['children'][0]['url']);
        DB::table('page_permissions')->delete();
        $this->assertSame([], $service->navigation($staff));
    }

    public function test_revisions_no_op_and_removed_entries_do_not_reappear_via_fallback(): void
    {
        [$actor,$pages] = $this->fixture();
        $service = app(MenuConfiguration::class);
        $this->assertCount(2, $service->navigation($actor));
        $service->save($actor, ['revision' => 1, 'items' => [$this->item('one', $pages[0]->id)]]);
        $state = $service->state($actor);
        $audit = DB::table('audit_events')->count();
        $service->save($actor, ['revision' => $state['revision'], 'items' => $state['items']]);
        $this->assertSame($audit, DB::table('audit_events')->count());
        $this->assertSame(2, $service->state($actor)['revision']);
        $this->putJson('/yonetim/menu', ['revision' => 1, 'items' => []])->assertStatus(409);
        $service->save($actor, ['revision' => 2, 'items' => []]);
        $this->assertSame([], $service->navigation($actor));
        $this->assertDatabaseCount('portal_pages', 2);
    }

    public function test_duplicate_targets_cycles_depth_and_unmapped_pages_fail_without_mutation(): void
    {
        [$actor,$pages] = $this->fixture();
        $unmapped = DB::table('portal_pages')->insertGetId(['code' => 'synthetic-unmapped', 'name' => 'SENTETİK', 'path' => '/portal']);
        foreach ([
            [$this->item('one', $pages[0]->id), $this->item('two', $pages[0]->id)],
            [$this->item('one', null, 'two'), $this->item('two', null, 'one')],
            [$this->item('one'), $this->item('two', null, 'one'), $this->item('three', $pages[0]->id, 'two')],
            [$this->item('one', $unmapped)],
            [$this->item('one', $pages[0]->id) + ['url' => 'https://example.com']],
            [array_replace($this->item('one', $pages[0]->id), ['id' => 99999])],
        ] as $items) {
            $this->putJson('/yonetim/menu', ['revision' => 1, 'items' => $items])->assertUnprocessable();
        }
        $this->putJson('/yonetim/menu', ['revision' => 1, 'items' => [array_diff_key($this->item('missing'), ['page_id' => true])]])->assertUnprocessable();
        $this->assertDatabaseCount('menu_items', 0);
        $this->assertDatabaseHas('menu_configuration', ['revision' => 1, 'configured' => false]);
    }

    public function test_target_swaps_and_group_removal_preserve_foreign_keys(): void
    {
        [$actor,$pages] = $this->fixture();
        $service = app(MenuConfiguration::class);
        $service->save($actor, ['revision' => 1, 'items' => [$this->item('group'), $this->item('one', $pages[0]->id, 'group'), $this->item('two', $pages[1]->id, 'group')]]);
        $state = $service->state($actor);
        $items = $state['items'];
        $group = collect($items)->firstWhere('page_id', null);
        $leaves = array_values(array_filter($items, fn ($item) => $item['page_id'] !== null));
        [$leaves[0]['page_id'],$leaves[1]['page_id']] = [$leaves[1]['page_id'], $leaves[0]['page_id']];
        foreach ($leaves as &$leaf) {
            $leaf['parent_key'] = null;
        }unset($leaf);
        $service->save($actor, ['revision' => 2, 'items' => array_reverse($leaves)]);
        $this->assertDatabaseMissing('menu_items', ['id' => $group['id']]);
        $this->assertDatabaseCount('menu_items', 2);
        $this->assertStringContainsString('synthetic-menu-one', $service->navigation($actor)[0]['url']);
    }

    public function test_late_audit_failure_rolls_back_configuration_items_and_target_releases(): void
    {
        [$actor,$pages] = $this->fixture();
        $service = app(MenuConfiguration::class);
        $service->save($actor, ['revision' => 1, 'items' => [$this->item('one', $pages[0]->id)]]);
        $before = $service->state($actor);
        $items = $before['items'];
        $items[0]['label'] = 'SENTETİK BAŞARISIZ';
        DB::unprepared("CREATE TRIGGER synthetic_menu_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT,'SYNTHETIC SECRET'); END");
        $response = $this->putJson('/yonetim/menu', ['revision' => 2, 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SYNTHETIC SECRET', $response->getContent());
        $this->assertSame($before, $service->state($actor));
    }

    public function test_hidden_groups_archived_targets_and_protected_endpoints_remain_closed(): void
    {
        $this->get('/yonetim/menu')->assertRedirect(route('login'));
        [$actor,$pages] = $this->fixture();
        $service = app(MenuConfiguration::class);
        $group = $this->item('group');
        $group['is_active'] = false;
        $service->save($actor, ['revision' => 1, 'items' => [$group, $this->item('one', $pages[0]->id, 'group')]]);
        $this->assertSame([], $service->navigation($actor));
        DB::table('menu_items')->update(['is_active' => true]);
        DB::table('portal_pages')->where('id', $pages[0]->id)->update(['is_active' => false]);
        $this->assertSame([], $service->navigation($actor));
        $state = $service->state($actor);
        $state['items'][0]['label'] = 'SENTETİK YENİ GRUP';
        $service->save($actor, ['revision' => $state['revision'], 'items' => $state['items']]);
        $this->assertSame([], $service->navigation($actor));
        DB::table('portal_pages')->where('id', $pages[0]->id)->update(['is_active' => true]);
        $this->assertCount(1, $service->navigation($actor));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/yonetim/menu')->assertForbidden();
        $this->putJson('/yonetim/menu', [])->assertForbidden();
        $actor->forceFill(['must_change_password' => true])->save();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $this->get('/yonetim/menu')->assertRedirect(route('password.edit'));
        $actor->forceFill(['must_change_password' => false])->save();
        $this->app->instance('env', 'production');
        $this->putJson('/yonetim/menu', [])->assertStatus(419);
    }
}
