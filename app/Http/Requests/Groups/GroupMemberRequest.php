<?php

declare(strict_types=1);

namespace App\Http\Requests\Groups;

use App\Enums\GroupMemberRole;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a member (POST, needs the customer) or change a member's role (PATCH).
 */
class GroupMemberRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_public_id' => $this->isMethod('post') ? ['required', 'string', 'ulid'] : ['prohibited'],
            'role' => [$this->isMethod('post') ? 'sometimes' : 'required', Rule::enum(GroupMemberRole::class)],
        ];
    }
}
