<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('username', 100)->change());
        if (! Schema::hasColumn('users', 'is_guest')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('is_guest')->default(false));
        }
        if (! Schema::hasColumn('users', 'last_activity_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->timestamp('last_activity_at')->nullable());
        }
        if (! Schema::hasIndex('users', ['last_activity_at'])) {
            Schema::table('users', fn (Blueprint $t) => $t->index('last_activity_at'));
        }
        foreach (['portal_login_events', 'account_requests'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->string('username', 100)->change());
        }
        if (! Schema::hasColumn('page_permissions', 'reason')) {
            Schema::table('page_permissions', fn (Blueprint $t) => $t->string('reason', 500)->nullable());
        }
        foreach (Schema::getForeignKeys('announcement_reads') as $foreign) {
            if ($foreign['columns'] === ['record_id']) {
                Schema::table('announcement_reads', fn (Blueprint $t) => $t->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? $foreign['columns'] : $foreign['name']));
            }
        }
        foreach (Schema::getIndexes('announcement_reads') as $index) {
            if ($index['unique'] && $index['columns'] === ['record_id', 'user_id']) {
                Schema::table('announcement_reads', fn (Blueprint $t) => $t->dropUnique($index['name']));
            }
        }
        if (! Schema::hasColumn('announcement_reads', 'record_table')) {
            Schema::table('announcement_reads', fn (Blueprint $t) => $t->string('record_table', 100)->default('institution_records'));
        }
        if (! Schema::hasIndex('announcement_reads', ['record_table', 'record_id', 'user_id'], 'unique')) {
            Schema::table('announcement_reads', fn (Blueprint $t) => $t->unique(['record_table', 'record_id', 'user_id'], 'announcement_read_target_unique'));
        }
        foreach (['description' => 1000, 'data_table' => 100] as $name => $length) {
            if (! Schema::hasColumn('portal_pages', $name)) {
                Schema::table('portal_pages', fn (Blueprint $t) => $t->string($name, $length)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::table('portal_pages', fn (Blueprint $t) => $t->dropColumn(['description', 'data_table']));
        Schema::table('page_permissions', fn (Blueprint $t) => $t->dropColumn('reason'));
        Schema::table('announcement_reads', function (Blueprint $t) {
            $t->dropUnique('announcement_read_target_unique');
            $t->dropColumn('record_table');
            $t->unique(['record_id', 'user_id']);
            $t->foreign('record_id')->references('id')->on('institution_records')->cascadeOnDelete();
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_guest', 'last_activity_at']));
    }
};
