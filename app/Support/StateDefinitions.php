<?php

namespace App\Support;

use App\Models\LookupGroup;
use Illuminate\Validation\ValidationException;

class StateDefinitions
{
    /** Existing historical values remain editable; new transitions use active definitions. */
    public static function validate(array $values, array $previous = [], array $groups = ['status_code' => 'inmate_status', 'risk_code' => 'risk_status', 'decision_code' => 'decision']): void
    {
        foreach ($groups as $field => $code) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '' || ($previous[$field] ?? null) === $value) {
                continue;
            }
            $group = LookupGroup::where('code', $code)->first();
            // Installations without a configured vocabulary retain their existing free-text workflow.
            if ($group && (! $group->is_active || ! $group->items()->where('is_active', true)->where('code', $value)->exists())) {
                throw ValidationException::withMessages([$field => 'Yeni durum için etkin bir tanım seçin.']);
            }
        }
    }
}
