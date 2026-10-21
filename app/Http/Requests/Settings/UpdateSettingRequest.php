<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the presence of "value" is checked here. Its real rules are stored on the setting row
     * and applied by SettingService, because every setting has different rules.
     */
    public function rules(): array
    {
        return [
            'value' => 'present',
        ];
    }
}
