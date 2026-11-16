<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administration_settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->json('values');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('portal_login_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('username', 50);
            $t->string('full_name', 150)->nullable();
            $t->string('outcome', 20);
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index(['outcome', 'created_at']);
        });
        Schema::create('account_requests', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('status', 20)->default('new');
            $t->string('username', 50);
            $t->string('full_name', 150)->nullable();
            $t->string('registry_number', 50)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $t->string('message', 1000)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('resolution', 500)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['kind', 'status', 'id']);
        });
        Schema::create('administration_backups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->unique();
            $t->string('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->json('summary');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['administration_backups', 'account_requests', 'portal_login_events', 'administration_settings'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
