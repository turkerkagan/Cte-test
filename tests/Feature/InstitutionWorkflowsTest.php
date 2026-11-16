<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Institution\ChatRooms;
use App\Services\Institution\DutyPlanning;
use App\Services\Institution\InstitutionRecords;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstitutionWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function department(string $code): int
    {
        return DB::table('departments')->insertGetId(['code' => $code, 'name' => $code, 'is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function title(string $code): int
    {
        return DB::table('job_titles')->insertGetId(['code' => $code, 'name' => $code, 'is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_pages_crud_csv_print_and_cross_kind_ids(): void
    {
        $a = $this->actor();
        foreach (['telefon', 'duyurular', 'kurul', 'mudurnobet', 'vardiyanobet', 'infaznobet', 'kismiarama'] as $kind) {
            foreach (['', '/cikti', '/yazdir'] as $suffix) {
                $this->get('/kurum/'.$kind.$suffix)->assertOk();
            }
        }$r = $this->postJson('/kurum/telefon', ['values' => ['name' => 'SENTETİK TELEFON', 'phone' => '0000123', 'position' => 1]])->assertOk();
        $id = $r->json('id');
        $this->postJson('/kurum/telefon/'.$id, ['revision' => 1, 'values' => ['phone' => '0000777']])->assertOk();
        $this->postJson('/kurum/telefon/'.$id, ['revision' => 1, 'values' => ['phone' => 'STALE']])->assertConflict();
        $this->postJson('/kurum/duyurular/'.$id, ['revision' => 2, 'values' => ['title' => 'BAD', 'body' => 'BAD']])->assertNotFound();
        $this->get('/kurum/telefon/cikti')->assertSee('0000777');
        $this->get('/kurum/yemek')->assertOk();
        $this->get('/kurum/sohbet')->assertOk();
    }

    public function test_announcement_receipts_are_personal_and_board_batch_rolls_back(): void
    {
        $a = $this->actor();
        $r = app(InstitutionRecords::class);
        $id = $r->save($a, 'duyurular', null, ['values' => ['title' => 'SENTETİK DUYURU', 'body' => 'İÇERİK']])['id'];
        $r->receipt($a, $id, true);
        $r->receipt($a, $id, true);
        $this->assertDatabaseCount('announcement_reads', 1);
        $r->receipt($a, $id, false);
        $this->assertDatabaseCount('announcement_reads', 0);
        $board = $r->read($a, 'kurul')['rows'];
        $payload = array_map(fn ($row) => ['id' => $row['id'], 'revision' => $row['revision'], 'values' => ['position' => $row['values']['position'], 'discipline_top' => 'SENTETİK']], $board);
        $payload[11]['revision'] = 999;
        $this->postJson('/kurum/kurul', ['action' => 'board', 'records' => $payload])->assertConflict();
        $this->assertSame(1, DB::table('institution_records')->where('kind', 'kurul')->min('revision'));
        $payload[11]['revision'] = 1;
        $this->postJson('/kurum/kurul', ['action' => 'board', 'records' => $payload])->assertOk();
        $this->assertSame(2, DB::table('institution_records')->where('kind', 'kurul')->min('revision'));
    }

    public function test_announcements_keep_newest_first_personal_receipts_and_stale_edits(): void
    {
        $actor = $this->actor();
        $records = app(InstitutionRecords::class);
        $ids = [];
        foreach (['2026-10-01 12:00:00', '2026-10-05 12:00:00', '2026-10-05 12:00:00'] as $time) {
            $id = $records->save($actor, 'duyurular', null, ['values' => ['title' => 'SENTETİK '.count($ids), 'body' => 'SENTETİK']])['id'];
            DB::table('institution_records')->where('id', $id)->update(['created_at' => $time]);
            $ids[] = $id;
        }
        $this->assertSame(array_reverse($ids), array_column($records->read($actor, 'duyurular')['rows'], 'id'));
        $other = User::factory()->create(['is_system_admin' => true])->refresh();
        $records->receipt($other, $ids[0], true);
        $records->receipt($actor, null, true);
        $this->assertSame([true, true, true], array_column($records->read($actor, 'duyurular')['rows'], 'isRead'));
        $records->receipt($actor, $ids[0], false);
        $this->assertDatabaseHas('announcement_reads', ['record_id' => $ids[0], 'user_id' => $other->id]);
        $this->assertDatabaseMissing('announcement_reads', ['record_id' => $ids[0], 'user_id' => $actor->id]);
        $this->postJson('/kurum/duyurular/'.$ids[2], ['revision' => 1, 'values' => ['title' => 'YENİ SENTETİK', 'body' => 'İÇERİK']])->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/kurum/duyurular/'.$ids[2], ['revision' => 1, 'values' => ['title' => 'ESKİ SENTETİK', 'body' => 'İÇERİK']])->assertConflict();
        $this->assertSame('YENİ SENTETİK', $records->read($actor, 'duyurular')['rows'][0]['values']['title']);
    }

    public function test_partial_search_cancel_preserves_history_date_filter_and_stale(): void
    {
        $a = $this->actor();
        $room = DB::table('housing_rooms')->where('is_active', true)->value('id');
        $values = ['room_id' => $room, 'date' => '2026-10-04T23:59', 'staff' => 'SENTETİK PERSONEL', 'result' => 'Olumsuzluk yok', 'note' => 'NOT'];
        $id = $this->postJson('/kurum/kismiarama', ['values' => $values])->assertOk()->json('id');
        $this->postJson('/kurum/kismiarama/'.$id, ['action' => 'archive', 'revision' => 1, 'reason' => 'İPTAL'])->assertOk();
        $this->postJson('/kurum/kismiarama/'.$id, ['action' => 'archive', 'revision' => 1, 'reason' => 'TEKRAR'])->assertConflict();
        $this->get('/kurum/kismiarama/cikti?from=2026-10-04&to=2026-10-04')->assertSee('İPTAL');
        $this->assertSame(1, app(InstitutionRecords::class)->read($a, 'kismiarama', ['to' => '2026-10-04'])['pagination']['total']);
        $this->postJson('/kurum/kismiarama', ['values' => array_replace($values, ['date' => '2026-02-30T14:00'])])->assertUnprocessable();
    }

    public function test_partial_search_csv_preserves_report_number_columns_and_formula_safety(): void
    {
        $actor = $this->actor();
        $room = DB::table('housing_rooms')->where('is_active', true)->first();
        $records = app(InstitutionRecords::class);
        $id = $records->save($actor, 'kismiarama', null, ['values' => ['room_id' => $room->id, 'date' => '2026-10-04T14:00', 'staff' => '=UNTRUSTED', 'result' => 'Olumsuzluk yok', 'note' => 'SENTETİK']])['id'];
        $this->postJson('/kurum/kismiarama/'.$id, ['action' => 'archive', 'revision' => 1, 'reason' => 'İPTAL'])->assertOk();
        $response = $this->get('/kurum/kismiarama/cikti?from=2026-10-04&to=2026-10-04')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $lines = explode("\n", trim(substr($response->getContent(), 3)));
        $header = str_getcsv($lines[0], ';', '"', '');
        $row = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame(['No', 'Tarih Saat', 'Blok', 'Koğuş', 'Görevliler', 'Sonuç', 'Açıklama', 'Kaydeden', 'İptal Tarihi', 'İptal Nedeni'], $header);
        $this->assertSame((string) $id, $row[0]);
        $this->assertSame('2026-10-04 14:00:00', $row[1]);
        $this->assertNotEmpty($row[2]);
        $this->assertSame("'=UNTRUSTED", $row[4]);
        $this->assertSame('SENTETİK', $row[6]);
        $this->assertSame('İPTAL', $row[9]);
        $this->assertCount(10, $row);
    }

    public function test_partial_search_summary_filters_whole_result_and_keeps_200_row_pages(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $actor = $this->actor();
        $room = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where('r.is_active', true)->first(['r.id', 'b.code as block']);
        $otherBlock = DB::table('housing_blocks')->insertGetId(['code' => 'G01_OTHER', 'name' => 'G01 OTHER', 'created_at' => now(), 'updated_at' => now()]);
        $otherRoom = DB::table('housing_rooms')->insertGetId(['block_id' => $otherBlock, 'code' => '1', 'name' => 'G01 OTHER ROOM', 'bed_count' => 1, 'capacity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $batch = [];
        for ($i = 0; $i < 205; $i++) {
            $values = ['room_id' => $room->id, 'room_name' => 'G01 ROOM', 'date' => '2026-10-05T12:00', 'staff' => 'G01 STAFF', 'result' => 'Olumsuzluk yok'];
            if ($i >= 200) {
                $values += ['cancelled_at' => '2026-10-06 10:00:00', 'cancel_reason' => 'G01 REASON'];
            }
            $batch[] = ['kind' => 'kismiarama', 'values' => json_encode($values), 'event_on' => '2026-10-05 12:00:00', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('institution_records')->insert($batch);
        $records = app(InstitutionRecords::class);
        $newest = $records->save($actor, 'kismiarama', null, ['values' => ['room_id' => $otherRoom, 'date' => '2026-10-06T12:00', 'staff' => 'G01 STAFF', 'result' => 'Olumsuzluk yok']])['id'];
        $records->save($actor, 'kismiarama', null, ['values' => ['room_id' => $otherRoom, 'date' => '2026-10-07T12:00', 'staff' => 'G01 FUTURE', 'result' => 'Olumsuzluk yok']]);
        $result = $records->read($actor, 'kismiarama');
        $this->assertSame(['valid' => 201, 'rooms' => 2, 'cancelled' => 5], $result['summary']);
        $this->assertSame(['page' => 1, 'pages' => 2, 'total' => 206], $result['pagination']);
        $this->assertCount(200, $result['rows']);
        $this->assertSame($newest, $result['rows'][0]['id']);
        $this->assertSame('2026-01-01', $result['queryState']['from']);
        $this->assertSame('2026-10-06', $result['queryState']['to']);
        $second = $records->read($actor, 'kismiarama', ['page' => 2]);
        $this->assertCount(6, $second['rows']);
        $this->assertSame($result['summary'], $second['summary']);
        $filtered = $records->read($actor, 'kismiarama', ['block' => $room->block]);
        $this->assertSame(['valid' => 200, 'rooms' => 1, 'cancelled' => 5], $filtered['summary']);
        $this->assertSame(205, $filtered['pagination']['total']);
        DB::table('housing_blocks')->where('id', $otherBlock)->update(['code' => 'G01_RENAMED']);
        $snapshot = $records->read($actor, 'kismiarama', ['block' => 'G01_OTHER']);
        $this->assertSame(1, $snapshot['pagination']['total']);
        $this->assertSame($newest, $snapshot['rows'][0]['id']);
        $this->getJson('/kurum/kismiarama?from=2026-10-06&to=2026-10-05')->assertUnprocessable();
    }

    public function test_duty_algorithms_keep_existing_identities_skip_unavailable_and_bound_range(): void
    {
        $a = $this->actor();
        $title = $this->title('idareci');
        $dep = $this->department('infaz');
        $u = User::factory()->create(['job_title_id' => $title, 'department_id' => $dep])->refresh();
        $plan = app(DutyPlanning::class);
        $r = app(InstitutionRecords::class);
        $first = $plan->generate($a, 'mudurnobet', ['from' => '2026-10-01', 'to' => '2026-10-03']);
        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $plan->generate($a, 'mudurnobet', ['from' => '2026-10-01', 'to' => '2026-10-03'])['created']);
        $plan->unavailable($a, ['user_id' => $u->id, 'dates' => ['2026-10-02'], 'reason' => 'SENTETİK']);
        $result = $plan->generate($a, 'infaznobet', ['from' => '2026-10-01', 'to' => '2026-10-03']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(['2026-10-02'], $result['unavailable_days']);
        $this->postJson('/kurum/infaznobet', ['action' => 'generate', 'from' => '2026-01-01', 'to' => '2027-01-02'])->assertUnprocessable();
        $row = $r->read($a, 'mudurnobet')['rows'][0];
        $this->assertSame($u->full_name, $row['values']['user_name']);
        DB::table('users')->where('id', $u->id)->update(['full_name' => 'RENAMED']);
        $this->assertSame($u->full_name, $r->read($a, 'mudurnobet')['rows'][0]['values']['user_name']);
    }

    public function test_shift_dates_and_fill_use_absolute_row_index(): void
    {
        $a = $this->actor();
        $dep = $this->department('vardiya');
        $title = $this->title('infaz_koruma');
        $u = User::factory()->create(['department_id' => $dep, 'job_title_id' => $title])->refresh();
        $plan = app(DutyPlanning::class);
        $plan->generate($a, 'vardiyanobet', ['from' => '2026-10-01', 'to' => '2026-10-02', 'fill' => true]);
        $row = app(InstitutionRecords::class)->read($a, 'vardiyanobet')['rows'][0]['values'];
        $this->assertSame('2026-10-02', $row['night']);
        $this->assertSame('2026-10-03', $row['leave1']);
        $this->assertSame('2026-10-04', $row['leave2']);
        $this->assertSame($u->id, $row['user_id']);
    }

    public function test_chat_room_and_private_thread_never_cross_membership_and_last80_order(): void
    {
        $a = $this->actor();
        $b = User::factory()->create(['is_system_admin' => true])->refresh();
        $c = User::factory()->create(['is_system_admin' => true])->refresh();
        $chat = app(ChatRooms::class);
        $room = $chat->mutate($a, ['action' => 'create', 'name' => 'SENTETİK ODA', 'members' => [$b->id]])['id'];
        $chat->mutate($b, ['action' => 'send', 'room' => $room, 'body' => 'ODA MESAJI']);
        $this->assertCount(1, $chat->read($a, ['room' => $room])['messages']);
        $this->actingAs($c)->getJson('/kurum/sohbet/mesajlar?room='.$room)->assertForbidden();
        $this->postJson('/kurum/sohbet', ['action' => 'send', 'room' => $room, 'body' => 'FORGED'])->assertForbidden();
        $chat->mutate($a, ['action' => 'send', 'recipient' => $b->id, 'body' => 'ÖZEL MESAJ']);
        $this->assertCount(0, $chat->read($c, ['recipient' => $b->id])['messages']);
        $this->assertCount(1, $chat->read($b, ['recipient' => $a->id])['messages']);
        for ($i = 0; $i < 82; $i++) {
            $chat->mutate($a, ['action' => 'send', 'room' => $room, 'body' => (string) $i]);
        }$messages = $chat->read($a, ['room' => $room])['messages'];
        $this->assertCount(80, $messages);
        $this->assertSame('2', $messages[0]->body);
        $this->assertSame('81', $messages[79]->body);
    }

    public function test_meals_partial_upload_stale_mime_and_private_delivery(): void
    {
        $a = $this->actor();
        Storage::fake('local');
        $week = now()->startOfWeek()->format('Y-m-d');
        $input = ['week' => $week, 'revisions' => ['weekly' => 0, 'diet' => 0, 'vegetarian' => 0], 'weekly' => UploadedFile::fake()->image('synthetic.png')];
        $this->post('/kurum/yemek', $input, ['Accept' => 'application/json'])->assertOk();
        $file = DB::table('meal_files')->first();
        $this->get('/kurum/yemek/dosyalar/'.$file->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/kurum/yemek', $input, ['Accept' => 'application/json'])->assertConflict();
        $this->assertTrue(Storage::disk('local')->exists($file->path));
        $this->post('/kurum/yemek', ['week' => $week, 'revisions' => ['weekly' => 1, 'diet' => 0, 'vegetarian' => 0], 'diet' => UploadedFile::fake()->image('diet.png')], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('meal_files', 2);
        $this->assertSame($file->path, DB::table('meal_files')->where('type', 'weekly')->value('path'));
        $this->post('/kurum/yemek', ['week' => $week, 'revisions' => ['weekly' => 1, 'diet' => 1, 'vegetarian' => 0], 'weekly' => UploadedFile::fake()->createWithContent('bad.pdf', '<script>BAD</script>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertTrue(Storage::disk('local')->exists($file->path));
    }
}
