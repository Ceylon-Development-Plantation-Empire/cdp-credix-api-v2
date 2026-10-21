<?php

namespace App\Http\Requests\Accounting;

use App\Enums\GlAccountType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An account of the chart. What a system account may not change (type, active) is checked by
 * the controller, which knows the account.
 */
class GlAccountRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'bail', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('gl_accounts', 'code')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'min:3', 'max:120'],
            'type' => [$required, Rule::enum(GlAccountType::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
