<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual journal. Each line is a debit or a credit; the service checks that they balance,
 * that no loan control account is used and that the period is open.
 */
class ManualJournalRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'narration' => ['required', 'string', 'min:3', 'max:255'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.gl_account_id' => ['required', 'integer', 'distinct', Rule::exists('gl_accounts', 'id')->where('is_active', true)],
            'lines.*.debit' => ['nullable', 'required_without:lines.*.credit', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'lines.*.credit' => ['nullable', 'required_without:lines.*.debit', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
