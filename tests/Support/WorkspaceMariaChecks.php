<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\People\PersonList;
use App\Services\Workspace\DataQuality;
use App\Services\Workspace\DutyScenarios;
use App\Services\Workspace\HandoverSummary;
use App\Services\Workspace\PermissionSimulation;
use Illuminate\Support\Facades\DB;

/** Bounded service-load regression in the disposable MariaDB integration schema. */
final class WorkspaceMariaChecks
{
    public static function run(User $actor, callable $check): void
    {
        if (! app()->environment('testing') || ! preg_match('/^cte_test_g03_[a-f0-9]{12}$/D', DB::connection()->getDatabaseName())) {
            throw new \LogicException('Performance checks require the disposable integration database.');
        }
        $report = ['syntheticPeople' => 20000, 'iterations' => 5, 'measurements' => [], 'realSourceRowsCopied' => 0];
        DB::beginTransaction();
        try {
            for ($offset = 0; $offset < 20000; $offset += 500) {
                $rows = [];
                for ($index = $offset; $index < $offset + 500; $index++) {
                    $rows[] = ['full_name' => 'SENTETIK PERF '.str_pad((string) $index, 5, '0', STR_PAD_LEFT), 'tc_identity' => null];
                }
                DB::table('people')->insert($rows);
            }
            $department = DB::table('departments')->where('code', 'infaz')->value('id')
                ?? DB::table('departments')->insertGetId(['code' => 'infaz', 'name' => 'SENTETIK PERF', 'is_active' => true]);
            DB::table('departments')->where('id', $department)->update(['is_active' => true]);
            for ($index = 0; $index < 100; $index++) {
                DB::table('users')->insert(['username' => 'synthetic_perf_'.$index, 'full_name' => 'SENTETIK PERF '.$index, 'password' => 'invalid-synthetic-no-login', 'department_id' => $department, 'is_active' => true]);
            }
            $jobs = [
                'people_search' => fn () => app(PersonList::class)->page($actor, ['scope' => 'all', 'q' => 'SENTETIK PERF', 'per_page' => 50]),
                'quality' => fn () => app(DataQuality::class)->read($actor, ['issue' => 'missing_identity']),
                'handover' => fn () => app(HandoverSummary::class)->read($actor, []),
                'permissions' => fn () => app(PermissionSimulation::class)->read($actor, ['user' => $actor->id]),
                'scenario_93_days' => fn () => app(DutyScenarios::class)->preview($actor, 'infaznobet', ['from' => '2027-01-01', 'to' => '2027-04-03']),
            ];
            foreach ($jobs as $name => $job) {
                $times = $queries = [];
                for ($iteration = 0; $iteration < 5; $iteration++) {
                    DB::enableQueryLog();
                    DB::flushQueryLog();
                    $started = hrtime(true);
                    $result = $job();
                    $times[] = round((hrtime(true) - $started) / 1000000, 2);
                    $queries[] = count(DB::getQueryLog());
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                    if (isset($result['rows'])) {
                        $check(count($result['rows']) <= 50, 'workspace_'.$name.'_bounded_rows_'.$iteration);
                    }
                }
                sort($times);
                $report['measurements'][$name] = ['medianMs' => $times[2], 'maxMs' => max($times), 'maxQueries' => max($queries)];
                $check(max($queries) < 180, 'workspace_'.$name.'_bounded_queries');
                $check(max($times) < 10000, 'workspace_'.$name.'_completes_within_regression_budget');
            }
            $check(count($result['candidates']) >= 100, 'workspace_scenarios_include_one_hundred_candidates');
            $check(count($result['scenarios'][0]['rows']) === 93, 'workspace_long_scenario_preserves_all_days');
            $output = getenv('CTE_WORKSPACE_PERFORMANCE_REPORT');
            if ($output) {
                file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            DB::rollBack();
        }
    }
}
