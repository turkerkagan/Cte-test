<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_records', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            // Server-owned 1 for person_single, NULL for other modes.
            $table->unsignedTinyInteger('single_person_slot')->nullable();
            $table->unique(['module_id', 'person_id', 'single_person_slot'], 'module_person_single_identity');
        });
    }

    public function down(): void
    {
        Schema::table('module_records', function (Blueprint $table) {
            $table->dropUnique('module_person_single_identity');
            $table->dropForeign(['person_id']);
            $table->dropColumn(['person_id', 'single_person_slot']);
        });
    }
};
