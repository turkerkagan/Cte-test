<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('legacy_file', 150)->nullable();
            $table->string('path', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('page_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('portal_pages')->cascadeOnDelete();
            $table->string('target_type', 10);
            $table->unsignedBigInteger('target_id')->default(0);
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('permission', 10);
            $table->timestamps();
            $table->unique(['page_id', 'target_type', 'target_id'], 'page_permission_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_permissions');
        Schema::dropIfExists('portal_pages');
    }
};
