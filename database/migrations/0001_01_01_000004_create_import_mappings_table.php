<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_name', 64);
            $table->string('source_table', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('target_table', 32);
            $table->unsignedBigInteger('target_id');
            $table->char('checksum', 64);
            $table->char('target_checksum', 64);
            $table->unsignedInteger('import_version');
            $table->timestamps();
            $table->unique(['source_name', 'source_table', 'source_id'], 'import_source_unique');
            $table->unique(['target_table', 'target_id'], 'import_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
