<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me: the signed-in user's own name and email.
 *
 * The email is the sign-in identifier, so it can be changed but never cleared.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * Any signed-in user may edit their own profile.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'email' => ['sometimes', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($this->user()?->id)],
        ];
    }
}
