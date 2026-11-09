<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inmate_files', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->change();
            $table->string('identity_number', 11)->nullable();
            $table->date('entered_at')->nullable();
            $table->string('status_code', 100)->nullable();
            $table->string('risk_code', 100)->nullable();
            $table->string('decision_code', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->unique(['institution_id', 'identity_number']);
            $table->index(['institution_id', 'is_active', 'full_name']);
            $table->index(['legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inmate_files', function (Blueprint $table): void {
            $table->dropUnique(['institution_id', 'identity_number']);
            $table->dropColumn(['identity_number', 'entered_at', 'status_code', 'risk_code', 'decision_code', 'notes', 'is_active', 'deleted_at']);
        });
    }
};
