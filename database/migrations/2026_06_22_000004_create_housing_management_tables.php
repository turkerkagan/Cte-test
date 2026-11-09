<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::table('wards', function (Blueprint $table): void {
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::table('beds', function (Blueprint $table): void {
            $table->string('name', 40)->nullable()->change();
            $table->string('code', 50)->nullable();
            $table->string('label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('beds', fn (Blueprint $table) => $table->dropColumn(['code', 'label', 'sort_order']));
        Schema::table('wards', fn (Blueprint $table) => $table->dropColumn(['capacity', 'sort_order']));
        Schema::table('blocks', fn (Blueprint $table) => $table->dropColumn(['description', 'sort_order']));
    }
};
