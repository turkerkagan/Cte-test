<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_versions', fn (Blueprint $table) => $table->unique(['id', 'module_id'], 'version_module_identity'));
        Schema::table('module_fields', function (Blueprint $table) {
            $table->unique(['id', 'module_id'], 'field_module_identity');
            $table->unique(['id', 'module_id', 'option_list_id'], 'field_option_source_identity');
        });
        Schema::table('option_items', fn (Blueprint $table) => $table->unique(['id', 'option_list_id'], 'option_source_identity'));
        Schema::create('module_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->restrictOnDelete();
            $table->unsignedBigInteger('version_id');
            $table->json('values');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'module_id'], 'record_module_identity');
            $table->foreign(['version_id', 'module_id'], 'record_version_module_fk')->references(['id', 'module_id'])->on('module_versions')->restrictOnDelete();
            $table->index(['module_id', 'id']);
        });
        Schema::create('module_record_index', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('field_id');
            $table->string('text_value', 255)->nullable();
            $table->text('longtext_value')->nullable();
            $table->bigInteger('integer_value')->nullable();
            $table->decimal('decimal_value', 18, 6)->nullable();
            $table->date('date_value')->nullable();
            $table->dateTime('datetime_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->primary(['record_id', 'field_id']);
            $table->foreign(['record_id', 'module_id'], 'index_record_module_fk')->references(['id', 'module_id'])->on('module_records')->cascadeOnDelete();
            $table->foreign(['field_id', 'module_id'], 'index_field_module_fk')->references(['id', 'module_id'])->on('module_fields')->restrictOnDelete();
            foreach (['text_value', 'integer_value', 'decimal_value', 'date_value', 'datetime_value', 'boolean_value'] as $column) {
                $table->index(['module_id', 'field_id', $column], 'record_'.$column.'_idx');
            }
        });
        Schema::create('module_record_options', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('option_list_id');
            $table->unsignedBigInteger('option_id');
            $table->string('label_snapshot', 150);
            $table->primary(['record_id', 'field_id', 'option_id']);
            $table->foreign(['record_id', 'module_id'], 'usage_record_module_fk')->references(['id', 'module_id'])->on('module_records')->cascadeOnDelete();
            $table->foreign(['field_id', 'module_id', 'option_list_id'], 'usage_field_source_fk')->references(['id', 'module_id', 'option_list_id'])->on('module_fields')->restrictOnDelete();
            $table->foreign(['option_id', 'option_list_id'], 'usage_option_source_fk')->references(['id', 'option_list_id'])->on('option_items')->restrictOnDelete();
            $table->index(['module_id', 'field_id', 'option_id'], 'record_option_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_record_options');
        Schema::dropIfExists('module_record_index');
        Schema::dropIfExists('module_records');
        Schema::table('option_items', fn (Blueprint $table) => $table->dropUnique('option_source_identity'));
        Schema::table('module_fields', function (Blueprint $table) {
            $table->dropUnique('field_option_source_identity');
            $table->dropUnique('field_module_identity');
        });
        Schema::table('module_versions', fn (Blueprint $table) => $table->dropUnique('version_module_identity'));
    }
};
