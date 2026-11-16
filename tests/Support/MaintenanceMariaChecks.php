<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Administration\SqlExports;
use App\Services\Administration\StandaloneRecovery;
use App\Services\Institution\InstitutionRecords;
use App\Services\People\PersonConflict;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class MaintenanceMariaChecks
{
    public static function run(User $actor, callable $check): void
    {
        if (! app()->environment('testing') || ! preg_match('/^cte_test_g03_[a-f0-9]{12}$/D', DB::connection()->getDatabaseName())) {
            throw new \LogicException('Maintenance checks require the disposable integration database.');
        }
        $prefix = DB::connection()->getTablePrefix();
        $physical = $prefix.'maintenance_export_probe';
        $recoveryPrefix = $prefix.'maintenance_';
        $directory = sys_get_temp_dir().'/cte-maintenance-maria-'.bin2hex(random_bytes(6));
        $file = null;
        try {
            $records = app(InstitutionRecords::class);
            $id = $records->save($actor, 'telefon', null, ['values' => ['name' => 'SENTETİK BAKIM', 'phone' => '00123', 'position' => 0]])['id'];
            $records->archive($actor, 'telefon', $id, 1);
            $check(! DB::table('institution_records')->where('id', $id)->value('is_active'), 'maintenance_phone_archived_without_deletion');
            try {
                $records->restore($actor, 'telefon', $id, 1);
                throw new \LogicException('Stale restore accepted.');
            } catch (PersonConflict) {
                $check((int) DB::table('institution_records')->where('id', $id)->value('revision') === 2, 'maintenance_phone_stale_restore_rejected');
            }
            $records->restore($actor, 'telefon', $id, 2);
            $check((int) DB::table('institution_records')->where('id', $id)->value('revision') === 3, 'maintenance_phone_restore_advances_revision');

            DB::statement('CREATE TABLE `'.$physical.'` (id INT PRIMARY KEY, value TEXT NULL, computed INT GENERATED ALWAYS AS (CHAR_LENGTH(value)) STORED) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $value = "SENTETİK İŞIK ' \\ \n\r\0";
            for ($offset = 0; $offset < 1201; $offset += 100) {
                $rows = [];
                for ($index = $offset; $index < min(1201, $offset + 100); $index++) {
                    $rows[] = ['id' => $index + 1, 'value' => $index === 0 ? null : $value];
                }
                DB::table('maintenance_export_probe')->insert($rows);
            }
            $file = app(SqlExports::class)->dataFile($actor, 'maintenance_export_probe');
            $sql = file_get_contents($file);
            $check(substr_count($sql, 'INSERT INTO `'.$physical.'`') === 1201, 'maintenance_sql_preserves_prefix_and_all_chunks');
            $check(! str_contains($sql, '(`id`,`value`,`computed`) VALUES'), 'maintenance_sql_omits_generated_values');
            DB::statement('RENAME TABLE `'.$physical.'` TO `'.$physical.'_source`');
            DB::unprepared($sql);
            $check(DB::table('maintenance_export_probe')->count() === 1201
                && DB::table('maintenance_export_probe')->where('id', 1)->value('value') === null
                && DB::table('maintenance_export_probe')->where('id', 2)->value('value') === $value
                && (int) DB::table('maintenance_export_probe')->where('id', 2)->value('computed') === mb_strlen($value), 'maintenance_sql_executes_with_unicode_null_escapes_and_generated_columns');

            DB::statement('CREATE TABLE `'.$recoveryPrefix.'users` LIKE `'.$prefix.'users`');
            $runtime = new StandaloneRecovery($directory, ['APP_KEY' => config('app.key'), 'DB_TABLE_PREFIX' => $recoveryPrefix]);
            foreach ([false, true] as $legacy) {
                $rows = [];
                // The default MariaDB collation considers türker/turker equal; test the ASCII alias in the current backup.
                foreach ([$legacy ? 'türker' : 'renamed-master', 'admin', $legacy ? 'ordinary-admin' : 'turker'] as $index => $username) {
                    $rows[] = ['id' => $index + 1, 'username' => $username, 'password' => 'synthetic-no-login', 'is_active' => 1,
                        'is_system_admin' => 1, 'is_master_admin' => (int) ($index === 0), 'session_version' => 0];
                }
                if ($legacy) {
                    foreach ($rows as &$row) {
                        unset($row['is_master_admin']);
                    }
                    unset($row);
                }
                $payload = ['format' => 'cte-administration-1', 'tables' => ['users' => $rows], 'files' => []];
                $runtime->restore(DB::connection()->getPdo(), Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'cte');
                $flags = DB::connection()->getPdo()->query('SELECT is_master_admin FROM `'.$recoveryPrefix.'users` ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
                $check(array_map('intval', $flags) === [1, 0, 0], 'maintenance_standalone_'.($legacy ? 'legacy' : 'current').'_backup_preserves_identity');
            }
        } finally {
            foreach ([$physical, $physical.'_source', $recoveryPrefix.'users'] as $table) {
                DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
            }
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
            File::deleteDirectory($directory);
        }
    }
}
