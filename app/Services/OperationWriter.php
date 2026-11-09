<?php

namespace App\Services;

use App\Models\OperationPrivateFile;
use App\Models\OperationRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OperationWriter
{
    public function save(?OperationRecord $record, array $data, ?array $members, ?UploadedFile $upload): OperationRecord
    {
        $path = $upload ? 'private/operations/'.Str::uuid().'.'.$upload->guessExtension() : null;
        if ($upload && $upload->storeAs('private/operations', basename($path), 'local') !== $path) {
            throw new RuntimeException('Belge kaydedilemedi.');
        }
        try {
            $record = DB::transaction(function () use ($record, $data, $members, $upload, $path) {
                $record ??= new OperationRecord;
                $record->fill($data)->save();
                if ($members !== null) {
                    $record->members()->sync($members);
                }
                if ($upload) {
                    $oldFiles = $record->privateFiles()->get();
                    OperationPrivateFile::create([
                        'operation_record_id' => $record->id, 'filename' => basename($path),
                        'original_filename' => $upload->getClientOriginalName(), 'mime_type' => $upload->getMimeType(),
                        'file_size' => $upload->getSize(), 'file_hash' => hash_file('sha256', $upload->getRealPath()), 'created_by' => auth()->id(),
                    ]);
                    foreach ($oldFiles as $old) {
                        $old->delete();
                    }
                    DB::afterCommit(function () use ($oldFiles): void {
                        foreach ($oldFiles as $old) {
                            Storage::disk('local')->delete('private/operations/'.$old->filename);
                        }
                    });
                }

                return $record;
            });
        } catch (Throwable $exception) {
            if ($path && ! OperationPrivateFile::where('filename', basename($path))->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return $record;
    }
}
