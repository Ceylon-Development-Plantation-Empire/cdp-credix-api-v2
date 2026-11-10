<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every field is optional: send only what changes. A new file needs a multipart request
 * (POST /documents/{id}); details alone can be sent as JSON with PUT.
 */
class UpdateDocumentRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_slug' => ['sometimes', 'string', Rule::exists('document_types', 'slug')->where('is_active', true)],
            'file' => ['sometimes', 'file', 'max:20480'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:'.BusinessDate::today()->toDateString()],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
