<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleValueValidator;
use App\Services\Modules\OptionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModuleValueValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $type, bool $required = false, bool $visible = true): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $service = app(ModuleDefinitions::class);
        $moduleId = $service->create($actor, ['code' => 'synthetic-module', 'name' => 'SENTETİK MODÜL', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $moduleId)->first();
        $listId = null;
        $optionIds = [];
        if (in_array($type, ['select', 'multiselect'], true)) {
            $catalog = app(OptionCatalog::class);
            $listId = $catalog->create($actor, ['code' => 'synthetic-options', 'name' => 'SENTETİK LİSTE']);
            $catalog->save($actor, $listId, ['revision' => 1, 'name' => 'SENTETİK LİSTE', 'is_active' => true,
                'items' => [['id' => null, 'label' => 'SENTETİK BİR', 'is_active' => true], ['id' => null, 'label' => 'SENTETİK İKİ', 'is_active' => true]]]);
            $optionIds = DB::table('option_items')->where('option_list_id', $listId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $field = ['id' => null, 'type' => $type, 'label' => 'SENTETİK ALAN', 'helper' => '', 'required' => $required, 'show_in_form' => $visible, 'show_in_table' => true];
        if ($listId !== null) {
            $field['option_list_id'] = $listId;
        }
        $service->save($actor, $moduleId, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK MODÜL', 'fields' => [$field]]);
        // Publication is still a synthetic test state, not an implemented action.
        DB::table('module_versions')->where('id', $version->id)->update(['status' => 'published', 'draft_slot' => null]);
        $fieldId = (int) DB::table('module_fields')->where('module_id', $moduleId)->value('id');

        return [$moduleId, (int) $version->id, $fieldId, $listId, $optionIds];
    }

    private function validate(array $fixture, mixed $value): array
    {
        return app(ModuleValueValidator::class)->validateForCreate($fixture[0], $fixture[1], [$fixture[2] => $value]);
    }

    private function rejects(array $fixture, mixed $value): void
    {
        try {
            $this->validate($fixture, $value);
            $this->fail('Invalid value accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('values.'.$fixture[2], $error->errors());
            $this->assertStringContainsString('SENTETİK ALAN', $error->errors()['values.'.$fixture[2]][0]);
        }
    }

    public static function validValues(): array
    {
        return [
            ['text', '  İğdır <b>ş</b>  ', '  İğdır <b>ş</b>  '], ['longtext', "Bir\r\nİki\rÜç", "Bir\nİki\nÜç"],
            ['integer', '9007199254740991', 9007199254740991], ['integer', '-9007199254740991', -9007199254740991], ['integer', 0, 0],
            ['decimal', '-00012.340000', '-12.34'], ['decimal', '999999999999.999999', '999999999999.999999'], ['decimal', '-0.000000', '0'],
            ['boolean', false, false], ['boolean', '0', false], ['boolean', '1', true],
            ['date', '2024-02-29', '2024-02-29'], ['datetime', '2026-10-04T14:00', '2026-10-04 14:00:00'], ['datetime', '2026-10-04 14:00:59', '2026-10-04 14:00:59'],
        ];
    }

    #[DataProvider('validValues')]
    public function test_values_are_normalized_without_rounding_or_changing_business_hours(string $type, mixed $value, mixed $expected): void
    {
        $fixture = $this->fixture($type, true);
        $this->assertSame([$fixture[2] => $expected], $this->validate($fixture, $value));
    }

    public static function invalidValues(): array
    {
        return [
            ['text', 123], ['text', ['script' => 'eval']], ['text', "Bad\0Text"], ['text', "line\nline"], ['text', "\xFF"], ['text', str_repeat('ş', 256)],
            ['longtext', str_repeat('ş', 10001)], ['integer', true], ['integer', 1.0], ['integer', '1.5'], ['integer', '1e3'], ['integer', '+1'], ['integer', '01'], ['integer', '9007199254740992'],
            ['decimal', 0.1], ['decimal', '1,5'], ['decimal', '1e5'], ['decimal', '1000000000000'], ['decimal', '0.0000001'],
            ['boolean', 'false'], ['boolean', 'yes'], ['boolean', 2], ['boolean', []],
            ['date', '2023-02-29'], ['date', '0000-00-00'], ['date', '2026-04-31'], ['date', '04.10.2026'], ['date', '2026-10-04 00:00:00'],
            ['datetime', '2026-10-04T24:00'], ['datetime', '2026-10-04T14:60'], ['datetime', '2026-10-04T14:00:60'], ['datetime', '2026-10-04T14:00Z'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_types_ranges_and_calendar_values_are_rejected(string $type, mixed $value): void
    {
        $this->rejects($this->fixture($type), $value);
    }

    public function test_missing_optional_values_are_explicit_and_required_false_zero_are_valid(): void
    {
        $fixture = $this->fixture('text');
        $this->assertSame([$fixture[2] => null], app(ModuleValueValidator::class)->validateForCreate($fixture[0], $fixture[1], []));
        $this->assertSame([$fixture[2] => null], $this->validate($fixture, '   '));
        DB::table('module_fields')->where('id', $fixture[2])->update(['type' => 'integer']);
        $definition = json_decode(DB::table('module_versions')->where('id', $fixture[1])->value('definition'), true);
        $definition['fields'][0]['type'] = 'integer';
        $definition['fields'][0]['required'] = true;
        DB::table('module_versions')->where('id', $fixture[1])->update(['definition' => json_encode($definition)]);
        $this->rejects($fixture, null);
        $this->assertSame([$fixture[2] => 0], $this->validate($fixture, '0'));
    }

    public function test_only_same_list_active_options_and_unique_lists_are_accepted(): void
    {
        $fixture = $this->fixture('multiselect', true);
        [$first, $second] = $fixture[4];
        $this->assertSame([$fixture[2] => [$first, $second]], $this->validate($fixture, [(string) $second, $first]));
        $this->rejects($fixture, [$first, (string) $first]);
        $this->rejects($fixture, ['not-a-list' => $first]);
        $this->rejects($fixture, array_fill(0, 501, $first));
        $this->rejects($fixture, []);
        $this->rejects($fixture, [$first, 99999]);
        $this->rejects($fixture, [0]);
        $other = DB::table('option_lists')->insertGetId(['code' => 'foreign', 'name' => 'SENTETİK']);
        $foreign = DB::table('option_items')->insertGetId(['option_list_id' => $other, 'label' => 'SENTETİK', 'position' => 0]);
        $this->rejects($fixture, [$foreign]);
        DB::table('option_items')->where('id', $second)->update(['is_active' => false]);
        $this->rejects($fixture, [$second]);
        $this->assertSame([$fixture[2] => [$first]], $this->validate($fixture, [$first]));
        DB::table('option_lists')->where('id', $fixture[3])->update(['is_active' => false]);
        $this->rejects($fixture, [$first]);
    }

    public function test_single_choice_requires_one_positive_id_and_never_option_label(): void
    {
        $fixture = $this->fixture('select');
        $this->assertSame([$fixture[2] => $fixture[4][0]], $this->validate($fixture, (string) $fixture[4][0]));
        foreach ([[$fixture[4][0]], false, 0, -1, 'SENTETİK BİR', '1e0', 9999] as $value) {
            $this->rejects($fixture, $value);
        }
    }

    public function test_unknown_field_system_key_and_hidden_value_are_rejected(): void
    {
        $fixture = $this->fixture('text', false, false);
        foreach ([[$fixture[2] => 'secret'], [9999 => 'foreign'], ['revision' => 999], ['01' => 'aliased'], array_fill(0, 101, 'oversized')] as $values) {
            try {
                app(ModuleValueValidator::class)->validateForCreate($fixture[0], $fixture[1], $values);
                $this->fail('Unlisted field accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('values', $error->errors());
            }
        }
        $this->assertSame([$fixture[2] => null], app(ModuleValueValidator::class)->validateForCreate($fixture[0], $fixture[1], []));
    }

    public function test_draft_wrong_module_and_older_published_version_cannot_create_records(): void
    {
        $fixture = $this->fixture('text');
        DB::table('module_versions')->where('id', $fixture[1])->update(['status' => 'draft', 'draft_slot' => 1]);
        $attempts = [[$fixture[0], $fixture[1]], [999, $fixture[1]], [$fixture[0], 999]];
        foreach ($attempts as [$module, $version]) {
            try {
                app(ModuleValueValidator::class)->validateForCreate($module, $version, []);
                $this->fail('Unavailable version accepted');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
        DB::table('module_versions')->where('id', $fixture[1])->update(['status' => 'published', 'draft_slot' => null]);
        $before = DB::table('module_versions')->where('id', $fixture[1])->value('definition');
        DB::table('module_versions')->insert(['module_id' => $fixture[0], 'number' => 2, 'status' => 'published', 'draft_slot' => null, 'definition' => $before]);
        $this->expectException(HttpException::class);
        $this->validate($fixture, 'SENTETİK');
    }

    public function test_corrupted_metadata_is_not_trusted_as_an_input_schema(): void
    {
        $fixture = $this->fixture('text');
        $definition = json_decode(DB::table('module_versions')->where('id', $fixture[1])->value('definition'), true);
        $definition['fields'][0]['type'] = 'integer';
        DB::table('module_versions')->where('id', $fixture[1])->update(['definition' => json_encode($definition)]);
        $this->expectException(LogicException::class);
        $this->validate($fixture, 1);
    }

    public function test_corrupted_shapes_duplicate_identity_and_hidden_required_fields_are_rejected(): void
    {
        $fixture = $this->fixture('text');
        $definition = json_decode(DB::table('module_versions')->where('id', $fixture[1])->value('definition'), true);
        $field = $definition['fields'][0];
        $shapes = [array_replace($definition, ['schema_version' => 999]), array_replace($definition, ['fields' => 'not-a-list']),
            array_replace($definition, ['fields' => ['key' => $field]]), array_replace($definition, ['fields' => ['not-an-object']]),
            array_replace($definition, ['fields' => [$field, $field]]), array_replace($definition, ['fields' => [array_replace($field, ['required' => true, 'show_in_form' => false])]]),
            array_replace($definition, ['fields' => [array_replace($field, ['id' => 9999])]])];
        foreach ($shapes as $shape) {
            DB::table('module_versions')->where('id', $fixture[1])->update(['definition' => json_encode($shape)]);
            try {
                $this->validate($fixture, 'SENTETİK');
                $this->fail('Corrupted definition accepted');
            } catch (LogicException $error) {
                $this->assertSame('Modül tanımı alan kimlikleriyle uyumlu değil.', $error->getMessage());
            }
        }
    }

    public function test_validation_is_read_only_and_choice_queries_are_bounded(): void
    {
        $fixture = $this->fixture('multiselect');
        $definition = json_decode(DB::table('module_versions')->where('id', $fixture[1])->value('definition'), true);
        for ($index = 0; $index < 49; $index++) {
            $id = DB::table('module_fields')->insertGetId(['module_id' => $fixture[0], 'type' => 'multiselect', 'option_list_id' => $fixture[3]]);
            $definition['fields'][] = array_replace($definition['fields'][0], ['id' => (int) $id]);
        }
        DB::table('module_versions')->where('id', $fixture[1])->update(['definition' => json_encode($definition)]);
        DB::enableQueryLog();
        $this->validate($fixture, $fixture[4]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
        $this->assertDatabaseCount('module_fields', 50);
        $this->assertDatabaseCount('option_items', 2);
        $this->assertDatabaseHas('modules', ['revision' => 2]);
    }
}
