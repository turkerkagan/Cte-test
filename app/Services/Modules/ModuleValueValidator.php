<?php

namespace App\Services\Modules;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Validates new-record values against a server-owned published definition.
 * The record service must authorize its actor and call this inside its write
 * transaction, using the shared installation lock also used by OptionCatalog.
 * This service performs reads only; it does not authorize, publish or save.
 */
final class ModuleValueValidator
{
    public const TEXT_LIMIT = 255;

    public const LONGTEXT_LIMIT = 10000;

    // Integers remain exact through PHP, JSON, JavaScript and the future index.
    public const INTEGER_LIMIT = 9007199254740991;

    // DECIMAL(18,6): decimal values remain strings, never binary floats.
    public const DECIMAL_INTEGER_DIGITS = 12;

    public const DECIMAL_SCALE = 6;

    public const MULTISELECT_LIMIT = 500;

    public function validateForCreate(int $moduleId, int $versionId, array $values): array
    {
        return $this->validate($moduleId, $versionId, $values);
    }

    public function validateForUpdate(int $moduleId, int $recordId, int $versionId, array $values): array
    {
        $record = DB::table('module_records')->where('module_id', $moduleId)->where('id', $recordId)->first(['version_id', 'values']);
        abort_if($record === null, 404);
        if ((int) $record->version_id !== $versionId) {
            throw new DefinitionConflict;
        }

        return $this->validate($moduleId, $versionId, $values, json_decode($record->values, true, 64, JSON_THROW_ON_ERROR));
    }

    public function checkedFields(int $moduleId, array $definition, ?string $recordMode = null): array
    {
        $fields = $definition['fields'] ?? null;
        if (($definition['schema_version'] ?? null) !== 1 || ! in_array($definition['record_mode'] ?? null, ModuleDefinitions::RECORD_MODES, true) || $definition['record_mode'] !== ($recordMode ?? DB::table('modules')->where('id', $moduleId)->value('record_mode')) || ! is_array($fields) || ! array_is_list($fields) || count($fields) > 100) {
            throw $this->invalidDefinition();
        }
        $identities = DB::table('module_fields')->where('module_id', $moduleId)->get(['id', 'type', 'option_list_id'])->keyBy('id');
        $allowed = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw $this->invalidDefinition();
            }
            $id = $field['id'] ?? null;
            if (! is_int($id) || $id < 1 || $id > self::INTEGER_LIMIT || isset($allowed[$id]) || ! $identities->has($id)
                || ! in_array($field['type'] ?? null, ModuleDefinitions::TYPES, true) || $identities[$id]->type !== $field['type']
                || ! is_string($field['label'] ?? null) || ! is_bool($field['required'] ?? null) || ! is_bool($field['show_in_form'] ?? null)
                || ($field['required'] && ! $field['show_in_form'])) {
                throw $this->invalidDefinition();
            }
            $choice = in_array($field['type'], ['select', 'multiselect'], true);
            if ($choice) {
                $listId = $field['option_list_id'] ?? null;
                if (! is_int($listId) || $listId < 1 || $listId > self::INTEGER_LIMIT || (int) $identities[$id]->option_list_id !== $listId) {
                    throw $this->invalidDefinition();
                }
            } elseif (($field['option_list_id'] ?? null) !== null || $identities[$id]->option_list_id !== null) {
                throw $this->invalidDefinition();
            }
            $allowed[$id] = $field;
        }

        return $allowed;
    }

    private function validate(int $moduleId, int $versionId, array $values, ?array $stored = null): array
    {
        if (count($values) > 100) {
            throw ValidationException::withMessages(['values' => 'En fazla 100 alan değeri gönderilebilir.']);
        }
        $version = DB::table('module_versions')->join('modules', 'modules.id', '=', 'module_versions.module_id')
            ->where('module_versions.module_id', $moduleId)->where('module_versions.id', $versionId)
            ->where('module_versions.status', 'published')->whereNull('module_versions.draft_slot')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('module_versions as newer')
                ->whereColumn('newer.module_id', 'module_versions.module_id')->where('newer.status', 'published')
                ->whereColumn('newer.number', '>', 'module_versions.number'))
            ->whereIn('modules.record_mode', ModuleDefinitions::RECORD_MODES)->first(['module_versions.definition', 'modules.record_mode']);
        if ($version === null && $stored !== null) {
            throw new DefinitionConflict;
        }
        abort_if($version === null, 404);
        $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
        $allowed = $this->checkedFields($moduleId, $definition, $version->record_mode);
        $listIds = array_column($allowed, 'option_list_id');
        $errors = [];
        foreach ($values as $key => $value) {
            if (! is_int($key) || ! isset($allowed[$key]) || ! $allowed[$key]['show_in_form']) {
                $errors['values'][] = 'Tanımda bulunmayan veya formda kapalı olan alan gönderilemez.';
            }
        }
        // One scoped query regardless of how many choice fields are present.
        $options = $listIds === [] ? collect() : DB::table('option_items')->join('option_lists', 'option_lists.id', '=', 'option_items.option_list_id')
            ->whereIn('option_items.option_list_id', array_unique($listIds))->where('option_lists.is_active', true)->where('option_items.is_active', true)
            ->get(['option_items.id', 'option_items.option_list_id'])->keyBy('id');
        // Only trusted DB values may retain an archived choice on an update.
        $historical = $stored === null || $listIds === [] ? collect() : DB::table('option_items')->join('option_lists', 'option_lists.id', '=', 'option_items.option_list_id')
            ->whereIn('option_items.option_list_id', array_unique($listIds))->get(['option_items.id', 'option_items.option_list_id'])->keyBy('id');
        $normalized = [];
        foreach ($allowed as $id => $field) {
            try {
                $value = $this->normalize($field['type'], ! $field['show_in_form'] && $stored !== null ? ($stored[$id] ?? null) : ($values[$id] ?? null));
                if ($field['required'] && ($value === null || $value === [])) {
                    throw new LogicException('Bu alan zorunlu.');
                }
                if ($value !== null && in_array($field['type'], ['select', 'multiselect'], true)) {
                    foreach ($field['type'] === 'select' ? [$value] : $value as $optionId) {
                        $previous = $stored[$id] ?? null;
                        $retained = $stored !== null && ($field['type'] === 'select' ? $previous === $optionId : (is_array($previous) && in_array($optionId, $previous, true)))
                            && $historical->has($optionId) && (int) $historical[$optionId]->option_list_id === $field['option_list_id'];
                        if ((! $options->has($optionId) || (int) $options[$optionId]->option_list_id !== $field['option_list_id']) && ! $retained) {
                            throw new LogicException('Bu listeye ait etkin bir seçenek seçin.');
                        }
                    }
                }
                $normalized[$id] = $value;
            } catch (LogicException $error) {
                $errors['values.'.$id][] = $field['label'].': '.$error->getMessage();
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    public function normalizeFilter(string $type, mixed $value): mixed
    {
        try {
            return $this->normalize($type === 'multiselect' ? 'select' : $type, $value);
        } catch (LogicException $error) {
            throw ValidationException::withMessages(['filter_value' => $error->getMessage()]);
        }
    }

    private function normalize(string $type, mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $type === 'multiselect' ? [] : null;
        }

        return match ($type) {
            'text', 'longtext' => $this->text($type, $value),
            'integer' => $this->integer($value),
            'decimal' => $this->decimal($value),
            'boolean' => $this->boolean($value),
            'date', 'datetime' => $this->date($type, $value),
            'select' => $this->optionId($value),
            'multiselect' => $this->multiple($value),
            default => throw $this->invalidDefinition(),
        };
    }

    private function text(string $type, mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new LogicException('Geçerli bir metin girin.');
        }
        $limit = $type === 'text' ? self::TEXT_LIMIT : self::LONGTEXT_LIMIT;
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (mb_strlen($value, 'UTF-8') > $limit || ($type === 'text' && str_contains($value, "\n"))) {
            throw new LogicException($type === 'text' ? 'Kısa metin tek satır ve en fazla 255 karakter olmalı.' : 'Uzun metin en fazla 10000 karakter olmalı.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string) $value)) {
            throw new LogicException('Tam sayı girin; kesir veya bilimsel gösterim kullanmayın.');
        }
        $digits = ltrim((string) $value, '-');
        $limit = (string) self::INTEGER_LIMIT;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new LogicException('Tam sayı -9007199254740991 ile 9007199254740991 arasında olmalı.');
        }

        return (int) $value;
    }

    private function decimal(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 32 || ! preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', (string) $value, $parts)) {
            throw new LogicException('Ondalık sayıyı nokta ile metin olarak gönderin; bilimsel gösterim kullanmayın.');
        }
        $whole = ltrim($parts[2], '0') ?: '0';
        $fraction = $parts[3] ?? '';
        if (strlen($whole) > self::DECIMAL_INTEGER_DIGITS || strlen($fraction) > self::DECIMAL_SCALE) {
            throw new LogicException('Ondalık sayı en fazla 12 tam ve 6 kesir basamağı içerebilir.');
        }
        $fraction = rtrim($fraction, '0');
        $negative = $parts[1] === '-' && ($whole !== '0' || $fraction !== '');

        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new LogicException('Evet/hayır değeri seçin.'),
        };
    }

    private function date(string $type, mixed $value): string
    {
        $pattern = $type === 'date' ? '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D' : '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?$/D';
        if (! is_string($value) || ! preg_match($pattern, $value)) {
            throw new LogicException('Tarihi YYYY-AA-GG, tarih-saati YYYY-AA-GG SS:DD[:ss] biçiminde girin.');
        }
        $value = str_replace('T', ' ', $value);
        if ($type === 'datetime' && strlen($value) === 16) {
            $value .= ':00';
        }
        $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new LogicException('Takvimde geçerli bir tarih ve saat girin.');
        }

        // Institution-local wall time; no timezone conversion or default hour.
        return $value;
    }

    private function optionId(mixed $value): int
    {
        $id = $this->integer($value);
        if ($id < 1) {
            throw new LogicException('Geçerli bir seçenek kimliği seçin.');
        }

        return $id;
    }

    private function multiple(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MULTISELECT_LIMIT) {
            throw new LogicException('En fazla 500 seçenekten oluşan sıralı bir liste gönderin.');
        }
        $ids = array_map(fn ($id) => $this->optionId($id), $value);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Aynı seçenek birden fazla seçilemez.');
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function invalidDefinition(): LogicException
    {
        return new LogicException('Modül tanımı alan kimlikleriyle uyumlu değil.');
    }
}
