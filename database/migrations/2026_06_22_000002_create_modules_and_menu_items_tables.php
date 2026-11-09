<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            Schema::create('modules', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->unique();
                $table->string('name');
                $table->string('icon', 40)->nullable();
                $table->string('route_name')->nullable();
                $table->string('permission_key')->nullable();
                $table->boolean('is_sensitive')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_visible')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        } else {
            Schema::table('modules', function (Blueprint $table): void {
                if (! Schema::hasColumn('modules', 'icon')) {
                    $table->string('icon', 40)->nullable()->after('name');
                }
                if (! Schema::hasColumn('modules', 'route_name')) {
                    $table->string('route_name')->nullable()->after('icon');
                }
                if (! Schema::hasColumn('modules', 'permission_key')) {
                    $table->string('permission_key')->nullable()->after('route_name');
                }
                if (! Schema::hasColumn('modules', 'is_visible')) {
                    $table->boolean('is_visible')->default(true)->after('is_active');
                }
                if (! Schema::hasColumn('modules', 'sort_order')) {
                    $table->unsignedInteger('sort_order')->default(0)->after('is_visible');
                }
            });
        }

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->string('module_code', 80)->nullable();
            $table->string('title');
            $table->string('icon', 40)->nullable();
            $table->string('route_name')->nullable();
            $table->string('permission_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
            $table->index(['is_active', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');

        if (Schema::hasTable('modules')) {
            Schema::table('modules', function (Blueprint $table): void {
                foreach (['sort_order', 'is_visible', 'permission_key', 'route_name', 'icon'] as $column) {
                    if (Schema::hasColumn('modules', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
