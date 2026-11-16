<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\People\PersonConflict;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StaffTree
{
    public function read(User $actor): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'personel_kadrosu');
        $rows = DB::table('staff_nodes')->orderBy('position')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $map = array_column($rows, null, 'id');
        foreach ($rows as &$row) {
            $row['review_reason'] = null;
            $seen = [$row['id'] => true];
            $parent = $row['parent_id'];
            while ($parent !== null) {
                if (isset($seen[$parent])) {
                    $row['review_reason'] = 'Döngülü üst bağlantı';
                    break;
                }
                if (! isset($map[$parent]) || ! $map[$parent]['is_active']) {
                    $row['review_reason'] = 'Olmayan veya pasif üst düğüm';
                    break;
                }
                $seen[$parent] = true;
                $parent = $map[$parent]['parent_id'];
            }
        }
        unset($row);

        return ['users' => app(WorkflowAccess::class)->canWrite($actor, 'personel_kadrosu') ? DB::table('users')->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name as name']) : [], 'nodes' => $rows, 'canWrite' => app(WorkflowAccess::class)->canWrite($actor, 'personel_kadrosu')];
    }

    public function save(User $actor, ?int $id, array $input): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'personel_kadrosu', 'yazma');
        $data = Validator::make(['payload' => $input], [
            'payload' => ['required', 'array:revision,parent_id,user_id,full_name,title,description,position,is_active'],
            'payload.revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'payload.parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'payload.user_id' => ['present', 'nullable', 'integer', 'min:1'],
            'payload.full_name' => ['required', 'string', 'max:255'],
            'payload.title' => ['required', 'string', 'max:255'],
            'payload.description' => ['present', 'nullable', 'string', 'max:10000'],
            'payload.position' => ['required', 'integer', 'between:0,100000'],
            'payload.is_active' => ['required', 'boolean'],
        ])->validate()['payload'];
        foreach (['parent_id', 'user_id', 'position', 'revision'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return DB::transaction(function () use ($actor, $id, $data) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = app(WorkflowAccess::class)->authorize($actor, 'personel_kadrosu', 'yazma', true);
            $rows = DB::table('staff_nodes')->lockForUpdate()->get()->keyBy('id');
            $row = $id === null ? null : $rows->get($id);
            abort_if($id !== null && $row === null, 404);
            if ($row !== null && (int) $row->revision !== (int) $data['revision']) {
                throw new PersonConflict;
            }
            $parent = $data['parent_id'];
            $seen = [];
            while ($parent !== null) {
                if ($parent === $id || isset($seen[$parent])) {
                    throw ValidationException::withMessages(['parent_id' => 'Kendisine veya alt düğümüne bağlanamaz.']);
                }
                $node = $rows->get($parent);
                if ($node === null || ! $node->is_active) {
                    throw ValidationException::withMessages(['parent_id' => 'Etkin bir üst düğüm seçin.']);
                }
                $seen[$parent] = true;
                $parent = $node->parent_id;
            }
            if ($data['user_id'] !== null && ! DB::table('users')->where('id', $data['user_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Etkin bir personel seçin.']);
            }
            unset($data['revision']);
            $changed = $row === null || collect($data)->contains(fn ($v, $k) => $v != $row->$k || ($v === null) !== ($row->$k === null));
            $revision = (int) ($row->revision ?? 0);
            if ($changed) {
                $revision++;
                $values = $data + ['revision' => $revision, 'updated_at' => now()];
                if ($id === null) {
                    $id = DB::table('staff_nodes')->insertGetId($values + ['created_at' => now()]);
                } else {
                    DB::table('staff_nodes')->where('id', $id)->update($values);
                }
                app(AuditLog::class)->record($actor, 'staff_node', $id, $row === null ? 'created' : 'updated', ['revision' => $revision, 'parent_id' => $data['parent_id'], 'position' => $data['position'], 'is_active' => $data['is_active']]);
            }

            return ['id' => $id, 'revision' => $revision];
        }, 3);
    }
}
