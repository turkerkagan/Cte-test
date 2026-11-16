<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 120);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->integer('position')->default(0);
            $t->timestamps();
        });
        Schema::create('housing_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('block_id')->constrained('housing_blocks')->restrictOnDelete();
            $t->string('code', 100);
            $t->string('name', 120);
            $t->unsignedSmallInteger('bed_count');
            $t->unsignedSmallInteger('capacity');
            $t->string('kind', 10)->default('normal');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->unique(['block_id', 'code'], 'housing_room_code');
        });
        Schema::create('housing_beds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained('housing_rooms')->restrictOnDelete();
            $t->unsignedSmallInteger('number');
            $t->boolean('is_active')->default(true);
            $t->unique(['room_id', 'number'], 'housing_bed_number');
        });
        // Occupancy has no public write route yet; the next placement/status slice owns assignments.
        Schema::create('housing_occupancies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->unique()->constrained('people')->restrictOnDelete();
            $t->foreignId('bed_id')->unique()->constrained('housing_beds')->restrictOnDelete();
            $t->timestamps();
        });
        $defaults = json_decode(file_get_contents(resource_path('defaults/housing.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($defaults) {
            foreach ($defaults['blocks'] as $b) {
                DB::table('housing_blocks')->insert(array_intersect_key($b, array_flip(['id', 'code', 'name', 'is_active', 'position'])) + ['created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($defaults['rooms'] as $r) {
                DB::table('housing_rooms')->insert($r + ['created_at' => now(), 'updated_at' => now()]);
            }
            $beds = [];
            foreach ($defaults['rooms'] as $r) {
                for ($i = 1; $i <= $r['bed_count']; $i++) {
                    $beds[] = ['room_id' => $r['id'], 'number' => $i, 'is_active' => true];
                }
            }
            foreach (array_chunk($beds, 200) as $chunk) {
                DB::table('housing_beds')->insert($chunk);
            }
        });
    }

    public function down(): void
    {
        foreach (['housing_occupancies', 'housing_beds', 'housing_rooms', 'housing_blocks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
