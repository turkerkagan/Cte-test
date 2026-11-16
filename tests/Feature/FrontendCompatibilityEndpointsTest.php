<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Institution\InstitutionRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrontendCompatibilityEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $actor = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    public function test_badges_count_only_own_unread_messages_and_unread_active_announcements(): void
    {
        $actor = $this->actor();
        $other = User::factory()->create();
        foreach ([$actor, $other] as $recipient) {
            DB::table('personal_messages')->insert(['sender_id' => $other->id, 'recipient_id' => $recipient->id, 'sender_name' => 'SENTETİK', 'recipient_name' => 'SENTETİK', 'subject' => 'SENTETİK', 'body' => 'SENTETİK', 'request_id' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $records = app(InstitutionRecords::class);
        $id = $records->save($actor, 'duyurular', null, ['values' => ['title' => 'SENTETİK', 'body' => 'SENTETİK']])['id'];
        $this->getJson('/portal/bildirimler')->assertOk()->assertExactJson(['messages' => 1, 'announcements' => 1]);
        $records->receipt($actor, $id, true);
        DB::table('personal_messages')->where('recipient_id', $actor->id)->update(['read_at' => now()]);
        $this->getJson('/portal/bildirimler')->assertOk()->assertExactJson(['messages' => 0, 'announcements' => 0]);
        $this->getJson('/kurum/duyurular/'.$id.'/okuyanlar')->assertOk()->assertExactJson(['readers' => [$actor->full_name]]);
        $this->actor(false);
        $this->getJson('/portal/bildirimler')->assertOk()->assertExactJson(['messages' => 0, 'announcements' => 0]);
        $this->getJson('/kurum/duyurular/'.$id.'/okuyanlar')->assertForbidden();
    }

    public function test_calendar_includes_all_month_records_without_pagination_and_rejects_unbounded_ranges(): void
    {
        $this->actor();
        for ($i = 0; $i < 65; $i++) {
            DB::table('institution_records')->insert(['kind' => 'infaznobet', 'values' => json_encode(['user_name' => 'SENTETİK '.$i]), 'event_on' => '2026-10-05', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('institution_records')->insert(['kind' => 'infaznobet', 'values' => '{}', 'event_on' => '2026-11-05', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/kurum/infaznobet/takvim?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(65, 'rows');
        $this->getJson('/kurum/infaznobet/takvim?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->actor(false);
        $this->getJson('/kurum/infaznobet/takvim?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_sentence_modal_json_and_calculation_keep_permissions_and_validation(): void
    {
        $this->actor();
        $id = DB::table('people')->insertGetId(['full_name' => 'SENTETİK', 'registered_on' => '2026-10-05', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/infaz/kisiler/'.$id)->assertOk()->assertJsonPath('workflow', 'infaz')->assertJsonPath('photoUrl', null)->assertJsonMissingPath('values.egt_domainsifre');
        $this->postJson('/infaz/hesapla', ['birth' => '2000-01-01', 'uyap' => '2026/1', 'year' => 1, 'month' => 0, 'day' => 0])->assertOk()->assertJsonPath('uyapValid', true);
        $this->postJson('/infaz/hesapla', ['birth' => '2026-02-31'])->assertUnprocessable();
        $this->actor(false);
        $this->getJson('/infaz/kisiler/'.$id)->assertForbidden();
        $this->get('/infaz/kisiler/'.$id.'/foto')->assertForbidden();
        $this->postJson('/infaz/hesapla', ['uyap' => '2026/1'])->assertForbidden();
    }
}
