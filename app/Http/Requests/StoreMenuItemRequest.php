<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'module_code' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:40'],
            'route_name' => ['nullable', 'string', 'max:255'],
            'permission_key' => ['nullable', 'string', 'max:255', 'exists:permissions,key'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $routeName = $this->input('route_name');

            if ($routeName && ! Route::has($routeName)) {
                $validator->errors()->add('route_name', 'Tanımlı olmayan route menüye bağlanamaz.');
            }
        });
    }
}
