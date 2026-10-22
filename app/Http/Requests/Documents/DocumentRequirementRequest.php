<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\EmploymentStatus;
use App\Enums\PartyRole;
use App\Enums\RequirementRule;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both create and update; a requirement is small enough that the whole row is always sent.
 */
class DocumentRequirementRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Leave out for a rule that applies to every product.
            'product_slug' => ['nullable', 'string', Rule::exists('products', 'slug')->whereNull('deleted_at')],
            'party_role' => ['required', Rule::enum(PartyRole::class)],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'requirement_group' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'rule' => ['required', Rule::enum(RequirementRule::class)],
            'document_type_slug' => ['required', 'string', Rule::exists('document_types', 'slug')],
            'min_count' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ];
    }
}
