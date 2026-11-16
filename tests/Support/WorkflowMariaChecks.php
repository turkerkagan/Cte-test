<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\People\PersonConflict;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\CaseReports;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WorkflowMariaChecks
{
    public static function run(User $actor, callable $check): void
    {
        $cases = app(CaseRecords::class);
        $list = app(CaseList::class);
        $reports = app(CaseReports::class);
        $ids = [];
        foreach (['2026/10', '2026/2', '2025/100'] as $i => $uyap) {
            $id = app(PersonRecords::class)->save($actor, ['full_name' => 'SENTETİK MARIA AKIŞ '.$i, 'tc_identity' => null, 'admitted_on' => null, 'registered_on' => null]);
            DB::table('person_status_assignments')->insert(['person_id' => $id, 'status_id' => DB::table('person_statuses')->where('code', 'special_leave')->value('id'), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
            $cases->save($actor, 'infaz', $id, ['revision' => 0, 'values' => ['infaz_uyap_no' => $uyap, 'infaz_ds_tarihi' => '2027-01-13', 'infaz_tahliye_tarihi' => '2028-01-01', 'infaz_ceza_yil' => 1, 'infaz_ceza_ay' => 6, 'infaz_dogum_tarihi' => '1990-01-01']]);
            $cases->save($actor, 'ilkgorus', $id, ['revision' => 0, 'values' => ['huk_saglik' => 'iyi ışık', 'huk_ds_durum' => '1']]);
            $cases->save($actor, 'izin', $id, ['revision' => 0, 'values' => ['izin_cikis_tarihi' => '2026-10-04', 'izin_donus_tarihi' => '2026-10-04', 'izin_donus_saati' => '23:59']]);
        }
        $filter = ['q' => 'SENTETİK MARIA AKIŞ'];
        $page = $list->page($actor, 'infaz', $filter);
        $check(array_column(array_column($page['rows'], 'values'), 'infaz_uyap_no') === ['2025/100', '2026/2', '2026/10'], 'workflow_maria_uyap_natural_asc');
        $page = $list->page($actor, 'infaz', $filter + ['direction' => 'desc']);
        $check(array_column(array_column($page['rows'], 'values'), 'infaz_uyap_no') === ['2026/2', '2026/10', '2025/100'], 'workflow_maria_uyap_desc_secondary_asc');
        $filtered = $filter + ['filters' => [['field' => 'mean', 'op' => 'gte', 'value' => '1.49'], ['field' => 'infaz_ds_tarihi', 'op' => 'month', 'value' => '2027-01']]];
        $page = $list->page($actor, 'infaz', $filtered);
        $check(count($page['rows']) === 3 && $page['rows'][0]['values']['mean'] === '1.49', 'workflow_maria_derived_numeric_month_filter');
        $snapshot = $list->page($actor, 'infaz', $filtered, true);
        $check($snapshot['rows'] === array_map(fn ($r) => array_values($r['values']), $page['rows']), 'workflow_maria_export_matches_filter');
        $check($cases->detail($actor, 'ilkgorus', $ids[0])['values']['huk_saglik'] === 'İYİ IŞIK'
            && $cases->detail($actor, 'izin', $ids[0])['values']['izin_donus_saati'] === '23:59:00', 'workflow_maria_scope_uppercase_time');
        try {
            $cases->save($actor, 'infaz', $ids[0], ['revision' => 0, 'values' => ['infaz_not' => 'STALE']]);
            throw new \LogicException('Stale accepted');
        } catch (PersonConflict) {
            $check(DB::table('person_case_data')->where('person_id', $ids[0])->value('infaz_not') === null, 'workflow_maria_stale_case_rejected');
        }
        $prefix = DB::connection()->getTablePrefix();
        DB::unprepared('CREATE TRIGGER synthetic_workflow_failure BEFORE INSERT ON `'.$prefix.'audit_events` FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "synthetic"');
        try {
            $cases->save($actor, 'ilkgorus', $ids[0], ['revision' => 1, 'values' => ['huk_meslek' => 'ROLLBACK']]);
            throw new \LogicException('Audit accepted');
        } catch (QueryException) {
            $check(DB::table('person_case_data')->where('person_id', $ids[0])->value('huk_meslek') === null, 'workflow_maria_case_audit_atomic');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS synthetic_workflow_failure');
        }
        $fixed = Carbon::parse('2026-10-04 12:00:00', 'Europe/Istanbul');
        Carbon::setTestNow($fixed);
        try {
            $transfer = $reports->transfer($actor, $filter + ['excluded' => []]);
            $check(count($transfer['rows']) === 3 && $transfer['rows'][0]['values']['ds_days'] === '100', 'workflow_maria_transfer_whole_day_priority');
            $cases->save($actor, 'infaz', $ids[0], ['revision' => 1, 'values' => ['infaz_ds_tarihi' => '2026-10-03']]);
            $check(count($reports->transfer($actor, $filter + ['excluded' => []])['rows']) === 2, 'workflow_maria_past_ds_beats_future_release');
            DB::statement("SET time_zone = '+03:00'");
            DB::statement("SET timestamp = UNIX_TIMESTAMP('2026-10-04 13:59:59')");
            $before = $reports->leave($actor, $filter);
            $check($before['counts'] === ['departure' => 3, 'return' => 3, 'control' => 0], 'workflow_maria_control_before_1400');
            DB::statement("SET timestamp = UNIX_TIMESTAMP('2026-10-04 14:00:00')");
            $after = $reports->leave($actor, $filter);
            $check($after['counts']['control'] === 3 && count($after['rows']) === 3, 'workflow_maria_control_exact_1400_ignores_return_time');
            $check(count($reports->leave($actor, $filter + ['date' => '2026-10-03'])['rows']) === 0, 'workflow_maria_selected_date_independent_current_time');
        } finally {
            DB::statement('SET timestamp = DEFAULT');
            Carbon::setTestNow();
        }
        $preview = $cases->dsPreview($actor);
        $cases->dsReset($actor, $preview + ['confirm' => true]);
        $check(DB::table('person_case_data')->whereIn('person_id', $ids)->where('huk_ds_durum', '0')->count() === 3, 'workflow_maria_bulk_ds_reset');
    }
}
