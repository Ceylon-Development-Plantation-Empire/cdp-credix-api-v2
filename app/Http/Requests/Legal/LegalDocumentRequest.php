<?php

declare(strict_types=1);

namespace App\Http\Requests\Legal;

use App\Enums\Language;
use App\Enums\LegalDocumentType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a legal document. The typed-in details are a flat list of short values.
 */
class LegalDocumentRequest extends FormRequest
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
            'loan_public_id' => $creating ? ['required', 'string', 'ulid'] : ['prohibited'],
            'document_type' => [$required, Rule::enum(LegalDocumentType::class)],
            'language' => [$required, Rule::enum(Language::class)],
            'status' => ['sometimes', Rule::in(['pending', 'created'])],
            'template_id' => ['nullable', 'integer'],
            'details' => ['nullable', 'array', 'max:150'],
            'details.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_scalar($value) || strlen((string) $value) > 2000) {
                    $fail('Each detail must be a short text or number.');
                }
            }],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
