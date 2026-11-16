<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $year = $mysql ? "CAST(SUBSTRING_INDEX(infaz_uyap_no,'/',1) AS UNSIGNED)" : 'CAST(infaz_uyap_no AS INTEGER)';
        $number = $mysql ? "CAST(SUBSTRING_INDEX(infaz_uyap_no,'/',-1) AS UNSIGNED)" : "CAST(CASE WHEN instr(infaz_uyap_no,'/')>0 THEN substr(infaz_uyap_no,instr(infaz_uyap_no,'/')+1) ELSE infaz_uyap_no END AS INTEGER)";
        Schema::table('person_case_data', function (Blueprint $table) use ($year, $number) {
            $table->unsignedBigInteger('infaz_sort_year')->nullable()->storedAs($year);
            $table->unsignedBigInteger('infaz_sort_number')->nullable()->storedAs($number);
            $table->index(['infaz_sort_year', 'infaz_sort_number', 'person_id'], 'case_sentence_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('person_case_data', function (Blueprint $table) {
            $table->dropIndex('case_sentence_sort_idx');
            $table->dropColumn(['infaz_sort_year', 'infaz_sort_number']);
        });
    }
};
