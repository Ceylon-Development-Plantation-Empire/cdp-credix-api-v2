<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /login. "login" is the staff member's email (field name kept from API guide v2.0).
 *
 * DECISION: no email-format rule. A username typed out of habit gets the same generic
 * "Invalid credentials" as any wrong login instead of a validation error that would reveal the
 * expected format. Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class LoginRequest extends FormRequest
{
    /**
     * Public endpoint (rate limited by the "login" limiter).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
