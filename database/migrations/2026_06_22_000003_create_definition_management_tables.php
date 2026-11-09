<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('definition_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('definition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_group_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('label');
            $table->string('color', 7)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['definition_group_id', 'code']);
            $table->index(['definition_group_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('definition_items');
        Schema::dropIfExists('definition_groups');
    }
};
