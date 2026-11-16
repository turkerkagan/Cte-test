<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('author_name', 255);
            $t->boolean('is_private');
            $t->text('body');
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->index(['person_id', 'is_private', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_notes');
    }
};
