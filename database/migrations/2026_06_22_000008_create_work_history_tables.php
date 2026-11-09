<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'is_active', 'sort_order']);
        });

        Schema::table('work_assignments', function (Blueprint $table): void {
            $table->foreignId('work_place_id')->constrained()->restrictOnDelete();
            $table->string('decision_code', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['inmate_file_id', 'started_at']);
            $table->index(['work_place_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('work_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('work_place_id');
            $table->dropColumn('decision_code');
        });
        Schema::dropIfExists('work_places');
    }
};
