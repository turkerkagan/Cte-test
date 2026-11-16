<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_records', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40);
            $t->json('values');
            $t->string('natural_key', 100)->nullable();
            $t->dateTime('event_on')->nullable();
            $t->integer('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['kind', 'natural_key']);
            $t->index(['kind', 'is_active', 'event_on', 'id']);
        });
        Schema::create('announcement_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('institution_records')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unique(['record_id', 'user_id']);
        });
        Schema::create('duty_unavailable', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('date');
            $t->string('reason', 500);
            $t->unique(['user_id', 'date']);
        });
        Schema::create('meal_files', function (Blueprint $t) {
            $t->id();
            $t->date('week');
            $t->string('type', 20);
            $t->string('path', 200);
            $t->string('mime', 50);
            $t->string('name', 180);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['week', 'type']);
        });
        Schema::create('chat_rooms', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_general')->default(false);
            $t->timestamps();
        });
        Schema::create('chat_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unique(['room_id', 'user_id']);
        });
        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->nullable()->constrained('chat_rooms')->restrictOnDelete();
            $t->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('sender_name', 255);
            $t->text('body');
            $t->timestamps();
            $t->index(['room_id', 'id']);
            $t->index(['sender_id', 'recipient_id', 'id']);
        });
        DB::table('chat_rooms')->insert(['name' => 'Genel Oda', 'is_general' => true, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 1; $i <= 12; $i++) {
            DB::table('institution_records')->insert(['kind' => 'kurul', 'values' => json_encode(['position' => $i]), 'natural_key' => (string) $i, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['chat_messages', 'chat_members', 'chat_rooms', 'meal_files', 'duty_unavailable', 'announcement_reads', 'institution_records'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
