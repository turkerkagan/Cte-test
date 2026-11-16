<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_statuses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('label', 120);
            $t->boolean('included_in_active_list');
            $t->boolean('counts_room');
            $t->boolean('reserves_bed');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->integer('position')->default(0);
            $t->timestamps();
        });
        Schema::create('person_status_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->unique()->constrained('people')->restrictOnDelete();
            $t->foreignId('status_id')->constrained('person_statuses')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        foreach (json_decode(file_get_contents(resource_path('defaults/person-statuses.json')), true, 512, JSON_THROW_ON_ERROR) as $status) {
            DB::table('person_statuses')->insert($status + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_status_assignments');
        Schema::dropIfExists('person_statuses');
    }
};
