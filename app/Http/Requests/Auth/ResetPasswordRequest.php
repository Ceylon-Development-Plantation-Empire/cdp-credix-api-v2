<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /reset-password: the code from the SMS and the new password (same strength rule as a password change).
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * Public endpoint (rate limited by the "password-reset" limiter).
     */
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
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::defaults()],
        ];
    }
}
