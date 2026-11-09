<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never silently discard conflicting historical data during an upgrade.
        foreach (['housing_assignments' => ['inmate_file_id', 'bed_id'], 'work_assignments' => ['inmate_file_id']] as $name => $keys) {
            foreach ($keys as $key) {
                if (DB::table($name)->whereNull('ended_at')->whereNotNull($key)->groupBy($key)->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new RuntimeException("{$name}: {$key} için mükerrer aktif atamalar var. Önce kayıtları uzlaştırın.");
                }
            }
        }
        foreach (['housing_assignments', 'work_assignments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('active_inmate_id')->nullable()->virtualAs('CASE WHEN ended_at IS NULL THEN inmate_file_id ELSE NULL END');
                $table->unique('active_inmate_id', $name.'_active_inmate_unique');
                if ($name === 'housing_assignments') {
                    $table->unsignedBigInteger('occupied_bed_id')->nullable()->virtualAs('CASE WHEN ended_at IS NULL THEN bed_id ELSE NULL END');
                    $table->unique('occupied_bed_id', 'housing_active_bed_unique');
                }
            });
        }
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('session_version')->default(0));
        foreach (['unit_records', 'operation_records'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('closed_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
        foreach (['unit_records', 'operation_records'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('closed_at'));
        }
        foreach (['housing_assignments', 'work_assignments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name.'_active_inmate_unique');
                $table->dropColumn('active_inmate_id');
                if ($name === 'housing_assignments') {
                    $table->dropUnique('housing_active_bed_unique');
                    $table->dropColumn('occupied_bed_id');
                }
            });
        }
    }
};
