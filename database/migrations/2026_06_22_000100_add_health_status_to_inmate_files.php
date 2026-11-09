<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inmate_files', function (Blueprint $table): void {
            $table->string('health_status')->nullable()->after('decision_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inmate_files', function (Blueprint $table): void {
            $table->dropColumn('health_status');
        });
    }
};
