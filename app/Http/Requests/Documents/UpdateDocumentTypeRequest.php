<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\DocumentTarget;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = DocumentType::findBySlug((string) $this->route('slug'));

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('document_types', 'slug')->ignore($type?->id)],
            'target' => ['sometimes', Rule::enum(DocumentTarget::class)],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'allowed_mimes' => ['sometimes', 'array', 'min:1'],
            'allowed_mimes.*' => ['string', 'distinct', Rule::in(['pdf', 'jpg', 'jpeg', 'png'])],
            'max_size_kb' => ['sometimes', 'integer', 'min:100', 'max:20480'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
