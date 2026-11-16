<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Institution\CountReport;
use App\Services\Institution\InstitutionRecords;
use App\Services\Institution\PersonStatistics;
use App\Services\Organization\StaffTree;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceInstitutionMariaChecks
{
    public static function run(User $a, callable $check): void
    {
        $id = app(PersonRecords::class)->save($a, ['full_name' => 'SENTETİK MARIA SERVİS', 'tc_identity' => null, 'admitted_on' => null, 'registered_on' => null]);
        DB::table('person_status_assignments')->insert(['person_id' => $id, 'status_id' => 1, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cases = app(CaseRecords::class);
        $cases->save($a, 'egitim', $id, ['revision' => 0, 'values' => ['egt_okulsifre' => 'SENTETİK MARIA SECRET', 'egt_okul' => 'SENTETİK OKUL']]);
        $check(DB::table('person_case_data')->where('person_id', $id)->value('egt_okulsifre') !== 'SENTETİK MARIA SECRET', 'services_encrypted_credentials');
        $check($cases->detail($a, 'egitim', $id)['values']['egt_okulsifre'] === 'SENTETİK MARIA SECRET', 'services_credentials_decrypt_authorized');
        $check(! str_contains(json_encode(app(CaseList::class)->page($a, 'egitim', ['q' => 'SENTETİK MARIA SERVİS'], true)), 'SENTETİK MARIA SECRET'), 'services_export_secret_omission');
        $cases->save($a, 'revir', $id, ['revision' => 0, 'values' => ['huk_saglik' => 'İYİ']]);
        $check($cases->detail($a, 'ilkgorus', $id)['values']['huk_saglik'] === 'İYİ', 'services_shared_health');
        $check($cases->detail($a, 'ilkgorus', $id)['revision'] === 1, 'services_shared_revision');
        $list = app(CaseList::class)->page($a, 'psikososyal', ['filters' => [['field' => 'samba', 'op' => 'empty', 'value' => null]]]);
        $check(in_array($id, array_column($list['rows'], 'id')), 'services_maria_derived_filter');
        $check(DB::table('case_option_lists')->count() === 39, 'service_option_lists_39');
        $check(app(CountReport::class)->read()['systemTotals']['registered'] > 0, 'count_prefix_group_query');
        $check(app(PersonStatistics::class)->read($a, 'mesemstat')['summary']['total'] > 0, 'statistics_semantic_predicates');
        $record = app(InstitutionRecords::class)->save($a, 'telefon', null, ['values' => ['name' => 'SENTETİK MARIA', 'phone' => '0012']]);
        $check(app(InstitutionRecords::class)->read($a, 'telefon', ['q' => 'SENTETİK MARIA'])['rows'][0]['display']['phone'] === '0012', 'institution_prefix_json_search');
        $root = app(StaffTree::class)->save($a, null, ['full_name' => 'SENTETİK KÖK', 'title' => 'SENTETİK UZUN UNVAN', 'parent_id' => null, 'user_id' => null, 'position' => 0, 'description' => null, 'is_active' => true]);
        $child = app(StaffTree::class)->save($a, null, ['full_name' => 'SENTETİK DAL', 'title' => 'DAL', 'parent_id' => $root['id'], 'user_id' => null, 'position' => 1, 'description' => null, 'is_active' => true]);
        $check(count(app(StaffTree::class)->read($a)['nodes']) === 2, 'staff_tree_prefix_self_fk');
        try {
            app(StaffTree::class)->save($a, $root['id'], ['revision' => 1, 'full_name' => 'SENTETİK KÖK', 'title' => 'KÖK', 'parent_id' => $child['id'], 'user_id' => null, 'position' => 0, 'description' => null, 'is_active' => true]);
            throw new \LogicException('cycle accepted');
        } catch (ValidationException) {
            $check(true, 'staff_maria_cycle_rejected');
        }
    }
}
