<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InmatePrivateFile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function inmate(): BelongsTo
    {
        return $this->belongsTo(InmateFile::class, 'inmate_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        if ($this->file_size < 1024) {
            return $this->file_size.' B';
        }

        if ($this->file_size < 1048576) {
            return number_format($this->file_size / 1024, 1, ',', '.').' KB';
        }

        return number_format($this->file_size / 1048576, 1, ',', '.').' MB';
    }
}
