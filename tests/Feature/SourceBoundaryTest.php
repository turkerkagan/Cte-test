<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_legacy_urls_resolve_inside_new_deployment_and_unknown_files_never_execute(): void
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);
        $catalog = app(PageCatalog::class);
        config(['app.url' => 'http://synthetic.invalid/cte/portal-next/public']);
        $aliases = json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true);
        foreach ($aliases as $file => $target) {
            $destination = $catalog->destination((object) ['is_active' => true, 'path' => null, 'legacy_file' => $file]);
            $this->assertStringStartsWith('http://synthetic.invalid/cte/portal-next/public/', $destination);
            $this->get('/'.$file)->assertRedirect(route($target['route']));
        }
        $this->assertNull($catalog->destination((object) ['is_active' => true, 'path' => null, 'legacy_file' => 'admin/file_manager.php']));
        $this->get('/index.php')->assertRedirect(route('portal'));
        $this->get('/index.php?sayfa=admin/file_manager.php')->assertNotFound();
        $this->post('/index.php', [])->assertStatus(405);
    }

    public function test_generated_sentence_projection_keeps_original_casts_after_update(): void
    {
        $person = DB::table('people')->insertGetId(['full_name' => 'SENTETİK']);
        foreach (['2026/9' => [2026, 9], '2026/100' => [2026, 100], '2025/2' => [2025, 2], '9' => [9, 9], 'metin' => [0, 0]] as $value => $expected) {
            DB::table('person_case_data')->updateOrInsert(['person_id' => $person], ['infaz_uyap_no' => $value]);
            $row = DB::table('person_case_data')->where('person_id', $person)->first();
            $this->assertSame($expected, [(int) $row->infaz_sort_year, (int) $row->infaz_sort_number]);
        }
    }
}
