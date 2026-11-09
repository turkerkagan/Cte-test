<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitDefinition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['types' => 'array', 'fields' => 'array', 'is_active' => 'boolean', 'is_visible' => 'boolean', 'is_sensitive' => 'boolean'];
    }

    public function configuration(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'task' => 'Özel birim',
            'permission' => 'custom_'.$this->code,
            'sensitive' => $this->is_sensitive,
            'types' => $this->types,
            'fields' => array_column($this->fields, 'key'),
            'field_definitions' => $this->fields,
            'visible' => $this->is_visible,
        ];
    }
}
