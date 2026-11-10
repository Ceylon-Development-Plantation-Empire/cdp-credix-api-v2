<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\SettingType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A custom setting. The validation rules of its value are derived from its type here and never taken
 * from the caller (a rule string is code).
 */
class StoreSettingRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', Rule::unique('settings', 'key')],
            'group' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => ['required', Rule::enum(SettingType::class)],
            'value' => ['present'],
            'label' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
