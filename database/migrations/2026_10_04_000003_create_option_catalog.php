<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('option_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_list_id')->constrained('option_lists')->restrictOnDelete();
            $table->string('label', 150);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['option_list_id', 'is_active', 'position']);
        });
        Schema::table('module_fields', function (Blueprint $table) {
            $table->foreignId('option_list_id')->nullable()->constrained('option_lists')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('module_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('option_list_id');
        });
        Schema::dropIfExists('option_items');
        Schema::dropIfExists('option_lists');
    }
};
