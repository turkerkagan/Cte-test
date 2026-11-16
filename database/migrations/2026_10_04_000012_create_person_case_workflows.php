<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = json_decode(file_get_contents(resource_path('defaults/person-workflows.json')), true, 64, JSON_THROW_ON_ERROR);
        Schema::create('case_option_lists', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('name', 150);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('case_option_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('option_list_id')->constrained('case_option_lists')->restrictOnDelete();
            $t->string('label', 150);
            $t->string('legacy_value', 150)->nullable();
            $t->unsignedInteger('position');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('person_case_data', function (Blueprint $t) use ($defaults) {
            $t->id();
            $t->foreignId('person_id')->unique()->constrained('people')->restrictOnDelete();
            foreach (['ilkgorus', 'infaz', 'izin'] as $group) {
                $t->unsignedInteger($group.'_revision')->default(0);
            }
            foreach ($defaults['fields'] as $key => $field) {
                match ($field['type']) {
                    'option' => $t->foreignId($key)->nullable(),
                    'integer' => $t->integer($key)->nullable(),
                    'date' => $t->date($key)->nullable(),
                    'time' => $t->string($key, 8)->nullable(),
                    'longtext' => $t->text($key)->nullable(),
                    default => $t->string($key, 255)->nullable(),
                };
                if ($field['type'] === 'option') {
                    $t->foreign($key, 'case_'.$key.'_fk')->references('id')->on('case_option_items')->restrictOnDelete();
                }
            }
            $t->timestamps();
            $t->index('infaz_ds_tarihi');
            $t->index('infaz_tahliye_tarihi');
            $t->index('izin_cikis_tarihi');
            $t->index('izin_donus_tarihi');
        });
        Schema::create('person_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->unique()->constrained('people')->restrictOnDelete();
            $t->string('path', 200)->nullable();
            $t->string('mime', 30)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        foreach ($defaults['options'] as $key => $items) {
            $list = DB::table('case_option_lists')->insertGetId(['code' => str_replace('_', '-', $key), 'name' => $defaults['fields'][$key]['label'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $i => $label) {
                DB::table('case_option_items')->insert(['option_list_id' => $list, 'label' => $label, 'legacy_value' => $label, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_photos');
        Schema::dropIfExists('person_case_data');
        Schema::dropIfExists('case_option_items');
        Schema::dropIfExists('case_option_lists');
    }
};
