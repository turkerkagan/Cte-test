<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $d = json_decode(file_get_contents(resource_path('defaults/service-workflows.json')), true, 64, JSON_THROW_ON_ERROR);
        Schema::table('person_case_data', function (Blueprint $t) use ($d) {
            foreach (array_keys($d['groups']) as $group) {
                $t->unsignedInteger($group.'_revision')->default(0);
            }
            foreach ($d['fields'] as $key => $f) {
                match ($f['type']) {
                    'option' => $t->foreignId($key)->nullable(),
                    'date' => $t->date($key)->nullable(),
                    'integer' => $t->integer($key)->nullable(),
                    'longtext','secret' => $t->text($key)->nullable(),
                    default => $t->string($key, 255)->nullable(),
                };
                if ($f['type'] === 'option') {
                    $t->foreign($key, 'svc_'.$key.'_fk')->references('id')->on('case_option_items')->restrictOnDelete();
                }
            }
        });
        foreach ($d['options'] as $key => $items) {
            $id = DB::table('case_option_lists')->insertGetId(['code' => str_replace('_', '-', $key), 'name' => $d['fields'][$key]['label'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $i => $label) {
                DB::table('case_option_items')->insert(['option_list_id' => $id, 'label' => $label, 'legacy_value' => $label, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $d = json_decode(file_get_contents(resource_path('defaults/service-workflows.json')), true, 64, JSON_THROW_ON_ERROR);
        Schema::table('person_case_data', function (Blueprint $t) use ($d) {
            foreach ($d['fields'] as $key => $f) {
                if ($f['type'] === 'option') {
                    $t->dropForeign('svc_'.$key.'_fk');
                }
            }
            $t->dropColumn([...array_keys($d['fields']), ...array_map(fn ($g) => $g.'_revision', array_keys($d['groups']))]);
        });
        $ids = DB::table('case_option_lists')->whereIn('code', array_map(fn ($k) => str_replace('_', '-', $k), array_keys($d['options'])))->pluck('id');
        DB::table('case_option_items')->whereIn('option_list_id', $ids)->delete();
        DB::table('case_option_lists')->whereIn('id', $ids)->delete();
    }
};
