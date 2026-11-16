<?php

namespace App\Services\Modules;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded read snapshot; exports never widen the visible list field scope. */
final class ModuleExport
{
    public const ROW_LIMIT = 5000;

    public const BYTE_LIMIT = 10485760;

    public function snapshot(User $actor, int $moduleId, array $input): array
    {
        return DB::transaction(function () use ($actor, $moduleId, $input): array {
            $version = app(ModuleAccess::class)->authorize($actor, $moduleId);
            $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
            $fields = collect(app(ModuleValueValidator::class)->checkedFields($moduleId, $definition))->where('show_in_table', true)->values();
            $linked = $definition['record_mode'] !== 'standalone';
            $identityFields = $linked ? [['id' => 'tc_identity', 'type' => 'text', 'label' => 'TC Kimlik', 'kind' => 'tc'], ['id' => 'full_name', 'type' => 'text', 'label' => 'Adı Soyadı', 'kind' => 'name']] : [];
            $query = DB::table('module_records')->where('module_id', $moduleId);
            $state = app(ModuleListQuery::class)->apply($query, $moduleId, $fields->all(), $input, $linked);
            if ($query->columns !== null) {
                $query->addSelect('module_records.person_id', 'module_records.values');
            }
            if ((clone $query)->count() > self::ROW_LIMIT) {
                $this->tooLarge();
            }
            $rows = [];
            $bytes = 0;
            for ($offset = 0; ; $offset += 100) {
                $batch = (clone $query)->offset($offset)->limit(100)->get(['id', 'values', 'person_id']);
                if ($batch->isEmpty()) {
                    break;
                }
                $people = app(ModulePersonLinks::class)->identities($batch->pluck('person_id')->filter()->map(fn ($id) => (int) $id)->all());
                $labels = DB::table('module_record_options')->whereIn('record_id', $batch->pluck('id'))->whereIn('field_id', $fields->pluck('id'))
                    ->orderBy('field_id')->orderBy('option_id')->get(['record_id', 'field_id', 'label_snapshot'])->groupBy('record_id');
                foreach ($batch as $record) {
                    $bytes += strlen($record->values);
                    if ($bytes > self::BYTE_LIMIT || count($rows) >= self::ROW_LIMIT) {
                        $this->tooLarge();
                    }
                    $values = json_decode($record->values, true, 64, JSON_THROW_ON_ERROR);
                    $person = $record->person_id === null ? null : ($people[(int) $record->person_id] ?? null);
                    $row = $linked ? [(string) ($person['tc_identity'] ?? ''), (string) ($person['full_name'] ?? '')] : [];
                    foreach ($fields as $field) {
                        $value = $values[$field['id']] ?? null;
                        $row[] = match ($field['type']) {
                            'select', 'multiselect' => $labels->get($record->id, collect())->where('field_id', $field['id'])->pluck('label_snapshot')->implode(', '),
                            'boolean' => $value === null ? '' : ($value ? 'Evet' : 'Hayır'),
                            default => $value === null ? '' : (string) $value,
                        };
                    }
                    $bytes += array_sum(array_map('strlen', $row));
                    if ($bytes > self::BYTE_LIMIT) {
                        $this->tooLarge();
                    }
                    $rows[] = $row;
                }
            }

            return ['title' => $definition['name'], 'definitionVersion' => (int) $version->number, 'fields' => array_merge($identityFields, $fields->map(fn ($field) => array_intersect_key($field, array_flip(['id', 'type', 'label'])))->all()),
                'rows' => $rows, 'queryState' => $state];
        });
    }

    public function csv(array $snapshot): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_merge(($snapshot['includeSequence'] ?? true) ? ['ID'] : [], array_map(fn ($field) => $this->safeText($field['label']), $snapshot['fields'])), ';', '"', '');
            foreach ($snapshot['rows'] as $index => $row) {
                $cells = [];
                foreach ($row as $position => $value) {
                    $cells[] = in_array($snapshot['fields'][$position]['type'], ['integer', 'decimal'], true) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) ? $value : $this->safeText($value);
                }
                fputcsv($stream, array_merge(($snapshot['includeSequence'] ?? true) ? [$index + 1] : [], $cells), ';', '"', '');
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function safeText(string $value): string
    {
        // Text and labels must not become spreadsheet formulas.
        return preg_match('/^[\s\x00-\x20]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }

    private function tooLarge(): never
    {
        throw ValidationException::withMessages(['export' => 'Çıktı en fazla 5000 kayıt ve 10 MB kaynak veri içerebilir. Filtreleri daraltıp yeniden deneyin.']);
    }
}
