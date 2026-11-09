<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_code', 80);
            $table->string('record_type', 100)->nullable();
            $table->string('title');
            $table->date('occurred_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->string('status_code', 100)->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->json('data')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['module_code', 'occurred_at']);
            $table->index(['inmate_file_id', 'module_code']);
            $table->index(['module_code', 'record_type']);
            $table->index(['module_code', 'is_closed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_records');
    }
};
