<?php

namespace App\Services\Security;

use Closure;

final class PasswordPolicy
{
    public static function rules(): array
    {
        return ['bail', 'required', 'string', 'min:6', 'max:128', static function (string $attribute, mixed $value, Closure $fail): void {
            if (config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                $fail('Parola çok uzun; daha kısa bir parola kullanın.');
            }
        }];
    }
}
