<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housing_assignments', function (Blueprint $table): void {
            $table->date('started_at')->change();
            $table->date('ended_at')->nullable()->change();
            $table->foreignId('block_id')->constrained()->restrictOnDelete();
            $table->foreignId('ward_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->index(['inmate_file_id', 'started_at']);
            $table->index(['ward_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('housing_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('block_id');
            $table->dropColumn('notes');
        });
    }
};
