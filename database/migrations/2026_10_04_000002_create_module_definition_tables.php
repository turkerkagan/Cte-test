<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('record_mode', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('module_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->restrictOnDelete();
            $table->string('type', 20);
            $table->timestamps();
        });
        Schema::create('module_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('draft_slot')->nullable()->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->json('definition');
            $table->timestamps();
            $table->unique(['module_id', 'number']);
            // Published history uses NULL: at most one mutable draft per module.
            $table->unique(['module_id', 'draft_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_versions');
        Schema::dropIfExists('module_fields');
        Schema::dropIfExists('modules');
    }
};
