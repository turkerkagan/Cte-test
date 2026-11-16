<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->unique()->constrained('people')->restrictOnDelete();
            $t->foreignId('block_id')->nullable()->constrained('housing_blocks')->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained('housing_rooms')->restrictOnDelete();
            $t->foreignId('bed_id')->nullable()->constrained('housing_beds')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        // Number zero is the legacy "Ranzasız" choice, not an extra physical bed.
        foreach (DB::table('housing_rooms')->where('bed_count', 0)->pluck('id') as $room) {
            DB::table('housing_beds')->insertOrIgnore(['room_id' => $room, 'number' => 0, 'is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_locations'); /* Preserve stable virtual bed IDs; FK-protected reservations may exist. */
    }
};
