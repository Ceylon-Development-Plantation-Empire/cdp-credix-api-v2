<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentTarget;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDocumentTypeRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:document_types,slug'],
            'target' => ['required', Rule::enum(DocumentTarget::class)],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Only safe document formats; executables, office macros and archives are never accepted.
            'allowed_mimes' => ['sometimes', 'array', 'min:1'],
            'allowed_mimes.*' => ['string', 'distinct', Rule::in(['pdf', 'jpg', 'jpeg', 'png'])],
            'max_size_kb' => ['sometimes', 'integer', 'min:100', 'max:20480'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
