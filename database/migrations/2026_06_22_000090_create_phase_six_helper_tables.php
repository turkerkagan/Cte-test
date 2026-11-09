<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('operation_records')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });

        Schema::create('operation_record_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_record_id')->constrained('operation_records')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 100)->nullable();
            $table->timestamps();

            $table->unique(['operation_record_id', 'user_id']);
        });

        Schema::create('operation_private_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_record_id')->constrained('operation_records')->cascadeOnDelete();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedInteger('file_size');
            $table->string('file_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_private_files');
        Schema::dropIfExists('operation_record_members');
        Schema::dropIfExists('announcement_reads');
    }
};
