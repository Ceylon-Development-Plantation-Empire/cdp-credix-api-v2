<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The overall size cap here is only a first guard; DocumentService checks the real content
     * type and the size limit of the chosen document type.
     */
    public function rules(): array
    {
        return [
            'document_type_slug' => ['required', 'string', Rule::exists('document_types', 'slug')->where('is_active', true)],
            'file' => ['required', 'file', 'max:20480'],
            'issued_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:'.BusinessDate::today()->toDateString()],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
