<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->string('sender_name');
            $t->string('recipient_name');
            $t->string('subject', 255);
            $t->longText('body');
            $t->boolean('sender_deleted')->default(false);
            $t->boolean('recipient_deleted')->default(false);
            $t->timestamp('read_at')->nullable();
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->index(['recipient_id', 'recipient_deleted', 'id'], 'pm_recipient_idx');
            $t->index(['sender_id', 'sender_deleted', 'id'], 'pm_sender_idx');
        });
        Schema::create('message_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('personal_messages')->cascadeOnDelete();
            $t->string('path');
            $t->string('name');
            $t->string('mime', 150);
            $t->unsignedInteger('size');
            $t->timestamp('expires_at');
            $t->timestamp('deleted_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->timestamp('last_downloaded_at')->nullable();
            $t->timestamps();
            $t->index(['expires_at', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('personal_messages');
    }
};
