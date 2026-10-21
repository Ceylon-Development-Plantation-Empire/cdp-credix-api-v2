<?php

namespace App\Http\Requests\Recovery;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A call, visit, promise to pay or note on a recovery case. A promise needs the amount and date.
 */
class RecoveryActivityRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['call', 'visit', 'promise_to_pay', 'note'])],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
            'promised_amount' => ['required_if:type,promise_to_pay', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'promised_date' => ['required_if:type,promise_to_pay', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
