<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\DocumentStatus;
use App\Enums\LoanStatus;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /documents: every document of the loan applications that are still before approval (or of the
 * stages asked for), whoever owns it: the loan, its customers or its collateral.
 */
class DocumentIndexRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Loan stages, comma separated (default: submitted,in_review,verified).
            'loan_status' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail) {
                $unknown = array_diff($this->loanStatuses() ?? [], array_column(LoanStatus::cases(), 'value'));

                if ($unknown !== []) {
                    $fail('Unknown loan status: '.implode(', ', $unknown));
                }
            }],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'document_type_slug' => ['nullable', 'string', 'max:100'],
            'owner_type' => ['nullable', Rule::in(['loan', 'customer', 'collateral'])],
            'loan_public_id' => ['nullable', 'string', 'ulid'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function loanStatuses(): ?array
    {
        $raw = (string) $this->input('loan_status', '');

        return $raw === '' ? null : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
