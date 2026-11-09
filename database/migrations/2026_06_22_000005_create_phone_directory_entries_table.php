<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $newColumns = [
            'title' => fn (Blueprint $table) => $table->string('title'),
            'person_name' => fn (Blueprint $table) => $table->string('person_name')->nullable(),
            'internal_number' => fn (Blueprint $table) => $table->string('internal_number', 30)->nullable(),
            'external_number' => fn (Blueprint $table) => $table->string('external_number', 30)->nullable(),
            'mobile_number' => fn (Blueprint $table) => $table->string('mobile_number', 30)->nullable(),
            'email' => fn (Blueprint $table) => $table->string('email')->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
        ];

        Schema::table('phone_directory_entries', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->string('number', 40)->nullable()->change();
        });

        if (! Schema::hasColumn('phone_directory_entries', 'department_id')) {
            Schema::table('phone_directory_entries', fn (Blueprint $table) => $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete());
        }

        foreach ($newColumns as $column => $definition) {
            if (! Schema::hasColumn('phone_directory_entries', $column)) {
                Schema::table('phone_directory_entries', $definition);
            }
        }

        Schema::table('phone_directory_entries', function (Blueprint $table): void {
            $table->index(['institution_id', 'is_active', 'sort_order'], 'phone_inst_active_sort_idx');
            $table->index(['department_id', 'sort_order'], 'phone_dept_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('phone_directory_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['title', 'person_name', 'internal_number', 'external_number', 'mobile_number', 'email', 'notes']);
        });
    }
};
