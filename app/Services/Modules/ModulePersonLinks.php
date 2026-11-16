<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Queries\TurkishTextSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Person identity is a system relationship, never an editable metadata field. */
final class ModulePersonLinks
{
    public function binding(object $module, ?object $record, array $input): array
    {
        $linked = $module->record_mode !== 'standalone';
        $id = array_key_exists('person_id', $input) ? $input['person_id'] : $record?->person_id;
        if (($linked && $id === null) || (! $linked && $id !== null)) {
            $this->invalid($linked ? 'Kişiye bağlı kayıtta kişi seçilmelidir.' : 'Bağımsız modül kişiye bağlanamaz.');
        }
        if ($record !== null && ($id === null ? null : (int) $id) !== ($record->person_id === null ? null : (int) $record->person_id)) {
            $this->invalid('Kaydın bağlı olduğu kişi değiştirilemez.');
        }
        if ($id !== null && ! DB::table('people')->where('id', $id)->lockForUpdate()->exists()) {
            $this->invalid('Seçilen kişi kaydı bulunamadı.');
        }
        $slot = $module->record_mode === 'person_single' ? 1 : null;
        if ($slot !== null && $record === null && DB::table('module_records')->where('module_id', $module->id)->where('person_id', $id)->exists()) {
            $this->invalid('Bu kişinin modülde kaydı var. Mevcut kaydı düzenleyin.');
        }

        return ['person_id' => $id === null ? null : (int) $id, 'single_person_slot' => $slot];
    }

    public function identities(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('people')->whereIn('id', array_unique($ids))->get(['id', 'tc_identity', 'full_name'])
            ->mapWithKeys(fn ($person) => [(int) $person->id => ['id' => (int) $person->id, 'tc_identity' => $person->tc_identity, 'full_name' => $person->full_name]])->all();
    }

    public function lookup(User $actor, int $moduleId, array $input): array
    {
        $version = app(ModuleAccess::class)->authorize($actor, $moduleId, 'yazma');
        abort_if(json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR)['record_mode'] === 'standalone', 404);
        $data = Validator::make($input, ['q' => ['required', 'string', 'max:150']])->validate();
        $search = trim($data['q']);
        if (mb_strlen($search) < 2) {
            throw ValidationException::withMessages(['q' => 'Kişi araması için en az iki karakter yazın.']);
        }
        $query = DB::table('people')->where(function ($query) use ($search): void {
            app(TurkishTextSearch::class)->contains($query, 'full_name', $search);
            app(TurkishTextSearch::class)->contains($query, 'tc_identity', $search, 'or');
        });

        return $query->orderBy('id')->limit(25)->get(['id', 'tc_identity', 'full_name'])
            ->map(fn ($person) => ['id' => (int) $person->id, 'tc_identity' => $person->tc_identity, 'full_name' => $person->full_name])->all();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['person_id' => $message]);
    }
}
