<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inmate_file_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_code', 80);
            $table->string('record_type', 100)->nullable();
            $table->string('title');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status_code', 100)->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('is_closed')->default(false);
            $table->json('data')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module_code', 'starts_at']);
            $table->index(['module_code', 'record_type']);
            $table->index(['module_code', 'is_closed']);
            $table->index(['inmate_file_id', 'module_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_records');
    }
};
