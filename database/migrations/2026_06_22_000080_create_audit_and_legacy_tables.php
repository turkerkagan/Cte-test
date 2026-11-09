<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inmate_file_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'created_at']);
            $table->index(['inmate_file_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('legacy_transfer_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('run_type', 40);
            $table->string('status', 40)->default('created');
            $table->json('summary')->nullable();
            $table->json('report')->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['run_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_transfer_runs');
        Schema::dropIfExists('audit_events');
    }
};
