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
            $table->string('name');
            $table->string('route_name')->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->string('title');
            $table->string('route_name')->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lookup_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('lookup_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookup_group_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['lookup_group_id', 'code']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->string('type', 30)->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->unique(['institution_id', 'key']);
        });

        Schema::create('phone_directory_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('number', 40)->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('inmate_file_id')->nullable()->index();
            $table->string('module', 80);
            $table->string('action', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('change_keys')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('phone_directory_entries');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('lookup_values');
        Schema::dropIfExists('lookup_groups');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('modules');
    }
};
