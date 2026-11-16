<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_installations', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('installed_at')->nullable();
        });
        DB::table('portal_installations')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_installations');
    }
};
