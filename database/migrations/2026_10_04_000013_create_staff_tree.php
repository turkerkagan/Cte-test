<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_nodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('staff_nodes')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('full_name', 255);
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['parent_id', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_nodes');
    }
};
