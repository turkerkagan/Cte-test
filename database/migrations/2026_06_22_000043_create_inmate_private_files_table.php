<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmate_private_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40)->default('document');
            $table->string('display_name')->nullable();
            $table->string('original_name');
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('extension', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['inmate_file_id', 'category']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inmate_private_files');
    }
};
